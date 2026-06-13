<?php
declare(strict_types=1);

/**
 * Faculty teaching load (units) from scheduled offerings.
 * Scoped: admin (optional college/faculty filters), dean (college), program chair (program), gened (GE courses).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_role(['admin', 'dean', 'program_chair', 'gened']);

$role = (string) ($_SESSION['role'] ?? '');
$collegeId = current_college_id();
$programScope = null;

if (is_dean()) {
    $collegeId = dean_college_id_or_fail();
} elseif (is_program_chair()) {
    $programScope = program_scope_or_fail();
    $collegeId = dean_or_program_chair_college_id_or_fail();
}

$adminCollegeId = (int) ($_GET['college_id'] ?? 0);
$dept = trim((string) ($_GET['dept'] ?? ''));
$sem = trim((string) ($_GET['semester'] ?? ''));
$sy = trim((string) ($_GET['school_year'] ?? ''));
$facultySearch = trim((string) ($_GET['faculty_search'] ?? ''));
$geFacultyFilter = (string) ($_GET['ge_faculty'] ?? '');
if ($geFacultyFilter !== '1' && $geFacultyFilter !== '0') {
    $geFacultyFilter = '';
}

$hasGeTargetsTable = db_table_exists('ge_schedule_targets');
$hasCourseLabFlag = db_column_exists('courses', 'is_laboratory');
$hasLectureUnits = db_column_exists('courses', 'lecture_units');
$hasLaboratoryUnits = db_column_exists('courses', 'laboratory_units');
$hasIsGenedCourse = db_column_exists('courses', 'is_gened');
$hasIsGenedFaculty = db_column_exists('faculty', 'is_gened');
$hasCourseYearLevel = db_column_exists('courses', 'year_level');
$hasCourseSection = db_column_exists('courses', 'section');

$targetSelect = $hasGeTargetsTable
    ? ', gst.program_name AS target_program, gst.year_level AS target_year_level, gst.section AS target_section'
    : '';
$targetJoin = $hasGeTargetsTable ? ' LEFT JOIN ge_schedule_targets gst ON gst.schedule_id = s.id ' : '';

$courseLabSelect = $hasCourseLabFlag ? ', c.is_laboratory' : '';

$courseCatalogBlockSelect = ', c.department AS course_department';
if ($hasCourseYearLevel) {
    $courseCatalogBlockSelect .= ', c.year_level AS course_year_level';
}
if ($hasCourseSection) {
    $courseCatalogBlockSelect .= ', c.section AS course_section';
}

$courseUnitsSelect = ', c.units AS course_units_total';
if ($hasLectureUnits) {
    $courseUnitsSelect .= ', c.lecture_units';
}
if ($hasLaboratoryUnits) {
    $courseUnitsSelect .= ', c.laboratory_units';
}

$sql = "SELECT s.id, s.faculty_id, s.course_id, s.college_id AS sched_college_id,
        s.semester, s.school_year,
        s.start_time, s.end_time, s.day_of_week,
        s.program AS sched_program, s.year_level AS sched_year_level, s.section AS sched_section,
        f.full_name AS faculty_name, c.course_code, c.course_name,
        r.type AS room_type, r.room_code, r.room_name,
        col.college_code, col.college_name
        {$courseUnitsSelect}
        {$courseLabSelect}
        {$courseCatalogBlockSelect}
        {$targetSelect}
        FROM schedules s
        INNER JOIN faculty f ON f.id = s.faculty_id
        INNER JOIN courses c ON c.id = s.course_id
        INNER JOIN rooms r ON r.id = s.room_id
        LEFT JOIN users uc ON uc.id = s.created_by
        LEFT JOIN colleges col ON col.id = s.college_id
        {$targetJoin}
        WHERE 1=1";
$params = [];

if ($role === 'admin') {
    if ($adminCollegeId > 0) {
        $sql .= ' AND s.college_id = ?';
        $params[] = $adminCollegeId;
    }
} elseif (is_dean() && $collegeId) {
    $sql .= ' AND s.college_id = ?';
    $params[] = $collegeId;
} elseif ($programScope !== null && $collegeId) {
    // Program chair: major offerings only — course department must match assigned program (no GE cross-list via gst).
    $sql .= ' AND s.college_id = ?';
    $params[] = $collegeId;
    $sql .= ' AND c.department = ?';
    $params[] = $programScope;
}

if (is_gened()) {
    if ($hasIsGenedCourse) {
        $sql .= ' AND COALESCE(c.is_gened, 0) = 1';
    } else {
        $sql .= ' AND COALESCE(uc.role, "") = "gened"';
    }
}

if (is_gened() && $hasIsGenedFaculty && $geFacultyFilter === '1') {
    $sql .= ' AND COALESCE(f.is_gened, 0) = 1';
} elseif (is_gened() && $hasIsGenedFaculty && $geFacultyFilter === '0') {
    $sql .= ' AND COALESCE(f.is_gened, 0) = 0';
}

if (is_program_chair()) {
    if ($hasIsGenedCourse) {
        $sql .= ' AND COALESCE(c.is_gened, 0) = 0';
    } else {
        $sql .= ' AND COALESCE(uc.role, "") != "gened"';
    }
}

if ($dept !== '') {
    if ($hasGeTargetsTable) {
        $sql .= ' AND (f.department = ? OR c.department = ? OR gst.program_name = ?)';
        $params[] = $dept;
        $params[] = $dept;
        $params[] = $dept;
    } else {
        $sql .= ' AND (f.department = ? OR c.department = ?)';
        $params[] = $dept;
        $params[] = $dept;
    }
}
if ($sem !== '') {
    $sql .= ' AND s.semester = ?';
    $params[] = $sem;
}
if ($sy !== '') {
    $sql .= ' AND s.school_year = ?';
    $params[] = $sy;
}
if (($role === 'admin' || is_dean() || is_program_chair() || is_gened()) && $facultySearch !== '') {
    $sql .= ' AND (f.full_name LIKE ? OR f.faculty_id LIKE ?)';
    $params[] = '%' . $facultySearch . '%';
    $params[] = '%' . $facultySearch . '%';
}

$sql .= ' ORDER BY s.school_year DESC, s.semester, col.college_code, f.full_name, s.start_time';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// One class block (start–end) with this many hours or more counts as LAB; shorter blocks count as LEC (not by room type).
$labSessionMinHours = 3.0;

$sessionHoursBetween = static function (string $start, string $end): float {
    $rawS = substr($start, 0, 8);
    $rawE = substr($end, 0, 8);
    $ds = DateTime::createFromFormat('H:i:s', $rawS) ?: DateTime::createFromFormat('H:i', substr($start, 0, 5));
    $de = DateTime::createFromFormat('H:i:s', $rawE) ?: DateTime::createFromFormat('H:i', substr($end, 0, 5));
    if (!$ds || !$de) {
        return 0.0;
    }
    $secs = $de->getTimestamp() - $ds->getTimestamp();
    if ($secs <= 0) {
        return 0.0;
    }
    return round($secs / 3600, 2);
};

$scheduleListGroups = [];
foreach ($rows as $r) {
    $cid = (int) ($r['sched_college_id'] ?? 0);
    $k = (int) $r['faculty_id'] . '|' . (int) $r['course_id'] . '|' . (string) $r['semester'] . '|' . (string) $r['school_year'] . '|' . $cid;
    $scheduleListGroups[$k][] = $r;
}
foreach ($scheduleListGroups as &$g) {
    usort(
        $g,
        static function (array $a, array $b) use ($sessionHoursBetween, $labSessionMinHours): int {
            $ha = $sessionHoursBetween((string) ($a['start_time'] ?? ''), (string) ($a['end_time'] ?? ''));
            $hb = $sessionHoursBetween((string) ($b['start_time'] ?? ''), (string) ($b['end_time'] ?? ''));
            $aLab = $ha >= $labSessionMinHours;
            $bLab = $hb >= $labSessionMinHours;
            if ($aLab !== $bLab) {
                return $aLab ? 1 : -1;
            }
            return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
        }
    );
}
unset($g);

$courseOfferingUnits = static function (array $r) use ($hasLectureUnits, $hasLaboratoryUnits): float {
    if ($hasLectureUnits && $hasLaboratoryUnits) {
        return (float) ($r['lecture_units'] ?? 0) + (float) ($r['laboratory_units'] ?? 0);
    }

    return (float) ($r['course_units_total'] ?? 0);
};

$formatLoadUnits = static function (float $u): string {
    $s = number_format($u, 1, '.', '');
    return rtrim(rtrim($s, '0'), '.');
};

$formatTime12h = static function (?string $time): string {
    $raw = substr((string) $time, 0, 5);
    $dt = DateTime::createFromFormat('H:i', $raw);
    return $dt ? $dt->format('g:i A') : $raw;
};

$resolveProgramYearSection = static function (array $r) use (
    $hasGeTargetsTable,
    $hasCourseYearLevel,
    $hasCourseSection
): array {
    $programShow = trim((string) ($r['sched_program'] ?? ''));
    $yearShow = trim((string) ($r['sched_year_level'] ?? ''));
    $sectionShow = trim((string) ($r['sched_section'] ?? ''));
    if ($hasGeTargetsTable) {
        if ($programShow === '') {
            $programShow = trim((string) ($r['target_program'] ?? ''));
        }
        if ($yearShow === '') {
            $yearShow = trim((string) ($r['target_year_level'] ?? ''));
        }
        if ($sectionShow === '') {
            $sectionShow = trim((string) ($r['target_section'] ?? ''));
        }
    }
    if ($programShow === '') {
        $programShow = trim((string) ($r['course_department'] ?? ''));
    }
    if ($yearShow === '' && $hasCourseYearLevel) {
        $yearShow = trim((string) ($r['course_year_level'] ?? ''));
    }
    if ($sectionShow === '' && $hasCourseSection) {
        $sectionShow = trim((string) ($r['course_section'] ?? ''));
    }

    return [$programShow, $yearShow, $sectionShow];
};

$mergeScheduleGroupForDetail = static function (array $group) use (
    $formatTime12h,
    $sessionHoursBetween,
    $formatLoadUnits,
    $resolveProgramYearSection,
    $hasLectureUnits,
    $hasLaboratoryUnits,
    $labSessionMinHours
): array {
    $timeParts = [];
    $dayParts = [];
    $lecHours = 0.0;
    $labHours = 0.0;
    $roomOrder = [];
    $roomSeen = [];
    foreach ($group as $r) {
        $h = $sessionHoursBetween((string) ($r['start_time'] ?? ''), (string) ($r['end_time'] ?? ''));
        $isLabSlot = $h >= $labSessionMinHours;
        $slotTag = $isLabSlot ? 'LAB' : 'LEC';
        $timeParts[] = $formatTime12h((string) ($r['start_time'] ?? '')) . ' – ' . $formatTime12h((string) ($r['end_time'] ?? '')) . ' (' . $slotTag . ')';
        $dayParts[] = str_replace(',', ', ', (string) ($r['day_of_week'] ?? ''));
        $dayCount = count(parse_day_set((string) ($r['day_of_week'] ?? '')));
        if ($dayCount < 1) {
            $dayCount = 1;
        }
        $weeklyH = $h * $dayCount;
        if ($isLabSlot) {
            $labHours += $weeklyH;
        } else {
            $lecHours += $weeklyH;
        }
        $rc = trim((string) ($r['room_code'] ?? ''));
        $rn = trim((string) ($r['room_name'] ?? ''));
        $roomLabel = $rc === '' && $rn === ''
            ? ''
            : ($rn !== '' && strcasecmp($rn, $rc) !== 0 ? $rc . ' — ' . $rn : ($rc !== '' ? $rc : $rn));
        if ($roomLabel !== '' && !isset($roomSeen[$roomLabel])) {
            $roomSeen[$roomLabel] = true;
            $roomOrder[] = $roomLabel;
        }
    }
    $timeDisplay = implode('; ', array_values(array_unique($timeParts)));
    $dayDisplay = implode('; ', array_values(array_unique($dayParts)));
    $roomDisplay = $roomOrder ? implode('; ', $roomOrder) : '—';

    $r0 = $group[0];
    if ($hasLectureUnits && $hasLaboratoryUnits) {
        $lecU = (float) ($r0['lecture_units'] ?? 0);
        $lecCell = $lecU > 0 ? $formatLoadUnits($lecU) : '—';
    } else {
        $lecCell = $lecHours > 0 ? $formatLoadUnits($lecHours) : '—';
    }
    $labCell = $labHours > 0 ? $formatLoadUnits($labHours) : '—';

    $programShow = '';
    $yearShow = '';
    $sectionShow = '';
    foreach ($group as $gr) {
        [$p, $y, $s] = $resolveProgramYearSection($gr);
        if ($programShow === '' && $p !== '') {
            $programShow = $p;
        }
        if ($yearShow === '' && $y !== '') {
            $yearShow = $y;
        }
        if ($sectionShow === '' && $s !== '') {
            $sectionShow = $s;
        }
        if ($programShow !== '' && $yearShow !== '' && $sectionShow !== '') {
            break;
        }
    }
    $cyParts = [];
    if ($programShow !== '') {
        $cyParts[] = $programShow;
    }
    if ($yearShow !== '') {
        $cyParts[] = $yearShow;
    }
    $courseYearLabel = $cyParts ? implode(' / ', $cyParts) : '—';

    $lecHoursN = ($hasLectureUnits && $hasLaboratoryUnits)
        ? (float) ($r0['lecture_units'] ?? 0)
        : $lecHours;
    $labHoursN = $labHours;

    return [
        'time_display' => $timeDisplay !== '' ? $timeDisplay : '—',
        'day_display' => $dayDisplay !== '' ? $dayDisplay : '—',
        'lec_hours_display' => $lecCell,
        'lab_hours_display' => $labCell,
        'lec_hours_n' => $lecHoursN,
        'lab_hours_n' => $labHoursN,
        'room_display' => $roomDisplay,
        'course_year_display' => $courseYearLabel,
        'section_display' => $sectionShow !== '' ? $sectionShow : '—',
    ];
};

$facultyLoadSummary = [];
foreach ($scheduleListGroups as $group) {
    $r0 = $group[0];
    $fid = (int) $r0['faculty_id'];
    $units = $courseOfferingUnits($r0);
    if (!isset($facultyLoadSummary[$fid])) {
        $facultyLoadSummary[$fid] = [
            'faculty_name' => (string) $r0['faculty_name'],
            'college_id' => (int) ($r0['sched_college_id'] ?? 0),
            'total_units' => 0.0,
            'offerings' => [],
        ];
    }
    $facultyLoadSummary[$fid]['total_units'] += $units;
    $merged = $mergeScheduleGroupForDetail($group);
    $facultyLoadSummary[$fid]['offerings'][] = [
        'course_code' => (string) $r0['course_code'],
        'course_name' => (string) $r0['course_name'],
        'units' => $units,
        'semester' => (string) $r0['semester'],
        'school_year' => (string) $r0['school_year'],
        'college_id' => (int) ($r0['sched_college_id'] ?? 0),
        'college_label' => trim((string) ($r0['college_code'] ?? '') . ' — ' . (string) ($r0['college_name'] ?? ''), " —\t "),
        'time_display' => $merged['time_display'],
        'day_display' => $merged['day_display'],
        'lec_hours_display' => $merged['lec_hours_display'],
        'lab_hours_display' => $merged['lab_hours_display'],
        'lec_hours_n' => $merged['lec_hours_n'],
        'lab_hours_n' => $merged['lab_hours_n'],
        'room_display' => $merged['room_display'],
        'course_year_display' => $merged['course_year_display'],
        'section_display' => $merged['section_display'],
    ];
}
uasort(
    $facultyLoadSummary,
    static function (array $a, array $b): int {
        return strcasecmp($a['faculty_name'], $b['faculty_name']);
    }
);

$grandTotalUnits = 0.0;
foreach ($facultyLoadSummary as $fl) {
    $grandTotalUnits += $fl['total_units'];
}

/** Detail table footer: totals for units + lec/lab hours when exactly one faculty is in view. */
$detailTableTotals = null;
if (count($facultyLoadSummary) === 1) {
    $onlyFl = reset($facultyLoadSummary);
    $sumUnits = 0.0;
    $sumLecN = 0.0;
    $sumLabN = 0.0;
    foreach ($onlyFl['offerings'] as $do) {
        $sumUnits += (float) ($do['units'] ?? 0);
        $sumLecN += (float) ($do['lec_hours_n'] ?? 0);
        $sumLabN += (float) ($do['lab_hours_n'] ?? 0);
    }
    $detailTableTotals = [
        'faculty_name' => (string) ($onlyFl['faculty_name'] ?? ''),
        'sum_units' => $sumUnits,
        'sum_lec_n' => $sumLecN,
        'sum_lab_n' => $sumLabN,
        'sum_total_n' => $sumLecN + $sumLabN,
    ];
}

/** Single-faculty snapshot for Teaching Load Memorandum print (same condition as detail totals row). */
$tlmMemoFaculty = null;
if ($detailTableTotals !== null) {
    $tlmMemoFaculty = reset($facultyLoadSummary);
}
$tlmMemoSchoolYear = $sy !== '' ? $sy : '__________';
$tlmMemoSemester = $sem !== '' ? $sem : '__________';
$tlmMemoDeanName = '________________________';
if ($tlmMemoFaculty !== null && ($tlmMemoFaculty['offerings'] ?? []) !== []) {
    $tlmFirst = $tlmMemoFaculty['offerings'][0];
    if ($sy === '' && trim((string) ($tlmFirst['school_year'] ?? '')) !== '') {
        $tlmMemoSchoolYear = (string) $tlmFirst['school_year'];
    }
    if ($sem === '' && trim((string) ($tlmFirst['semester'] ?? '')) !== '') {
        $tlmMemoSemester = (string) $tlmFirst['semester'];
    }
}
if ($tlmMemoFaculty !== null) {
    $tlmMemoCollegeId = (int) ($tlmMemoFaculty['college_id'] ?? 0);
    if ($tlmMemoCollegeId <= 0 && ($tlmMemoFaculty['offerings'] ?? []) !== []) {
        $tlmMemoCollegeId = (int) ($tlmMemoFaculty['offerings'][0]['college_id'] ?? 0);
    }
    if ($tlmMemoCollegeId > 0) {
        $deanStmt = db()->prepare(
            'SELECT u.full_name
             FROM colleges c
             LEFT JOIN users u ON u.id = c.dean_user_id
             WHERE c.id = ?
             LIMIT 1'
        );
        $deanStmt->execute([$tlmMemoCollegeId]);
        $tlmMemoDeanNameDb = trim((string) ($deanStmt->fetchColumn() ?: ''));
        if ($tlmMemoDeanNameDb === '') {
            $fallbackDeanStmt = db()->prepare(
                'SELECT full_name
                 FROM users
                 WHERE role = "dean" AND college_id = ?
                 ORDER BY is_active DESC, id ASC
                 LIMIT 1'
            );
            $fallbackDeanStmt->execute([$tlmMemoCollegeId]);
            $tlmMemoDeanNameDb = trim((string) ($fallbackDeanStmt->fetchColumn() ?: ''));
        }
        if ($tlmMemoDeanNameDb !== '') {
            $tlmMemoDeanName = $tlmMemoDeanNameDb;
        }
    }
}

$collegeFilterOptions = [];
if ($role === 'admin') {
    $collegeFilterOptions = db()->query(
        'SELECT id, college_code, college_name FROM colleges ORDER BY college_code'
    )->fetchAll();
}

if (is_program_chair() && $programScope !== null) {
    $depts = [$programScope];
    if ($dept === '' || $dept !== $programScope) {
        $dept = $programScope;
    }
} elseif (is_dean() && $collegeId) {
    $d = db()->prepare('SELECT DISTINCT department FROM faculty WHERE college_id=? AND department != "" ORDER BY department');
    $d->execute([$collegeId]);
    $depts = $d->fetchAll(PDO::FETCH_COLUMN);
} else {
    $depts = db()->query('SELECT DISTINCT department FROM faculty WHERE department != "" ORDER BY department')->fetchAll(PDO::FETCH_COLUMN);
}
$sems = db()->query('SELECT DISTINCT semester FROM schedules ORDER BY semester')->fetchAll(PDO::FETCH_COLUMN);
$years = db()->query('SELECT DISTINCT school_year FROM schedules ORDER BY school_year DESC')->fetchAll(PDO::FETCH_COLUMN);

$filterColClass = is_program_chair() ? 'col-md-2' : 'col-md-3';
$showCollegeColumn = $role === 'admin' && $adminCollegeId === 0;

$ftlScriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$ftlScriptDir = rtrim(dirname($ftlScriptName), '/');
$ftlMemoLogoWebPath = ($ftlScriptDir === '' || $ftlScriptDir === '.' || $ftlScriptDir === '/')
    ? '/assets/wpu-logo.png'
    : '/' . ltrim(preg_replace('#/+#', '/', $ftlScriptDir . '/assets/wpu-logo.png'), '/');
$ftlWpuLogoAbsUrl = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . $ftlMemoLogoWebPath;

$pageTitle = 'Faculty teaching load (units)';
$mainContainerClass = 'container py-4 py-md-5 app-main';
require_once __DIR__ . '/includes/header.php';
?>
<style>
    .ftl-page .ftl-scope-note { font-size: 0.9rem; }
    .ftl-page .ftl-filters {
        background: var(--bs-body-bg);
        border: 1px solid var(--bs-border-color);
        border-radius: 1rem;
        padding: 1rem;
    }
    .ftl-page .schedule-faculty-load {
        background: var(--bs-body-bg);
        border: 1px solid var(--bs-border-color);
        border-radius: 1rem;
        padding: 1rem 1.1rem;
    }
    .ftl-page .schedule-faculty-load h2 {
        font-size: 1rem;
        font-weight: 600;
        margin-bottom: 0.35rem;
    }
    .ftl-page .schedule-faculty-load-card {
        background: var(--bs-secondary-bg);
        border: 1px solid var(--bs-border-color);
        border-radius: 0.85rem;
        padding: 0.85rem 1rem;
        height: 100%;
    }
    .ftl-page .schedule-faculty-load-card .faculty-load-course-list {
        max-height: 280px;
        overflow-y: auto;
        margin-top: 0.5rem;
        padding-right: 4px;
    }
    .ftl-page .schedule-faculty-load-card .faculty-load-course-list li {
        border-color: var(--bs-border-color) !important;
    }

    /* Detail table: compact type, wrap long text, avoid cramped overlap */
    .ftl-detail-wrap {
        font-size: 0.6875rem;
        line-height: 1.35;
        -webkit-font-smoothing: antialiased;
    }
    .ftl-detail-wrap .ftl-detail-table {
        font-family: system-ui, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", "Liberation Sans", sans-serif;
        margin-bottom: 0;
        table-layout: fixed;
        width: 100%;
    }
    .ftl-detail-wrap .ftl-detail-table > :is(thead, tbody) > tr > :is(th, td) {
        padding: 0.3rem 0.35rem;
        vertical-align: top;
        white-space: normal;
        word-break: break-word;
        overflow-wrap: anywhere;
        hyphens: auto;
    }
    .ftl-detail-wrap .ftl-detail-table thead th {
        font-weight: 600;
        font-size: 0.625rem;
        letter-spacing: 0.02em;
        text-transform: none;
        line-height: 1.25;
    }
    .ftl-detail-wrap .ftl-dt-faculty { width: 8%; }
    .ftl-detail-wrap .ftl-dt-code { width: 5%; }
    .ftl-detail-wrap .ftl-dt-title { width: 13%; }
    .ftl-detail-wrap .ftl-dt-units { width: 5%; white-space: nowrap; }
    .ftl-detail-wrap .ftl-dt-time { width: 10%; }
    .ftl-detail-wrap .ftl-dt-day { width: 8%; }
    .ftl-detail-wrap .ftl-dt-lec { width: 4%; white-space: nowrap; }
    .ftl-detail-wrap .ftl-dt-lab { width: 4%; white-space: nowrap; }
    .ftl-detail-wrap .ftl-dt-total { width: 4%; white-space: nowrap; }
    .ftl-detail-wrap .ftl-dt-room { width: 8%; }
    .ftl-detail-wrap .ftl-dt-cyear { width: 7%; }
    .ftl-detail-wrap .ftl-dt-section { width: 4%; white-space: nowrap; }
    .ftl-detail-wrap .ftl-dt-sem { width: 6%; }
    .ftl-detail-wrap .ftl-dt-sy { width: 5%; white-space: nowrap; }
    .ftl-detail-wrap .ftl-dt-college { width: 14%; }

    @media print {
        .ftl-detail-wrap { font-size: 7.5pt; line-height: 1.3; }
        .ftl-detail-wrap .ftl-detail-table thead th { font-size: 7pt; }
    }
    .ftl-detail-wrap .ftl-detail-table tfoot td {
        border-top: 2px solid var(--bs-border-color);
        vertical-align: middle;
        font-weight: 600;
        background: var(--bs-secondary-bg);
    }
    /* Memorandum source: off-screen (used by print popup only) */
    .ftl-page .ftl-tlm-print-source {
        position: absolute;
        width: 210mm;
        left: -9999px;
        top: 0;
        visibility: hidden;
        pointer-events: none;
    }
</style>
<div class="ftl-page">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h3 mb-0"><i class="fa-solid fa-scale-balanced me-2 text-primary"></i>Faculty teaching load (units)</h1>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <span class="badge text-bg-primary rounded-pill">Total Units in view: <?= htmlspecialchars($formatLoadUnits($grandTotalUnits)) ?> Units</span>
        </div>
    </div>

    <?php if ($programScope !== null): ?>
        <p class="text-muted ftl-scope-note mb-3">
            Program scope: <strong><?= htmlspecialchars($programScope) ?></strong> (<?= htmlspecialchars(college_name_by_id($collegeId)) ?>).
            <?php if (is_program_chair()): ?>
                <?php if ($hasIsGenedCourse): ?>
                    <span class="d-block mt-1">General Education (GE) catalog courses are excluded; only <strong>major</strong> courses in this program are included.</span>
                <?php else: ?>
                    <span class="d-block mt-1">Schedules created by GEN ED accounts are excluded. Run <a href="upgrade_roles.php">upgrade_roles.php</a> so GE can be flagged in the catalog for clearer filtering.</span>
                <?php endif; ?>
            <?php endif; ?>
        </p>
    <?php elseif (is_dean()): ?>
        <p class="text-muted ftl-scope-note mb-3">College: <strong><?= htmlspecialchars(college_name_by_id($collegeId)) ?></strong></p>
    <?php elseif (is_gened()): ?>
        <p class="text-muted ftl-scope-note mb-3">
            <?php if ($hasIsGenedCourse): ?>
                Showing schedules for courses flagged as <strong>General Education</strong> in the catalog.
            <?php else: ?>
                Showing schedules created by <strong>GEN ED</strong> coordinator accounts. Run upgrade_roles.php so courses can be flagged as GE in the catalog for this report to follow those offerings instead.
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <form class="row g-2 mb-4 no-print align-items-end ftl-filters" method="get">
        <?php if ($role === 'admin'): ?>
            <div class="<?= htmlspecialchars($filterColClass) ?>">
                <label class="form-label small mb-0">College</label>
                <select name="college_id" class="form-select form-select-sm">
                    <option value="0">All colleges</option>
                    <?php foreach ($collegeFilterOptions as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= $adminCollegeId === (int) $c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['college_code'] . ' — ' . $c['college_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <?php if (is_program_chair() && $programScope !== null): ?>
            <div class="<?= htmlspecialchars($filterColClass) ?>">
                <label class="form-label small mb-0">Program</label>
                <input type="text" class="form-control form-control-sm bg-body-secondary" readonly value="<?= htmlspecialchars($programScope) ?>" aria-readonly="true"<?= app_tooltip_attr('Your account is limited to this program. Teaching load lists only offerings for this program.') ?>>
                <input type="hidden" name="dept" value="<?= htmlspecialchars($programScope) ?>">
            </div>
        <?php else: ?>
            <div class="<?= htmlspecialchars($filterColClass) ?>">
                <label class="form-label small mb-0">Program</label>
                <select name="dept" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($depts as $d): ?>
                        <option value="<?= htmlspecialchars((string) $d) ?>" <?= $dept === (string) $d ? 'selected' : '' ?>><?= htmlspecialchars((string) $d) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <div class="<?= htmlspecialchars($filterColClass) ?>">
            <label class="form-label small mb-0">Semester</label>
            <select name="semester" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach ($sems as $s): ?>
                    <option value="<?= htmlspecialchars((string) $s) ?>" <?= $sem === (string) $s ? 'selected' : '' ?>><?= htmlspecialchars((string) $s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="<?= htmlspecialchars($filterColClass) ?>">
            <label class="form-label small mb-0">School year</label>
            <select name="school_year" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach ($years as $y): ?>
                    <option value="<?= htmlspecialchars((string) $y) ?>" <?= $sy === (string) $y ? 'selected' : '' ?>><?= htmlspecialchars((string) $y) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if (is_gened() && $hasIsGenedFaculty): ?>
            <div class="<?= htmlspecialchars($filterColClass) ?>">
                <label class="form-label small mb-0">Faculty GE restriction</label>
                <select name="ge_faculty" class="form-select form-select-sm"<?= app_tooltip_attr('GE-designated faculty are the pool allowed on GEN ED schedules. Other instructors may still appear if they teach a flagged GE section.') ?>>
                    <option value="">All instructors</option>
                    <option value="1" <?= $geFacultyFilter === '1' ? 'selected' : '' ?>>GE-designated only</option>
                    <option value="0" <?= $geFacultyFilter === '0' ? 'selected' : '' ?>>Not GE-designated</option>
                </select>
            </div>
        <?php endif; ?>
        <?php if (is_gened()): ?>
            <div class="<?= htmlspecialchars($filterColClass) ?>">
                <label class="form-label small mb-0">Faculty search</label>
                <input type="search" name="faculty_search" class="form-control form-control-sm" placeholder="Name or faculty ID" value="<?= htmlspecialchars($facultySearch) ?>" maxlength="120" autocomplete="off"<?= app_tooltip_attr('Narrows rows to instructors whose name or ID contains this text.') ?>>
            </div>
        <?php endif; ?>
        <?php if ($role === 'admin'): ?>
            <div class="col-md-3">
                <label class="form-label small mb-0">Faculty search</label>
                <input type="search" name="faculty_search" class="form-control form-control-sm" placeholder="Name or faculty ID" value="<?= htmlspecialchars($facultySearch) ?>" maxlength="120" autocomplete="off">
            </div>
        <?php endif; ?>
        <?php if (is_program_chair()): ?>
            <div class="col-md-3">
                <label class="form-label small mb-0">Faculty name / ID</label>
                <input type="search" name="faculty_search" class="form-control form-control-sm" value="<?= htmlspecialchars($facultySearch) ?>" placeholder="Search faculty…" maxlength="120" autocomplete="off"<?= app_tooltip_attr('Narrows rows to instructors whose name or ID contains this text.') ?>>
            </div>
        <?php endif; ?>
        <?php if (is_dean()): ?>
            <div class="<?= htmlspecialchars($filterColClass) ?>">
                <label class="form-label small mb-0">Faculty search</label>
                <input type="search" name="faculty_search" class="form-control form-control-sm" value="<?= htmlspecialchars($facultySearch) ?>" placeholder="Faculty name or ID" maxlength="120" autocomplete="off"<?= app_tooltip_attr('Narrows results to instructors whose name or faculty ID contains this text.') ?>>
            </div>
        <?php endif; ?>
        <div class="col-md-3">
            <button type="submit" class="btn btn-outline-primary btn-sm"<?= app_tooltip_attr('Apply filters to the teaching load totals.') ?>>Apply</button>
            <a href="faculty_teaching_load.php" class="btn btn-outline-secondary btn-sm"<?= app_tooltip_attr('Clear filters.') ?>>Reset</a>
        </div>
    </form>

    <section class="schedule-faculty-load no-print mb-4" aria-label="Faculty teaching load summary">
        <h2><i class="fa-solid fa-chalkboard-user me-2 text-primary"></i>By faculty member</h2>
        <p class="small text-muted mb-2">
            Each course section counts once per semester and school year. Lecture and laboratory timetable rows for the same section are merged.
            <?php if ($hasLectureUnits && $hasLaboratoryUnits): ?>
                Units are <strong>lecture + laboratory</strong> from the course catalog.
            <?php else: ?>
                Units use the course catalog <strong>units</strong> field.
            <?php endif; ?>
        </p>
        <?php if (!$facultyLoadSummary): ?>
            <p class="text-muted small mb-0">No scheduled offerings match your filters.</p>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($facultyLoadSummary as $fl): ?>
                    <div class="col-md-6 col-xl-4">
                        <div class="schedule-faculty-load-card">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <span class="fw-semibold"><?= htmlspecialchars($fl['faculty_name']) ?></span>
                                <span class="badge bg-primary rounded-pill">Total: <?= htmlspecialchars($formatLoadUnits($fl['total_units'])) ?> Units</span>
                            </div>
                            <ul class="list-unstyled small faculty-load-course-list mb-0 text-muted">
                                <?php foreach ($fl['offerings'] as $o): ?>
                                    <li class="d-flex justify-content-between gap-2 py-1 border-top">
                                        <span>
                                            <strong class="text-body"><?= htmlspecialchars($o['course_code']) ?></strong>
                                            <span class="d-block small"><?= htmlspecialchars($o['course_name']) ?></span>
                                            <span class="small"><?= htmlspecialchars($o['semester']) ?> · <?= htmlspecialchars($o['school_year']) ?></span>
                                            <?php if ($showCollegeColumn && ($o['college_label'] ?? '') !== ''): ?>
                                                <span class="d-block small text-secondary"><?= htmlspecialchars($o['college_label']) ?></span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="text-nowrap fw-semibold text-body"><?= htmlspecialchars($formatLoadUnits($o['units'])) ?> Units</span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($facultyLoadSummary): ?>
        <div class="card border shadow-sm no-print">
            <div class="card-header py-2">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <span class="fw-semibold small text-uppercase text-muted">Detail table</span>
                        <?php if ($tlmMemoFaculty !== null): ?>
                            <button type="button" class="btn btn-primary btn-sm" id="ftlTlmPrintBtn" onclick="ftlPrintTeachingLoadMemo()"<?= app_tooltip_attr('Opens a printable Teaching Load Memorandum for the one faculty in this view (WPU-style layout).') ?>>
                                <i class="fa-solid fa-print me-1" aria-hidden="true"></i>Print memorandum
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="table-responsive ftl-detail-wrap">
                <table class="table table-sm table-hover mb-0 align-middle ftl-detail-table">
                    <thead class="table-light">
                        <tr>
                            <th scope="col" class="ftl-dt-faculty">Faculty</th>
                            <th scope="col" class="ftl-dt-code">Code</th>
                            <th scope="col" class="ftl-dt-title">Title</th>
                            <th scope="col" class="text-end ftl-dt-units">Units</th>
                            <th scope="col" class="ftl-dt-time">Time</th>
                            <th scope="col" class="ftl-dt-day">Day</th>
                            <th scope="col" class="ftl-dt-lec">Lec h</th>
                            <th scope="col" class="ftl-dt-lab">Lab h</th>
                            <th scope="col" class="ftl-dt-total">Total h</th>
                            <th scope="col" class="ftl-dt-room">Room</th>
                            <th scope="col" class="ftl-dt-cyear">Course / Yr</th>
                            <th scope="col" class="ftl-dt-section">Sec.</th>
                            <th scope="col" class="ftl-dt-sem">Sem.</th>
                            <th scope="col" class="ftl-dt-sy">SY</th>
                            <?php if ($showCollegeColumn): ?>
                                <th scope="col" class="ftl-dt-college">College</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($facultyLoadSummary as $fl): ?>
                            <?php foreach ($fl['offerings'] as $o): ?>
                                <tr>
                                    <td class="ftl-dt-faculty"><?= htmlspecialchars($fl['faculty_name']) ?></td>
                                    <td class="ftl-dt-code"><?= htmlspecialchars($o['course_code']) ?></td>
                                    <td class="ftl-dt-title"><?= htmlspecialchars($o['course_name']) ?></td>
                                    <td class="text-end fw-semibold ftl-dt-units"><?= htmlspecialchars($formatLoadUnits($o['units'])) ?></td>
                                    <td class="ftl-dt-time"><?= htmlspecialchars($o['time_display']) ?></td>
                                    <td class="ftl-dt-day"><?= htmlspecialchars($o['day_display']) ?></td>
                                    <td class="ftl-dt-lec"><?= htmlspecialchars($o['lec_hours_display']) ?></td>
                                    <td class="ftl-dt-lab"><?= htmlspecialchars($o['lab_hours_display']) ?></td>
                                    <td class="ftl-dt-total"><?= htmlspecialchars($formatLoadUnits((float) ($o['lec_hours_n'] ?? 0) + (float) ($o['lab_hours_n'] ?? 0))) ?></td>
                                    <td class="ftl-dt-room"><?= htmlspecialchars($o['room_display']) ?></td>
                                    <td class="ftl-dt-cyear"><?= htmlspecialchars($o['course_year_display']) ?></td>
                                    <td class="ftl-dt-section"><?= htmlspecialchars($o['section_display']) ?></td>
                                    <td class="ftl-dt-sem"><?= htmlspecialchars($o['semester']) ?></td>
                                    <td class="ftl-dt-sy"><?= htmlspecialchars($o['school_year']) ?></td>
                                    <?php if ($showCollegeColumn): ?>
                                        <td class="ftl-dt-college"><?= htmlspecialchars($o['college_label'] !== '' ? $o['college_label'] : '—') ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                    <?php if ($detailTableTotals !== null): ?>
                        <tfoot>
                            <tr class="ftl-dt-total-row">
                                <td class="ftl-dt-faculty"><?= htmlspecialchars($detailTableTotals['faculty_name']) ?> — total</td>
                                <td class="ftl-dt-code">—</td>
                                <td class="ftl-dt-title">—</td>
                                <td class="text-end ftl-dt-units"><?= htmlspecialchars($formatLoadUnits($detailTableTotals['sum_units'])) ?></td>
                                <td class="ftl-dt-time">—</td>
                                <td class="ftl-dt-day">—</td>
                                <td class="ftl-dt-lec"><?= (float) $detailTableTotals['sum_lec_n'] > 0 ? htmlspecialchars($formatLoadUnits((float) $detailTableTotals['sum_lec_n'])) : '—' ?></td>
                                <td class="ftl-dt-lab"><?= (float) $detailTableTotals['sum_lab_n'] > 0 ? htmlspecialchars($formatLoadUnits((float) $detailTableTotals['sum_lab_n'])) : '—' ?></td>
                                <td class="ftl-dt-total"><?= (float) $detailTableTotals['sum_total_n'] > 0 ? htmlspecialchars($formatLoadUnits((float) $detailTableTotals['sum_total_n'])) : '—' ?></td>
                                <td class="ftl-dt-room">—</td>
                                <td class="ftl-dt-cyear">—</td>
                                <td class="ftl-dt-section">—</td>
                                <td class="ftl-dt-sem">—</td>
                                <td class="ftl-dt-sy">—</td>
                                <?php if ($showCollegeColumn): ?>
                                    <td class="ftl-dt-college">—</td>
                                <?php endif; ?>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($tlmMemoFaculty !== null && $detailTableTotals !== null): ?>
        <div id="ftl-tlm-print" class="ftl-tlm-print-source" aria-hidden="true">
            <div class="ftl-tlm-doc">
                <header class="ftl-tlm-head">
                    <div class="ftl-tlm-head-left d-flex gap-3 align-items-start">
                        <img src="<?= htmlspecialchars($ftlWpuLogoAbsUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Western Philippines University" class="ftl-tlm-logo-img flex-shrink-0" width="44" height="44">
                        <div>
                            <div class="ftl-tlm-phil small text-uppercase">Republic of the Philippines</div>
                            <div class="ftl-tlm-uni-name fw-bold">Western Philippines University</div>
                        </div>
                    </div>
                    <div class="ftl-tlm-head-right text-end">
                        <div class="ftl-tlm-tagline text-primary small fw-semibold text-uppercase" style="font-family: Arial, Helvetica, sans-serif;">A strong partner for sustainable development</div>
                        <div class="ftl-tlm-aa text-primary fw-bold text-uppercase mt-1" style="font-family: Arial, Helvetica, sans-serif; letter-spacing: 0.04em;">Academic Affairs</div>
                    </div>
                </header>

                <h1 class="ftl-tlm-title text-center text-uppercase fw-bold my-3">Teaching Load Memorandum</h1>

                <p class="mb-2"><strong>TO:</strong> <?= htmlspecialchars($tlmMemoFaculty['faculty_name']) ?></p>
                <p class="mb-4">
                    The following subjects have been scheduled for you to handle this
                    <strong><?= htmlspecialchars($tlmMemoSemester) ?></strong> semester, school year
                    <strong><?= htmlspecialchars($tlmMemoSchoolYear) ?></strong>.
                </p>

                <table class="ftl-tlm-table w-100">
                    <thead>
                        <tr>
                            <th scope="col">Course Code</th>
                            <th scope="col">Course Title</th>
                            <th scope="col" class="text-center">Units</th>
                            <th scope="col">Time</th>
                            <th scope="col">Day</th>
                            <th scope="col" class="text-center">Lec h</th>
                            <th scope="col" class="text-center">Lab h</th>
                            <th scope="col">Room</th>
                            <th scope="col">Course/Year</th>
                            <th scope="col" class="text-center">Section</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tlmMemoFaculty['offerings'] as $mo): ?>
                            <tr>
                                <td><?= htmlspecialchars($mo['course_code']) ?></td>
                                <td><?= htmlspecialchars($mo['course_name']) ?></td>
                                <td class="text-center"><?= htmlspecialchars($formatLoadUnits($mo['units'])) ?></td>
                                <td><?= htmlspecialchars($mo['time_display']) ?></td>
                                <td><?= htmlspecialchars($mo['day_display']) ?></td>
                                <td class="text-center"><?= htmlspecialchars($mo['lec_hours_display']) ?></td>
                                <td class="text-center"><?= htmlspecialchars($mo['lab_hours_display']) ?></td>
                                <td><?= htmlspecialchars($mo['room_display']) ?></td>
                                <td><?= htmlspecialchars($mo['course_year_display']) ?></td>
                                <td class="text-center"><?= htmlspecialchars($mo['section_display']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <?php
                        $tlmMemoTotalLecShow = (float) $detailTableTotals['sum_lec_n'] > 0
                            ? $formatLoadUnits((float) $detailTableTotals['sum_lec_n'])
                            : '—';
                        $tlmMemoTotalLabShow = (float) $detailTableTotals['sum_lab_n'] > 0
                            ? $formatLoadUnits((float) $detailTableTotals['sum_lab_n'])
                            : '—';
                        $tlmMemoTotalHoursShow = (float) ($detailTableTotals['sum_total_n'] ?? ((float) $detailTableTotals['sum_lec_n'] + (float) $detailTableTotals['sum_lab_n'])) > 0
                            ? $formatLoadUnits((float) ($detailTableTotals['sum_total_n'] ?? ((float) $detailTableTotals['sum_lec_n'] + (float) $detailTableTotals['sum_lab_n'])))
                            : '—';
                        ?>
                        <tr>
                            <td colspan="2"><strong>Total Units</strong></td>
                            <td class="text-center fw-bold"><?= htmlspecialchars($formatLoadUnits($detailTableTotals['sum_units'])) ?></td>
                            <td colspan="2"></td>
                            <td class="text-center fw-bold"><?= htmlspecialchars($tlmMemoTotalLecShow) ?></td>
                            <td class="text-center fw-bold"><?= htmlspecialchars($tlmMemoTotalLabShow) ?></td>
                            <td class="text-center fw-bold"><?= htmlspecialchars($tlmMemoTotalHoursShow) ?></td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                </table>

                <p class="mt-4 mb-5">
                    You are advised to meet these classes beginning <span class="ftl-tlm-blank">________________________</span> until the end of the semester.
                </p>

                <div class="ftl-tlm-sigs text-center">
                    <div class="ftl-tlm-sig mb-5">
                        <div class="ftl-tlm-sig-line mx-auto"></div>
                        <div class="small mt-1 fw-bold text-uppercase"><?= htmlspecialchars($tlmMemoDeanName) ?></div>
                        <div class="small mt-1">Dean</div>
                    </div>
                    <div class="ftl-tlm-sig">
                        <div class="ftl-tlm-sig-line mx-auto"></div>
                        <div class="small mt-1">Vice President for Academic Affairs</div>
                    </div>
                </div>
            </div>
        </div>
        <script>
        function ftlPrintTeachingLoadMemo() {
            var inner = document.querySelector('#ftl-tlm-print .ftl-tlm-doc');
            if (!inner) {
                return;
            }
            var css = [
                '@page { margin: 8mm; size: A4 portrait; }',
                '*,*::before,*::after{box-sizing:border-box;}',
                'html,body{margin:0;padding:0;font-family:Times New Roman,Times,serif;font-size:8.5pt;line-height:1.35;color:#000;background:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact;}',
                '.ftl-tlm-doc{max-width:100%;}',
                '.ftl-tlm-doc p{margin:0 0 0.35rem;line-height:1.35;font-size:8.5pt;}',
                '.ftl-tlm-doc .mb-4{margin-bottom:0.5rem !important;}',
                '.ftl-tlm-doc .my-3{margin-top:0.4rem !important;margin-bottom:0.4rem !important;}',
                '.ftl-tlm-doc .mt-4{margin-top:0.5rem !important;}',
                '.ftl-tlm-doc .mb-5{margin-bottom:0.65rem !important;}',
                '.ftl-tlm-head{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:1.5px solid #000;padding-bottom:6px;margin-bottom:8px;gap:6px;}',
                '.ftl-tlm-head .gap-3{gap:6px !important;}',
                '.ftl-tlm-logo-img{width:44px;height:44px;min-width:44px;object-fit:contain;display:block;}',
                '.ftl-tlm-phil{font-size:7pt;letter-spacing:0.02em;line-height:1.25;}',
                '.ftl-tlm-uni-name{font-size:11pt;line-height:1.2;font-family:Times New Roman,Times,serif;}',
                '.ftl-tlm-tagline{font-size:6.5pt;max-width:11rem;margin-left:auto;line-height:1.2;}',
                '.ftl-tlm-aa{font-size:10pt;line-height:1.15;}',
                'h1.ftl-tlm-title{font-size:10pt !important;line-height:1.25 !important;letter-spacing:0.05em;margin:8px 0 !important;font-weight:bold !important;}',
                '.ftl-tlm-table{border-collapse:collapse;width:100%;margin-top:4px;table-layout:fixed;}',
                '.ftl-tlm-table th,.ftl-tlm-table td{border:1px solid #000;padding:2px 3px;vertical-align:top;word-break:break-word;overflow-wrap:anywhere;hyphens:auto;white-space:normal;}',
                '.ftl-tlm-table thead th{font-weight:bold;text-align:center;font-size:7.5pt;line-height:1.25;}',
                '.ftl-tlm-table tbody td{font-size:7.5pt;line-height:1.3;}',
                '.ftl-tlm-table tfoot td{font-size:7.5pt;border:1px solid #000;line-height:1.25;}',
                '.ftl-tlm-table th:nth-child(1),.ftl-tlm-table td:nth-child(1){width:8%;}',
                '.ftl-tlm-table th:nth-child(2),.ftl-tlm-table td:nth-child(2){width:20%;}',
                '.ftl-tlm-table th:nth-child(3),.ftl-tlm-table td:nth-child(3){width:5%;}',
                '.ftl-tlm-table th:nth-child(4),.ftl-tlm-table td:nth-child(4){width:13%;}',
                '.ftl-tlm-table th:nth-child(5),.ftl-tlm-table td:nth-child(5){width:10%;}',
                '.ftl-tlm-table th:nth-child(6),.ftl-tlm-table td:nth-child(6){width:8%;}',
                '.ftl-tlm-table th:nth-child(7),.ftl-tlm-table td:nth-child(7){width:8%;}',
                '.ftl-tlm-table th:nth-child(8),.ftl-tlm-table td:nth-child(8){width:10%;}',
                '.ftl-tlm-table th:nth-child(9),.ftl-tlm-table td:nth-child(9){width:10%;}',
                '.ftl-tlm-table th:nth-child(10),.ftl-tlm-table td:nth-child(10){width:8%;}',
                '.ftl-tlm-table tbody tr{break-inside:avoid;page-break-inside:avoid;}',
                '.ftl-tlm-blank{border-bottom:1px solid #000;padding:0 2px;}',
                '.ftl-tlm-sig-line{width:55%;border-top:1px solid #000;margin-top:28px;}',
                '.ftl-tlm-sig .small{font-size:7.5pt;line-height:1.2;}',
                '.ftl-tlm-sig.mb-5{margin-bottom:1.25rem !important;}'
            ].join('');
            var w = window.open('', '_blank');
            if (!w) {
                alert('Please allow pop-ups to print the memorandum.');
                return;
            }
            w.document.open();
            w.document.write('<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Teaching Load Memorandum</title>');
            w.document.write('<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">');
            w.document.write('<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">');
            w.document.write('<style>' + css + '</style></head><body>');
            w.document.write(inner.innerHTML);
            w.document.write('</body></html>');
            w.document.close();
            w.focus();
            setTimeout(function () {
                w.print();
                w.close();
            }, 350);
        }
        </script>
    <?php endif; ?>
</div>
<?php
require_once __DIR__ . '/includes/footer.php';

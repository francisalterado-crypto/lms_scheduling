<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/admin_activity_log.php';

require_role(['admin', 'dean', 'program_chair', 'gened', 'faculty']);
$role = (string) ($_SESSION['role'] ?? '');
$collegeId = current_college_id();
$programScope = is_program_chair() ? program_scope_or_fail() : null;
$facultySelfId = isset($_SESSION['faculty_id']) ? (int) $_SESSION['faculty_id'] : 0;
$hasGeTargetsTable = db_table_exists('ge_schedule_targets');
$hasCourseLabFlag = db_column_exists('courses', 'is_laboratory');
$hasCourseYearLevel = db_column_exists('courses', 'year_level');
$hasCourseSection = db_column_exists('courses', 'section');
$hasLectureUnits = db_column_exists('courses', 'lecture_units');
$hasLaboratoryUnits = db_column_exists('courses', 'laboratory_units');

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $sid = (int) $_POST['delete_id'];
    if (is_admin()) {
        $snap = db()->prepare(
            'SELECT s.id, s.faculty_id, s.course_id, s.room_id, s.college_id, s.semester, s.school_year,
                    s.day_of_week, s.start_time, s.end_time, s.schedule_type,
                    f.full_name AS faculty_name, c.course_code, c.course_name, r.room_code,
                    COALESCE(col.college_code, "") AS college_code
             FROM schedules s
             INNER JOIN faculty f ON f.id = s.faculty_id
             INNER JOIN courses c ON c.id = s.course_id
             INNER JOIN rooms r ON r.id = s.room_id
             LEFT JOIN colleges col ON col.id = s.college_id
             WHERE s.id = ? LIMIT 1'
        );
        $snap->execute([$sid]);
        $schedBefore = $snap->fetch(PDO::FETCH_ASSOC);
        $stmt = db()->prepare('DELETE FROM schedules WHERE id=?');
        $stmt->execute([$sid]);
        if ($schedBefore) {
            log_admin_activity(
                'delete',
                'Schedules',
                'Schedule #' . $sid,
                (array) $schedBefore,
                null
            );
        }
        $_SESSION['flash'] = 'Schedule deleted by admin emergency override.';
    } elseif ((is_dean() || is_program_chair()) && $collegeId) {
        $check = db()->prepare(
            'SELECT s.id, COALESCE(u.role, "") AS creator_role, c.department
             FROM schedules s
             INNER JOIN courses c ON c.id = s.course_id
             LEFT JOIN users u ON u.id = s.created_by
             WHERE s.id=? AND s.college_id=? LIMIT 1'
        );
        $check->execute([$sid, $collegeId]);
        $row = $check->fetch();
        if (!$row) {
            $_SESSION['flash'] = 'Schedule not found.';
        } elseif ($programScope !== null && trim((string) ($row['department'] ?? '')) !== $programScope) {
            $_SESSION['flash'] = 'This schedule is outside your assigned program.';
        } elseif ((string) $row['creator_role'] === 'gened') {
            $_SESSION['flash'] = 'This GE-created schedule is read-only for Deans.';
        } else {
            $snapPc = db()->prepare(
                'SELECT s.id, s.faculty_id, s.course_id, s.room_id, s.college_id, s.semester, s.school_year,
                        s.day_of_week, s.start_time, s.end_time, s.schedule_type,
                        f.full_name AS faculty_name, c.course_code, c.course_name, r.room_code
                 FROM schedules s
                 INNER JOIN faculty f ON f.id = s.faculty_id
                 INNER JOIN courses c ON c.id = s.course_id
                 INNER JOIN rooms r ON r.id = s.room_id
                 WHERE s.id = ? AND s.college_id = ? LIMIT 1'
            );
            $snapPc->execute([$sid, $collegeId]);
            $schedBeforePc = $snapPc->fetch(PDO::FETCH_ASSOC);
            $stmt = db()->prepare('DELETE FROM schedules WHERE id=? AND college_id=?');
            $stmt->execute([$sid, $collegeId]);
            if ($schedBeforePc) {
                log_user_activity('delete', 'Schedules', 'Schedule #' . $sid, (array) $schedBeforePc, null);
            }
            log_dean_activity('schedule_delete', 'Deleted schedule #' . $sid);
            $_SESSION['flash'] = 'Schedule deleted.';
        }
    } elseif (is_gened()) {
        $snapGe = db()->prepare(
            'SELECT s.id, s.faculty_id, s.course_id, s.room_id, s.college_id, s.semester, s.school_year,
                    s.day_of_week, s.start_time, s.end_time, s.schedule_type,
                    f.full_name AS faculty_name, c.course_code, c.course_name, r.room_code
             FROM schedules s
             INNER JOIN users u ON u.id = s.created_by
             INNER JOIN faculty f ON f.id = s.faculty_id
             INNER JOIN courses c ON c.id = s.course_id
             INNER JOIN rooms r ON r.id = s.room_id
             WHERE s.id = ? AND u.role = "gened" LIMIT 1'
        );
        $snapGe->execute([$sid]);
        $schedBeforeGe = $snapGe->fetch(PDO::FETCH_ASSOC);
        $stmt = db()->prepare(
            'DELETE s FROM schedules s
             INNER JOIN users u ON u.id = s.created_by
             WHERE s.id=? AND u.role="gened"'
        );
        $stmt->execute([$sid]);
        if ($stmt->rowCount() > 0 && $schedBeforeGe) {
            log_user_activity('delete', 'Schedules', 'Schedule #' . $sid, (array) $schedBeforeGe, null);
        }
        $_SESSION['flash'] = $stmt->rowCount() > 0
            ? 'GE schedule deleted.'
            : 'Only GE-created schedules can be deleted by GEN ED account.';
    }
    header('Location: schedule.php');
    exit;
}

$dept = trim((string) ($_GET['dept'] ?? ''));
$sem = trim((string) ($_GET['semester'] ?? ''));
$sy = trim((string) ($_GET['school_year'] ?? ''));
$facultySearch = trim((string) ($_GET['faculty_search'] ?? ''));
$courseSearch = trim((string) ($_GET['course'] ?? ''));

$targetSelect = $hasGeTargetsTable
    ? ", gst.program_name AS target_program, gst.year_level AS target_year_level, gst.section AS target_section"
    : '';
$targetJoin = $hasGeTargetsTable ? ' LEFT JOIN ge_schedule_targets gst ON gst.schedule_id = s.id ' : '';
$courseLabSelect = $hasCourseLabFlag ? ', c.is_laboratory' : '';
$courseBlockSelect = '';
if ($hasCourseYearLevel || $hasCourseSection) {
    $courseBlockSelect = ', c.department AS course_block_department';
    if ($hasCourseYearLevel) {
        $courseBlockSelect .= ', c.year_level AS course_block_year_level';
    }
    if ($hasCourseSection) {
        $courseBlockSelect .= ', c.section AS course_block_section';
    }
}

$courseUnitsSelect = ', c.units AS course_units_total';
if ($hasLectureUnits) {
    $courseUnitsSelect .= ', c.lecture_units';
}
if ($hasLaboratoryUnits) {
    $courseUnitsSelect .= ', c.laboratory_units';
}

$sql = "SELECT s.id, s.faculty_id, s.course_id, s.schedule_type, s.day_of_week, s.start_time, s.end_time, s.semester, s.school_year,
        f.full_name AS faculty_name, f.department AS fac_dept, c.course_code, c.course_name, r.room_code, r.type AS room_type,
        col.college_name, COALESCE(uc.role, '') AS creator_role
        {$courseUnitsSelect}
        {$courseLabSelect}
        {$courseBlockSelect}
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
if (is_dean() && $collegeId) {
    $hasIsGenedCourseCol = db_column_exists('courses', 'is_gened');
    $sql .= dean_schedule_scope_sql($collegeId, $hasIsGenedCourseCol, $params);
} elseif ($programScope !== null && $collegeId) {
    $sql .= ' AND s.college_id = ?';
    $params[] = $collegeId;
    if ($hasGeTargetsTable) {
        $sql .= ' AND (c.department = ? OR gst.program_name = ?)';
        $params[] = $programScope;
        $params[] = $programScope;
    } else {
        $sql .= ' AND c.department = ?';
        $params[] = $programScope;
    }
} elseif (is_faculty() && $facultySelfId > 0) {
    $sql .= ' AND s.faculty_id = ?';
    $params[] = $facultySelfId;
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
if (is_program_chair() && $facultySearch !== '') {
    $sql .= ' AND (f.full_name LIKE ? OR f.faculty_id LIKE ?)';
    $params[] = '%' . $facultySearch . '%';
    $params[] = '%' . $facultySearch . '%';
}
if ($courseSearch !== '') {
    $sql .= ' AND (c.course_code LIKE ? OR c.course_name LIKE ?)';
    $courseLike = '%' . $courseSearch . '%';
    $params[] = $courseLike;
    $params[] = $courseLike;
}
$sql .= ' ORDER BY s.school_year DESC, s.semester, f.full_name, s.start_time';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

/** Group lecture + lab lines for the same course offering into one list row (laboratory courses only). */
$scheduleListGroups = [];
foreach ($rows as $r) {
    $k = (int) $r['faculty_id'] . '|' . (int) $r['course_id'] . '|' . (string) $r['semester'] . '|' . (string) $r['school_year'];
    $scheduleListGroups[$k][] = $r;
}
foreach ($scheduleListGroups as &$g) {
    usort($g, static function (array $a, array $b): int {
        $ta = strtolower((string) ($a['room_type'] ?? ''));
        $tb = strtolower((string) ($b['room_type'] ?? ''));
        if ($ta === 'laboratory' && $tb !== 'laboratory') {
            return 1;
        }
        if ($tb === 'laboratory' && $ta !== 'laboratory') {
            return -1;
        }
        return strcmp((string) $a['start_time'], (string) $b['start_time']);
    });
}
unset($g);

$scheduleListDisplay = [];
foreach ($scheduleListGroups as $group) {
    $isLabCourse = $hasCourseLabFlag && isset($group[0]['is_laboratory']) && (int) $group[0]['is_laboratory'] === 1;
    if (count($group) > 1 && $isLabCourse) {
        $scheduleListDisplay[] = ['merged' => true, 'lines' => $group];
    } else {
        foreach ($group as $r) {
            $scheduleListDisplay[] = ['merged' => false, 'lines' => [$r]];
        }
    }
}

$formatTime12h = static function (?string $time): string {
    $raw = substr((string) $time, 0, 5);
    $dt = DateTime::createFromFormat('H:i', $raw);
    return $dt ? $dt->format('g:i A') : $raw;
};

/** Laboratory-style block: lab room, or a single scheduled block of 3+ hours. */
$segmentIsLaboratory = static function (array $r): bool {
    if (strtolower(trim((string) ($r['room_type'] ?? ''))) === 'laboratory') {
        return true;
    }
    $st = time_to_minutes(substr((string) ($r['start_time'] ?? ''), 0, 8));
    $en = time_to_minutes(substr((string) ($r['end_time'] ?? ''), 0, 8));
    $d = $en - $st;
    return $d > 0 && $d >= 180;
};

/** Block label from course program/year/section (Dean module) when row is not a GE target. */
$courseBlockLabelFromRow = static function (array $r) use ($hasCourseYearLevel, $hasCourseSection): ?string {
    if (!$hasCourseYearLevel && !$hasCourseSection) {
        return null;
    }
    $d = trim((string) ($r['course_block_department'] ?? ''));
    if ($d === '') {
        return null;
    }
    $yl = $hasCourseYearLevel ? trim((string) ($r['course_block_year_level'] ?? '')) : '';
    $sec = $hasCourseSection ? trim((string) ($r['course_block_section'] ?? '')) : '';
    if ($yl === '' && $sec === '') {
        return null;
    }
    if ($yl !== '' && $sec !== '') {
        return $d . ' Y' . $yl . '-' . $sec;
    }
    if ($sec !== '') {
        return $d . ' · Sec ' . $sec;
    }

    return $d . ' · Y' . $yl;
};

if ((is_dean() || is_program_chair()) && $collegeId) {
    $d = db()->prepare('SELECT DISTINCT department FROM faculty WHERE college_id=? AND department != "" ORDER BY department');
    $d->execute([$collegeId]);
    $depts = $d->fetchAll(PDO::FETCH_COLUMN);
} else {
    $depts = db()->query('SELECT DISTINCT department FROM faculty WHERE department != "" ORDER BY department')->fetchAll(PDO::FETCH_COLUMN);
}
if ($programScope !== null) {
    $depts = [$programScope];
    if ($dept === '') {
        $dept = $programScope;
    }
}
$sems = db()->query('SELECT DISTINCT semester FROM schedules ORDER BY semester')->fetchAll(PDO::FETCH_COLUMN);
$years = db()->query('SELECT DISTINCT school_year FROM schedules ORDER BY school_year DESC')->fetchAll(PDO::FETCH_COLUMN);
$scheduleFilterDeptSemSyCol = is_program_chair() ? 'col-md-2' : 'col-md-3';

$pageTitle = 'Schedules';
require_once __DIR__ . '/includes/header.php';
?>
<style>
    .schedule-list-page {
        --schedule-bg-body: #f1f5f9;
        --schedule-card-bg: #ffffff;
        --schedule-table-header-bg: #f8fafc;
        --schedule-border-color: #e2e8f0;
        --schedule-text-primary: #0f172a;
        --schedule-text-secondary: #334155;
        --schedule-hover-bg: #f1f5f9;
        --schedule-shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.03), 0 1px 2px rgba(0, 0, 0, 0.05);
        background: var(--schedule-bg-body);
        color: var(--schedule-text-primary);
        border-radius: 20px;
        padding: 16px;
    }

    html[data-bs-theme="dark"] .schedule-list-page {
        --schedule-bg-body: #0a0f1c;
        --schedule-card-bg: #0f172a;
        --schedule-table-header-bg: #1e293b;
        --schedule-border-color: #1e2a3e;
        --schedule-text-primary: #e2e8f0;
        --schedule-text-secondary: #94a3b8;
        --schedule-hover-bg: #1e293b;
        --schedule-shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.45);
    }

    .schedule-container {
        max-width: 1400px;
        margin: 0 auto;
    }

    .schedule-header {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        flex-wrap: wrap;
        margin-bottom: 1.25rem;
        gap: 12px;
    }

    .schedule-title {
        font-size: 1.1rem;
        font-weight: 600;
        letter-spacing: -0.2px;
        color: var(--schedule-text-primary);
        background: var(--schedule-card-bg);
        display: inline-block;
        padding: 0.3rem 0.8rem;
        border-radius: 40px;
        border: 1px solid var(--schedule-border-color);
    }

    .schedule-list-page .schedule-actions .btn {
        border-radius: 999px;
        font-size: 0.75rem;
        padding: 0.35rem 0.75rem;
    }

    .schedule-list-page .schedule-filters {
        background: var(--schedule-card-bg);
        border: 1px solid var(--schedule-border-color);
        border-radius: 20px;
        box-shadow: var(--schedule-shadow-sm);
        padding: 14px;
    }

    .schedule-list-page .schedule-filters .form-label {
        font-size: 0.72rem;
        color: var(--schedule-text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.2px;
    }

    .schedule-list-page .form-select-sm,
    .schedule-list-page .btn-sm {
        font-size: 0.75rem;
    }

    .schedule-list-wrapper {
        overflow-x: auto;
        border-radius: 20px;
        background: var(--schedule-card-bg);
        border: 1px solid var(--schedule-border-color);
        box-shadow: var(--schedule-shadow-sm);
    }

    .schedule-table {
        width: 100%;
        font-size: 0.75rem;
        font-weight: 450;
        border-collapse: collapse;
        background: var(--schedule-card-bg);
        color: var(--schedule-text-primary);
        min-width: 980px;
        margin-bottom: 0;
    }

    .schedule-table thead th {
        text-align: left;
        padding: 12px 8px;
        background-color: var(--schedule-table-header-bg);
        font-weight: 600;
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        color: var(--schedule-text-secondary);
        border-bottom: 1px solid var(--schedule-border-color);
    }

    .schedule-table td {
        padding: 10px 8px;
        border-bottom: 1px solid var(--schedule-border-color);
        vertical-align: middle;
        font-size: 0.73rem;
        color: var(--schedule-text-primary);
    }

    .schedule-table tr:last-child td {
        border-bottom: none;
    }

    .schedule-table tbody tr:hover td {
        background-color: var(--schedule-hover-bg);
        transition: 0.1s;
    }

    .schedule-table th:first-child, .schedule-table td:first-child {
        padding-left: 14px;
    }

    .schedule-table th:last-child, .schedule-table td:last-child {
        padding-right: 14px;
    }

    .schedule-table .badge {
        font-size: 0.65rem;
        font-weight: 600;
    }

    .schedule-table .text-muted,
    .schedule-list-page .text-muted {
        color: var(--schedule-text-secondary) !important;
    }

    .schedule-info-note {
        margin-top: 16px;
        text-align: center;
        font-size: 0.65rem;
        color: var(--schedule-text-secondary);
        border-top: 1px solid var(--schedule-border-color);
        padding-top: 14px;
        letter-spacing: 0.2px;
    }

    .schedule-info-note span {
        background: var(--schedule-card-bg);
        padding: 2px 8px;
        border-radius: 30px;
    }

    @media (max-width: 780px) {
        .schedule-list-page {
            padding: 12px;
        }

        .schedule-table thead {
            display: none;
        }

        .schedule-table,
        .schedule-table tbody,
        .schedule-table tr,
        .schedule-table td {
            display: block;
            width: 100%;
        }

        .schedule-table tr {
            background: var(--schedule-card-bg);
            margin-bottom: 16px;
            border: 1px solid var(--schedule-border-color);
            border-radius: 20px;
            padding: 12px 14px;
            box-shadow: var(--schedule-shadow-sm);
        }

        .schedule-table td {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 12px;
            padding: 8px 0;
            border-bottom: 1px dashed var(--schedule-border-color);
            font-size: 0.7rem;
            line-height: 1.35;
            word-break: break-word;
        }

        .schedule-table td:last-child {
            border-bottom: none;
        }

        .schedule-table td::before {
            content: attr(data-label);
            font-weight: 600;
            width: 40%;
            min-width: 95px;
            color: var(--schedule-text-secondary);
            font-size: 0.66rem;
            letter-spacing: 0.2px;
            text-transform: uppercase;
            flex-shrink: 0;
        }

        .schedule-table td > * {
            width: 60%;
            text-align: right;
        }

        .schedule-table td[data-label="ACTIONS"] > * {
            width: auto;
            margin-left: auto;
        }
    }
</style>
<div class="schedule-list-page">
<div class="schedule-container">
<div class="schedule-header">
    <h1 class="schedule-title"><i class="fa-solid fa-list me-2 text-primary"></i>Schedule list</h1>
    <?php if (is_dean() || is_program_chair() || is_gened()): ?>
        <div class="d-flex gap-2 schedule-actions">
            <?php if (is_dean() || is_program_chair()): ?>
                <a href="add_schedule.php" class="btn btn-primary no-print"<?= app_tooltip_attr('Creates a new schedule row for a course section. Use this when manually placing a class in time and room.') ?>><i class="fa-solid fa-plus me-1"></i>Add schedule</a>
                <?php if (is_dean()): ?>
                <a href="auto_schedule.php" class="btn btn-outline-primary no-print"<?= app_tooltip_attr('Runs automated placement for your college. Use this to generate or refresh draft schedules.') ?>><i class="fa-solid fa-wand-magic-sparkles me-1"></i>Auto schedule</a>
                <?php endif; ?>
            <?php else: ?>
                <a href="gened_schedule.php" class="btn btn-primary no-print"<?= app_tooltip_attr('Adds a General Education schedule entry. Use this for GE-specific time and room placement.') ?>><i class="fa-solid fa-plus me-1"></i>Add GE schedule</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php if ($programScope !== null): ?>
    <p class="text-muted">Program scope: <strong><?= htmlspecialchars($programScope) ?></strong></p>
<?php endif; ?>

<?php if ($flash): ?>
    <div class="alert alert-success alert-dismissible fade show no-print">
        <?= htmlspecialchars($flash) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"<?= app_tooltip_attr('Dismisses this success notice after you have read it.') ?>></button>
    </div>
<?php endif; ?>

<form class="row g-2 mb-4 no-print align-items-end schedule-filters" method="get">
    <div class="<?= htmlspecialchars($scheduleFilterDeptSemSyCol) ?>">
        <label class="form-label small mb-0">Program</label>
        <select name="dept" class="form-select form-select-sm">
            <option value="">All</option>
            <?php foreach ($depts as $d): ?>
                <option value="<?= htmlspecialchars((string) $d) ?>" <?= $dept === (string) $d ? 'selected' : '' ?>><?= htmlspecialchars((string) $d) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="<?= htmlspecialchars($scheduleFilterDeptSemSyCol) ?>">
        <label class="form-label small mb-0">Semester</label>
        <select name="semester" class="form-select form-select-sm">
            <option value="">All</option>
            <?php foreach ($sems as $s): ?>
                <option value="<?= htmlspecialchars((string) $s) ?>" <?= $sem === (string) $s ? 'selected' : '' ?>><?= htmlspecialchars((string) $s) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="<?= htmlspecialchars($scheduleFilterDeptSemSyCol) ?>">
        <label class="form-label small mb-0">School year</label>
        <select name="school_year" class="form-select form-select-sm">
            <option value="">All</option>
            <?php foreach ($years as $y): ?>
                <option value="<?= htmlspecialchars((string) $y) ?>" <?= $sy === (string) $y ? 'selected' : '' ?>><?= htmlspecialchars((string) $y) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 col-md-6 col-xl-4">
        <label class="form-label small mb-0">Course</label>
        <input type="search" name="course" class="form-control form-control-sm" value="<?= htmlspecialchars($courseSearch) ?>" placeholder="Code or title…" maxlength="160" autocomplete="off"<?= app_tooltip_attr('Narrows the list to schedules whose course code or course title contains this text (partial match).') ?>>
    </div>
    <?php if (is_program_chair()): ?>
    <div class="col-12 col-md-6 col-xl-3">
        <label class="form-label small mb-0">Faculty name / ID</label>
        <input type="search" name="faculty_search" class="form-control form-control-sm" value="<?= htmlspecialchars($facultySearch) ?>" placeholder="Search faculty…" maxlength="120" autocomplete="off"<?= app_tooltip_attr('Narrows the list to schedules whose instructor name or faculty ID contains this text.') ?>>
    </div>
    <?php endif; ?>
    <div class="col-12 col-xl-auto d-flex flex-wrap align-items-end gap-2 pt-1 pt-xl-0">
        <button type="submit" class="btn btn-outline-primary btn-sm"<?= app_tooltip_attr(is_program_chair() ? 'Applies program, term, course, and faculty filters to the schedule list.' : 'Applies program, term, and course filters to the schedule list. Use this to narrow rows you need to edit or audit.') ?>>Filter</button>
        <a href="schedule.php" class="btn btn-outline-secondary btn-sm"<?= app_tooltip_attr('Clears filters and shows all schedules in your scope again.') ?>>Reset</a>
    </div>
</form>

<div class="schedule-list-wrapper">
            <table class="schedule-table">
                <thead>
                <tr>
                    <th>Time / Days</th>
                    <th>Course</th>
                    <th>Faculty</th>
                    <th>Room</th>
                    <th>College</th>
                    <?php if ($hasGeTargetsTable): ?><th>Target Block</th><?php endif; ?>
                    <th>Semester</th>
                    <th>School year</th>
                    <th class="no-print"></th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td data-label="STATUS" colspan="<?= $hasGeTargetsTable ? '9' : '8' ?>" class="text-muted p-4">No schedules match your filters.</td></tr>
                <?php endif; ?>
                <?php foreach ($scheduleListDisplay as $block): ?>
                    <?php
                    $lines = $block['lines'];
                    $r0 = $lines[0];
                    ?>
                    <tr>
                        <td data-label="TIME / DAYS">
                            <?php foreach ($lines as $idx => $r): ?>
                                <?php
                                $isLabTime = $segmentIsLaboratory($r);
                                $segLabel = $isLabTime ? 'Laboratory' : 'Lecture';
                                $segBadgeClass = $isLabTime ? 'bg-warning text-dark' : 'bg-info text-dark';
                                ?>
                                <div class="<?= $idx > 0 ? 'mt-2 pt-2 border-top border-light' : '' ?>">
                                    <span class="badge bg-secondary"><?= htmlspecialchars($r['schedule_type']) ?></span>
                                    <span class="badge <?= $segBadgeClass ?>"><?= htmlspecialchars($segLabel) ?></span><br>
                                    <small><?= htmlspecialchars(str_replace(',', ', ', (string) $r['day_of_week'])) ?></small><br>
                                    <?= htmlspecialchars($formatTime12h((string) $r['start_time'])) ?> – <?= htmlspecialchars($formatTime12h((string) $r['end_time'])) ?>
                                </div>
                            <?php endforeach; ?>
                        </td>
                        <td data-label="COURSE"><strong><?= htmlspecialchars($r0['course_code']) ?></strong><br><span class="small text-muted"><?= htmlspecialchars($r0['course_name']) ?></span></td>
                        <td data-label="FACULTY"><?= htmlspecialchars($r0['faculty_name']) ?></td>
                        <td data-label="ROOM">
                            <?php foreach ($lines as $idx => $r): ?>
                                <?php $roomIsLab = $segmentIsLaboratory($r); ?>
                                <div class="<?= $idx > 0 ? 'mt-1' : '' ?>">
                                    <?= htmlspecialchars($r['room_code']) ?>
                                    <?php if ($roomIsLab): ?>
                                        <span class="badge bg-warning text-dark ms-1">Laboratory</span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </td>
                        <td data-label="COLLEGE"><?= htmlspecialchars((string) ($r0['college_name'] ?? '')) ?></td>
                        <?php if ($hasGeTargetsTable): ?>
                            <td data-label="TARGET BLOCK">
                                <?php
                                $shownTarget = false;
                                foreach ($lines as $r) {
                                    if (!empty($r['target_program'])) {
                                        $shownTarget = true;
                                        ?>
                                        <span class="small d-block"><?= htmlspecialchars((string) $r['target_program']) ?> Y<?= htmlspecialchars((string) ($r['target_year_level'] ?? '')) ?>-<?= htmlspecialchars((string) ($r['target_section'] ?? '')) ?></span>
                                        <?php
                                        break;
                                    }
                                }
                                if (!$shownTarget) {
                                    $blockLbl = $courseBlockLabelFromRow($r0);
                                    if ($blockLbl !== null) {
                                        echo '<span class="small d-block">' . htmlspecialchars($blockLbl) . '</span>';
                                    } else {
                                        echo '<span class="text-muted small">—</span>';
                                    }
                                }
                                ?>
                            </td>
                        <?php endif; ?>
                        <td data-label="SEMESTER"><?= htmlspecialchars($r0['semester']) ?></td>
                        <td data-label="SCHOOL YEAR"><?= htmlspecialchars($r0['school_year']) ?></td>
                        <td data-label="ACTIONS" class="no-print text-nowrap">
                            <?php foreach ($lines as $r): ?>
                                <?php $isGeCreated = ((string) ($r['creator_role'] ?? '') === 'gened'); ?>
                                <div class="d-inline-flex gap-1 align-items-center mb-1">
                                    <?php if ((is_dean() || is_program_chair()) && !$isGeCreated): ?>
                                        <a href="edit_schedule.php?id=<?= (int) $r['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit <?= htmlspecialchars($r['room_type'] ?? '') ?>"<?= app_tooltip_attr('Opens the editor for this schedule segment. Use this to change time, room, faculty, or meeting link.') ?>><i class="fa-solid fa-pen"></i></a>
                                    <?php endif; ?>
                                    <?php if (is_gened() && $isGeCreated): ?>
                                        <a href="gened_edit_schedule.php?id=<?= (int) $r['id'] ?>" class="btn btn-sm btn-outline-primary"<?= app_tooltip_attr('Opens the GE schedule editor for this row. Use this to adjust GE placement or targets.') ?>><i class="fa-solid fa-pen"></i></a>
                                    <?php endif; ?>
                                    <?php if (is_admin() || ((is_dean() || is_program_chair()) && !$isGeCreated) || (is_gened() && $isGeCreated)): ?>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this schedule row?');">
                                            <input type="hidden" name="delete_id" value="<?= (int) $r['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete this row"<?= app_tooltip_attr('Deletes this schedule row after confirmation. Use this when a section is cancelled or duplicated by mistake.') ?>><i class="fa-solid fa-trash"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
</div>
<div class="schedule-info-note">
    <span>Compact table • mobile cards • dark mode ready</span>
</div>
</div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

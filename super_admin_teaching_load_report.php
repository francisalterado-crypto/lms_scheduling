<?php
declare(strict_types=1);

/**
 * Super Admin: weekly teaching contact hours by program (faculty.department),
 * flagged under load (<18 hrs/week) or overload (>27). Uses the same weekly
 * hour rules as faculty_teaching_load (session length × meeting days; lab if block ≥ 3h).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_role(['super_admin']);
$hasEmploymentStatusColumn = db_column_exists('faculty', 'employment_status');

$adminCollegeId = (int) ($_GET['college_id'] ?? 0);
$sem = trim((string) ($_GET['semester'] ?? ''));
$sy = trim((string) ($_GET['school_year'] ?? ''));
$programSearch = trim((string) ($_GET['program_search'] ?? ''));
$employmentStatusFilter = trim((string) ($_GET['employment_status'] ?? ''));
$allowedEmploymentStatuses = ['Permanent', 'Contract of Service', 'Temporary'];
if (!in_array($employmentStatusFilter, $allowedEmploymentStatuses, true)) {
    $employmentStatusFilter = '';
}

$latestTerm = db()->query(
    'SELECT semester, school_year FROM schedules ORDER BY school_year DESC, id DESC LIMIT 1'
)->fetch(PDO::FETCH_ASSOC) ?: null;
if ($sem === '' && $latestTerm) {
    $sem = (string) ($latestTerm['semester'] ?? '');
}
if ($sy === '' && $latestTerm) {
    $sy = (string) ($latestTerm['school_year'] ?? '');
}

$sql = "SELECT s.id, s.faculty_id, s.course_id, s.college_id AS sched_college_id,
        s.semester, s.school_year,
        s.start_time, s.end_time, s.day_of_week,
        f.full_name AS faculty_name, f.department AS faculty_department,
        c.course_code, c.units AS course_units_total
        FROM schedules s
        INNER JOIN faculty f ON f.id = s.faculty_id
        INNER JOIN courses c ON c.id = s.course_id
        WHERE 1=1";
$params = [];
if ($adminCollegeId > 0) {
    $sql .= ' AND s.college_id = ?';
    $params[] = $adminCollegeId;
}
if ($sem !== '') {
    $sql .= ' AND s.semester = ?';
    $params[] = $sem;
}
if ($sy !== '') {
    $sql .= ' AND s.school_year = ?';
    $params[] = $sy;
}
$sql .= ' ORDER BY s.school_year DESC, s.semester, f.full_name, s.start_time';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$schedRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

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

$weeklyContactFromGroup = static function (array $group) use ($sessionHoursBetween, $labSessionMinHours): float {
    $lec = 0.0;
    $lab = 0.0;
    foreach ($group as $r) {
        $h = $sessionHoursBetween((string) ($r['start_time'] ?? ''), (string) ($r['end_time'] ?? ''));
        $dayCount = count(parse_day_set((string) ($r['day_of_week'] ?? '')));
        if ($dayCount < 1) {
            $dayCount = 1;
        }
        $weeklyH = $h * $dayCount;
        if ($h >= $labSessionMinHours) {
            $lab += $weeklyH;
        } else {
            $lec += $weeklyH;
        }
    }
    return round($lec + $lab, 2);
};

$scheduleListGroups = [];
foreach ($schedRows as $r) {
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

$hoursByFaculty = [];
$unitsByFaculty = [];
foreach ($scheduleListGroups as $group) {
    $r0 = $group[0];
    $fid = (int) $r0['faculty_id'];
    $h = $weeklyContactFromGroup($group);
    $units = (float) ($r0['course_units_total'] ?? 0);
    if (!isset($hoursByFaculty[$fid])) {
        $hoursByFaculty[$fid] = 0.0;
    }
    if (!isset($unitsByFaculty[$fid])) {
        $unitsByFaculty[$fid] = 0.0;
    }
    $hoursByFaculty[$fid] = round($hoursByFaculty[$fid] + $h, 2);
    $unitsByFaculty[$fid] = round($unitsByFaculty[$fid] + $units, 2);
}

$underMax = 18.0;
$overMin = 27.0;

$facultyEmploymentSelect = $hasEmploymentStatusColumn
    ? 'f.employment_status'
    : "'Permanent' AS employment_status";
$facultySql = "SELECT f.id, f.full_name, f.department, {$facultyEmploymentSelect}, f.college_id,
               c.college_code, c.college_name
               FROM faculty f
               LEFT JOIN colleges c ON c.id = f.college_id
               WHERE f.status = 'active'";
$facultyParams = [];
if ($adminCollegeId > 0) {
    $facultySql .= ' AND f.college_id = ?';
    $facultyParams[] = $adminCollegeId;
}
if ($programSearch !== '') {
    $facultySql .= ' AND f.department = ?';
    $facultyParams[] = $programSearch;
}
if ($employmentStatusFilter !== '' && $hasEmploymentStatusColumn) {
    $facultySql .= ' AND f.employment_status = ?';
    $facultyParams[] = $employmentStatusFilter;
}
$facultySql .= ' ORDER BY f.department, f.full_name';
$facStmt = db()->prepare($facultySql);
$facStmt->execute($facultyParams);
$allFaculty = $facStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$byProgram = [];
foreach ($allFaculty as $fr) {
    $prog = trim((string) ($fr['department'] ?? ''));
    if ($prog === '') {
        $prog = '— (no program / department)';
    }
    $fid = (int) $fr['id'];
    $hrs = $hoursByFaculty[$fid] ?? 0.0;
    $units = $unitsByFaculty[$fid] ?? 0.0;
    if ($hrs < $underMax) {
        $cat = 'under';
    } elseif ($hrs > $overMin) {
        $cat = 'over';
    } else {
        $cat = 'normal';
    }
    if (!isset($byProgram[$prog])) {
        $byProgram[$prog] = ['under' => [], 'normal' => [], 'over' => []];
    }
    $cc = trim((string) ($fr['college_code'] ?? ''));
    $cn = trim((string) ($fr['college_name'] ?? ''));
    $collegeLabel = ($cc === '' && $cn === '') ? '—' : ($cc !== '' ? $cc . ' — ' . $cn : $cn);
    $byProgram[$prog][$cat][] = [
        'name' => (string) $fr['full_name'],
        'employment_status' => trim((string) ($fr['employment_status'] ?? '')) !== '' ? (string) $fr['employment_status'] : 'Permanent',
        'hours' => $hrs,
        'units' => $units,
        'college' => $collegeLabel,
    ];
}
uksort($byProgram, 'strcasecmp');

$collegeOpts = db()->query(
    'SELECT id, college_code, college_name FROM colleges ORDER BY college_code'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$programOptsSql = 'SELECT DISTINCT department FROM faculty WHERE department <> ""';
$programOptsParams = [];
if ($adminCollegeId > 0) {
    $programOptsSql .= ' AND college_id = ?';
    $programOptsParams[] = $adminCollegeId;
}
$programOptsSql .= ' ORDER BY department';
$programOptsStmt = db()->prepare($programOptsSql);
$programOptsStmt->execute($programOptsParams);
$programOpts = $programOptsStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

$sems = db()->query('SELECT DISTINCT semester FROM schedules ORDER BY semester')->fetchAll(PDO::FETCH_COLUMN) ?: [];
$years = db()->query('SELECT DISTINCT school_year FROM schedules ORDER BY school_year DESC')->fetchAll(PDO::FETCH_COLUMN) ?: [];

$pageTitle = 'Teaching load report (all programs)';
$mainContainerClass = 'container py-4 py-md-5 app-main';
require_once __DIR__ . '/includes/header.php';
?>
<style>
    :root {
        --bg: #f0f4f8;
        --text: #1e2a3e;
        --muted: #5b6e8c;
        --primary: #1f5e6b;
        --primary-dark: #12464f;
        --surface: #ffffff;
        --line: #e2e8f0;
        --surface-soft: #f9fbfd;
        --table-text: #1f2f3e;
        --title-strong: #1f4e5c;
        --name-strong: #13505b;
    }

    body {
        background: var(--bg);
        color: var(--text);
    }

    .report-dashboard {
        max-width: 1440px;
        margin: 0 auto;
    }

    .report-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        flex-wrap: wrap;
        gap: 0.75rem;
        margin-bottom: 1.5rem;
        border-bottom: 2px solid var(--line);
        padding-bottom: 1rem;
    }

    .report-title {
        font-size: 1.9rem;
        font-weight: 700;
        letter-spacing: -0.3px;
        margin: 0;
        background: linear-gradient(135deg, #1e3c3f 0%, #2b5e6b 100%);
        background-clip: text;
        -webkit-background-clip: text;
        color: transparent;
    }

    .report-subtitle {
        margin: 0.35rem 0 0 0;
        color: #4a627a;
        font-weight: 500;
        font-size: 0.9rem;
    }

    .export-badge {
        background: var(--surface);
        padding: 0.5rem 1rem;
        border-radius: 999px;
        font-size: 0.8rem;
        font-weight: 600;
        color: #2c5f6e;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        border: 1px solid #cbd5e1;
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
    }

    .filter-card {
        background: var(--surface);
        border-radius: 24px;
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.03), 0 2px 6px rgba(0, 0, 0, 0.05);
        padding: 1.35rem 1.6rem;
        margin-bottom: 1.8rem;
        border: 1px solid #e9edf2;
    }

    .filter-label {
        display: block;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.45px;
        color: var(--muted);
        margin-bottom: 0.45rem;
    }

    .filter-control {
        border-radius: 16px;
        border: 1px solid #cfdfed;
        font-size: 0.9rem;
        font-weight: 500;
        color: #1f2f40;
        min-height: 42px;
    }

    .filter-control:focus {
        border-color: #2c8f9c;
        box-shadow: 0 0 0 3px rgba(44, 143, 156, 0.18);
    }

    .btn-pill {
        border-radius: 999px;
        padding: 0.6rem 1.2rem;
        font-size: 0.85rem;
        font-weight: 600;
    }

    .program-card {
        background: var(--surface);
        border-radius: 22px;
        border: 1px solid #eef2f8;
        box-shadow: 0 4px 14px rgba(0, 0, 0, 0.02);
        padding: 1rem;
        margin-bottom: 1.25rem;
    }

    .program-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.8rem;
        margin-bottom: 0.9rem;
    }

    .program-name {
        margin: 0;
        font-size: 1.3rem;
        color: var(--title-strong);
        font-weight: 700;
    }

    .stats-row {
        display: flex;
        gap: 0.7rem;
        flex-wrap: wrap;
    }

    .stat-chip {
        background: var(--surface-soft);
        border: 1px solid var(--line);
        border-radius: 999px;
        padding: 0.45rem 0.85rem;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.8rem;
    }

    .stat-chip .value {
        font-weight: 700;
        color: #1f3b45;
    }

    .table-shell {
        border: 1px solid #e6edf4;
        border-radius: 16px;
        overflow: auto;
    }

    .report-table {
        width: 100%;
        min-width: 760px;
        border-collapse: collapse;
        font-size: 0.86rem;
    }

    .report-table th {
        text-align: left;
        padding: 0.9rem 0.85rem;
        background: var(--surface-soft);
        color: #2c4b5c;
        border-bottom: 1px solid #e4eaf1;
        font-weight: 700;
        font-size: 0.79rem;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .report-table td {
        padding: 0.85rem;
        border-bottom: 1px solid #edf2f7;
        color: var(--table-text);
        font-weight: 500;
        vertical-align: middle;
    }

    .report-table tbody tr:last-child td {
        border-bottom: none;
    }

    .report-table tbody tr:hover td {
        background: #fafcff;
    }

    .faculty-name {
        font-weight: 700;
        color: var(--name-strong);
    }

    .status-pill {
        display: inline-flex;
        padding: 0.25rem 0.6rem;
        border-radius: 999px;
        font-size: 0.72rem;
        font-weight: 700;
        border: 1px solid transparent;
    }

    .status-under {
        background: #fff6e5;
        color: #9a6200;
        border-color: #f6d7a6;
    }

    .status-normal {
        background: #e8f7ee;
        color: #0d6a39;
        border-color: #bde7cd;
    }

    .status-over {
        background: #feecec;
        color: #a11a1a;
        border-color: #f5c3c3;
    }

    .empty-state {
        text-align: center;
        padding: 2rem 1rem;
        color: #8ea0b3;
        font-style: italic;
    }

    .footer-note {
        margin-top: 1rem;
        font-size: 0.74rem;
        color: #7c8ea0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 0.8rem;
        flex-wrap: wrap;
    }

    [data-bs-theme="dark"] {
        --bg: #0f1722;
        --text: #d9e4f0;
        --muted: #98adc4;
        --surface: #111c28;
        --surface-soft: #162435;
        --line: #2a3a4d;
        --table-text: #d3e0ec;
        --title-strong: #cfe8ff;
        --name-strong: #a7deef;
    }

    [data-bs-theme="dark"] .report-subtitle {
        color: #9db4cc;
    }

    [data-bs-theme="dark"] .export-badge {
        background: #152334;
        border-color: #2a4159;
        color: #b9d7ec;
        box-shadow: none;
    }

    [data-bs-theme="dark"] .filter-card,
    [data-bs-theme="dark"] .program-card {
        box-shadow: none;
        border-color: #2a3a4d;
    }

    [data-bs-theme="dark"] .filter-control {
        background: #0f1a27;
        border-color: #2e435b;
        color: #d7e6f5;
    }

    [data-bs-theme="dark"] .filter-control:focus {
        border-color: #4d7fa3;
        box-shadow: 0 0 0 3px rgba(93, 146, 186, 0.25);
    }

    [data-bs-theme="dark"] .report-table th {
        color: #bcd1e4;
        border-bottom-color: #334b61;
    }

    [data-bs-theme="dark"] .report-table td {
        border-bottom-color: #24384c;
    }

    [data-bs-theme="dark"] .report-table tbody tr:hover td {
        background: #18283a;
    }

    [data-bs-theme="dark"] .table-shell {
        border-color: #2a3d53;
    }

    [data-bs-theme="dark"] .footer-note {
        color: #8ea4ba;
    }

    @media print {
        @page { size: A4 portrait; margin: 8mm; }

        .no-print { display: none !important; }
        .footer-note { display: none !important; }
        .app-main { padding-top: 0 !important; padding-bottom: 0 !important; }
        .stats-row { display: none !important; }
        .export-badge { display: none !important; }
        .report-subtitle { margin-top: 0.15rem; font-size: 8pt; }
        .report-header { margin-bottom: 0.4rem; padding-bottom: 0.35rem; }
        .program-head { margin-bottom: 0.35rem; }
        .program-name { font-size: 1rem; }
        .program-card { break-inside: auto; page-break-inside: auto; box-shadow: none; border-color: #aab2bd; margin-bottom: 0.3rem; padding: 0.4rem; }
        body { font-size: 9pt; background: #fff; }
        .report-title {
            color: #000 !important;
            background: none !important;
            background-clip: initial !important;
            -webkit-background-clip: initial !important;
            font-size: 14pt;
            letter-spacing: 0;
        }
        .report-title i,
        .program-name i {
            display: none !important;
        }
        .table-shell { border-radius: 10px; }
        .report-table { font-size: 7.8pt; }
        .report-table th, .report-table td { padding: 0.2rem 0.24rem; line-height: 1.1; }
        .report-table th { color: #111; background: #fff; border-bottom: 1px solid #333; }
        .report-table td { color: #111; }
        .status-pill { padding: 0.08rem 0.25rem; font-size: 0.58rem; border-color: #666 !important; background: #fff !important; color: #111 !important; }
        .faculty-name i { display: none; }
        .empty-state { padding: 0.8rem 0.5rem; font-style: normal; }

        /* Remove any QR-related widgets/images from print to prevent extra pages */
        [id*="qr" i],
        [class*="qr" i],
        [data-qr],
        .qrcode, .qr-code, .qr-wrapper, .qr-section, .qr-container,
        img[src*="qr" i], img[alt*="qr" i], svg[id*="qr" i], canvas[id*="qr" i],
        iframe[src*="qr" i], object[data*="qr" i], embed[src*="qr" i],
        .print-hide-qr {
            display: none !important;
            visibility: hidden !important;
        }

        .print-single-program .program-card { display: none !important; }
        .print-single-program .program-card.selected-for-print { display: block !important; }
    }

    @media (max-width: 760px) {
        .filter-card {
            padding: 1rem;
        }
    }
</style>
<div class="report-dashboard">
    <div class="report-header">
        <div>
            <h1 class="report-title"><i class="fa-solid fa-chalkboard-user me-2" style="color:#2f7c8a;"></i>Teaching load report</h1>
            <p class="report-subtitle">Western Philippines University · official academic load summary</p>
        </div>
        <button type="button" class="export-badge no-print" onclick="downloadExcelReport()">
            <i class="fa-solid fa-file-excel"></i>Download Excel
        </button>
    </div>

    <form method="get" class="filter-card no-print">
        <div class="row g-3 align-items-end">
            <div class="col-lg-2 col-md-4">
                <label class="filter-label"><i class="fa-solid fa-building-columns me-1"></i>College</label>
                <select name="college_id" class="form-select filter-control">
                    <option value="0">All colleges</option>
                    <?php foreach ($collegeOpts as $co): ?>
                        <option value="<?= (int) $co['id'] ?>"<?= $adminCollegeId === (int) $co['id'] ? ' selected' : '' ?>>
                            <?= htmlspecialchars((string) $co['college_code']) ?> — <?= htmlspecialchars((string) $co['college_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="filter-label"><i class="fa-solid fa-calendar-days me-1"></i>Semester</label>
                <select name="semester" class="form-select filter-control">
                    <option value="">(auto from data)</option>
                    <?php foreach ($sems as $s): ?>
                        <option value="<?= htmlspecialchars((string) $s) ?>"<?= $sem === (string) $s ? ' selected' : '' ?>><?= htmlspecialchars((string) $s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="filter-label"><i class="fa-solid fa-graduation-cap me-1"></i>School year</label>
                <select name="school_year" class="form-select filter-control">
                    <option value="">(auto from data)</option>
                    <?php foreach ($years as $y): ?>
                        <option value="<?= htmlspecialchars((string) $y) ?>"<?= $sy === (string) $y ? ' selected' : '' ?>><?= htmlspecialchars((string) $y) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-3 col-md-6">
                <label class="filter-label"><i class="fa-solid fa-laptop-code me-1"></i>Program</label>
                <select name="program_search" class="form-select filter-control">
                    <option value="">All programs</option>
                    <?php foreach ($programOpts as $program): ?>
                        <option value="<?= htmlspecialchars((string) $program) ?>"<?= $programSearch === (string) $program ? ' selected' : '' ?>>
                            <?= htmlspecialchars((string) $program) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-3 col-md-6">
                <label class="filter-label"><i class="fa-solid fa-user-check me-1"></i>Employment status</label>
                <select name="employment_status" class="form-select filter-control"<?= $hasEmploymentStatusColumn ? '' : ' disabled' ?>>
                    <option value="">All statuses</option>
                    <?php foreach ($allowedEmploymentStatuses as $status): ?>
                        <option value="<?= htmlspecialchars($status) ?>"<?= $employmentStatusFilter === $status ? ' selected' : '' ?>>
                            <?= htmlspecialchars($status) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!$hasEmploymentStatusColumn): ?>
                    <div class="form-text small text-warning">Run <a href="upgrade_roles.php">upgrade_roles.php</a> to enable this filter.</div>
                <?php endif; ?>
            </div>
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-pill"><i class="fa-solid fa-filter me-1"></i>Apply filters</button>
                <a href="super_admin_teaching_load_report.php" class="btn btn-outline-secondary btn-pill"><i class="fa-solid fa-rotate-right me-1"></i>Reset</a>
            </div>
        </div>
    </form>

    <?php if ($sem === '' && $sy === '' && $schedRows === []): ?>
        <div class="alert alert-warning">No schedules were found. Add schedules first or select a term.</div>
    <?php else: ?>
        <?php if ($byProgram === []): ?>
            <div class="alert alert-info">
                No matching programs found<?= $programSearch !== '' ? ' for "' . htmlspecialchars($programSearch) . '"' : '' ?>.
            </div>
        <?php endif; ?>

        <?php foreach ($byProgram as $programName => $buckets): ?>
            <?php
                $allRows = array_merge($buckets['under'], $buckets['normal'], $buckets['over']);
                usort($allRows, static fn(array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));
                $totalFaculty = count($allRows);
                $totalUnits = array_reduce($allRows, static fn(float $carry, array $row): float => $carry + (float) ($row['units'] ?? 0), 0.0);
                $totalHours = array_reduce($allRows, static fn(float $carry, array $row): float => $carry + (float) ($row['hours'] ?? 0), 0.0);
            ?>
            <section class="program-card<?= $programSearch !== '' && $programSearch === $programName ? ' selected-for-print' : '' ?>" data-program-name="<?= htmlspecialchars($programName, ENT_QUOTES) ?>" aria-labelledby="prog-<?= htmlspecialchars(md5($programName)) ?>">
                <div class="program-head">
                    <h2 class="program-name" id="prog-<?= htmlspecialchars(md5($programName)) ?>">
                        <i class="fa-solid fa-graduation-cap me-2"></i><?= htmlspecialchars($programName) ?>
                    </h2>
                    <div class="stats-row">
                        <span class="stat-chip"><i class="fa-solid fa-users"></i> Faculty <span class="value"><?= $totalFaculty ?></span></span>
                        <span class="stat-chip"><i class="fa-solid fa-book"></i> Units <span class="value"><?= number_format($totalUnits, 2) ?></span></span>
                        <span class="stat-chip"><i class="fa-solid fa-clock"></i> Hours/week <span class="value"><?= number_format($totalHours, 2) ?></span></span>
                    </div>
                </div>

                <div class="table-shell">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Faculty</th>
                                <th>Employment Status</th>
                                <th>College</th>
                                <th>Load Status</th>
                                <th class="text-end">Total Units</th>
                                <th class="text-end">Hours/week</th>
                                <th class="text-end">Total Hours x2</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($allRows === []): ?>
                                <tr><td colspan="7" class="empty-state">No faculty records found for this program.</td></tr>
                            <?php else: ?>
                                <?php foreach ($allRows as $row): ?>
                                    <?php
                                        $hours = (float) ($row['hours'] ?? 0);
                                        $statusClass = $hours < $underMax ? 'status-under' : ($hours > $overMin ? 'status-over' : 'status-normal');
                                        $statusLabel = $hours < $underMax ? 'Under load' : ($hours > $overMin ? 'Over load' : 'Normal load');
                                        $employmentStatus = trim((string) ($row['employment_status'] ?? ''));
                                        $doubleHours = in_array($employmentStatus, ['Permanent', 'Temporary'], true)
                                            ? number_format($hours * 2, 2)
                                            : '—';
                                    ?>
                                    <tr>
                                        <td class="faculty-name"><i class="fa-solid fa-user-graduate me-1" style="color:#568c9b;"></i><?= htmlspecialchars((string) $row['name']) ?></td>
                                        <td><?= htmlspecialchars((string) $row['employment_status']) ?></td>
                                        <td><?= htmlspecialchars((string) $row['college']) ?></td>
                                        <td><span class="status-pill <?= $statusClass ?>"><?= $statusLabel ?></span></td>
                                        <td class="text-end font-monospace"><strong><?= number_format((float) ($row['units'] ?? 0), 2) ?></strong></td>
                                        <td class="text-end font-monospace"><?= number_format($hours, 2) ?></td>
                                        <td class="text-end font-monospace"><?= $doubleHours ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="footer-note">
        <span><i class="fa-regular fa-note-sticky"></i> Load summary based on official teaching assignments.</span>
        <span><i class="fa-solid fa-download"></i> Western Philippines University · Teaching Load System</span>
    </div>
</div>

<script>
    function downloadExcelReport() {
        const cards = Array.from(document.querySelectorAll('.program-card'));
        if (cards.length === 0) {
            window.alert('No report data available to export.');
            return;
        }

        const parts = [];
        parts.push('<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">');
        parts.push('<head><meta charset="UTF-8"><style>');
        parts.push('table{border-collapse:collapse;width:100%;font-family:Arial,sans-serif;font-size:12px;}');
        parts.push('th,td{border:1px solid #333;padding:6px;text-align:left;}');
        parts.push('th{background:#f0f0f0;font-weight:700;}');
        parts.push('h2{margin:14px 0 6px 0;font-family:Arial,sans-serif;font-size:14px;}');
        parts.push('p{margin:0 0 6px 0;font-family:Arial,sans-serif;font-size:11px;color:#333;}');
        parts.push('</style></head><body>');
        parts.push('<h2>Western Philippines University - Teaching Load Report</h2>');
        parts.push('<p>Generated: ' + new Date().toLocaleString() + '</p>');

        cards.forEach((card) => {
            const titleNode = card.querySelector('.program-name');
            const tableNode = card.querySelector('.report-table');
            if (!tableNode) {
                return;
            }
            const programTitle = titleNode ? titleNode.textContent.trim() : 'Program';
            parts.push('<h2>' + escapeHtml(programTitle) + '</h2>');
            parts.push(tableNode.outerHTML);
        });

        parts.push('</body></html>');
        const blob = new Blob([parts.join('')], { type: 'application/vnd.ms-excel;charset=utf-8;' });
        const fileName = 'teaching_load_report_' + new Date().toISOString().slice(0, 10) + '.xls';
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = fileName;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(link.href);
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

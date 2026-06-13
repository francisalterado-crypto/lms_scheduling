<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_role(['admin']);

/** @return array{title:string,columns:string[],rows:list<array<string,mixed>>} */
function build_admin_report(string $reportKey, int $collegeId, int $facultyId, string $day): array
{
    $title = 'Report';
    $columns = [];
    $rows = [];
    $employmentStatusExpr = db_column_exists('faculty', 'employment_status')
        ? "COALESCE(NULLIF(TRIM(f.employment_status), ''), 'Permanent')"
        : "'Permanent'";

    switch ($reportKey) {
        case 'master_faculty_list':
            $title = 'Master Faculty List (All Colleges)';
            $columns = ['Faculty ID', 'Name', 'Department/Program', 'Employment Status', 'Email', 'College', 'Status'];
            $rows = db()->query(
                "SELECT f.faculty_id, f.full_name, f.department,
                        {$employmentStatusExpr} AS employment_status,
                        f.email,
                        COALESCE(c.college_code, 'Unassigned') AS college_code, f.status
                 FROM faculty f
                 LEFT JOIN colleges c ON c.id = f.college_id
                 ORDER BY c.college_code, f.full_name"
            )->fetchAll();
            break;
        case 'faculty_per_college':
            $title = 'Faculty per College';
            $columns = ['College Code', 'College Name', 'Faculty Count'];
            $rows = db()->query(
                "SELECT c.college_code, c.college_name, COUNT(f.id) AS faculty_count
                 FROM colleges c
                 LEFT JOIN faculty f ON f.college_id = c.id
                 GROUP BY c.id
                 ORDER BY c.college_code"
            )->fetchAll();
            break;
        case 'faculty_per_department':
        case 'faculty_per_program':
            $title = $reportKey === 'faculty_per_department' ? 'Faculty per Department' : 'Faculty per Program';
            $columns = ['College', 'Department/Program', 'Faculty Count'];
            $rows = db()->query(
                "SELECT COALESCE(c.college_code, 'Unassigned') AS college_code,
                        COALESCE(NULLIF(TRIM(f.department), ''), 'Unspecified') AS dept_program,
                        COUNT(*) AS faculty_count
                 FROM faculty f
                 LEFT JOIN colleges c ON c.id = f.college_id
                 GROUP BY c.college_code, dept_program
                 ORDER BY c.college_code, dept_program"
            )->fetchAll();
            break;
        case 'faculty_per_year_level':
            $title = 'Faculty per Year Level';
            $columns = ['Year Level', 'Faculty Count'];
            $rows = db()->query(
                "SELECT COALESCE(NULLIF(TRIM(c.year_level), ''), 'Unspecified') AS year_level,
                        COUNT(DISTINCT s.faculty_id) AS faculty_count
                 FROM schedules s
                 INNER JOIN courses c ON c.id = s.course_id
                 GROUP BY year_level
                 ORDER BY year_level"
            )->fetchAll();
            break;
        case 'active_inactive_faculty':
            $title = 'Active/Inactive Faculty';
            $columns = ['College', 'Status', 'Faculty Count'];
            $rows = db()->query(
                "SELECT COALESCE(c.college_code, 'Unassigned') AS college_code, f.status, COUNT(*) AS faculty_count
                 FROM faculty f
                 LEFT JOIN colleges c ON c.id = f.college_id
                 GROUP BY c.college_code, f.status
                 ORDER BY c.college_code, f.status"
            )->fetchAll();
            break;
        case 'faculty_weekly_schedule':
            $title = 'Faculty Weekly Schedule (Individual)';
            $columns = ['Faculty', 'Course', 'Days', 'Time', 'Room', 'Term'];
            if ($facultyId > 0) {
                $st = db()->prepare(
                    "SELECT f.full_name AS faculty_name, CONCAT(c.course_code, ' - ', c.course_name) AS course_title,
                            s.day_of_week, CONCAT(SUBSTR(s.start_time,1,5), '-', SUBSTR(s.end_time,1,5)) AS schedule_time,
                            r.room_code, CONCAT(s.semester, ' / ', s.school_year) AS term_label
                     FROM schedules s
                     INNER JOIN faculty f ON f.id = s.faculty_id
                     INNER JOIN courses c ON c.id = s.course_id
                     INNER JOIN rooms r ON r.id = s.room_id
                     WHERE s.faculty_id = ?
                     ORDER BY s.day_of_week, s.start_time"
                );
                $st->execute([$facultyId]);
                $rows = $st->fetchAll();
            }
            break;
        case 'faculty_daily_schedule':
            $title = 'Faculty Daily Schedule';
            $columns = ['Faculty', 'Course', 'Day', 'Time', 'Room', 'Term'];
            $st = db()->prepare(
                "SELECT f.full_name AS faculty_name, CONCAT(c.course_code, ' - ', c.course_name) AS course_title,
                        ? AS day_name, CONCAT(SUBSTR(s.start_time,1,5), '-', SUBSTR(s.end_time,1,5)) AS schedule_time,
                        r.room_code, CONCAT(s.semester, ' / ', s.school_year) AS term_label
                 FROM schedules s
                 INNER JOIN faculty f ON f.id = s.faculty_id
                 INNER JOIN courses c ON c.id = s.course_id
                 INNER JOIN rooms r ON r.id = s.room_id
                 WHERE FIND_IN_SET(?, s.day_of_week) > 0
                 ORDER BY f.full_name, s.start_time"
            );
            $st->execute([$day, $day]);
            $rows = $st->fetchAll();
            break;
        case 'all_faculty_schedules':
            $title = 'All Faculty Schedules (Consolidated)';
            $columns = ['Faculty', 'Course', 'Days', 'Time', 'Room', 'Term'];
            $rows = db()->query(
                "SELECT f.full_name AS faculty_name, CONCAT(c.course_code, ' - ', c.course_name) AS course_title,
                        s.day_of_week, CONCAT(SUBSTR(s.start_time,1,5), '-', SUBSTR(s.end_time,1,5)) AS schedule_time,
                        r.room_code, CONCAT(s.semester, ' / ', s.school_year) AS term_label
                 FROM schedules s
                 INNER JOIN faculty f ON f.id = s.faculty_id
                 INNER JOIN courses c ON c.id = s.course_id
                 INNER JOIN rooms r ON r.id = s.room_id
                 ORDER BY f.full_name, s.day_of_week, s.start_time"
            )->fetchAll();
            break;
        case 'faculty_load_summary':
            $title = 'Faculty Load Summary';
            $columns = ['Faculty', 'College', 'Max Hours/Day', 'Total Weekly Hours'];
            $rows = db()->query(
                "SELECT f.full_name AS faculty_name, COALESCE(c.college_code, 'Unassigned') AS college_code,
                        f.max_hours_per_day,
                        ROUND(SUM(TIMESTAMPDIFF(MINUTE, s.start_time, s.end_time)) / 60, 2) AS total_weekly_hours
                 FROM faculty f
                 LEFT JOIN colleges c ON c.id = f.college_id
                 LEFT JOIN schedules s ON s.faculty_id = f.id
                 GROUP BY f.id
                 ORDER BY total_weekly_hours DESC, f.full_name"
            )->fetchAll();
            break;
        case 'faculty_availability':
            $title = 'Faculty Availability Report';
            $columns = ['Faculty', 'Max Hours/Day', 'Estimated Weekly Capacity', 'Assigned Weekly Hours', 'Availability'];
            $rows = db()->query(
                "SELECT f.full_name AS faculty_name, f.max_hours_per_day,
                        (f.max_hours_per_day * 5) AS weekly_capacity,
                        ROUND(COALESCE(SUM(TIMESTAMPDIFF(MINUTE, s.start_time, s.end_time)), 0) / 60, 2) AS assigned_weekly_hours,
                        CASE
                            WHEN ROUND(COALESCE(SUM(TIMESTAMPDIFF(MINUTE, s.start_time, s.end_time)), 0) / 60, 2) >= (f.max_hours_per_day * 5) THEN 'Fully Loaded'
                            WHEN ROUND(COALESCE(SUM(TIMESTAMPDIFF(MINUTE, s.start_time, s.end_time)), 0) / 60, 2) >= (f.max_hours_per_day * 4) THEN 'Near Capacity'
                            ELSE 'Available'
                        END AS availability_status
                 FROM faculty f
                 LEFT JOIN schedules s ON s.faculty_id = f.id
                 GROUP BY f.id
                 ORDER BY assigned_weekly_hours DESC"
            )->fetchAll();
            break;
        case 'total_faculty_per_college':
            $title = 'Total Faculty per College';
            $columns = ['College Code', 'College Name', 'Total Faculty'];
            $rows = db()->query(
                "SELECT c.college_code, c.college_name, COUNT(f.id) AS total_faculty
                 FROM colleges c
                 LEFT JOIN faculty f ON f.college_id = c.id
                 GROUP BY c.id
                 ORDER BY c.college_code"
            )->fetchAll();
            break;
        case 'faculty_load_distribution':
            $title = 'Faculty Teaching Load Distribution';
            $columns = ['Load Band', 'Faculty Count'];
            $rows = db()->query(
                "SELECT
                    CASE
                        WHEN load_hours < 6 THEN 'Under 6 hrs/week'
                        WHEN load_hours < 12 THEN '6-12 hrs/week'
                        WHEN load_hours < 18 THEN '12-18 hrs/week'
                        ELSE '18+ hrs/week'
                    END AS load_band,
                    COUNT(*) AS faculty_count
                 FROM (
                    SELECT f.id,
                           ROUND(COALESCE(SUM(TIMESTAMPDIFF(MINUTE, s.start_time, s.end_time)),0) / 60, 2) AS load_hours
                    FROM faculty f
                    LEFT JOIN schedules s ON s.faculty_id = f.id
                    GROUP BY f.id
                 ) t
                 GROUP BY load_band
                 ORDER BY MIN(load_hours)"
            )->fetchAll();
            break;
        case 'faculty_per_course':
            $title = 'Faculty per Course';
            $columns = ['Course Code', 'Course Name', 'Assigned Faculty'];
            $rows = db()->query(
                "SELECT c.course_code, c.course_name, COUNT(DISTINCT s.faculty_id) AS assigned_faculty
                 FROM courses c
                 LEFT JOIN schedules s ON s.course_id = c.id
                 GROUP BY c.id
                 ORDER BY assigned_faculty DESC, c.course_code"
            )->fetchAll();
            break;
        case 'faculty_room_assignment':
            $title = 'Faculty Room Assignment';
            $columns = ['Room', 'Faculty', 'Assignments'];
            $rows = db()->query(
                "SELECT r.room_code, f.full_name AS faculty_name, COUNT(*) AS assignments
                 FROM schedules s
                 INNER JOIN rooms r ON r.id = s.room_id
                 INNER JOIN faculty f ON f.id = s.faculty_id
                 GROUP BY r.id, f.id
                 ORDER BY r.room_code, assignments DESC"
            )->fetchAll();
            break;
        case 'college_faculty_list':
            $title = 'College Faculty List';
            $columns = ['College', 'Faculty ID', 'Name', 'Department/Program', 'Employment Status', 'Status'];
            if ($collegeId > 0) {
                $st = db()->prepare(
                    "SELECT CONCAT(c.college_code, ' - ', c.college_name) AS college_label,
                            f.faculty_id, f.full_name, f.department,
                            {$employmentStatusExpr} AS employment_status,
                            f.status
                     FROM faculty f
                     INNER JOIN colleges c ON c.id = f.college_id
                     WHERE f.college_id = ?
                     ORDER BY f.full_name"
                );
                $st->execute([$collegeId]);
                $rows = $st->fetchAll();
            }
            break;
    }

    return ['title' => $title, 'columns' => $columns, 'rows' => $rows];
}

$reportCatalog = [
    'FACULTY REPORTS' => [
        'master_faculty_list' => 'Master Faculty List (All Colleges)',
        'faculty_per_college' => 'Faculty per College',
        'faculty_per_department' => 'Faculty per Department',
        'faculty_per_program' => 'Faculty per Program',
        'faculty_per_year_level' => 'Faculty per Year Level',
        'active_inactive_faculty' => 'Active/Inactive Faculty',
    ],
    'SCHEDULE REPORTS' => [
        'faculty_weekly_schedule' => 'Faculty Weekly Schedule (Individual)',
        'faculty_daily_schedule' => 'Faculty Daily Schedule',
        'all_faculty_schedules' => 'All Faculty Schedules (Consolidated)',
        'faculty_load_summary' => 'Faculty Load Summary',
        'faculty_availability' => 'Faculty Availability Report',
    ],
    'SUMMARY REPORTS' => [
        'total_faculty_per_college' => 'Total Faculty per College',
        'faculty_load_distribution' => 'Faculty Teaching Load Distribution',
        'faculty_per_course' => 'Faculty per Course',
        'faculty_room_assignment' => 'Faculty Room Assignment',
    ],
    'COLLEGE REPORTS' => [
        'college_faculty_list' => 'College Faculty List',
    ],
];

$flatCatalog = [];
foreach ($reportCatalog as $group => $items) {
    foreach ($items as $key => $label) {
        $flatCatalog[$key] = $label;
    }
}

$report = (string) ($_GET['report'] ?? 'master_faculty_list');
if (!isset($flatCatalog[$report])) {
    $report = 'master_faculty_list';
}
$collegeId = (int) ($_GET['college_id'] ?? 0);
$facultyId = (int) ($_GET['faculty_id'] ?? 0);
$day = (string) ($_GET['day'] ?? 'Monday');
$output = (string) ($_GET['output'] ?? '');

$colleges = db()->query('SELECT id, college_code, college_name FROM colleges ORDER BY college_code')->fetchAll();
$facultyFilterList = db()->query(
    "SELECT f.id, f.full_name, COALESCE(c.college_code, 'N/A') AS college_code
     FROM faculty f
     LEFT JOIN colleges c ON c.id = f.college_id
     ORDER BY f.full_name"
)->fetchAll();

$reportPayload = build_admin_report($report, $collegeId, $facultyId, $day);

if ($output === 'excel') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="admin_report_' . $report . '_' . date('Ymd_His') . '.csv"');
    $fp = fopen('php://output', 'w');
    if ($fp !== false) {
        fputcsv($fp, [$reportPayload['title']]);
        fputcsv($fp, []);
        fputcsv($fp, $reportPayload['columns']);
        foreach ($reportPayload['rows'] as $r) {
            fputcsv($fp, array_values($r));
        }
        fclose($fp);
    }
    exit;
}

$pageTitle = 'Admin Print Module';
require_once __DIR__ . '/includes/header.php';
?>
<div class="report-hub">
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <h1 class="h3 mb-0"><i class="fa-solid fa-chart-column me-2 text-primary"></i>Admin Print Module</h1>
    <span class="badge text-bg-primary px-3 py-2">Improved Reports Center</span>
</div>

<form method="get" class="card shadow-sm mb-4 no-print">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-lg-6">
                <label class="form-label">Report Type</label>
                <select name="report" class="form-select">
                    <?php foreach ($reportCatalog as $group => $items): ?>
                        <optgroup label="<?= htmlspecialchars($group) ?>">
                            <?php foreach ($items as $key => $label): ?>
                                <option value="<?= htmlspecialchars($key) ?>" <?= $report === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-3">
                <label class="form-label">College Filter</label>
                <select name="college_id" class="form-select">
                    <option value="0">All / Not selected</option>
                    <?php foreach ($colleges as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= $collegeId === (int) $c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['college_code'] . ' - ' . $c['college_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-3">
                <label class="form-label">Faculty Filter</label>
                <select name="faculty_id" class="form-select">
                    <option value="0">All / Not selected</option>
                    <?php foreach ($facultyFilterList as $f): ?>
                        <option value="<?= (int) $f['id'] ?>" <?= $facultyId === (int) $f['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($f['full_name'] . ' (' . $f['college_code'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-3">
                <label class="form-label">Day (Daily report)</label>
                <select name="day" class="form-select">
                    <?php foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $d): ?>
                        <option value="<?= $d ?>" <?= $day === $d ? 'selected' : '' ?>><?= $d ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-9 d-flex flex-wrap gap-2 align-items-end">
                <button type="submit" class="btn btn-primary"<?= app_tooltip_attr('Builds the full report with your selected filters and options.') ?>><i class="fa-solid fa-gears me-1"></i>Generate Report</button>
                <button type="submit" class="btn btn-outline-primary"<?= app_tooltip_attr('Shows an on-screen preview before printing or exporting.') ?>><i class="fa-regular fa-eye me-1"></i>Preview</button>
                <button type="button" class="btn btn-outline-secondary" onclick="window.print()"<?= app_tooltip_attr('Opens the print dialog for the current report view.') ?>><i class="fa-solid fa-print me-1"></i>Print</button>
                <button type="button" class="btn btn-outline-secondary" onclick="window.print()"<?= app_tooltip_attr('Same as Print—use your browser’s Save as PDF for a PDF file.') ?>><i class="fa-regular fa-file-pdf me-1"></i>Export to PDF</button>
                <button type="submit" class="btn btn-outline-success" name="output" value="excel"<?= app_tooltip_attr('Downloads the report data as a spreadsheet file.') ?>><i class="fa-regular fa-file-excel me-1"></i>Export to Excel</button>
            </div>
        </div>
    </div>
</form>

<div class="row g-3 mb-4 no-print">
    <?php foreach ($reportCatalog as $group => $items): ?>
        <div class="col-xl-3 col-md-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white"><strong><?= htmlspecialchars($group) ?></strong></div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($items as $key => $label): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span><?= htmlspecialchars($label) ?></span>
                            <a class="btn btn-sm btn-outline-primary" href="?report=<?= urlencode($key) ?>&college_id=<?= (int) $collegeId ?>&faculty_id=<?= (int) $facultyId ?>&day=<?= urlencode($day) ?>"<?= app_tooltip_attr('Loads this report type with your current college, faculty, and day filters.') ?>>Use</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <strong><?= htmlspecialchars($reportPayload['title']) ?></strong>
        <span class="small text-muted"><?= count($reportPayload['rows']) ?> row(s)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-striped mb-0">
            <thead class="table-light">
                <tr>
                    <?php foreach ($reportPayload['columns'] as $col): ?>
                        <th><?= htmlspecialchars($col) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php if ($reportPayload['rows'] === []): ?>
                    <tr><td colspan="<?= max(1, count($reportPayload['columns'])) ?>" class="text-muted p-3">No data for this report/filter selection.</td></tr>
                <?php else: ?>
                    <?php foreach ($reportPayload['rows'] as $row): ?>
                        <tr>
                            <?php foreach (array_values($row) as $value): ?>
                                <td><?= htmlspecialchars((string) $value) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

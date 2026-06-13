<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_role(['admin', 'dean', 'program_chair', 'gened', 'faculty']);
$role = (string) ($_SESSION['role'] ?? '');
$collegeId = current_college_id();
$programScope = is_program_chair() ? program_scope_or_fail() : null;
$facultySelfId = isset($_SESSION['faculty_id']) ? (int) $_SESSION['faculty_id'] : 0;

$sem = trim((string) ($_GET['semester'] ?? ''));
$sy = trim((string) ($_GET['school_year'] ?? ''));
$facultySearch = trim((string) ($_GET['faculty_search'] ?? ''));
$adminCollegeId = (int) ($_GET['college_id'] ?? 0);

$collegeFilterOptions = [];
if ($role === 'admin') {
    $collegeFilterOptions = db()->query(
        'SELECT id, college_code, college_name FROM colleges ORDER BY college_code'
    )->fetchAll();
}

$hasLectureUnits = db_column_exists('courses', 'lecture_units');
$hasLaboratoryUnits = db_column_exists('courses', 'laboratory_units');
$hasCourseLabFlag = db_column_exists('courses', 'is_laboratory');
$hasIsGenedCourse = db_column_exists('courses', 'is_gened');

$courseUnitsSelect = ', c.units AS course_units_total';
if ($hasLectureUnits) {
    $courseUnitsSelect .= ', c.lecture_units';
}
if ($hasLaboratoryUnits) {
    $courseUnitsSelect .= ', c.laboratory_units';
}
$courseLabSelect = $hasCourseLabFlag ? ', c.is_laboratory' : '';

$sql = "SELECT s.*, f.full_name AS faculty_name, f.department AS fac_dept, c.course_code, c.course_name, c.department AS course_dept, r.room_code, r.type AS room_type
        {$courseUnitsSelect}
        {$courseLabSelect}
        FROM schedules s
        INNER JOIN faculty f ON f.id = s.faculty_id
        INNER JOIN courses c ON c.id = s.course_id
        INNER JOIN rooms r ON r.id = s.room_id
        WHERE 1=1";
$params = [];
if ($role === 'dean' && $collegeId) {
    $sql .= ' AND s.college_id = ?';
    $params[] = $collegeId;
} elseif ($programScope !== null && $collegeId) {
    $sql .= ' AND s.college_id = ? AND c.department = ?';
    $params[] = $collegeId;
    $params[] = $programScope;
} elseif ($role === 'faculty' && $facultySelfId > 0) {
    $sql .= ' AND s.faculty_id = ?';
    $params[] = $facultySelfId;
} elseif ($role === 'admin') {
    if ($adminCollegeId > 0) {
        $sql .= ' AND s.college_id = ?';
        $params[] = $adminCollegeId;
    }
}
if ($role === 'gened') {
    if ($hasIsGenedCourse) {
        $sql .= ' AND COALESCE(c.is_gened, 0) = 1';
    } else {
        $sql .= ' AND c.department = ?';
        $params[] = 'General Education';
    }
}
if ($facultySearch !== '' && $role !== 'faculty') {
    $sql .= ' AND (f.full_name LIKE ? OR f.faculty_id LIKE ?)';
    $params[] = '%' . $facultySearch . '%';
    $params[] = '%' . $facultySearch . '%';
}
if ($sem !== '') {
    $sql .= ' AND s.semester = ?';
    $params[] = $sem;
}
if ($sy !== '') {
    $sql .= ' AND s.school_year = ?';
    $params[] = $sy;
}
$sql .= ' ORDER BY f.full_name, s.start_time';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$sems = db()->query('SELECT DISTINCT semester FROM schedules ORDER BY semester')->fetchAll(PDO::FETCH_COLUMN);
$years = db()->query('SELECT DISTINCT school_year FROM schedules ORDER BY school_year DESC')->fetchAll(PDO::FETCH_COLUMN);

$byFaculty = [];
foreach ($rows as $r) {
    $k = $r['faculty_name'];
    if (!isset($byFaculty[$k])) {
        $byFaculty[$k] = [];
    }
    $byFaculty[$k][] = $r;
}

$formatTime12h = static function (?string $time): string {
    $raw = substr((string) $time, 0, 5);
    $dt = DateTime::createFromFormat('H:i', $raw);
    return $dt ? $dt->format('g:i A') : $raw;
};

$sessionHoursBetween = static function (?string $start, ?string $end): float {
    $rawS = substr((string) $start, 0, 8);
    $rawE = substr((string) $end, 0, 8);
    $ds = DateTime::createFromFormat('H:i:s', $rawS) ?: DateTime::createFromFormat('H:i', substr((string) $start, 0, 5));
    $de = DateTime::createFromFormat('H:i:s', $rawE) ?: DateTime::createFromFormat('H:i', substr((string) $end, 0, 5));
    if (!$ds || !$de) {
        return 0.0;
    }
    $secs = $de->getTimestamp() - $ds->getTimestamp();
    if ($secs <= 0) {
        return 0.0;
    }
    return round($secs / 3600, 2);
};

$dayCount = static function (?string $days): int {
    $parts = array_filter(array_map('trim', explode(',', (string) $days)), static fn(string $d): bool => $d !== '');
    return max(1, count($parts));
};

$courseTotalUnits = static function (array $row) use ($hasLectureUnits, $hasLaboratoryUnits): float {
    if ($hasLectureUnits && $hasLaboratoryUnits) {
        return (float) ($row['lecture_units'] ?? 0) + (float) ($row['laboratory_units'] ?? 0);
    }
    return (float) ($row['course_units_total'] ?? 0);
};

$isLaboratoryRow = static function (array $row): bool {
    if (strtolower((string) ($row['room_type'] ?? '')) === 'laboratory') {
        return true;
    }
    if (!empty($row['is_laboratory']) && (float) ($row['laboratory_units'] ?? 0) > 0) {
        return true;
    }
    return false;
};

/** One row per scheduled offering (course may have multiple time slots). */
$scheduleOfferingKey = static function (array $row): string {
    return (int) ($row['faculty_id'] ?? 0) . '|'
        . (int) ($row['course_id'] ?? 0) . '|'
        . (string) ($row['semester'] ?? '') . '|'
        . (string) ($row['school_year'] ?? '') . '|'
        . (int) ($row['college_id'] ?? 0);
};

$mergeOfferingRows = static function (array $group) use (
    $courseTotalUnits,
    $sessionHoursBetween,
    $dayCount,
    $isLaboratoryRow,
    $formatTime12h
): array {
    $r0 = $group[0];
    $lecHours = 0.0;
    $labHours = 0.0;
    $dayParts = [];
    $timeParts = [];
    $roomParts = [];
    $roomSeen = [];
    foreach ($group as $r) {
        $hours = $sessionHoursBetween((string) ($r['start_time'] ?? ''), (string) ($r['end_time'] ?? ''))
            * $dayCount((string) ($r['day_of_week'] ?? ''));
        if ($isLaboratoryRow($r)) {
            $labHours += $hours;
        } else {
            $lecHours += $hours;
        }
        $dayParts[] = str_replace(',', ', ', (string) ($r['day_of_week'] ?? ''));
        $timeParts[] = $formatTime12h((string) ($r['start_time'] ?? ''))
            . ' – '
            . $formatTime12h((string) ($r['end_time'] ?? ''));
        $roomCode = trim((string) ($r['room_code'] ?? ''));
        if ($roomCode !== '' && !isset($roomSeen[$roomCode])) {
            $roomSeen[$roomCode] = true;
            $roomParts[] = $roomCode;
        }
    }

    return [
        'course_code' => (string) ($r0['course_code'] ?? ''),
        'course_name' => (string) ($r0['course_name'] ?? ''),
        'units' => $courseTotalUnits($r0),
        'lec_hours' => $lecHours,
        'lab_hours' => $labHours,
        'total_hours' => $lecHours + $labHours,
        'day_display' => implode('; ', array_values(array_unique($dayParts))),
        'time_display' => implode('; ', array_values(array_unique($timeParts))),
        'room_display' => $roomParts !== [] ? implode('; ', $roomParts) : '—',
    ];
};

$pageTitle = 'Schedule reports';
require_once __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <h1 class="h3 mb-0"><i class="fa-solid fa-file-lines me-2 text-primary"></i>Schedule reports</h1>
    <button type="button" class="btn btn-outline-secondary no-print" onclick="window.print()"<?= app_tooltip_attr('Prints the report using your browser. Use this for a clean paper or PDF copy.') ?>><i class="fa-solid fa-print me-1"></i>Print</button>
</div>
<?php if ($programScope !== null): ?>
    <p class="text-muted">Program scope: <strong><?= htmlspecialchars($programScope) ?></strong></p>
<?php endif; ?>

<form class="row g-2 mb-4 no-print align-items-end" method="get">
    <div class="col-md-3">
        <label class="form-label small mb-0">Semester</label>
        <select name="semester" class="form-select form-select-sm">
            <option value="">All semesters</option>
            <?php foreach ($sems as $s): ?>
                <option value="<?= htmlspecialchars((string) $s) ?>" <?= $sem === (string) $s ? 'selected' : '' ?>><?= htmlspecialchars((string) $s) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label small mb-0">School year</label>
        <select name="school_year" class="form-select form-select-sm">
            <option value="">All years</option>
            <?php foreach ($years as $y): ?>
                <option value="<?= htmlspecialchars((string) $y) ?>" <?= $sy === (string) $y ? 'selected' : '' ?>><?= htmlspecialchars((string) $y) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <button type="submit" class="btn btn-outline-primary btn-sm"<?= app_tooltip_attr('Reloads the report with the selected semester, year, and filters. Use this after changing criteria.') ?>>Generate</button>
        <a href="reports.php" class="btn btn-outline-secondary btn-sm"<?= app_tooltip_attr('Clears filters and shows the default report view again.') ?>>Clear</a>
    </div>
    <?php if ($role !== 'faculty'): ?>
        <div class="col-md-3">
            <label class="form-label small mb-0">Faculty search</label>
            <input type="text" name="faculty_search" class="form-control form-control-sm" placeholder="Name or faculty ID" value="<?= htmlspecialchars($facultySearch) ?>">
        </div>
    <?php endif; ?>
    <?php if ($role === 'admin'): ?>
        <div class="col-md-3">
            <label class="form-label small mb-0">College</label>
            <select name="college_id" class="form-select form-select-sm">
                <option value="0">All colleges</option>
                <?php foreach ($collegeFilterOptions as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= $adminCollegeId === (int) $c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['college_code'] . ' - ' . $c['college_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
</form>

<?php if (($facultySearch !== '' && $role !== 'faculty') || ($role === 'admin' && $adminCollegeId > 0)): ?>
    <div class="small text-muted mb-3">
        Active filters:
        <?php if ($facultySearch !== '' && $role !== 'faculty'): ?><span class="badge text-bg-light border me-1">Faculty: <?= htmlspecialchars($facultySearch) ?></span><?php endif; ?>
        <?php if ($role === 'admin' && $adminCollegeId > 0):
            $selectedCollege = '';
            foreach ($collegeFilterOptions as $c) {
                if ((int) $c['id'] === $adminCollegeId) {
                    $selectedCollege = (string) ($c['college_code'] . ' - ' . $c['college_name']);
                    break;
                }
            }
        ?>
            <span class="badge text-bg-light border">College: <?= htmlspecialchars($selectedCollege) ?></span>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php foreach ($byFaculty as $fname => $items): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white">
            <strong><?= htmlspecialchars($fname) ?></strong>
            <span class="small opacity-75 ms-2"><?= htmlspecialchars($items[0]['fac_dept'] ?? '') ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Course</th>
                            <th class="text-end">Units</th>
                            <th class="text-end">LEC hrs</th>
                            <th class="text-end">LAB hrs</th>
                            <th class="text-end">Total hrs</th>
                            <th>Days</th>
                            <th>Time</th>
                            <th>Room</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $facultyTotalUnits = 0.0;
                        $facultyLecHours = 0.0;
                        $facultyLabHours = 0.0;
                        $offeringGroups = [];
                        foreach ($items as $r) {
                            $offeringGroups[$scheduleOfferingKey($r)][] = $r;
                        }
                        ?>
                        <?php foreach ($offeringGroups as $group): ?>
                            <?php
                            $merged = $mergeOfferingRows($group);
                            $facultyTotalUnits += $merged['units'];
                            $facultyLecHours += $merged['lec_hours'];
                            $facultyLabHours += $merged['lab_hours'];
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($merged['course_code']) ?> — <?= htmlspecialchars($merged['course_name']) ?></td>
                                <td class="text-end"><?= htmlspecialchars(number_format($merged['units'], 1)) ?></td>
                                <td class="text-end"><?= htmlspecialchars(number_format($merged['lec_hours'], 1)) ?></td>
                                <td class="text-end"><?= htmlspecialchars(number_format($merged['lab_hours'], 1)) ?></td>
                                <td class="text-end"><?= htmlspecialchars(number_format($merged['total_hours'], 1)) ?></td>
                                <td><?= htmlspecialchars($merged['day_display']) ?></td>
                                <td><?= htmlspecialchars($merged['time_display']) ?></td>
                                <td><?= htmlspecialchars($merged['room_display']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="table-light fw-semibold">
                            <td class="text-end">TOTAL</td>
                            <td class="text-end"><?= htmlspecialchars(number_format($facultyTotalUnits, 1)) ?></td>
                            <td class="text-end"><?= htmlspecialchars(number_format($facultyLecHours, 1)) ?></td>
                            <td class="text-end"><?= htmlspecialchars(number_format($facultyLabHours, 1)) ?></td>
                            <td class="text-end"><?= htmlspecialchars(number_format($facultyLecHours + $facultyLabHours, 1)) ?></td>
                            <td colspan="3"></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php if ($byFaculty === []): ?>
    <p class="text-muted">No data for the selected filters.</p>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

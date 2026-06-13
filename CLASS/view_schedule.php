<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_role(['admin', 'dean', 'program_chair', 'gened', 'faculty']);
$role = (string) ($_SESSION['role'] ?? '');
$collegeId = current_college_id();
$programScope = is_program_chair() ? program_scope_or_fail() : null;
$facultySelfId = isset($_SESSION['faculty_id']) ? (int) $_SESSION['faculty_id'] : 0;
$facultyCollegeId = ($role === 'faculty' && $facultySelfId > 0) ? faculty_college_id($facultySelfId) : null;

$dept = trim((string) ($_GET['dept'] ?? ''));
$sem = trim((string) ($_GET['semester'] ?? ''));
$sy = trim((string) ($_GET['school_year'] ?? ''));
$facultyFilter = (int) ($_GET['faculty_id'] ?? 0);
$roomFilter = (int) ($_GET['room_id'] ?? 0);
$collegeFilter = (int) ($_GET['college_id'] ?? 0);

$hasOnlineUrlCol = db_column_exists('schedules', 'online_class_url');
$hasLiveAtCol = db_column_exists('schedules', 'online_live_at');
$hasGeTargetsTable = db_table_exists('ge_schedule_targets');
$targetJoin = $hasGeTargetsTable ? ' LEFT JOIN ge_schedule_targets gst ON gst.schedule_id = s.id' : '';

$sql = "SELECT DISTINCT s.*, f.full_name AS faculty_name, c.course_code, c.course_name, r.room_code, r.room_name
        FROM schedules s
        INNER JOIN faculty f ON f.id = s.faculty_id
        INNER JOIN courses c ON c.id = s.course_id
        INNER JOIN rooms r ON r.id = s.room_id
        {$targetJoin}
        WHERE 1=1";
$params = [];
if ($role === 'faculty' && $facultySelfId > 0) {
    $sql .= ' AND s.faculty_id = ?';
    $params[] = $facultySelfId;
    if ($facultyCollegeId !== null) {
        $sql .= ' AND s.college_id = ?';
        $params[] = $facultyCollegeId;
    }
} elseif ($programScope !== null && $collegeId) {
    if ($hasGeTargetsTable) {
        $sql .= ' AND s.college_id = ? AND (c.department = ? OR gst.program_name = ?)';
        $params[] = $collegeId;
        $params[] = $programScope;
        $params[] = $programScope;
    } else {
        $sql .= ' AND s.college_id = ? AND c.department = ?';
        $params[] = $collegeId;
        $params[] = $programScope;
    }
} elseif (is_dean() && $collegeId) {
    $sql .= ' AND s.college_id = ?';
    $params[] = $collegeId;
}
if ($collegeFilter > 0 && !is_dean() && $role !== 'faculty') {
    $sql .= ' AND s.college_id = ?';
    $params[] = $collegeFilter;
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
if ($facultyFilter > 0) {
    $sql .= ' AND s.faculty_id = ?';
    $params[] = $facultyFilter;
}
if ($roomFilter > 0) {
    $sql .= ' AND s.room_id = ?';
    $params[] = $roomFilter;
}
$sql .= ' ORDER BY s.start_time, f.full_name';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$schedules = $stmt->fetchAll();

$formatTime12h = static function (?string $time): string {
    $raw = substr((string) $time, 0, 5);
    $dt = DateTime::createFromFormat('H:i', $raw);
    return $dt ? $dt->format('g:i A') : $raw;
};

/** Faculty clicked “Go live” within the last 2 hours (shown to dean on weekly view). */
$scheduleShowsLive = static function (?string $liveAt): bool {
    if ($liveAt === null || $liveAt === '') {
        return false;
    }
    $t = strtotime($liveAt);
    if ($t === false) {
        return false;
    }
    return (time() - $t) <= 2 * 3600;
};

$byDay = [];
foreach (schedule_days_list() as $d) {
    $byDay[$d] = [];
}
foreach ($schedules as $s) {
    foreach (parse_day_set((string) $s['day_of_week']) as $d) {
        if (isset($byDay[$d])) {
            $byDay[$d][] = $s;
        }
    }
}
foreach ($byDay as $d => $list) {
    usort($byDay[$d], static function ($a, $b) {
        return strcmp((string) $a['start_time'], (string) $b['start_time']);
    });
}

$depts = ($role === 'dean' || $role === 'program_chair') && $collegeId
    ? (function () use ($collegeId) {
        $st = db()->prepare('SELECT DISTINCT department FROM faculty WHERE department != "" AND college_id=? ORDER BY department');
        $st->execute([$collegeId]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    })()
    : db()->query('SELECT DISTINCT department FROM faculty WHERE department != "" ORDER BY department')->fetchAll(PDO::FETCH_COLUMN);
$depts = $programScope !== null ? [$programScope] : $depts;
$sems = db()->query('SELECT DISTINCT semester FROM schedules ORDER BY semester')->fetchAll(PDO::FETCH_COLUMN);
$years = db()->query('SELECT DISTINCT school_year FROM schedules ORDER BY school_year DESC')->fetchAll(PDO::FETCH_COLUMN);
if (($role === 'dean' || $role === 'program_chair') && $collegeId) {
    $sql = "SELECT id, full_name FROM faculty WHERE status='active' AND college_id=?";
    $params = [$collegeId];
    if ($programScope !== null) {
        $sql .= " AND department=?";
        $params[] = $programScope;
    }
    $sql .= " ORDER BY full_name";
    $st = db()->prepare($sql);
    $st->execute($params);
    $facultyList = $st->fetchAll();
    $st = db()->prepare("SELECT id, room_code FROM rooms WHERE status IN ('available','tba') AND college_id=? ORDER BY room_code");
    $st->execute([$collegeId]);
    $roomList = $st->fetchAll();
} elseif ($role === 'faculty') {
    $facultyList = [];
    $roomList = [];
} else {
    $facultyList = db()->query("SELECT id, full_name FROM faculty WHERE status='active' ORDER BY full_name")->fetchAll();
    $roomList = db()->query("SELECT id, room_code FROM rooms WHERE status IN ('available','tba') ORDER BY room_code")->fetchAll();
}
$collegeList = db()->query("SELECT id, college_code, college_name FROM colleges WHERE status='active' ORDER BY college_code")->fetchAll();

$pageTitle = 'Weekly schedule view';
require_once __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0"><i class="fa-solid fa-calendar-week me-2 text-primary"></i>Weekly schedule</h1>
    <button type="button" class="btn btn-outline-secondary no-print" onclick="window.print()"<?= app_tooltip_attr('Opens the print dialog for this weekly view. Use this for a paper copy or PDF without sidebar clutter.') ?>><i class="fa-solid fa-print me-1"></i>Print</button>
</div>

<form class="row g-2 mb-4 no-print align-items-end" method="get">
    <div class="col-6 col-md-2">
        <label class="form-label small mb-0">Program</label>
        <select name="dept" class="form-select form-select-sm">
            <option value="">All</option>
            <?php foreach ($depts as $d): ?>
                <option value="<?= htmlspecialchars((string) $d) ?>" <?= $dept === (string) $d ? 'selected' : '' ?>><?= htmlspecialchars((string) $d) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label small mb-0">Semester</label>
        <select name="semester" class="form-select form-select-sm">
            <option value="">All</option>
            <?php foreach ($sems as $s): ?>
                <option value="<?= htmlspecialchars((string) $s) ?>" <?= $sem === (string) $s ? 'selected' : '' ?>><?= htmlspecialchars((string) $s) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label small mb-0">School year</label>
        <select name="school_year" class="form-select form-select-sm">
            <option value="">All</option>
            <?php foreach ($years as $y): ?>
                <option value="<?= htmlspecialchars((string) $y) ?>" <?= $sy === (string) $y ? 'selected' : '' ?>><?= htmlspecialchars((string) $y) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php if ($role !== 'faculty' && $programScope === null && ($role === 'admin' || $role === 'gened')): ?>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-0">College</label>
            <select name="college_id" class="form-select form-select-sm">
                <option value="0">All colleges</option>
                <?php foreach ($collegeList as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= $collegeFilter === (int) $c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['college_code']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
    <?php if ($role !== 'faculty'): ?>
        <div class="col-6 col-md-3">
            <label class="form-label small mb-0">Faculty</label>
            <select name="faculty_id" class="form-select form-select-sm">
                <option value="0">All faculty</option>
                <?php foreach ($facultyList as $f): ?>
                    <option value="<?= (int) $f['id'] ?>" <?= $facultyFilter === (int) $f['id'] ? 'selected' : '' ?>><?= htmlspecialchars($f['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small mb-0">Room</label>
            <select name="room_id" class="form-select form-select-sm">
                <option value="0">All rooms</option>
                <?php foreach ($roomList as $r): ?>
                    <option value="<?= (int) $r['id'] ?>" <?= $roomFilter === (int) $r['id'] ? 'selected' : '' ?>><?= htmlspecialchars($r['room_code']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
    <div class="col-12">
        <button type="submit" class="btn btn-outline-primary btn-sm"<?= app_tooltip_attr('Reloads the grid using program, term, faculty, and room filters. Use this after narrowing what you need to review.') ?>>Apply filters</button>
        <a href="view_schedule.php" class="btn btn-outline-secondary btn-sm"<?= app_tooltip_attr('Clears filters and shows the default weekly view. Use this when you want to start selection over.') ?>>Reset</a>
    </div>
</form>

<div class="table-responsive">
    <table class="table table-bordered bg-body schedule-weekly">
        <thead class="table-primary">
            <tr>
                <?php foreach (schedule_days_list() as $day): ?>
                    <th class="text-center" style="min-width:140px"><?= htmlspecialchars($day) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <tr>
                <?php
                foreach (schedule_days_list() as $day):
                    ?>
                    <td class="align-top schedule-cell p-2">
                        <?php foreach ($byDay[$day] as $s): ?>
                            <?php $c = 'c' . ((int) $s['course_id'] % 6); ?>
                            <div class="schedule-block <?= $c ?>">
                                <div class="fw-semibold"><?= htmlspecialchars($formatTime12h((string) $s['start_time'])) ?> – <?= htmlspecialchars($formatTime12h((string) $s['end_time'])) ?></div>
                                <div><?= htmlspecialchars($s['course_code']) ?></div>
                                <div class="small text-muted"><?= htmlspecialchars($s['faculty_name']) ?></div>
                                <div class="small"><i class="fa-solid fa-door-open me-1"></i><?= htmlspecialchars($s['room_code']) ?></div>
                                <?php
                                $onlineUrl = $hasOnlineUrlCol ? trim((string) ($s['online_class_url'] ?? '')) : '';
                                $liveAtStr = $hasLiveAtCol ? ($s['online_live_at'] ?? null) : null;
                                $liveAtStr = $liveAtStr !== null && $liveAtStr !== '' ? (string) $liveAtStr : null;
                                $isLive = $hasLiveAtCol && $scheduleShowsLive($liveAtStr);
                                ?>
                                <?php if ($hasOnlineUrlCol && $onlineUrl !== ''): ?>
                                    <div class="mt-1 pt-1 border-top border-secondary-subtle small">
                                        <?php if ($hasLiveAtCol && $isLive): ?>
                                            <span class="badge bg-danger live-pulse-badge rounded-pill me-1"><i class="fa-solid fa-circle me-1" style="font-size:0.5rem;vertical-align:middle;"></i>LIVE</span>
                                            <a class="btn btn-sm btn-success text-white py-0 px-2" href="<?= htmlspecialchars($onlineUrl) ?>" target="_blank" rel="noopener noreferrer"<?= app_tooltip_attr('Opens the live online class while the instructor is broadcasting. Use this during scheduled class time.') ?>>Join online</a>
                                        <?php elseif ($hasLiveAtCol): ?>
                                            <span class="badge bg-secondary me-1">Not live</span>
                                            <span class="text-muted">Faculty has not gone live</span>
                                        <?php else: ?>
                                            <a class="btn btn-sm btn-outline-primary py-0 px-2" href="<?= htmlspecialchars($onlineUrl) ?>" target="_blank" rel="noopener noreferrer"<?= app_tooltip_attr('Opens the faculty’s online meeting link in a new tab. Use this to join the virtual room for this block.') ?>><i class="fa-solid fa-video me-1"></i>Online class</a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($byDay[$day] === []): ?>
                            <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                <?php endforeach; ?>
            </tr>
        </tbody>
    </table>
</div>

<p class="text-muted small mt-3 no-print">Use filters to show faculty-specific or room-specific schedules. <strong>LIVE</strong> appears when the instructor taps <em>Go live</em> on My Schedule (within the last 2 hours). Print uses a simplified layout.</p>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

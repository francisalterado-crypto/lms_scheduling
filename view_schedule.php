<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_role(['super_admin', 'admin', 'dean', 'program_chair', 'gened', 'faculty']);
$role = (string) ($_SESSION['role'] ?? '');
$collegeId = current_college_id();
$programScope = is_program_chair() ? program_scope_or_fail() : null;
$facultySelfId = isset($_SESSION['faculty_id']) ? (int) $_SESSION['faculty_id'] : 0;
$facultyCollegeId = ($role === 'faculty' && $facultySelfId > 0) ? faculty_college_id($facultySelfId) : null;

$hasCourseColors = ensure_faculty_course_colors_table();

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $role === 'faculty'
    && $facultySelfId > 0
    && (string) ($_POST['action'] ?? '') === 'save_course_color'
) {
    $redirectQs = [];
    foreach (['dept', 'semester', 'school_year'] as $qk) {
        $qv = trim((string) ($_POST[$qk] ?? $_GET[$qk] ?? ''));
        if ($qv !== '') {
            $redirectQs[$qk] = $qv;
        }
    }
    $redirectUrl = 'view_schedule.php' . ($redirectQs !== [] ? ('?' . http_build_query($redirectQs)) : '');
    try {
        save_faculty_course_color(
            $facultySelfId,
            (int) ($_POST['course_id'] ?? 0),
            (int) ($_POST['color_index'] ?? 0),
            $facultyCollegeId
        );
        $_SESSION['flash'] = 'Course color saved. Matching blocks on your weekly view use this color.';
    } catch (Throwable $e) {
        $_SESSION['flash'] = 'Error: ' . $e->getMessage();
    }
    header('Location: ' . $redirectUrl);
    exit;
}

$flash = '';
if (!empty($_SESSION['flash'])) {
    $flash = (string) $_SESSION['flash'];
    unset($_SESSION['flash']);
}

$dept = trim((string) ($_GET['dept'] ?? ''));
$sem = trim((string) ($_GET['semester'] ?? ''));
$sy = trim((string) ($_GET['school_year'] ?? ''));
$facultyFilter = (int) ($_GET['faculty_id'] ?? 0);
$roomFilter = (int) ($_GET['room_id'] ?? 0);
$collegeFilter = (int) ($_GET['college_id'] ?? 0);

$hasOnlineUrlCol = db_column_exists('schedules', 'online_class_url');
$hasLiveAtCol = db_column_exists('schedules', 'online_live_at');
$hasGeTargetsTable = db_table_exists('ge_schedule_targets');
$hasCourseIsGenedCol = db_column_exists('courses', 'is_gened');
$targetJoin = $hasGeTargetsTable ? ' LEFT JOIN ge_schedule_targets gst ON gst.schedule_id = s.id' : '';
$hasOcTable = db_table_exists('online_classrooms');
$hasSyllabusOnOc = $hasOcTable && db_column_exists('online_classrooms', 'syllabus_stored_name');
$ocJoin = $hasOcTable ? ' LEFT JOIN online_classrooms oc ON oc.schedule_id = s.id' : '';
$ocSelect = '';
if ($hasOcTable) {
    $ocSelect = ', oc.id AS oc_classroom_id';
    if ($hasSyllabusOnOc) {
        $ocSelect .= ', oc.syllabus_stored_name AS oc_syllabus_stored';
    }
}
$courseIsGenedSelect = $hasCourseIsGenedCol ? ', c.is_gened AS course_is_gened' : '';
$geTargetSelect = $hasGeTargetsTable ? ', gst.program_name AS ge_target_program' : '';

$hostCollegeJoin = ' LEFT JOIN colleges sched_col ON sched_col.id = s.college_id';
$hostCollegeSelect = ', sched_col.college_code AS host_college_code';

$sql = "SELECT DISTINCT s.*, f.full_name AS faculty_name, c.course_code, c.course_name, c.department AS course_department{$courseIsGenedSelect}{$geTargetSelect}, r.room_code, r.room_name{$hostCollegeSelect}{$ocSelect}
        FROM schedules s
        INNER JOIN faculty f ON f.id = s.faculty_id
        INNER JOIN courses c ON c.id = s.course_id
        INNER JOIN rooms r ON r.id = s.room_id
        {$hostCollegeJoin}
        {$targetJoin}
        {$ocJoin}
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
    $sql .= program_chair_schedule_scope_sql($collegeId, $programScope, $hasGeTargetsTable, $params);
} elseif (is_dean() && $collegeId) {
    $sql .= dean_schedule_scope_sql($collegeId, $hasCourseIsGenedCol, $params, $hasGeTargetsTable);
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

/** @var array<int, array<int, int>> $facultyColorMaps faculty_id => (course_id => color_index) */
$facultyColorMaps = [];
if ($hasCourseColors && $schedules) {
    $facultyIdsForColors = [];
    foreach ($schedules as $sRow) {
        $fid = (int) ($sRow['faculty_id'] ?? 0);
        if ($fid > 0) {
            $facultyIdsForColors[$fid] = true;
        }
    }
    $ids = array_keys($facultyIdsForColors);
    if ($ids !== []) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stColors = db()->prepare(
            "SELECT faculty_id, course_id, color_index
             FROM faculty_course_colors
             WHERE faculty_id IN ({$placeholders})"
        );
        $stColors->execute($ids);
        foreach ($stColors->fetchAll(PDO::FETCH_ASSOC) as $colorRow) {
            $fid = (int) $colorRow['faculty_id'];
            $cid = (int) $colorRow['course_id'];
            $facultyColorMaps[$fid][$cid] = max(0, min(5, (int) $colorRow['color_index']));
        }
    }
}

$facultyCourseLegend = [];
if ($role === 'faculty' && $facultySelfId > 0) {
    foreach ($schedules as $sRow) {
        $cid = (int) ($sRow['course_id'] ?? 0);
        if ($cid < 1 || isset($facultyCourseLegend[$cid])) {
            continue;
        }
        $facultyCourseLegend[$cid] = [
            'course_code' => (string) ($sRow['course_code'] ?? ''),
            'course_name' => (string) ($sRow['course_name'] ?? ''),
            'color_index' => (int) ($facultyColorMaps[$facultySelfId][$cid] ?? ($cid % 6)),
        ];
    }
    uasort($facultyCourseLegend, static function (array $a, array $b): int {
        return strcmp($a['course_code'], $b['course_code']);
    });
}
$colorPalette = schedule_color_palette();

$formatTime12h = static function (?string $time): string {
    $raw = substr((string) $time, 0, 5);
    $dt = DateTime::createFromFormat('H:i', $raw);
    return $dt ? $dt->format('g:i A') : $raw;
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

/** Hourly grid bounds so vacant slots are visible against the time axis. */
$gridStartMin = defined('TIME_MIN') ? time_to_minutes((string) TIME_MIN) : 7 * 60;
$gridEndMin = defined('TIME_MAX') ? time_to_minutes((string) TIME_MAX) : 21 * 60;
foreach ($schedules as $s) {
    $st = time_to_minutes(substr((string) $s['start_time'], 0, 8));
    $en = time_to_minutes(substr((string) $s['end_time'], 0, 8));
    if ($st < $gridStartMin) {
        $gridStartMin = $st;
    }
    if ($en > $gridEndMin) {
        $gridEndMin = $en;
    }
}
$gridStartHour = (int) floor($gridStartMin / 60);
$gridEndHour = (int) max($gridStartHour + 1, (int) ceil($gridEndMin / 60));
$timeSlots = [];
for ($h = $gridStartHour; $h < $gridEndHour; $h++) {
    $timeSlots[] = $h;
}

$scheduleOverlapsHour = static function (array $scheduleRow, int $hour): bool {
    $slotStart = $hour * 60;
    $slotEnd = ($hour + 1) * 60;
    $st = time_to_minutes(substr((string) $scheduleRow['start_time'], 0, 8));
    $en = time_to_minutes(substr((string) $scheduleRow['end_time'], 0, 8));
    return $st < $slotEnd && $en > $slotStart;
};

$scheduleStartsInHour = static function (array $scheduleRow, int $hour): bool {
    $st = time_to_minutes(substr((string) $scheduleRow['start_time'], 0, 8));
    return $st >= ($hour * 60) && $st < (($hour + 1) * 60);
};

/**
 * Merge a straight multi-hour class into one tall cell (rowspan) when the next
 * hour(s) are only a continuation of the same block (no other class begins there).
 * @var array<string, array<int, array{startHour:int, span:int}>> $rowspanPlans
 * @var array<string, array<int, int>> $coveredByRowspan day => hour => startHour that owns the rowspan
 */
$rowspanPlans = [];
$coveredByRowspan = [];
foreach (schedule_days_list() as $dayName) {
    $dayList = $byDay[$dayName] ?? [];
    foreach ($dayList as $schedRow) {
        $sid = (int) ($schedRow['id'] ?? 0);
        if ($sid < 1) {
            continue;
        }
        $overlapHours = [];
        foreach ($timeSlots as $hour) {
            if ($scheduleOverlapsHour($schedRow, $hour)) {
                $overlapHours[] = $hour;
            }
        }
        if (count($overlapHours) < 2) {
            continue;
        }
        $startHour = $overlapHours[0];
        $span = 1;
        for ($i = 1, $n = count($overlapHours); $i < $n; $i++) {
            if ($overlapHours[$i] !== $overlapHours[$i - 1] + 1) {
                break;
            }
            $nextHour = $overlapHours[$i];
            $hourClear = true;
            foreach ($dayList as $other) {
                if (!$scheduleOverlapsHour($other, $nextHour)) {
                    continue;
                }
                // Another class appears in this next hour but not at the start → stop merging here.
                if (!$scheduleOverlapsHour($other, $startHour)) {
                    $hourClear = false;
                    break;
                }
            }
            if (!$hourClear) {
                break;
            }
            $span++;
        }
        if ($span < 2) {
            continue;
        }
        // Only block if a different start-hour already claimed a continuation slot.
        $blocked = false;
        for ($h = $startHour + 1; $h < $startHour + $span; $h++) {
            if (isset($coveredByRowspan[$dayName][$h]) && $coveredByRowspan[$dayName][$h] !== $startHour) {
                $blocked = true;
                break;
            }
        }
        if ($blocked) {
            continue;
        }
        $rowspanPlans[$dayName][$sid] = ['startHour' => $startHour, 'span' => $span];
        for ($h = $startHour + 1; $h < $startHour + $span; $h++) {
            $coveredByRowspan[$dayName][$h] = $startHour;
        }
    }
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

<?php if ($flash !== ''): ?><?php render_information_popup((string) $flash); ?><?php endif; ?>

<?php if ($role === 'faculty' && $hasCourseColors && $facultyCourseLegend !== []): ?>
    <?php
    $firstCourseId = (int) array_key_first($facultyCourseLegend);
    $firstCourseColor = (int) ($facultyCourseLegend[$firstCourseId]['color_index'] ?? 0);
    ?>
    <div class="card border-0 shadow-sm mb-3 no-print course-color-panel">
        <div class="card-body py-3">
            <div class="mb-2">
                <h2 class="h6 mb-1"><i class="fa-solid fa-palette me-1 text-primary"></i>Course colors</h2>
                <p class="small text-muted mb-0">Choose a course and a color, then apply it to the weekly grid.</p>
            </div>
            <form method="post" class="row g-2 align-items-end course-color-form" id="course-color-form">
                <input type="hidden" name="action" value="save_course_color">
                <?php if ($dept !== ''): ?><input type="hidden" name="dept" value="<?= htmlspecialchars($dept) ?>"><?php endif; ?>
                <?php if ($sem !== ''): ?><input type="hidden" name="semester" value="<?= htmlspecialchars($sem) ?>"><?php endif; ?>
                <?php if ($sy !== ''): ?><input type="hidden" name="school_year" value="<?= htmlspecialchars($sy) ?>"><?php endif; ?>
                <div class="col-12 col-sm-5 col-md-4">
                    <label class="form-label small mb-0" for="course-color-course">Course</label>
                    <select id="course-color-course" name="course_id" class="form-select form-select-sm" required>
                        <?php foreach ($facultyCourseLegend as $courseId => $courseInfo): ?>
                            <option
                                value="<?= (int) $courseId ?>"
                                data-color-index="<?= (int) $courseInfo['color_index'] ?>"
                                <?= (int) $courseId === $firstCourseId ? 'selected' : '' ?>
                            >
                                <?= htmlspecialchars($courseInfo['course_code']) ?><?= $courseInfo['course_name'] !== '' ? ' — ' . htmlspecialchars($courseInfo['course_name']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-8 col-sm-4 col-md-3">
                    <label class="form-label small mb-0" for="course-color-index">Color</label>
                    <div class="d-flex align-items-center gap-2">
                        <span
                            id="course-color-preview"
                            class="course-color-preview"
                            style="--swatch-bg: <?= htmlspecialchars($colorPalette[$firstCourseColor]['bg'] ?? '#e2e8f0') ?>; --swatch-border: <?= htmlspecialchars($colorPalette[$firstCourseColor]['border'] ?? '#94a3b8') ?>;"
                            aria-hidden="true"
                        ></span>
                        <select id="course-color-index" name="color_index" class="form-select form-select-sm" required>
                            <?php foreach ($colorPalette as $swatch): ?>
                                <option
                                    value="<?= (int) $swatch['index'] ?>"
                                    data-bg="<?= htmlspecialchars($swatch['bg']) ?>"
                                    data-border="<?= htmlspecialchars($swatch['border']) ?>"
                                    <?= (int) $swatch['index'] === $firstCourseColor ? 'selected' : '' ?>
                                >
                                    <?= htmlspecialchars($swatch['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-4 col-sm-3 col-md-2">
                    <button type="submit" class="btn btn-sm btn-primary w-100"<?= app_tooltip_attr('Saves the selected color for the chosen course on your weekly schedule.') ?>>Apply</button>
                </div>
            </form>
            <?php if (count($facultyCourseLegend) > 0): ?>
                <div class="course-color-legend mt-3 pt-2 border-top">
                    <div class="small text-muted mb-2">Current colors</div>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($facultyCourseLegend as $courseId => $courseInfo): ?>
                            <?php
                            $ci = (int) $courseInfo['color_index'];
                            $sw = $colorPalette[$ci] ?? $colorPalette[0];
                            ?>
                            <span class="course-color-legend-item">
                                <span
                                    class="course-color-preview course-color-preview--sm"
                                    style="--swatch-bg: <?= htmlspecialchars($sw['bg']) ?>; --swatch-border: <?= htmlspecialchars($sw['border']) ?>;"
                                    aria-hidden="true"
                                ></span>
                                <span class="small"><?= htmlspecialchars($courseInfo['course_code']) ?></span>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <script>
    (function () {
        var courseSelect = document.getElementById('course-color-course');
        var colorSelect = document.getElementById('course-color-index');
        var preview = document.getElementById('course-color-preview');
        if (!courseSelect || !colorSelect || !preview) return;

        function syncPreview() {
            var opt = colorSelect.options[colorSelect.selectedIndex];
            if (!opt) return;
            preview.style.setProperty('--swatch-bg', opt.getAttribute('data-bg') || '#e2e8f0');
            preview.style.setProperty('--swatch-border', opt.getAttribute('data-border') || '#94a3b8');
        }

        courseSelect.addEventListener('change', function () {
            var opt = courseSelect.options[courseSelect.selectedIndex];
            if (!opt) return;
            var idx = opt.getAttribute('data-color-index');
            if (idx !== null && idx !== '') {
                colorSelect.value = String(idx);
            }
            syncPreview();
        });
        colorSelect.addEventListener('change', syncPreview);
    })();
    </script>
<?php elseif ($role === 'faculty' && !$hasCourseColors): ?>
    <div class="alert alert-warning no-print">Course color coding needs a database update. Ask your administrator to run <a href="upgrade_roles.php">upgrade_roles.php</a> once.</div>
<?php endif; ?>

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

<p class="text-muted small mb-2 no-print">Times on the left mark each hour. Empty cells are vacant. A straight class (for example 8:00 AM–10:00 AM) shows as one tall block across those hours when the next slot is only a continuation.</p>
<div class="table-responsive">
    <table class="table table-bordered bg-body schedule-weekly schedule-weekly--timed">
        <thead class="table-primary">
            <tr>
                <th class="text-center schedule-time-col" scope="col">Time</th>
                <?php foreach (schedule_days_list() as $day): ?>
                    <th class="text-center" style="min-width:140px"><?= htmlspecialchars($day) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($timeSlots as $hour): ?>
                <?php
                $slotLabel = $formatTime12h(sprintf('%02d:00', $hour));
                $slotEndLabel = $formatTime12h(sprintf('%02d:00', $hour + 1));
                ?>
                <tr>
                    <th class="schedule-time-col text-end align-top" scope="row">
                        <div class="schedule-time-label fw-semibold"><?= htmlspecialchars($slotLabel) ?></div>
                        <div class="schedule-time-end small text-muted"><?= htmlspecialchars($slotEndLabel) ?></div>
                    </th>
                    <?php foreach (schedule_days_list() as $day): ?>
                        <?php
                        // Cell already occupied by a multi-hour rowspan from an earlier hour.
                        if (isset($coveredByRowspan[$day][$hour])) {
                            continue;
                        }
                        $slotClasses = [];
                        foreach ($byDay[$day] as $s) {
                            if ($scheduleOverlapsHour($s, $hour)) {
                                $slotClasses[] = $s;
                            }
                        }
                        $isVacant = $slotClasses === [];
                        $cellRowspan = 1;
                        foreach ($slotClasses as $s) {
                            $sid = (int) ($s['id'] ?? 0);
                            if ($sid > 0 && isset($rowspanPlans[$day][$sid]) && $rowspanPlans[$day][$sid]['startHour'] === $hour) {
                                $cellRowspan = max($cellRowspan, (int) $rowspanPlans[$day][$sid]['span']);
                            }
                        }
                        ?>
                        <td class="align-top schedule-cell p-2<?= $isVacant ? ' schedule-cell--vacant' : '' ?><?= $cellRowspan > 1 ? ' schedule-cell--spanned' : '' ?>"<?= $cellRowspan > 1 ? ' rowspan="' . $cellRowspan . '"' : '' ?>>
                            <?php if ($isVacant): ?>
                                <span class="schedule-vacant-label">Vacant</span>
                            <?php else: ?>
                                <?php foreach ($slotClasses as $s): ?>
                                    <?php
                                    $startsHere = $scheduleStartsInHour($s, $hour);
                                    $sid = (int) ($s['id'] ?? 0);
                                    $blockSpan = ($sid > 0 && isset($rowspanPlans[$day][$sid]) && $rowspanPlans[$day][$sid]['startHour'] === $hour)
                                        ? (int) $rowspanPlans[$day][$sid]['span']
                                        : 1;
                                    // Skip "(continues)" only inside the merged rowspan range.
                                    if (!$startsHere && $sid > 0 && isset($rowspanPlans[$day][$sid])) {
                                        $plan = $rowspanPlans[$day][$sid];
                                        if ($hour >= $plan['startHour'] && $hour < $plan['startHour'] + $plan['span']) {
                                            continue;
                                        }
                                    }
                                    $blockFacultyId = (int) ($s['faculty_id'] ?? 0);
                                    $blockCourseId = (int) ($s['course_id'] ?? 0);
                                    $c = schedule_block_color_class(
                                        $blockCourseId,
                                        $facultyColorMaps[$blockFacultyId] ?? null
                                    );
                                    $isGeCourse = $hasCourseIsGenedCol && (int) ($s['course_is_gened'] ?? 0) === 1;
                                    $hostCollegeCode = trim((string) ($s['host_college_code'] ?? ''));
                                    $showHostCollege = is_dean() && $collegeId && $isGeCourse && $hostCollegeCode !== ''
                                        && (int) ($s['college_id'] ?? 0) !== $collegeId;
                                    ?>
                                    <?php
                                    $roomLabel = trim((string) ($s['room_code'] ?? ''));
                                    $roomName = trim((string) ($s['room_name'] ?? ''));
                                    if ($roomLabel !== '' && $roomName !== '' && strcasecmp($roomLabel, $roomName) !== 0) {
                                        $roomLabel .= ' — ' . $roomName;
                                    } elseif ($roomLabel === '' && $roomName !== '') {
                                        $roomLabel = $roomName;
                                    }
                                    ?>
                                    <?php
                                    $liveDisplayMode = weekly_schedule_online_live_mode($role, $s, $collegeFilter, $dept);
                                    $onlineUrl = $hasOnlineUrlCol ? trim((string) ($s['online_class_url'] ?? '')) : '';
                                    $liveAtStr = $hasLiveAtCol ? ($s['online_live_at'] ?? null) : null;
                                    $liveAtStr = $liveAtStr !== null && $liveAtStr !== '' ? (string) $liveAtStr : null;
                                    $isLive = $hasLiveAtCol && schedule_display_is_faculty_live($s);
                                    $showLiveIndicator = $isLive && $liveDisplayMode !== 'hidden';
                                    ?>
                                    <?php if (!$startsHere): ?>
                                        <div class="schedule-block schedule-block--continued <?= $c ?>">
                                            <div class="small fw-semibold">
                                                <?= htmlspecialchars($s['course_code']) ?> <span class="text-muted fw-normal">(continues)</span>
                                                <?php if ($showLiveIndicator): ?>
                                                    <span class="badge bg-danger live-pulse-badge rounded-pill ms-1" style="font-size:0.65rem"><i class="fa-solid fa-circle me-1" style="font-size:0.5rem;vertical-align:middle;"></i>LIVE</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="small text-muted"><?= htmlspecialchars($formatTime12h((string) $s['start_time'])) ?> – <?= htmlspecialchars($formatTime12h((string) $s['end_time'])) ?></div>
                                            <?php if ($roomLabel !== ''): ?>
                                                <div class="small"><i class="fa-solid fa-door-open me-1"></i><?= htmlspecialchars($roomLabel) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="schedule-block <?= $c ?><?= $blockSpan > 1 ? ' schedule-block--spanned' : '' ?>"<?= $blockSpan > 1 ? ' style="--block-span:' . $blockSpan . '"' : '' ?>>
                                            <div class="fw-semibold"><?= htmlspecialchars($formatTime12h((string) $s['start_time'])) ?> – <?= htmlspecialchars($formatTime12h((string) $s['end_time'])) ?></div>
                                            <div><?= htmlspecialchars($s['course_code']) ?><?php if ($isGeCourse): ?> <span class="badge bg-info text-dark" style="font-size:0.65rem">GE</span><?php endif; ?><?php if (!empty($s['is_makeup'])): ?> <span class="badge bg-warning text-dark" style="font-size:0.65rem">Makeup</span><?php endif; ?></div>
                                            <?php if ($showHostCollege): ?>
                                                <div class="small text-muted"><i class="fa-solid fa-building-columns me-1"></i><?= htmlspecialchars($hostCollegeCode) ?></div>
                                            <?php endif; ?>
                                            <div class="small text-muted"><?= htmlspecialchars($s['faculty_name']) ?></div>
                                            <?php if ($roomLabel !== ''): ?>
                                                <div class="small"><i class="fa-solid fa-door-open me-1"></i><?= htmlspecialchars($roomLabel) ?></div>
                                            <?php endif; ?>
                                            <?php if ($hasOnlineUrlCol && $onlineUrl !== '' && $liveDisplayMode !== 'hidden'): ?>
                                                <div class="mt-1 pt-1 border-top border-secondary-subtle small">
                                                    <?php if ($liveDisplayMode === 'unauthorized' && $hasLiveAtCol && $isLive): ?>
                                                        <span class="badge bg-danger live-pulse-badge rounded-pill me-1"><i class="fa-solid fa-circle me-1" style="font-size:0.5rem;vertical-align:middle;"></i>LIVE</span>
                                                        <?php
                                                        $unauthTip = $role === 'dean'
                                                            ? 'Live GE classes can only be joined by the Dean of the College of Arts and Sciences. You can still monitor schedules and conflicts in this view.'
                                                            : 'General education classes cannot be joined from a program chair account. Use the dean or GEN ED weekly view for oversight.';
                                                        ?>
                                                        <span class="badge bg-warning text-dark"<?= app_tooltip_attr($unauthTip) ?>>Unauthorized</span>
                                                    <?php elseif ($liveDisplayMode === 'normal' && $hasLiveAtCol && $isLive): ?>
                                                        <span class="badge bg-danger live-pulse-badge rounded-pill me-1"><i class="fa-solid fa-circle me-1" style="font-size:0.5rem;vertical-align:middle;"></i>LIVE</span>
                                                        <a class="btn btn-sm btn-success text-white py-0 px-2" href="<?= htmlspecialchars($onlineUrl) ?>" target="_blank" rel="noopener noreferrer"<?= app_tooltip_attr('Opens the live online class while the instructor is broadcasting. Use this during scheduled class time.') ?>>Join online</a>
                                                    <?php elseif ($liveDisplayMode === 'normal' && $hasLiveAtCol): ?>
                                                        <span class="badge bg-secondary me-1">Not live</span>
                                                        <span class="text-muted">Faculty has not gone live</span>
                                                    <?php elseif ($liveDisplayMode === 'normal'): ?>
                                                        <a class="btn btn-sm btn-outline-primary py-0 px-2" href="<?= htmlspecialchars($onlineUrl) ?>" target="_blank" rel="noopener noreferrer"<?= app_tooltip_attr('Opens the faculty’s online meeting link in a new tab. Use this to join the virtual room for this block.') ?>><i class="fa-solid fa-video me-1"></i>Online class</a>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php
                                            $ocPortalId = isset($s['oc_classroom_id']) ? (int) $s['oc_classroom_id'] : 0;
                                            $ocHasSyllabus = $hasSyllabusOnOc && $ocPortalId > 0 && trim((string) ($s['oc_syllabus_stored'] ?? '')) !== '';
                                            $hidePcGeButtons = $role === 'program_chair' && $isGeCourse;
                                            $hideGeNonGeButtons = $role === 'gened' && !$isGeCourse;
                                            $hideButtons = $hidePcGeButtons || $hideGeNonGeButtons;
                                            $syllabusRoles = ['admin', 'dean', 'program_chair', 'gened'];
                                            $monitorRoles = ['admin', 'dean', 'program_chair', 'gened'];
                                            $monitorHref = 'classroom_materials_monitor.php?id=' . $ocPortalId;
                                            if ($role === 'gened') {
                                                $monitorHref .= '&monitor_college=' . (string) $collegeFilter;
                                                $monitorHref .= '&monitor_program=' . rawurlencode($dept);
                                            }
                                            ?>
                                            <?php if ($ocHasSyllabus && in_array($role, $syllabusRoles, true) && !$hideButtons): ?>
                                                <div class="mt-1">
                                                    <a class="btn btn-sm btn-outline-secondary py-0 px-2" href="<?= htmlspecialchars(classroom_syllabus_href($ocPortalId)) ?>" onclick="var w=400,h=300,l=(screen.width-w)/2,t=(screen.height-h)/2;window.open(this.href,'syllabusWin','width='+w+',height='+h+',left='+l+',top='+t+',scrollbars=yes,resizable=yes');return false;"<?= app_tooltip_attr('Opens the faculty-uploaded syllabus for this section in a small window (oversight).') ?>><i class="fa-solid fa-file-contract me-1"></i>Syllabus</a>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($ocPortalId > 0 && in_array($role, $monitorRoles, true) && !$hideButtons): ?>
                                                <div class="mt-1">
                                                    <a class="btn btn-sm btn-outline-dark py-0 px-2" href="<?= htmlspecialchars($monitorHref) ?>" onclick="var w=400,h=300,l=(screen.width-w)/2,t=(screen.height-h)/2;window.open(this.href,'monitorWin','width='+w+',height='+h+',left='+l+',top='+t+',scrollbars=yes,resizable=yes');return false;"<?= app_tooltip_attr('Opens read-only monitoring of posted materials and week topics for this classroom.') ?>><i class="fa-solid fa-list-check me-1"></i>Monitor</a>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
(function () {
    if (!document.querySelector('.live-pulse-badge')) {
        return;
    }
    window.setInterval(function () {
        window.location.reload();
    }, 45000);
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

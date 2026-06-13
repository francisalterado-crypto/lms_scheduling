<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/schedule_helpers.php';

require_role(['dean', 'program_chair']);
$collegeId = dean_or_program_chair_college_id_or_fail();
$programScope = is_program_chair() ? program_scope_or_fail() : null;
$hasLabFlag = db_column_exists('courses', 'is_laboratory');
$hasProgramsTable = db_table_exists('programs');
$hasYearLevel = db_column_exists('courses', 'year_level');
$hasSection = db_column_exists('courses', 'section');

$errors = [];
$old = $_POST ?? [];
if (isset($old['days']) && is_array($old['days'])) {
    $old['day_array'] = $old['days'];
}
if (isset($old['lab_days']) && is_array($old['lab_days'])) {
    $old['lab_day_array'] = $old['lab_days'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $facultyId = (int) ($_POST['faculty_id'] ?? 0);
    $courseId = (int) ($_POST['course_id'] ?? 0);
    $roomId = (int) ($_POST['room_id'] ?? 0);
    $scheduleType = (string) ($_POST['schedule_type'] ?? 'Custom');
    $days = isset($_POST['days']) && is_array($_POST['days']) ? array_map('strval', $_POST['days']) : [];
    $startTime = substr((string) ($_POST['start_time'] ?? ''), 0, 5) . ':00';
    $endTime = substr((string) ($_POST['end_time'] ?? ''), 0, 5) . ':00';
    $semester = trim((string) ($_POST['semester'] ?? ''));
    $schoolYear = trim((string) ($_POST['school_year'] ?? ''));
    $academicYear = trim((string) ($_POST['academic_year'] ?? ''));

    $labRoomId = (int) ($_POST['lab_room_id'] ?? 0);
    $labDays = isset($_POST['lab_days']) && is_array($_POST['lab_days']) ? array_map('strval', $_POST['lab_days']) : [];
    $labStartTime = substr((string) ($_POST['lab_start_time'] ?? ''), 0, 5) . ':00';
    $labEndTime = substr((string) ($_POST['lab_end_time'] ?? ''), 0, 5) . ':00';

    $requestOverride = !empty($_POST['request_admin_override']);
    $overrideReason = trim((string) ($_POST['override_reason'] ?? ''));
    $selectedProgram = $programScope ?? trim((string) ($_POST['program'] ?? ''));

    if ($facultyId < 1 || $courseId < 1 || $roomId < 1) {
        $errors[] = 'Please select faculty, course, and room.';
    }
    if ($days === []) {
        $errors[] = 'Select at least one lecture day.';
    }
    if ($semester === '' || $schoolYear === '') {
        $errors[] = 'Semester and school year are required.';
    }

    $courseIsLab = false;
    if (!$errors) {
        $sql = 'SELECT COUNT(*) FROM faculty WHERE id=? AND college_id=?';
        $params = [$facultyId, $collegeId];
        if ($programScope !== null) {
            $sql .= ' AND department=?';
            $params[] = $programScope;
        }
        $chk = db()->prepare($sql);
        $chk->execute($params);
        if ((int) $chk->fetchColumn() < 1) {
            $errors[] = $programScope !== null
                ? 'Selected faculty is outside your assigned program.'
                : 'Selected faculty is not in your college.';
        }

        if ($hasLabFlag) {
            $sql = 'SELECT is_laboratory FROM courses WHERE id=? AND college_id=?';
            $params = [$courseId, $collegeId];
            if ($programScope !== null) {
                $sql .= ' AND department=?';
                $params[] = $programScope;
            }
            $chk = db()->prepare($sql);
            $chk->execute($params);
            $v = $chk->fetchColumn();
            if ($v === false) {
                $errors[] = $programScope !== null
                    ? 'Selected course is outside your assigned program.'
                    : 'Selected course is not in your college.';
            } else {
                $courseIsLab = (int) $v === 1;
            }
        } else {
            $sql = 'SELECT COUNT(*) FROM courses WHERE id=? AND college_id=?';
            $params = [$courseId, $collegeId];
            if ($programScope !== null) {
                $sql .= ' AND department=?';
                $params[] = $programScope;
            }
            $chk = db()->prepare($sql);
            $chk->execute($params);
            if ((int) $chk->fetchColumn() < 1) {
                $errors[] = $programScope !== null
                    ? 'Selected course is outside your assigned program.'
                    : 'Selected course is not in your college.';
            }
        }

        $chk = db()->prepare('SELECT COUNT(*) FROM rooms WHERE id=?');
        $chk->execute([$roomId]);
        if ((int) $chk->fetchColumn() < 1) {
            $errors[] = 'Selected lecture room is invalid.';
        }

        if ($hasProgramsTable && $selectedProgram !== '') {
            $chk = db()->prepare("SELECT COUNT(*) FROM programs WHERE college_id=? AND program_name=? AND status='active'");
            $chk->execute([$collegeId, $selectedProgram]);
            if ((int) $chk->fetchColumn() < 1) {
                $errors[] = 'Selected program is invalid for your college.';
            } else {
                $chk = db()->prepare('SELECT TRIM(department) FROM courses WHERE id=? AND college_id=?');
                $chk->execute([$courseId, $collegeId]);
                $courseDept = trim((string) $chk->fetchColumn());
                if ($courseDept !== $selectedProgram) {
                    $errors[] = 'Selected course does not match the selected program.';
                }
            }
        }

        if ($hasYearLevel && $hasSection) {
            $yearLevelPost = trim((string) ($_POST['year_level'] ?? ''));
            $sectionPost = trim((string) ($_POST['section'] ?? ''));
            if ($yearLevelPost === '' || $sectionPost === '') {
                $errors[] = 'Year level and section are required.';
            } else {
                $chk = db()->prepare('SELECT year_level, section FROM courses WHERE id=? AND college_id=?');
                $chk->execute([$courseId, $collegeId]);
                $crow = $chk->fetch();
                if ($crow) {
                    $cy = trim((string) ($crow['year_level'] ?? ''));
                    $cs = trim((string) ($crow['section'] ?? ''));
                    if ($cy !== $yearLevelPost || $cs !== $sectionPost) {
                        $errors[] = 'Selected course does not match the chosen year level and section.';
                    }
                }
            }
        }
    }

    $lectureRules = validate_schedule_rules($scheduleType, $days, $startTime, $endTime);
    foreach ($lectureRules['errors'] as $e) {
        $errors[] = $e;
    }

    $segments = [[
        'label' => 'Lecture',
        'room_id' => $roomId,
        'days' => $days,
        'start_time' => $startTime,
        'end_time' => $endTime,
        'schedule_type' => $scheduleType,
    ]];

    if ($courseIsLab) {
        if ($labRoomId < 1 || $labDays === []) {
            $errors[] = 'For laboratory courses, please set laboratory room and laboratory days.';
        }
        $labRules = validate_schedule_rules('Custom', $labDays, $labStartTime, $labEndTime);
        foreach ($labRules['errors'] as $e) {
            $errors[] = 'Laboratory: ' . $e;
        }
        if ($labRoomId > 0) {
            $chk = db()->prepare('SELECT COUNT(*) FROM rooms WHERE id=?');
            $chk->execute([$labRoomId]);
            if ((int) $chk->fetchColumn() < 1) {
                $errors[] = 'Selected laboratory room is invalid.';
            }
        }
        $segments[] = [
            'label' => 'Laboratory',
            'room_id' => $labRoomId,
            'days' => $labDays,
            'start_time' => $labStartTime,
            'end_time' => $labEndTime,
            'schedule_type' => 'Custom',
        ];
    }

    $allConflicts = [];
    $segmentCross = [];
    if (!$errors) {
        foreach ($segments as $idx => $seg) {
            $conflicts = checkConflicts(
                $facultyId,
                (int) $seg['room_id'],
                $seg['days'],
                $seg['start_time'],
                $seg['end_time'],
                $semester,
                $schoolYear,
                null,
                $collegeId
            );
            $internal = [];
            $cross = [];
            foreach ($conflicts as $c) {
                if (($c['scope'] ?? '') === 'cross_college') {
                    $cross[] = $c;
                } else {
                    $internal[] = $c;
                }
            }
            foreach ($internal as $c) {
                $errors[] = $seg['label'] . ': ' . $c['description'];
            }
            if ($cross) {
                $segmentCross[$idx] = $cross;
            }
            $allConflicts[$idx] = $conflicts;
        }

        if ($segmentCross && !$requestOverride) {
            foreach ($segmentCross as $idx => $crossList) {
                foreach ($crossList as $c) {
                    $errors[] = $segments[$idx]['label'] . ': ' . $c['description'] . ' (Request admin override to proceed.)';
                }
            }
        }
        if ($segmentCross && $requestOverride && $overrideReason === '') {
            $errors[] = 'Please add a reason for admin override request.';
        }

        if (!$errors && $segmentCross && $requestOverride) {
            foreach ($segmentCross as $idx => $crossList) {
                $seg = $segments[$idx];
                create_conflict_request([
                    'requested_by' => (int) $_SESSION['user_id'],
                    'college_id' => $collegeId,
                    'faculty_id' => $facultyId,
                    'course_id' => $courseId,
                    'room_id' => (int) $seg['room_id'],
                    'schedule_type' => (string) $seg['schedule_type'],
                    'day_of_week' => days_to_set($seg['days']),
                    'start_time' => (string) $seg['start_time'],
                    'end_time' => (string) $seg['end_time'],
                    'semester' => $semester,
                    'school_year' => $schoolYear,
                    'academic_year' => $academicYear,
                    'reason' => $overrideReason . ' [' . $seg['label'] . ']',
                ]);
            }
            log_dean_activity('schedule_override_request', 'Submitted cross-college conflict override request (with separated lab days/time)');
            $_SESSION['flash'] = 'Conflict request sent to admin for approval.';
            header('Location: schedule.php');
            exit;
        }
    }

    if (!$errors) {
        $uid = (int) $_SESSION['user_id'];
        $stmt = db()->prepare(
            'INSERT INTO schedules (faculty_id, course_id, room_id, college_id, schedule_type, day_of_week, start_time, end_time, semester, school_year, academic_year, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $createdIds = [];
        foreach ($segments as $idx => $seg) {
            $stmt->execute([
                $facultyId,
                $courseId,
                (int) $seg['room_id'],
                $collegeId,
                (string) $seg['schedule_type'],
                days_to_set($seg['days']),
                (string) $seg['start_time'],
                (string) $seg['end_time'],
                $semester,
                $schoolYear,
                $academicYear,
                $uid,
            ]);
            $newId = (int) db()->lastInsertId();
            $createdIds[] = $newId;
            if (!empty($allConflicts[$idx])) {
                log_conflicts($newId, $allConflicts[$idx], false);
            }
        }

        log_dean_activity('schedule_create', 'Created schedule entries #' . implode(', ', $createdIds));
        $_SESSION['flash'] = $courseIsLab
            ? 'Lecture and laboratory schedules created successfully.'
            : 'Schedule created successfully.';
        header('Location: schedule.php');
        exit;
    }
}

$pageTitle = 'Add schedule';
require_once __DIR__ . '/includes/header.php';
?>
<h1 class="h3 mb-4"><i class="fa-solid fa-plus me-2 text-primary"></i>Add schedule</h1>

<?php if ($errors): ?>
    <div class="alert alert-warning">
        <strong><i class="fa-solid fa-triangle-exclamation me-1"></i>Please fix the following:</strong>
        <ul class="mb-0 mt-2">
            <?php foreach ($errors as $e): ?>
                <li><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body">
        <form method="post" novalidate>
            <?php
            $defaults = $old;
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $defaults === []) {
                $defaults = [
                    'schedule_type' => 'Custom',
                    'semester' => '1st Semester',
                    'school_year' => date('Y') . '-' . (date('Y') + 1),
                    'start_time' => '08:00:00',
                    'end_time' => '09:00:00',
                    'lab_start_time' => '13:00:00',
                    'lab_end_time' => '16:00:00',
                ];
            }
            render_schedule_form($defaults);
            ?>
            <div class="mt-3 p-3 border rounded bg-light">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="request_admin_override" name="request_admin_override" value="1" <?= !empty($old['request_admin_override']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="request_admin_override">Request Admin Override for cross-college conflict</label>
                </div>
                <label class="form-label mt-2">Reason (required when requesting override)</label>
                <textarea name="override_reason" rows="2" class="form-control"><?= htmlspecialchars((string) ($old['override_reason'] ?? '')) ?></textarea>
            </div>
            <div class="mt-4">
                <button type="submit" class="btn btn-primary"<?= app_tooltip_attr('Creates the schedule row with the course, faculty, room, and times you entered. Use this after filling all required fields.') ?>><i class="fa-solid fa-check me-1"></i>Save schedule</button>
                <a href="schedule.php" class="btn btn-outline-secondary"<?= app_tooltip_attr('Returns to the schedule list without saving this draft.') ?>>Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/schedule_helpers.php';
require_once __DIR__ . '/includes/admin_activity_log.php';

require_role(['dean', 'program_chair']);
$collegeId = dean_or_program_chair_college_id_or_fail();
$programScope = is_program_chair() ? program_scope_or_fail() : null;
$hasLabFlag = db_column_exists('courses', 'is_laboratory');
$hasProgramsTable = db_table_exists('programs');
$hasYearLevel = db_column_exists('courses', 'year_level');
$hasSection = db_column_exists('courses', 'section');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['schedule_id'] ?? 0);
} else {
    $id = (int) ($_GET['id'] ?? 0);
}

if ($id < 1) {
    header('Location: schedule.php');
    exit;
}

$sql = 'SELECT s.*, COALESCE(u.role, "") AS creator_role
     FROM schedules s
     INNER JOIN courses c_scope ON c_scope.id = s.course_id
     LEFT JOIN users u ON u.id = s.created_by
     WHERE s.id = ? AND s.college_id = ?';
$params = [$id, $collegeId];
if ($programScope !== null) {
    $sql .= ' AND c_scope.department = ?';
    $params[] = $programScope;
}
$sql .= ' LIMIT 1';
$st = db()->prepare($sql);
$st->execute($params);
$scheduleRow = $st->fetch();
if (!$scheduleRow) {
    $_SESSION['flash'] = 'Schedule not found or not in your college.';
    header('Location: schedule.php');
    exit;
}
if ((string) ($scheduleRow['creator_role'] ?? '') === 'gened') {
    $_SESSION['flash'] = 'GE-created schedules cannot be edited here. Use GEN ED tools.';
    header('Location: schedule.php');
    exit;
}

$sql = 'SELECT * FROM courses WHERE id=? AND college_id=?';
$params = [(int) $scheduleRow['course_id'], $collegeId];
if ($programScope !== null) {
    $sql .= ' AND department=?';
    $params[] = $programScope;
}
$st = db()->prepare($sql);
$st->execute($params);
$courseRow = $st->fetch();
if (!$courseRow) {
    $_SESSION['flash'] = 'Course for this schedule is missing or not in your college.';
    header('Location: schedule.php');
    exit;
}

$errors = [];
$old = $_POST ?? [];
if (isset($old['days']) && is_array($old['days'])) {
    $old['day_array'] = $old['days'];
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

    $requestOverride = !empty($_POST['request_admin_override']);
    $overrideReason = trim((string) ($_POST['override_reason'] ?? ''));
    $selectedProgram = $programScope ?? trim((string) ($_POST['program'] ?? ''));

    if ($facultyId < 1 || $courseId < 1 || $roomId < 1) {
        $errors[] = 'Please select faculty, course, and room.';
    }
    if ($days === []) {
        $errors[] = 'Select at least one day.';
    }
    if ($semester === '' || $schoolYear === '') {
        $errors[] = 'Semester and school year are required.';
    }

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
            $errors[] = 'Selected room is invalid.';
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

    $allConflicts = [];
    $segmentCross = [];
    if (!$errors) {
        $conflicts = checkConflicts(
            $facultyId,
            $roomId,
            $days,
            $startTime,
            $endTime,
            $semester,
            $schoolYear,
            $id,
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
            $errors[] = $c['description'];
        }
        if ($cross) {
            $segmentCross = $cross;
        }
        $allConflicts = $conflicts;

        if ($segmentCross && !$requestOverride) {
            foreach ($segmentCross as $c) {
                $errors[] = $c['description'] . ' (Request admin override to proceed.)';
            }
        }
        if ($segmentCross && $requestOverride && $overrideReason === '') {
            $errors[] = 'Please add a reason for admin override request.';
        }

        if (!$errors && $segmentCross && $requestOverride) {
            create_conflict_request([
                'requested_by' => (int) $_SESSION['user_id'],
                'college_id' => $collegeId,
                'faculty_id' => $facultyId,
                'course_id' => $courseId,
                'room_id' => $roomId,
                'schedule_type' => $scheduleType,
                'day_of_week' => days_to_set($days),
                'start_time' => $startTime,
                'end_time' => $endTime,
                'semester' => $semester,
                'school_year' => $schoolYear,
                'academic_year' => $academicYear,
                'reason' => $overrideReason . ' [Edit schedule #' . $id . ']',
            ]);
            log_dean_activity('schedule_override_request', 'Submitted cross-college conflict override request (edit schedule #' . $id . ')');
            $_SESSION['flash'] = 'Conflict request sent to admin for approval.';
            header('Location: schedule.php');
            exit;
        }
    }

    if (!$errors) {
        $beforeSched = [
            'id' => (int) $scheduleRow['id'],
            'faculty_id' => (int) $scheduleRow['faculty_id'],
            'course_id' => (int) $scheduleRow['course_id'],
            'room_id' => (int) $scheduleRow['room_id'],
            'schedule_type' => (string) $scheduleRow['schedule_type'],
            'day_of_week' => (string) $scheduleRow['day_of_week'],
            'start_time' => (string) $scheduleRow['start_time'],
            'end_time' => (string) $scheduleRow['end_time'],
            'semester' => (string) $scheduleRow['semester'],
            'school_year' => (string) $scheduleRow['school_year'],
            'academic_year' => (string) ($scheduleRow['academic_year'] ?? ''),
        ];
        db()->prepare(
            'UPDATE schedules SET faculty_id=?, course_id=?, room_id=?, schedule_type=?, day_of_week=?, start_time=?, end_time=?, semester=?, school_year=?, academic_year=?
             WHERE id=? AND college_id=?'
        )->execute([
            $facultyId,
            $courseId,
            $roomId,
            $scheduleType,
            days_to_set($days),
            $startTime,
            $endTime,
            $semester,
            $schoolYear,
            $academicYear,
            $id,
            $collegeId,
        ]);
        if ($allConflicts !== []) {
            log_conflicts($id, $allConflicts, false);
        }
        $stUp = db()->prepare(
            'SELECT s.id, s.faculty_id, s.course_id, s.room_id, s.schedule_type, s.day_of_week, s.start_time, s.end_time, s.semester, s.school_year, s.academic_year,
                    f.full_name AS faculty_name, c.course_code, r.room_code
             FROM schedules s
             INNER JOIN faculty f ON f.id = s.faculty_id
             INNER JOIN courses c ON c.id = s.course_id
             INNER JOIN rooms r ON r.id = s.room_id
             WHERE s.id = ? LIMIT 1'
        );
        $stUp->execute([$id]);
        $afterSched = $stUp->fetch(PDO::FETCH_ASSOC);
        log_user_activity('edit', 'Schedules', 'Schedule #' . $id, $beforeSched, $afterSched ? (array) $afterSched : null);
        log_dean_activity('schedule_update', 'Updated schedule #' . $id);
        $_SESSION['flash'] = 'Schedule updated.';
        header('Location: schedule.php');
        exit;
    }
}

$pageTitle = 'Edit schedule';
require_once __DIR__ . '/includes/header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors !== []) {
    $defaults = $old;
    if (isset($old['days']) && is_array($old['days'])) {
        $defaults['day_array'] = $old['days'];
    }
} else {
    $defaults = [
        'faculty_id' => (int) $scheduleRow['faculty_id'],
        'course_id' => (int) $scheduleRow['course_id'],
        'room_id' => (int) $scheduleRow['room_id'],
        'schedule_type' => (string) $scheduleRow['schedule_type'],
        'day_of_week' => (string) $scheduleRow['day_of_week'],
        'start_time' => (string) $scheduleRow['start_time'],
        'end_time' => (string) $scheduleRow['end_time'],
        'semester' => (string) $scheduleRow['semester'],
        'school_year' => (string) $scheduleRow['school_year'],
        'academic_year' => (string) ($scheduleRow['academic_year'] ?? ''),
        'program' => trim((string) ($courseRow['department'] ?? '')),
        'year_level' => trim((string) ($courseRow['year_level'] ?? '')),
        'section' => trim((string) ($courseRow['section'] ?? '')),
    ];
}
?>
<h1 class="h3 mb-4"><i class="fa-solid fa-pen me-2 text-primary"></i>Edit schedule #<?= (int) $id ?></h1>
<p class="text-muted small">You are editing this schedule row only. For courses with separate lecture and laboratory rows, edit each row from the list.</p>

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
            <input type="hidden" name="schedule_id" value="<?= (int) $id ?>">
            <?php render_schedule_form($defaults, ['edit_single_row' => true]); ?>
            <div class="mt-3 p-3 border rounded bg-light">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="request_admin_override" name="request_admin_override" value="1" <?= !empty($old['request_admin_override']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="request_admin_override">Request Admin Override for cross-college conflict</label>
                </div>
                <label class="form-label mt-2">Reason (required when requesting override)</label>
                <textarea name="override_reason" rows="2" class="form-control"><?= htmlspecialchars((string) ($old['override_reason'] ?? '')) ?></textarea>
            </div>
            <div class="mt-4">
                <button type="submit" class="btn btn-primary"<?= app_tooltip_attr('Saves edits to this schedule row. Use this after changing time, room, faculty, or meeting link.') ?>><i class="fa-solid fa-check me-1"></i>Save changes</button>
                <a href="schedule.php" class="btn btn-outline-secondary"<?= app_tooltip_attr('Discards unsaved edits and returns to the schedule list.') ?>>Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

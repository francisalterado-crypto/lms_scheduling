<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/admin_activity_log.php';

require_role(['gened']);

$errors = [];
$old = $_POST ?? [];
if (isset($old['days']) && is_array($old['days'])) {
    $old['day_array'] = $old['days'];
}
if (!isset($old['lecture_slot_day']) || !is_array($old['lecture_slot_day'])) {
    $old['lecture_slot_day'] = [''];
}
if (!isset($old['lecture_slot_start_time']) || !is_array($old['lecture_slot_start_time'])) {
    $old['lecture_slot_start_time'] = [''];
}
if (!isset($old['lecture_slot_end_time']) || !is_array($old['lecture_slot_end_time'])) {
    $old['lecture_slot_end_time'] = [''];
}
if (!isset($old['lecture_slot_room_id']) || !is_array($old['lecture_slot_room_id'])) {
    $old['lecture_slot_room_id'] = [''];
}

$hasIsGenedFaculty = db_column_exists('faculty', 'is_gened');
$hasIsGenedCourse = db_column_exists('courses', 'is_gened');
$hasIsGenedRoom = db_column_exists('rooms', 'is_gened');
$hasCourseYearLevel = db_column_exists('courses', 'year_level');
$hasCourseSection = db_column_exists('courses', 'section');
$hasProgramsTable = db_table_exists('programs');
$hasGeScheduleTargets = db_table_exists('ge_schedule_targets');

$programRows = [];
if ($hasProgramsTable) {
    $programRows = db()->query(
        "SELECT p.college_id, p.program_name, c.college_code
         FROM programs p
         INNER JOIN colleges c ON c.id = p.college_id
         WHERE p.status='active'
         ORDER BY c.college_code, p.program_name"
    )->fetchAll();
}

$deanSectionRows = [];
if ($hasCourseYearLevel && $hasCourseSection) {
    $deanSectionRows = db()->query(
        "SELECT DISTINCT college_id, department, year_level, section
         FROM courses
         WHERE COALESCE(is_gened, 0) = 0
           AND college_id IS NOT NULL
           AND TRIM(COALESCE(department, '')) <> ''
           AND TRIM(COALESCE(section, '')) <> ''
           AND TRIM(COALESCE(year_level, '')) <> ''
         ORDER BY college_id, department, year_level, section"
    )->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $collegeId = (int) ($_POST['college_id'] ?? 0);
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
    $targetProgram = trim((string) ($_POST['target_program'] ?? ''));
    $targetYearLevel = trim((string) ($_POST['target_year_level'] ?? ''));
    $targetSection = trim((string) ($_POST['target_section'] ?? ''));
    $lectureSlotDays = isset($_POST['lecture_slot_day']) && is_array($_POST['lecture_slot_day']) ? array_map('strval', $_POST['lecture_slot_day']) : [];
    $lectureSlotStartTimes = isset($_POST['lecture_slot_start_time']) && is_array($_POST['lecture_slot_start_time']) ? array_map('strval', $_POST['lecture_slot_start_time']) : [];
    $lectureSlotEndTimes = isset($_POST['lecture_slot_end_time']) && is_array($_POST['lecture_slot_end_time']) ? array_map('strval', $_POST['lecture_slot_end_time']) : [];
    $lectureSlotRoomIds = isset($_POST['lecture_slot_room_id']) && is_array($_POST['lecture_slot_room_id']) ? array_map('intval', $_POST['lecture_slot_room_id']) : [];

    if ($collegeId < 1 || $facultyId < 1 || $courseId < 1 || $roomId < 1) {
        $errors[] = 'Please select college, GE faculty, GE course, and GE room.';
    }
    if ($days === []) {
        $errors[] = 'Select at least one day.';
    }
    if ($semester === '' || $schoolYear === '') {
        $errors[] = 'Semester and school year are required.';
    }
    if (!$hasIsGenedFaculty || !$hasIsGenedCourse || !$hasIsGenedRoom) {
        $errors[] = 'Run upgrade_roles.php first to enable GE scheduling.';
    }
    if (!$hasProgramsTable) {
        $errors[] = 'Run upgrade_roles.php first to sync Programs from Dean modules.';
    }
    if ($targetProgram === '' || $targetYearLevel === '' || $targetSection === '') {
        $errors[] = 'Program, year level, and section are required for GE scheduling.';
    }

    $rules = validate_schedule_rules($scheduleType, $days, $startTime, $endTime);
    foreach ($rules['errors'] as $e) {
        $errors[] = $e;
    }

    if (!$errors) {
        $chk = db()->prepare("SELECT COUNT(*) FROM colleges WHERE id=? AND status='active'");
        $chk->execute([$collegeId]);
        if ((int) $chk->fetchColumn() < 1) {
            $errors[] = 'Selected college is invalid.';
        }
        $chk = db()->prepare("SELECT COUNT(*) FROM faculty WHERE id=? AND is_gened=1 AND status='active'");
        $chk->execute([$facultyId]);
        if ((int) $chk->fetchColumn() < 1) {
            $errors[] = 'Selected faculty is not a GE faculty member.';
        }
        $chk = db()->prepare('SELECT COUNT(*) FROM courses WHERE id=? AND is_gened=1');
        $chk->execute([$courseId]);
        if ((int) $chk->fetchColumn() < 1) {
            $errors[] = 'Selected course is not a GE course.';
        }
        $chk = db()->prepare("SELECT COUNT(*) FROM rooms WHERE id=? AND is_gened=1 AND status IN ('available','tba')");
        $chk->execute([$roomId]);
        if ((int) $chk->fetchColumn() < 1) {
            $errors[] = 'Selected room is not an available GE room.';
        }

        try {
            $chk = db()->prepare('SELECT COUNT(*) FROM ge_course_colleges WHERE course_id=? AND college_id=?');
            $chk->execute([$courseId, $collegeId]);
            if ((int) $chk->fetchColumn() < 1) {
                $errors[] = 'This GE course is not assigned to the selected college. Configure it in GE Offerings first.';
            }
        } catch (Throwable $e) {
            $errors[] = 'Run upgrade_roles.php first to create GE course offering mappings.';
        }

        if ($targetProgram !== '') {
            $chk = db()->prepare("SELECT COUNT(*) FROM programs WHERE college_id=? AND program_name=? AND status='active'");
            $chk->execute([$collegeId, $targetProgram]);
            if ((int) $chk->fetchColumn() < 1) {
                $errors[] = 'Selected program is invalid for the chosen college.';
            }
        }
    }

    $segments = [[
        'room_id' => $roomId,
        'days' => $days,
        'start_time' => $startTime,
        'end_time' => $endTime,
        'schedule_type' => $scheduleType,
    ]];

    $slotCount = max(count($lectureSlotDays), count($lectureSlotStartTimes), count($lectureSlotEndTimes), count($lectureSlotRoomIds));
    for ($i = 0; $i < $slotCount; $i++) {
        $slotDay = trim((string) ($lectureSlotDays[$i] ?? ''));
        $slotStartRaw = trim((string) ($lectureSlotStartTimes[$i] ?? ''));
        $slotEndRaw = trim((string) ($lectureSlotEndTimes[$i] ?? ''));
        $slotRoomId = (int) ($lectureSlotRoomIds[$i] ?? 0);
        $isEmpty = $slotDay === '' && $slotStartRaw === '' && $slotEndRaw === '' && $slotRoomId < 1;
        if ($isEmpty) {
            continue;
        }
        if ($slotDay === '' || $slotStartRaw === '' || $slotEndRaw === '' || $slotRoomId < 1) {
            $errors[] = 'Each additional lecture slot must have day, start time, end time, and room.';
            continue;
        }
        $slotStart = substr($slotStartRaw, 0, 5) . ':00';
        $slotEnd = substr($slotEndRaw, 0, 5) . ':00';
        $slotRules = validate_schedule_rules('Custom', [$slotDay], $slotStart, $slotEnd);
        foreach ($slotRules['errors'] as $e) {
            $errors[] = 'Additional lecture slot: ' . $e;
        }
        $chk = db()->prepare("SELECT COUNT(*) FROM rooms WHERE id=? AND is_gened=1 AND status IN ('available','tba')");
        $chk->execute([$slotRoomId]);
        if ((int) $chk->fetchColumn() < 1) {
            $errors[] = 'Selected room for additional lecture slot is not an available GE room.';
        }
        $segments[] = [
            'room_id' => $slotRoomId,
            'days' => [$slotDay],
            'start_time' => $slotStart,
            'end_time' => $slotEnd,
            'schedule_type' => 'Custom',
        ];
    }

    $conflicts = [];
    if (!$errors) {
        foreach ($segments as $seg) {
            $segConflicts = checkConflicts(
                $facultyId,
                (int) $seg['room_id'],
                (array) $seg['days'],
                (string) $seg['start_time'],
                (string) $seg['end_time'],
                $semester,
                $schoolYear,
                null,
                $collegeId
            );
            foreach ($segConflicts as $c) {
                $errors[] = $c['description'];
            }
            $conflicts = array_merge($conflicts, $segConflicts);

            $st = db()->prepare(
                'SELECT s.id, s.day_of_week, s.start_time, s.end_time, c.course_code, c.course_name
                 FROM schedules s
                 INNER JOIN courses c ON c.id = s.course_id
                 WHERE s.college_id = ?
                   AND s.semester = ?
                   AND s.school_year = ?
                   AND c.department = ?
                   AND c.year_level = ?
                   AND c.section = ?'
            );
            $st->execute([$collegeId, $semester, $schoolYear, $targetProgram, $targetYearLevel, $targetSection]);
            $blockRows = $st->fetchAll();
            $stMin = time_to_minutes((string) $seg['start_time']);
            $enMin = time_to_minutes((string) $seg['end_time']);
            foreach ($blockRows as $br) {
                $existingDays = parse_day_set((string) $br['day_of_week']);
                if (!array_intersect((array) $seg['days'], $existingDays)) {
                    continue;
                }
                $rowStart = time_to_minutes((string) $br['start_time']);
                $rowEnd = time_to_minutes((string) $br['end_time']);
                if (intervals_overlap($stMin, $enMin, $rowStart, $rowEnd)) {
                    $errors[] = 'Target block conflict: ' . $targetProgram . ' Y' . $targetYearLevel . '-' . $targetSection
                        . ' already has ' . $br['course_code'] . ' at this time.';
                    break;
                }
            }

            if ($hasGeScheduleTargets) {
                $st = db()->prepare(
                    'SELECT s.id, s.day_of_week, s.start_time, s.end_time, c.course_code
                     FROM ge_schedule_targets gst
                     INNER JOIN schedules s ON s.id = gst.schedule_id
                     INNER JOIN courses c ON c.id = s.course_id
                     WHERE gst.college_id = ?
                       AND gst.program_name = ?
                       AND gst.year_level = ?
                       AND gst.section = ?
                       AND s.semester = ?
                       AND s.school_year = ?'
                );
                $st->execute([$collegeId, $targetProgram, $targetYearLevel, $targetSection, $semester, $schoolYear]);
                $targetRows = $st->fetchAll();
                foreach ($targetRows as $tr) {
                    $existingDays = parse_day_set((string) $tr['day_of_week']);
                    if (!array_intersect((array) $seg['days'], $existingDays)) {
                        continue;
                    }
                    $rowStart = time_to_minutes((string) $tr['start_time']);
                    $rowEnd = time_to_minutes((string) $tr['end_time']);
                    if (intervals_overlap($stMin, $enMin, $rowStart, $rowEnd)) {
                        $errors[] = 'GE block conflict: another GE schedule (' . $tr['course_code'] . ') already targets '
                            . $targetProgram . ' Y' . $targetYearLevel . '-' . $targetSection . ' at this time.';
                        break;
                    }
                }
            }
        }
    }

    if (!$errors) {
        $insert = db()->prepare(
            'INSERT INTO schedules (faculty_id, course_id, room_id, college_id, schedule_type, day_of_week, start_time, end_time, semester, school_year, academic_year, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $createdIds = [];
        foreach ($segments as $seg) {
            $insert->execute([
                $facultyId,
                $courseId,
                (int) $seg['room_id'],
                $collegeId,
                (string) $seg['schedule_type'],
                days_to_set((array) $seg['days']),
                (string) $seg['start_time'],
                (string) $seg['end_time'],
                $semester,
                $schoolYear,
                $academicYear,
                (int) $_SESSION['user_id'],
            ]);
            $newId = (int) db()->lastInsertId();
            $createdIds[] = $newId;
            if ($hasGeScheduleTargets && $targetProgram !== '' && $targetYearLevel !== '' && $targetSection !== '') {
                db()->prepare(
                    'INSERT INTO ge_schedule_targets (schedule_id, college_id, program_name, year_level, section)
                     VALUES (?,?,?,?,?)'
                )->execute([$newId, $collegeId, $targetProgram, $targetYearLevel, $targetSection]);
            }
            if ($conflicts) {
                log_conflicts($newId, $conflicts, false);
            }
            $stGe = db()->prepare(
                'SELECT s.id, s.faculty_id, s.course_id, s.room_id, s.college_id, s.semester, s.school_year,
                        s.day_of_week, s.start_time, s.end_time, s.schedule_type,
                        f.full_name AS faculty_name, c.course_code, r.room_code
                 FROM schedules s
                 INNER JOIN faculty f ON f.id = s.faculty_id
                 INNER JOIN courses c ON c.id = s.course_id
                 INNER JOIN rooms r ON r.id = s.room_id
                 WHERE s.id = ? LIMIT 1'
            );
            $stGe->execute([$newId]);
            $afterGe = $stGe->fetch(PDO::FETCH_ASSOC);
            log_user_activity('add', 'Schedules (GEN ED)', 'GE schedule #' . $newId, null, $afterGe ? (array) $afterGe : null);
        }
        $_SESSION['flash'] = count($createdIds) > 1
            ? 'GE schedules created successfully.'
            : 'GE schedule created successfully.';
        header('Location: schedule.php');
        exit;
    }
}

$facultyList = $hasIsGenedFaculty
    ? db()->query("SELECT id, faculty_id, full_name FROM faculty WHERE status='active' AND is_gened=1 ORDER BY full_name")->fetchAll()
    : [];
$courseList = $hasIsGenedCourse
    ? db()->query('SELECT id, course_code, course_name FROM courses WHERE is_gened=1 ORDER BY course_code')->fetchAll()
    : [];
$roomList = $hasIsGenedRoom
    ? db()->query("SELECT id, room_code, room_name FROM rooms WHERE is_gened=1 AND status IN ('available','tba') ORDER BY room_code")->fetchAll()
    : [];
$collegeList = db()->query("SELECT id, college_code, college_name FROM colleges WHERE status='active' ORDER BY college_code")->fetchAll();
$days = schedule_days_list();
$semesters = ['1st Semester', '2nd Semester', 'Summer'];
$types = ['MW', 'TTH', 'MWF', 'TTHS', 'Saturday', 'Sunday', 'MW_TTH', 'Custom'];
$selDays = isset($old['day_array']) ? (array) $old['day_array'] : [];
$hasProgramsYearLevels = db_table_exists('programs_year_levels');
$programYearLevelsByCollege = [];
if ($hasProgramsYearLevels) {
    $ylSt = db()->query(
        'SELECT p.college_id AS cid, p.program_name AS pname, pyl.year_level AS yl
         FROM programs_year_levels pyl
         INNER JOIN programs p ON p.id = pyl.program_id'
    );
    while ($rw = $ylSt->fetch(PDO::FETCH_ASSOC)) {
        $cid = (int) ($rw['cid'] ?? 0);
        $pname = trim((string) ($rw['pname'] ?? ''));
        $yl = trim((string) ($rw['yl'] ?? ''));
        if ($cid < 1 || $pname === '' || $yl === '') {
            continue;
        }
        if (!isset($programYearLevelsByCollege[$cid])) {
            $programYearLevelsByCollege[$cid] = [];
        }
        if (!isset($programYearLevelsByCollege[$cid][$pname])) {
            $programYearLevelsByCollege[$cid][$pname] = [];
        }
        if (!in_array($yl, $programYearLevelsByCollege[$cid][$pname], true)) {
            $programYearLevelsByCollege[$cid][$pname][] = $yl;
        }
    }
}

$programsByCollege = [];
foreach ($programRows as $pr) {
    $cid = (int) $pr['college_id'];
    if (!isset($programsByCollege[$cid])) {
        $programsByCollege[$cid] = [];
    }
    $nm = (string) $pr['program_name'];
    $deanYl = [];
    if (isset($programYearLevelsByCollege[$cid][$nm])) {
        $deanYl = sort_schedule_year_levels($programYearLevelsByCollege[$cid][$nm]);
    }
    $programsByCollege[$cid][] = [
        'name' => $nm,
        'college_code' => (string) $pr['college_code'],
        'year_levels' => ($deanYl !== []) ? $deanYl : ['1', '2', '3', '4', '5'],
    ];
}
$sectionsByScope = [];
foreach ($deanSectionRows as $sr) {
    $cid = (int) ($sr['college_id'] ?? 0);
    $program = (string) ($sr['department'] ?? '');
    $yl = (string) ($sr['year_level'] ?? '');
    $sec = (string) ($sr['section'] ?? '');
    if ($cid < 1 || $program === '' || $yl === '' || $sec === '') {
        continue;
    }
    if (!isset($sectionsByScope[$cid])) {
        $sectionsByScope[$cid] = [];
    }
    if (!isset($sectionsByScope[$cid][$program])) {
        $sectionsByScope[$cid][$program] = [];
    }
    if (!isset($sectionsByScope[$cid][$program][$yl])) {
        $sectionsByScope[$cid][$program][$yl] = [];
    }
    if (!in_array($sec, $sectionsByScope[$cid][$program][$yl], true)) {
        $sectionsByScope[$cid][$program][$yl][] = $sec;
    }
}

$pageTitle = 'Add GE Schedule';
require_once __DIR__ . '/includes/header.php';
?>
<h1 class="h3 mb-4"><i class="fa-solid fa-plus me-2 text-primary"></i>Add GE schedule</h1>
<?php if ($errors): ?>
    <div class="alert alert-warning"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body">
        <form method="post" class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Target college</label>
                <select name="college_id" id="college_id" class="form-select" required>
                    <option value="">— Select —</option>
                    <?php foreach ($collegeList as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= (int) ($old['college_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['college_code'] . ' — ' . $c['college_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Target program</label>
                <select name="target_program" id="target_program" class="form-select" required>
                    <option value="">— Select program —</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Target year level</label>
                <select name="target_year_level" id="target_year_level" class="form-select" required>
                    <option value="">— Select —</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Target section</label>
                <?php if ($hasCourseYearLevel && $hasCourseSection): ?>
                    <select name="target_section" id="target_section" class="form-select" required>
                        <option value="">— Select section —</option>
                    </select>
                <?php else: ?>
                    <input type="text" name="target_section" class="form-control" maxlength="20" required value="<?= htmlspecialchars((string) ($old['target_section'] ?? '')) ?>">
                <?php endif; ?>
            </div>
            <div class="col-md-4">
                <label class="form-label">GE Faculty</label>
                <select name="faculty_id" class="form-select" required>
                    <option value="">— Select —</option>
                    <?php foreach ($facultyList as $f): ?>
                        <option value="<?= (int) $f['id'] ?>" <?= (int) ($old['faculty_id'] ?? 0) === (int) $f['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($f['full_name'] . ' (' . $f['faculty_id'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">GE Course</label>
                <select name="course_id" class="form-select" required>
                    <option value="">— Select —</option>
                    <?php foreach ($courseList as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= (int) ($old['course_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['course_code'] . ' — ' . $c['course_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">GE Room</label>
                <select name="room_id" class="form-select" required>
                    <option value="">— Select —</option>
                    <?php foreach ($roomList as $r): ?>
                        <option value="<?= (int) $r['id'] ?>" <?= (int) ($old['room_id'] ?? 0) === (int) $r['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($r['room_code'] . ($r['room_name'] ? ' — ' . $r['room_name'] : '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Schedule type</label>
                <select name="schedule_type" class="form-select">
                    <?php foreach ($types as $t): ?>
                        <option value="<?= $t ?>" <?= (($old['schedule_type'] ?? 'Custom') === $t) ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2"><label class="form-label">Start time</label><input type="time" name="start_time" class="form-control" value="<?= htmlspecialchars(substr((string) ($old['start_time'] ?? '08:00'), 0, 5)) ?>"></div>
            <div class="col-md-2"><label class="form-label">End time</label><input type="time" name="end_time" class="form-control" value="<?= htmlspecialchars(substr((string) ($old['end_time'] ?? '09:00'), 0, 5)) ?>"></div>
            <div class="col-12">
                <label class="form-label">Days</label>
                <div class="d-flex flex-wrap gap-3">
                    <?php foreach ($days as $d): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="days[]" value="<?= htmlspecialchars($d) ?>" id="d_<?= htmlspecialchars($d) ?>" <?= in_array($d, $selDays, true) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="d_<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="col-12">
                <div class="p-3 border rounded bg-light">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <h6 class="mb-0">Additional lecture time slots</h6>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="addLectureSlotBtn">
                            <i class="fa-solid fa-plus me-1"></i>Add slot
                        </button>
                    </div>
                    <div class="small text-muted mb-3">
                        Use this when GE lecture days have different times.
                    </div>
                    <div id="lectureSlotsWrap">
                        <?php
                        $slotDays = is_array($old['lecture_slot_day'] ?? null) ? $old['lecture_slot_day'] : [''];
                        $slotStarts = is_array($old['lecture_slot_start_time'] ?? null) ? $old['lecture_slot_start_time'] : [''];
                        $slotEnds = is_array($old['lecture_slot_end_time'] ?? null) ? $old['lecture_slot_end_time'] : [''];
                        $slotRooms = is_array($old['lecture_slot_room_id'] ?? null) ? $old['lecture_slot_room_id'] : [''];
                        $slotTotal = max(1, count($slotDays), count($slotStarts), count($slotEnds), count($slotRooms));
                        for ($i = 0; $i < $slotTotal; $i++):
                            $slotDayValue = (string) ($slotDays[$i] ?? '');
                            $slotStartValue = substr((string) ($slotStarts[$i] ?? ''), 0, 5);
                            $slotEndValue = substr((string) ($slotEnds[$i] ?? ''), 0, 5);
                            $slotRoomValue = (int) ($slotRooms[$i] ?? 0);
                        ?>
                        <div class="row g-2 mb-2 lecture-slot-row">
                            <div class="col-md-3">
                                <select name="lecture_slot_day[]" class="form-select">
                                    <option value="">Day</option>
                                    <?php foreach ($days as $d): ?>
                                        <option value="<?= htmlspecialchars($d) ?>" <?= $slotDayValue === $d ? 'selected' : '' ?>><?= htmlspecialchars($d) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <input type="time" name="lecture_slot_start_time[]" class="form-control" value="<?= htmlspecialchars($slotStartValue) ?>">
                            </div>
                            <div class="col-md-3">
                                <input type="time" name="lecture_slot_end_time[]" class="form-control" value="<?= htmlspecialchars($slotEndValue) ?>">
                            </div>
                            <div class="col-md-3">
                                <div class="d-flex gap-2">
                                    <select name="lecture_slot_room_id[]" class="form-select">
                                        <option value="">GE Room</option>
                                        <?php foreach ($roomList as $r): ?>
                                            <option value="<?= (int) $r['id'] ?>" <?= $slotRoomValue === (int) $r['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($r['room_code'] . ($r['room_name'] ? ' — ' . $r['room_name'] : '')) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="btn btn-outline-danger remove-lecture-slot" title="Remove slot">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label">Semester</label>
                <select name="semester" class="form-select">
                    <?php foreach ($semesters as $s): ?><option value="<?= htmlspecialchars($s) ?>" <?= (($old['semester'] ?? '') === $s) ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3"><label class="form-label">School year</label><input type="text" name="school_year" class="form-control" value="<?= htmlspecialchars((string) ($old['school_year'] ?? (date('Y') . '-' . (date('Y') + 1)))) ?>"></div>
            <div class="col-md-3"><label class="form-label">Academic year</label><input type="text" name="academic_year" class="form-control" value="<?= htmlspecialchars((string) ($old['academic_year'] ?? '')) ?>"></div>
            <div class="col-12"><div class="alert alert-info mb-0">GE schedules are targeted per college program/year/section to avoid block conflicts with major schedules.</div></div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary"<?= app_tooltip_attr('Creates the GE schedule entry with course, faculty, room, and time. Use when adding a new GE section.') ?>>Save GE schedule</button>
                <a href="schedule.php" class="btn btn-outline-secondary"<?= app_tooltip_attr('Returns to the main schedule list without saving this form.') ?>>Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
(() => {
    const data = <?= json_encode($programsByCollege, JSON_UNESCAPED_SLASHES) ?>;
    const collegeSel = document.getElementById('college_id');
    const programSel = document.getElementById('target_program');
    const yearSel = document.getElementById('target_year_level');
    const sectionSel = document.getElementById('target_section');
    const sectionData = <?= json_encode($sectionsByScope, JSON_UNESCAPED_SLASHES) ?>;
    const selectedProgram = <?= json_encode((string) ($old['target_program'] ?? ''), JSON_UNESCAPED_SLASHES) ?>;
    const selectedSection = <?= json_encode((string) ($old['target_section'] ?? ''), JSON_UNESCAPED_SLASHES) ?>;
    const selectedYearInitial = <?= json_encode((string) ($old['target_year_level'] ?? ''), JSON_UNESCAPED_SLASHES) ?>;
    let yearInitialApplied = false;

    function yearLevelsForCurrentTarget() {
        const cid = collegeSel.value;
        const pname = programSel.value || '';
        const rows = data[cid] || [];
        for (let i = 0; i < rows.length; i++) {
            if (rows[i].name === pname) {
                return Array.isArray(rows[i].year_levels) && rows[i].year_levels.length ? rows[i].year_levels : ['1','2','3','4','5'];
            }
        }
        return ['1','2','3','4','5'];
    }

    function renderYearLevels() {
        if (!yearSel) {
            return;
        }
        const prev = yearSel.value || '';
        const list = yearLevelsForCurrentTarget();
        yearSel.innerHTML = '';
        const z = document.createElement('option');
        z.value = '';
        z.textContent = '\u2014 Select \u2014';
        yearSel.appendChild(z);
        for (let i = 0; i < list.length; i++) {
            const lvl = String(list[i]);
            const opt = document.createElement('option');
            opt.value = lvl;
            opt.textContent = lvl;
            yearSel.appendChild(opt);
        }
        let pick = '';
        if (!yearInitialApplied && selectedYearInitial && list.indexOf(selectedYearInitial) !== -1) {
            pick = selectedYearInitial;
            yearInitialApplied = true;
        } else if (prev && list.indexOf(prev) !== -1) {
            pick = prev;
        }
        yearSel.value = pick || '';
    }

    function renderPrograms() {
        const cid = collegeSel.value;
        const rows = data[cid] || [];
        programSel.innerHTML = '';
        const first = document.createElement('option');
        first.value = '';
        first.textContent = '— Select program —';
        programSel.appendChild(first);
        rows.forEach((r) => {
            const opt = document.createElement('option');
            opt.value = r.name;
            opt.textContent = r.name;
            if (selectedProgram && selectedProgram === r.name) {
                opt.selected = true;
            }
            programSel.appendChild(opt);
        });
    }

    function renderSections() {
        if (!sectionSel || !yearSel) {
            return;
        }
        const cid = collegeSel ? collegeSel.value : '';
        const program = programSel ? programSel.value : '';
        const yl = yearSel.value;
        const rows = (((sectionData[cid] || {})[program] || {})[yl] || []);
        sectionSel.innerHTML = '';
        const first = document.createElement('option');
        first.value = '';
        first.textContent = '— Select section —';
        sectionSel.appendChild(first);
        rows.forEach((s) => {
            const opt = document.createElement('option');
            opt.value = s;
            opt.textContent = s;
            if (selectedSection && selectedSection === s) {
                opt.selected = true;
            }
            sectionSel.appendChild(opt);
        });
    }

    collegeSel.addEventListener('change', () => {
        renderPrograms();
        renderYearLevels();
        renderSections();
    });
    if (yearSel) {
        yearSel.addEventListener('change', () => {
            if (!sectionSel) {
                return;
            }
            sectionSel.innerHTML = '';
            const cid = collegeSel ? collegeSel.value : '';
            const program = programSel ? programSel.value : '';
            const rows = (((sectionData[cid] || {})[program] || {})[yearSel.value] || []);
            const first = document.createElement('option');
            first.value = '';
            first.textContent = '— Select section —';
            sectionSel.appendChild(first);
            rows.forEach((s) => {
                const opt = document.createElement('option');
                opt.value = s;
                opt.textContent = s;
                sectionSel.appendChild(opt);
            });
        });
    }
    if (programSel) {
        programSel.addEventListener('change', () => {
            renderYearLevels();
            renderSections();
        });
    }
    if (collegeSel) {
        collegeSel.addEventListener('change', renderSections);
    }

    renderPrograms();
    renderYearLevels();
    renderSections();

    const wrap = document.getElementById('lectureSlotsWrap');
    const addBtn = document.getElementById('addLectureSlotBtn');
    if (wrap && addBtn) {
        const cloneTemplateRow = () => {
            const source = wrap.querySelector('.lecture-slot-row');
            if (!source) return null;
            const clone = source.cloneNode(true);
            clone.querySelectorAll('input').forEach((el) => {
                el.value = '';
            });
            clone.querySelectorAll('select').forEach((el) => {
                el.selectedIndex = 0;
            });
            return clone;
        };

        addBtn.addEventListener('click', () => {
            const row = cloneTemplateRow();
            if (row) {
                wrap.appendChild(row);
            }
        });

        wrap.addEventListener('click', (event) => {
            const btn = event.target.closest('.remove-lecture-slot');
            if (!btn) return;
            const rows = wrap.querySelectorAll('.lecture-slot-row');
            if (rows.length <= 1) {
                const row = btn.closest('.lecture-slot-row');
                if (!row) return;
                row.querySelectorAll('input').forEach((el) => {
                    el.value = '';
                });
                row.querySelectorAll('select').forEach((el) => {
                    el.selectedIndex = 0;
                });
                return;
            }
            const row = btn.closest('.lecture-slot-row');
            if (row) row.remove();
        });
    }
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

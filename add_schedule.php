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

$errors = [];
$old = $_POST ?? [];
if (isset($old['days']) && is_array($old['days'])) {
    $old['day_array'] = $old['days'];
}
if (isset($old['lab_days']) && is_array($old['lab_days'])) {
    $old['lab_day_array'] = $old['lab_days'];
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
    $lectureSlotDays = isset($_POST['lecture_slot_day']) && is_array($_POST['lecture_slot_day']) ? array_map('strval', $_POST['lecture_slot_day']) : [];
    $lectureSlotStartTimes = isset($_POST['lecture_slot_start_time']) && is_array($_POST['lecture_slot_start_time']) ? array_map('strval', $_POST['lecture_slot_start_time']) : [];
    $lectureSlotEndTimes = isset($_POST['lecture_slot_end_time']) && is_array($_POST['lecture_slot_end_time']) ? array_map('strval', $_POST['lecture_slot_end_time']) : [];
    $lectureSlotRoomIds = isset($_POST['lecture_slot_room_id']) && is_array($_POST['lecture_slot_room_id']) ? array_map('intval', $_POST['lecture_slot_room_id']) : [];

    $requestOverride = !empty($_POST['request_admin_override']);
    $overrideReason = trim((string) ($_POST['override_reason'] ?? ''));
    $selectedProgram = $programScope ?? trim((string) ($_POST['program'] ?? ''));

    if ($facultyId < 1 || $courseId < 1) {
        $errors[] = 'Please select faculty and course.';
    }
    if ($semester === '' || $schoolYear === '') {
        $errors[] = 'Semester and school year are required.';
    }

    $courseIsLab = false;
    $geProgramScope = is_ge_program_scope($programScope);
    if (!$errors) {
        if ($geProgramScope && db_column_exists('faculty', 'is_gened')) {
            $chk = db()->prepare("SELECT COUNT(*) FROM faculty WHERE id=? AND COALESCE(is_gened,0)=1 AND status='active'");
            $chk->execute([$facultyId]);
            if ((int) $chk->fetchColumn() < 1) {
                $errors[] = 'Selected faculty is not a GE faculty member.';
            }
        } else {
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
        }

        $courseOk = false;
        if ($geProgramScope && db_column_exists('courses', 'is_gened') && db_table_exists('ge_course_colleges')) {
            $chk = db()->prepare(
                'SELECT c.is_laboratory FROM courses c
                 INNER JOIN ge_course_colleges gcc ON gcc.course_id = c.id
                 WHERE c.id=? AND COALESCE(c.is_gened,0)=1 AND gcc.college_id=?'
            );
            $chk->execute([$courseId, $collegeId]);
            $geRow = $chk->fetch(PDO::FETCH_ASSOC);
            if ($geRow) {
                $courseOk = true;
                $courseIsLab = $hasLabFlag && (int) ($geRow['is_laboratory'] ?? 0) === 1;
            }
        }

        if (!$courseOk && $hasLabFlag) {
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
                $courseOk = true;
                $courseIsLab = (int) $v === 1;
            }
        } elseif (!$courseOk) {
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
            } else {
                $courseOk = true;
            }
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

    $hasLectureDetails = ($roomId > 0) || ($days !== []);
    $hasLabDetails = ($labRoomId > 0) || ($labDays !== []);

    $lectureComplete = $roomId > 0 && $days !== [];
    $labComplete = $labRoomId > 0 && $labDays !== [];

    $segments = [];

    if ($courseIsLab) {
        if (!$lectureComplete && !$labComplete) {
            $errors[] = 'For laboratory courses, set at least a laboratory schedule (room and days), or complete both lecture room and lecture days.';
        }
        if ($hasLectureDetails && !$lectureComplete) {
            $errors[] = 'Lecture schedule is incomplete. Please set both lecture room and lecture days, or clear lecture fields.';
        }
        if ($hasLabDetails && !$labComplete) {
            $errors[] = 'Laboratory schedule is incomplete. Please set both laboratory room and laboratory days.';
        }
    } else {
        if (!$lectureComplete) {
            $errors[] = 'Please set lecture room and select at least one lecture day.';
        }
    }

    if ($lectureComplete) {
        $lectureRules = validate_schedule_rules($scheduleType, $days, $startTime, $endTime);
        foreach ($lectureRules['errors'] as $e) {
            $errors[] = $e;
        }
        $chk = db()->prepare('SELECT COUNT(*) FROM rooms WHERE id=?');
        $chk->execute([$roomId]);
        if ((int) $chk->fetchColumn() < 1) {
            $errors[] = 'Selected lecture room is invalid.';
        }
        $segments[] = [
            'label' => 'Lecture',
            'room_id' => $roomId,
            'days' => $days,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'schedule_type' => $scheduleType,
        ];
    }

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
        $chk = db()->prepare('SELECT COUNT(*) FROM rooms WHERE id=?');
        $chk->execute([$slotRoomId]);
        if ((int) $chk->fetchColumn() < 1) {
            $errors[] = 'Selected room for additional lecture slot is invalid.';
        }
        $segments[] = [
            'label' => 'Lecture',
            'room_id' => $slotRoomId,
            'days' => [$slotDay],
            'start_time' => $slotStart,
            'end_time' => $slotEnd,
            'schedule_type' => 'Custom',
        ];
    }

    if ($courseIsLab && $labComplete) {
        $labRules = validate_schedule_rules('Custom', $labDays, $labStartTime, $labEndTime);
        foreach ($labRules['errors'] as $e) {
            $errors[] = 'Laboratory: ' . $e;
        }
        $chk = db()->prepare('SELECT COUNT(*) FROM rooms WHERE id=?');
        $chk->execute([$labRoomId]);
        if ((int) $chk->fetchColumn() < 1) {
            $errors[] = 'Selected laboratory room is invalid.';
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
                $collegeId,
                true
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
        $summaryRows = [];
        foreach ($createdIds as $cidLog) {
            $stL = db()->prepare(
                'SELECT s.id, s.faculty_id, s.course_id, s.room_id, s.college_id, s.semester, s.school_year, s.day_of_week, s.start_time, s.end_time,
                        f.full_name AS faculty_name, c.course_code, r.room_code
                 FROM schedules s
                 INNER JOIN faculty f ON f.id = s.faculty_id
                 INNER JOIN courses c ON c.id = s.course_id
                 INNER JOIN rooms r ON r.id = s.room_id
                 WHERE s.id = ? LIMIT 1'
            );
            $stL->execute([(int) $cidLog]);
            $r = $stL->fetch(PDO::FETCH_ASSOC);
            if ($r) {
                $summaryRows[] = $r;
            }
        }
        log_user_activity(
            'add',
            'Schedules',
            'New schedule row(s): #' . implode(', #', $createdIds),
            null,
            ['created_schedule_ids' => $createdIds, 'rows' => $summaryRows]
        );
        if ($courseIsLab) {
            $createdLecture = in_array('Lecture', array_column($segments, 'label'), true);
            $createdLaboratory = in_array('Laboratory', array_column($segments, 'label'), true);
            if ($createdLecture && $createdLaboratory) {
                $_SESSION['flash'] = 'Lecture and laboratory schedules created successfully.';
            } elseif ($createdLaboratory) {
                $_SESSION['flash'] = 'Laboratory schedule created successfully.';
            } else {
                $_SESSION['flash'] = 'Lecture schedule created successfully.';
            }
        } else {
            $_SESSION['flash'] = 'Schedule created successfully.';
        }
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
            <div class="mt-4 p-3 border rounded bg-light">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <h6 class="mb-0">Additional lecture time slots</h6>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="addLectureSlotBtn">
                        <i class="fa-solid fa-plus me-1"></i>Add slot
                    </button>
                </div>
                <div class="small text-muted mb-3">
                    Use this when lecture days have different times (e.g., Monday 8:00-9:30 and Wednesday 1:00-2:30).
                </div>
                <div id="lectureSlotsWrap">
                    <?php
                    $slotDays = is_array($old['lecture_slot_day'] ?? null) ? $old['lecture_slot_day'] : [''];
                    $slotStarts = is_array($old['lecture_slot_start_time'] ?? null) ? $old['lecture_slot_start_time'] : [''];
                    $slotEnds = is_array($old['lecture_slot_end_time'] ?? null) ? $old['lecture_slot_end_time'] : [''];
                    $slotRooms = is_array($old['lecture_slot_room_id'] ?? null) ? $old['lecture_slot_room_id'] : [''];
                    $slotTotal = max(1, count($slotDays), count($slotStarts), count($slotEnds), count($slotRooms));
                    $dayOptions = schedule_days_list();
                    $roomOptions = db()->query("SELECT id, room_code, room_name FROM rooms WHERE status IN ('available','tba') ORDER BY room_code")->fetchAll();
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
                                <?php foreach ($dayOptions as $d): ?>
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
                                    <option value="">Room</option>
                                    <?php foreach ($roomOptions as $r): ?>
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

<script>
    (function () {
        const wrap = document.getElementById('lectureSlotsWrap');
        const addBtn = document.getElementById('addLectureSlotBtn');
        if (!wrap || !addBtn) return;

        const rowTemplate = function () {
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

        addBtn.addEventListener('click', function () {
            const row = rowTemplate();
            if (!row) return;
            wrap.appendChild(row);
        });

        wrap.addEventListener('click', function (event) {
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
    })();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

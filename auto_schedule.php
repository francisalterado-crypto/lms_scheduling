<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/admin_activity_log.php';

require_role(['dean']);
$collegeId = dean_college_id_or_fail();
$deanUserId = (int) $_SESSION['user_id'];
$collegeName = college_name_by_id($collegeId);
$hasLabFlag = db_column_exists('courses', 'is_laboratory');
$hasLectureUnits = db_column_exists('courses', 'lecture_units');
$hasLaboratoryUnits = db_column_exists('courses', 'laboratory_units');
$hasSpecializationTable = db()->query("SHOW TABLES LIKE 'faculty_specializations'")->fetchColumn() !== false;

function auto_candidate_starts(int $durationMinutes): array
{
    $starts = [];
    $min = time_to_minutes(TIME_MIN);
    $max = time_to_minutes(TIME_MAX) - $durationMinutes;
    for ($m = $min; $m <= $max; $m += 60) {
        $starts[] = minutes_to_time($m);
    }
    return $starts;
}

function auto_find_slot(
    int $facultyId,
    int $roomId,
    array $days,
    int $durationMinutes,
    string $semester,
    string $schoolYear,
    int $collegeId,
    bool $allowLongBlock = false
): ?array {
    foreach ($days as $day) {
        foreach (auto_candidate_starts($durationMinutes) as $start) {
            $stMin = time_to_minutes($start);
            $end = minutes_to_time($stMin + $durationMinutes);
            $conf = checkConflicts(
                $facultyId,
                $roomId,
                [$day],
                $start,
                $end,
                $semester,
                $schoolYear,
                null,
                $collegeId,
                $allowLongBlock
            );
            if ($conf === []) {
                return ['day' => $day, 'start' => $start, 'end' => $end];
            }
        }
    }
    return null;
}

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$errors = [];
$warnings = [];
$results = [];
$specializationCourseIds = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $facultyId = (int) ($_POST['faculty_id'] ?? 0);
    $subjectCount = max(1, min(20, (int) ($_POST['subject_count'] ?? 1)));
    $semester = trim((string) ($_POST['semester'] ?? ''));
    $schoolYear = trim((string) ($_POST['school_year'] ?? ''));
    $academicYear = trim((string) ($_POST['academic_year'] ?? ''));
    $allowLabFallbackForLecture = !empty($_POST['allow_lab_fallback_for_lecture']);
    $specializationCourseIds = isset($_POST['specialization_course_ids']) && is_array($_POST['specialization_course_ids'])
        ? array_values(array_unique(array_map('intval', $_POST['specialization_course_ids'])))
        : [];

    if ($facultyId < 1 || $semester === '' || $schoolYear === '') {
        $errors[] = 'Faculty, semester, and school year are required.';
    } else {
        $st = db()->prepare('SELECT COUNT(*) FROM faculty WHERE id=? AND college_id=?');
        $st->execute([$facultyId, $collegeId]);
        if ((int) $st->fetchColumn() < 1) {
            $errors[] = 'Selected faculty is not under your college.';
        }
    }

    $courseScopeIds = [];
    if (!$errors && $hasSpecializationTable) {
        // Save posted specialization for this faculty (persistent mapping)
        if (isset($_POST['specialization_course_ids'])) {
            $validStmt = db()->prepare('SELECT id FROM courses WHERE college_id=?');
            $validStmt->execute([$collegeId]);
            $valid = array_fill_keys(array_map('intval', $validStmt->fetchAll(PDO::FETCH_COLUMN)), true);

            $clean = [];
            foreach ($specializationCourseIds as $cid) {
                if (isset($valid[$cid])) {
                    $clean[] = $cid;
                }
            }

            db()->beginTransaction();
            try {
                db()->prepare('DELETE FROM faculty_specializations WHERE faculty_id=?')->execute([$facultyId]);
                if ($clean) {
                    $insSpec = db()->prepare('INSERT INTO faculty_specializations (faculty_id, course_id) VALUES (?,?)');
                    foreach ($clean as $cid) {
                        $insSpec->execute([$facultyId, $cid]);
                    }
                }
                db()->commit();
                $specializationCourseIds = $clean;
            } catch (Throwable $e) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                throw $e;
            }
        }

        if ($specializationCourseIds) {
            $courseScopeIds = $specializationCourseIds;
        } else {
            $sp = db()->prepare('SELECT course_id FROM faculty_specializations WHERE faculty_id=?');
            $sp->execute([$facultyId]);
            $saved = array_map('intval', $sp->fetchAll(PDO::FETCH_COLUMN));
            if ($saved) {
                $courseScopeIds = $saved;
            }
        }
    }

    if (!$errors) {
        $orderBy = $hasLabFlag ? 'c.is_laboratory DESC, c.course_code ASC' : 'c.course_code ASC';
        if ($courseScopeIds) {
            $ph = implode(',', array_fill(0, count($courseScopeIds), '?'));
            $coursesStmt = db()->prepare(
                'SELECT c.*
                 FROM courses c
                 WHERE c.college_id=? AND c.id IN (' . $ph . ')
                 ORDER BY ' . $orderBy . '
                 LIMIT ' . $subjectCount
            );
            $coursesStmt->execute(array_merge([$collegeId], $courseScopeIds));
        } else {
            $coursesStmt = db()->prepare(
                'SELECT c.*
                 FROM courses c
                 WHERE c.college_id=?
                 ORDER BY ' . $orderBy . '
                 LIMIT ' . $subjectCount
            );
            $coursesStmt->execute([$collegeId]);
        }
        $courses = $coursesStmt->fetchAll();
        if (!$courses) {
            $errors[] = $courseScopeIds
                ? 'No specialization courses found for this faculty.'
                : 'No courses found for this college.';
        }
    }

    if (!$errors) {
        $roomLectureStmt = db()->prepare(
            "SELECT id, room_code FROM rooms WHERE status='available' AND college_id=? AND type IN ('lecture','conference') ORDER BY room_code"
        );
        $roomLectureStmt->execute([$collegeId]);
        $lectureRooms = $roomLectureStmt->fetchAll();

        $roomLabStmt = db()->prepare(
            "SELECT id, room_code FROM rooms WHERE status='available' AND college_id=? AND type='laboratory' ORDER BY room_code"
        );
        $roomLabStmt->execute([$collegeId]);
        $labRooms = $roomLabStmt->fetchAll();

        if (!$lectureRooms) {
            if ($allowLabFallbackForLecture && $labRooms) {
                $lectureRooms = $labRooms;
                $warnings[] = 'No lecture/conference rooms found. Using laboratory rooms as fallback for lecture parts (toggle enabled).';
            } else {
                $errors[] = 'No available lecture/conference rooms in this college.'
                    . ($labRooms ? ' Enable "Allow laboratory fallback for lecture parts" to continue.' : '');
            }
        }

        if (!$errors) {
            db()->beginTransaction();
            try {
                $created = 0;
                $failed = 0;
                $createdScheduleIds = [];
                $ins = db()->prepare(
                    'INSERT INTO schedules (faculty_id, course_id, room_id, college_id, schedule_type, day_of_week, start_time, end_time, semester, school_year, academic_year, created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
                );

                foreach ($courses as $course) {
                    $isLab = $hasLabFlag && (int) ($course['is_laboratory'] ?? 0) === 1;
                    $courseCode = (string) $course['course_code'];
                    $courseId = (int) $course['id'];
                    $legacyUnits = (float) ($course['units'] ?? 0);
                    $lectureUnits = $hasLectureUnits
                        ? (float) ($course['lecture_units'] ?? 0)
                        : $legacyUnits;
                    $laboratoryUnits = $hasLaboratoryUnits
                        ? (float) ($course['laboratory_units'] ?? 0)
                        : ($isLab ? $legacyUnits : 0.0);

                    $parts = [];
                    if ($isLab) {
                        // New rule: 1 unit lecture = 1 hour, 1 unit lab = 3 hours
                        if ($lectureUnits > 0) {
                            $lectureMinutes = max(60, (int) round($lectureUnits * 60));
                            $parts[] = ['label' => 'Lecture Part', 'duration' => $lectureMinutes, 'roomPool' => $lectureRooms, 'days' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday']];
                        }
                        if ($laboratoryUnits > 0) {
                            $labMinutes = max(180, (int) round($laboratoryUnits * 180));
                            $parts[] = ['label' => 'Laboratory Part', 'duration' => $labMinutes, 'roomPool' => $labRooms, 'days' => ['Saturday', 'Friday', 'Thursday']];
                        }
                        if ($parts === []) {
                            $results[] = "{$courseCode}: skipped (no lecture or laboratory units configured).";
                            $failed++;
                            continue;
                        }
                    } else {
                        // New rule: lecture hours are unit-based (1 unit = 1 hour)
                        $effectiveLectureUnits = $lectureUnits > 0 ? $lectureUnits : $legacyUnits;
                        $duration = max(60, (int) round($effectiveLectureUnits * 60));
                        $parts[] = ['label' => 'Regular', 'duration' => $duration, 'roomPool' => $lectureRooms, 'days' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday']];
                    }

                    $okForCourse = true;
                    foreach ($parts as $part) {
                        if (empty($part['roomPool'])) {
                            $results[] = "{$courseCode}: No room available for {$part['label']}.";
                            $okForCourse = false;
                            break;
                        }

                        $scheduledPart = false;
                        foreach ($part['roomPool'] as $room) {
                            $slot = auto_find_slot(
                                $facultyId,
                                (int) $room['id'],
                                $part['days'],
                                (int) $part['duration'],
                                $semester,
                                $schoolYear,
                                $collegeId,
                                ((int) $part['duration']) > (MAX_CLASS_BLOCK_HOURS * 60)
                            );
                            if ($slot) {
                                $ins->execute([
                                    $facultyId,
                                    $courseId,
                                    (int) $room['id'],
                                    $collegeId,
                                    'Custom',
                                    $slot['day'],
                                    $slot['start'],
                                    $slot['end'],
                                    $semester,
                                    $schoolYear,
                                    $academicYear,
                                    $deanUserId,
                                ]);
                                $created++;
                                $createdScheduleIds[] = (int) db()->lastInsertId();
                                $results[] = "{$courseCode} ({$part['label']}): {$slot['day']} " . substr($slot['start'], 0, 5) . '-' . substr($slot['end'], 0, 5) . " @ {$room['room_code']}";
                                $scheduledPart = true;
                                break;
                            }
                        }
                        if (!$scheduledPart) {
                            $results[] = "{$courseCode}: No available slot for {$part['label']}.";
                            $okForCourse = false;
                            break;
                        }
                    }

                    if (!$okForCourse) {
                        $failed++;
                    }
                }

                db()->commit();
                if ($created > 0) {
                    log_user_activity(
                        'add',
                        'Schedules (auto)',
                        "Auto-generated {$created} schedule row(s)",
                        null,
                        [
                            'created_count' => $created,
                            'failed_courses' => $failed,
                            'created_schedule_ids' => $createdScheduleIds,
                            'preview' => array_slice($results, 0, 25),
                        ]
                    );
                }
                log_dean_activity('auto_schedule', "Auto scheduling run: created {$created} entries, {$failed} course(s) incomplete.");
                $_SESSION['flash'] = "Auto scheduling completed. Created {$created} schedule entry/entries.";
            } catch (Throwable $e) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                throw $e;
            }
        }
    }
}

$facultyStmt = db()->prepare('SELECT id, faculty_id, full_name FROM faculty WHERE college_id=? AND status="active" ORDER BY full_name');
$facultyStmt->execute([$collegeId]);
$facultyList = $facultyStmt->fetchAll();
$courseListStmt = db()->prepare('SELECT id, course_code, course_name FROM courses WHERE college_id=? ORDER BY course_code');
$courseListStmt->execute([$collegeId]);
$courseList = $courseListStmt->fetchAll();

$pageTitle = 'Automatic Scheduling';
require_once __DIR__ . '/includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0"><i class="fa-solid fa-wand-magic-sparkles me-2 text-primary"></i>Automatic Scheduling</h1>
</div>
<p class="text-muted">
    College: <strong><?= htmlspecialchars($collegeName) ?></strong>.
    Unit-based time: <strong>Lecture Units x 1 hour</strong>, <strong>Laboratory Units x 3 hours</strong>.
</p>
<?php if (!$hasLectureUnits || !$hasLaboratoryUnits): ?>
    <div class="alert alert-warning">
        Split-unit columns are missing in your database. Run <a href="upgrade_roles.php">upgrade_roles.php</a> once.
    </div>
<?php endif; ?>
<?php if (!$hasLabFlag): ?>
    <div class="alert alert-warning">
        Laboratory split is currently disabled because your database is missing `courses.is_laboratory`.
        Run <a href="upgrade_roles.php">upgrade_roles.php</a> once, then retry.
    </div>
<?php endif; ?>

<?php if ($flash): ?>
    <div class="alert alert-success"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>
<?php if ($errors): ?>
    <div class="alert alert-warning">
        <ul class="mb-0">
            <?php foreach ($errors as $e): ?>
                <li><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
<?php if ($warnings): ?>
    <div class="alert alert-info">
        <ul class="mb-0">
            <?php foreach ($warnings as $w): ?>
                <li><?= htmlspecialchars($w) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white"><strong>Generate Schedule Plan</strong></div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Faculty</label>
                <select class="form-select" name="faculty_id" required>
                    <option value="">Select faculty</option>
                    <?php foreach ($facultyList as $f): ?>
                        <option value="<?= (int) $f['id'] ?>" <?= (int) ($_POST['faculty_id'] ?? 0) === (int) $f['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($f['full_name'] . ' (' . $f['faculty_id'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label"># Subjects</label>
                <input type="number" class="form-control" name="subject_count" min="1" max="20" value="<?= htmlspecialchars((string) ($_POST['subject_count'] ?? 5)) ?>" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Semester</label>
                <select class="form-select" name="semester" required>
                    <?php foreach (['1st Semester', '2nd Semester', 'Summer'] as $s): ?>
                        <option value="<?= htmlspecialchars($s) ?>" <?= ($_POST['semester'] ?? '1st Semester') === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">School Year</label>
                <input type="text" class="form-control" name="school_year" value="<?= htmlspecialchars((string) ($_POST['school_year'] ?? (date('Y') . '-' . (date('Y') + 1)))) ?>" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Academic Year</label>
                <input type="text" class="form-control" name="academic_year" value="<?= htmlspecialchars((string) ($_POST['academic_year'] ?? '')) ?>">
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="allow_lab_fallback_for_lecture" name="allow_lab_fallback_for_lecture" value="1"
                        <?= !empty($_POST['allow_lab_fallback_for_lecture']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="allow_lab_fallback_for_lecture">
                        Allow laboratory fallback for lecture parts (optional)
                    </label>
                </div>
            </div>
            <div class="col-12">
                <label class="form-label">Faculty specialization courses (optional, saved per faculty)</label>
                <select class="form-select" name="specialization_course_ids[]" multiple size="6">
                    <?php foreach ($courseList as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= in_array((int) $c['id'], $specializationCourseIds, true) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['course_code'] . ' - ' . $c['course_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">
                    If you select courses here, those become this faculty's specialization set and Auto Scheduling prioritizes them.
                </div>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary"<?= app_tooltip_attr('Runs the automatic scheduler for your college with the options you selected.') ?>><i class="fa-solid fa-gears me-1"></i>Auto-generate schedules</button>
                <a href="schedule.php" class="btn btn-outline-secondary"<?= app_tooltip_attr('Returns to the schedule list without running another generation.') ?>>Back to schedules</a>
            </div>
        </form>
    </div>
</div>

<?php if ($results): ?>
    <div class="card shadow-sm">
        <div class="card-header bg-white"><strong>Generation Results</strong></div>
        <div class="card-body">
            <ul class="mb-0">
                <?php foreach ($results as $line): ?>
                    <li><?= htmlspecialchars($line) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

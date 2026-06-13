<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_role(['gened']);
$hasGeScheduleTargets = db_table_exists('ge_schedule_targets');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id < 1) {
    header('Location: schedule.php');
    exit;
}

$targetSelect = $hasGeScheduleTargets
    ? ', gst.program_name AS target_program, gst.year_level AS target_year_level, gst.section AS target_section'
    : ", '' AS target_program, '' AS target_year_level, '' AS target_section";
$targetJoin = $hasGeScheduleTargets ? ' LEFT JOIN ge_schedule_targets gst ON gst.schedule_id = s.id ' : '';
$st = db()->prepare(
    "SELECT s.* {$targetSelect}
     FROM schedules s
     INNER JOIN users u ON u.id = s.created_by
     {$targetJoin}
     WHERE s.id=? AND u.role='gened' LIMIT 1"
);
$st->execute([$id]);
$row = $st->fetch();
if (!$row) {
    http_response_code(403);
    exit('Only GE-created schedules can be edited by GEN ED account.');
}

$errors = [];
$old = $_POST ?? [];
if (isset($old['days']) && is_array($old['days'])) {
    $old['day_array'] = $old['days'];
}

$hasIsGenedFaculty = db_column_exists('faculty', 'is_gened');
$hasIsGenedCourse = db_column_exists('courses', 'is_gened');
$hasIsGenedRoom = db_column_exists('rooms', 'is_gened');
$hasCourseYearLevel = db_column_exists('courses', 'year_level');
$hasCourseSection = db_column_exists('courses', 'section');
$hasProgramsTable = db_table_exists('programs');

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

        $chk = db()->prepare('SELECT COUNT(*) FROM ge_course_colleges WHERE course_id=? AND college_id=?');
        $chk->execute([$courseId, $collegeId]);
        if ((int) $chk->fetchColumn() < 1) {
            $errors[] = 'This GE course is not assigned to the selected college. Configure it in GE Offerings first.';
        }

        if ($targetProgram !== '') {
            $chk = db()->prepare("SELECT COUNT(*) FROM programs WHERE college_id=? AND program_name=? AND status='active'");
            $chk->execute([$collegeId, $targetProgram]);
            if ((int) $chk->fetchColumn() < 1) {
                $errors[] = 'Selected program is invalid for the chosen college.';
            }
        }
    }

    $conflicts = [];
    if (!$errors) {
        $conflicts = checkConflicts($facultyId, $roomId, $days, $startTime, $endTime, $semester, $schoolYear, $id, $collegeId);
        foreach ($conflicts as $c) {
            $errors[] = $c['description'];
        }

        $stBlock = db()->prepare(
            'SELECT s.id, s.day_of_week, s.start_time, s.end_time, c.course_code
             FROM schedules s
             INNER JOIN courses c ON c.id = s.course_id
             WHERE s.college_id = ?
               AND s.semester = ?
               AND s.school_year = ?
               AND c.department = ?
               AND c.year_level = ?
               AND c.section = ?
               AND s.id <> ?'
        );
        $stBlock->execute([$collegeId, $semester, $schoolYear, $targetProgram, $targetYearLevel, $targetSection, $id]);
        $blockRows = $stBlock->fetchAll();
        $stMin = time_to_minutes($startTime);
        $enMin = time_to_minutes($endTime);
        foreach ($blockRows as $br) {
            $existingDays = parse_day_set((string) $br['day_of_week']);
            if (!array_intersect($days, $existingDays)) {
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
            $stTarget = db()->prepare(
                'SELECT s.id, s.day_of_week, s.start_time, s.end_time, c.course_code
                 FROM ge_schedule_targets gst
                 INNER JOIN schedules s ON s.id = gst.schedule_id
                 INNER JOIN courses c ON c.id = s.course_id
                 WHERE gst.college_id = ?
                   AND gst.program_name = ?
                   AND gst.year_level = ?
                   AND gst.section = ?
                   AND s.semester = ?
                   AND s.school_year = ?
                   AND s.id <> ?'
            );
            $stTarget->execute([$collegeId, $targetProgram, $targetYearLevel, $targetSection, $semester, $schoolYear, $id]);
            $targetRows = $stTarget->fetchAll();
            foreach ($targetRows as $tr) {
                $existingDays = parse_day_set((string) $tr['day_of_week']);
                if (!array_intersect($days, $existingDays)) {
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

    if (!$errors) {
        db()->prepare(
            'UPDATE schedules
             SET faculty_id=?, course_id=?, room_id=?, college_id=?, schedule_type=?, day_of_week=?, start_time=?, end_time=?, semester=?, school_year=?, academic_year=?
             WHERE id=?'
        )->execute([
            $facultyId,
            $courseId,
            $roomId,
            $collegeId,
            $scheduleType,
            days_to_set($days),
            $startTime,
            $endTime,
            $semester,
            $schoolYear,
            $academicYear,
            $id,
        ]);
        if ($hasGeScheduleTargets && $targetProgram !== '' && $targetYearLevel !== '' && $targetSection !== '') {
            db()->prepare(
                'INSERT INTO ge_schedule_targets (schedule_id, college_id, program_name, year_level, section)
                 VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    college_id = VALUES(college_id),
                    program_name = VALUES(program_name),
                    year_level = VALUES(year_level),
                    section = VALUES(section)'
            )->execute([$id, $collegeId, $targetProgram, $targetYearLevel, $targetSection]);
        }
        if ($conflicts) {
            log_conflicts($id, $conflicts, false);
        }
        $_SESSION['flash'] = 'GE schedule updated.';
        header('Location: schedule.php');
        exit;
    }
} else {
    $old = $row;
    $old['day_array'] = parse_day_set((string) $row['day_of_week']);
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
$programsByCollege = [];
foreach ($programRows as $pr) {
    $cid = (int) $pr['college_id'];
    if (!isset($programsByCollege[$cid])) {
        $programsByCollege[$cid] = [];
    }
    $programsByCollege[$cid][] = [
        'name' => (string) $pr['program_name'],
        'college_code' => (string) $pr['college_code'],
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

$pageTitle = 'Edit GE Schedule';
require_once __DIR__ . '/includes/header.php';
?>
<h1 class="h3 mb-4"><i class="fa-solid fa-pen me-2 text-primary"></i>Edit GE schedule</h1>
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
                    <?php foreach (['1','2','3','4','5'] as $yl): ?>
                        <option value="<?= $yl ?>" <?= ((string) ($old['target_year_level'] ?? '') === $yl) ? 'selected' : '' ?>><?= htmlspecialchars($yl) ?></option>
                    <?php endforeach; ?>
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
                <button type="submit" class="btn btn-primary"<?= app_tooltip_attr('Saves your edits to this GE schedule row.') ?>>Update GE schedule</button>
                <a href="schedule.php" class="btn btn-outline-secondary"<?= app_tooltip_attr('Returns to the schedule list without further edits here.') ?>>Back</a>
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
        while (programSel.firstChild) {
            programSel.removeChild(programSel.firstChild);
        }
        const rows = data[collegeSel.value] || [];
        const first = document.createElement('option');
        first.value = '';
        first.textContent = '— Select program —';
        programSel.appendChild(first);
        rows.forEach((r) => {
            const opt = document.createElement('option');
            opt.value = r.name;
            opt.textContent = r.name;
            programSel.appendChild(opt);
        });
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
        programSel.addEventListener('change', renderSections);
    }
    if (collegeSel) {
        collegeSel.addEventListener('change', renderSections);
    }

    renderPrograms();
    renderSections();
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

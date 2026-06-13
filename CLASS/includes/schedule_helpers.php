<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

/**
 * @param array<string,mixed> $defaults
 * @param array<string,mixed> $options Pass `edit_single_row` => true when editing one schedule row (no lab split block).
 */
function render_schedule_form(array $defaults = [], array $options = []): void
{
    $editSingleRow = !empty($options['edit_single_row']);
    $role = (string) ($_SESSION['role'] ?? '');
    $collegeId = isset($_SESSION['college_id']) ? (int) $_SESSION['college_id'] : null;
    $programScope = $role === 'program_chair' ? current_program_scope() : null;
    $hasLabFlag = db_column_exists('courses', 'is_laboratory');
    $hasProgramsTable = db_table_exists('programs');
    $hasYearLevel = db_column_exists('courses', 'year_level');
    $hasSection = db_column_exists('courses', 'section');
    $showScopedRole = in_array($role, ['dean', 'program_chair'], true) && $collegeId;
    $showBlockScope = $showScopedRole && $hasYearLevel && $hasSection;
    $showProgramFilter = $hasProgramsTable && $role === 'dean' && $collegeId;
    $showProgramLocked = $hasProgramsTable && $role === 'program_chair' && $collegeId && $programScope !== null;
    $programOptions = [];
    if ($showProgramFilter || $showProgramLocked) {
        $st = db()->prepare("SELECT program_name FROM programs WHERE college_id=? AND status='active' ORDER BY program_name");
        $st->execute([$collegeId]);
        $programOptions = $st->fetchAll(PDO::FETCH_COLUMN);
    }
    $selectedProgram = $programScope ?? (string) ($defaults['program'] ?? '');
    $yearLevelOptions = ['1', '2', '3', '4', '5'];
    $sectionOptions = [];
    $programYearLevelsByName = [];
    $courseYearLevelsByProgramName = [];
    $mergedDeanYearLevelsCollege = [];

    if ($showBlockScope) {
        $hasProgYlTable = db_table_exists('programs_year_levels');
        if ($hasProgYlTable && $collegeId) {
            $programYearLevelsByName = dean_program_year_levels_map($collegeId);
            foreach ($programYearLevelsByName as $arr) {
                foreach ($arr as $yv) {
                    $yv = trim((string) $yv);
                    if ($yv !== '') {
                        $mergedDeanYearLevelsCollege[] = $yv;
                    }
                }
            }
            $mergedDeanYearLevelsCollege = sort_schedule_year_levels($mergedDeanYearLevelsCollege);
        }

        $scopeCourseParams = [$collegeId];
        $scopeCourseSqlExtra = '';
        if ($programScope !== null) {
            $scopeCourseSqlExtra = ' AND department=? ';
            $scopeCourseParams[] = $programScope;
        }

        $st = db()->prepare(
            'SELECT DISTINCT TRIM(COALESCE(department,\'\')) AS dep, TRIM(year_level) AS yl
             FROM courses
             WHERE college_id=?
               AND TRIM(COALESCE(year_level,\'\'))<>\'\'
               AND TRIM(COALESCE(department,\'\'))<>\'\'
               ' . $scopeCourseSqlExtra . '
             ORDER BY dep, yl'
        );
        $st->execute($scopeCourseParams);
        while ($crow = $st->fetch(PDO::FETCH_ASSOC)) {
            $dp = trim((string) ($crow['dep'] ?? ''));
            $yl = trim((string) ($crow['yl'] ?? ''));
            if ($dp === '' || $yl === '') {
                continue;
            }
            if (!isset($courseYearLevelsByProgramName[$dp])) {
                $courseYearLevelsByProgramName[$dp] = [];
            }
            if (!in_array($yl, $courseYearLevelsByProgramName[$dp], true)) {
                $courseYearLevelsByProgramName[$dp][] = $yl;
            }
        }
        foreach ($courseYearLevelsByProgramName as $k => $_) {
            $courseYearLevelsByProgramName[$k] = sort_schedule_year_levels($courseYearLevelsByProgramName[$k]);
        }

        $courseYearLevelsCollegeWide = [];
        foreach ($courseYearLevelsByProgramName as $arrs) {
            foreach ($arrs as $yv) {
                $courseYearLevelsCollegeWide[] = $yv;
            }
        }
        $courseYearLevelsCollegeWide = sort_schedule_year_levels($courseYearLevelsCollegeWide);

        $progFocus = trim($selectedProgram);

        $pick = [];
        if ($progFocus !== '' && isset($programYearLevelsByName[$progFocus]) && $programYearLevelsByName[$progFocus] !== []) {
            $pick = $programYearLevelsByName[$progFocus];
        } elseif ($progFocus !== '' && isset($courseYearLevelsByProgramName[$progFocus]) && $courseYearLevelsByProgramName[$progFocus] !== []) {
            $pick = $courseYearLevelsByProgramName[$progFocus];
        } elseif ($progFocus === '' && $mergedDeanYearLevelsCollege !== []) {
            $pick = $mergedDeanYearLevelsCollege;
        } elseif ($courseYearLevelsCollegeWide !== []) {
            $pick = $courseYearLevelsCollegeWide;
        } else {
            $pick = $yearLevelOptions;
        }

        $yearLevelOptions = $pick;

        $st = db()->prepare(
            "SELECT DISTINCT TRIM(section) AS sec
             FROM courses
             WHERE college_id=?
               AND TRIM(COALESCE(section,''))<>''
               " . ($programScope !== null ? "AND department=? " : '') . "
             ORDER BY sec"
        );
        $secParams = [$collegeId];
        if ($programScope !== null) {
            $secParams[] = $programScope;
        }
        $st->execute($secParams);
        $sectionOptions = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
        if ($sectionOptions === []) {
            $sectionOptions = ['A', 'B', 'C', 'D', 'E'];
        }
    }
    $colPick = $showBlockScope
        ? 'col-12 col-sm-6 col-lg-2'
        : (($showProgramFilter || $showProgramLocked) && $programOptions !== [] ? 'col-md-3' : 'col-md-4');
    $days = schedule_days_list();
    $selDays = isset($defaults['day_array']) ? $defaults['day_array'] : [];
    $selLabDays = isset($defaults['lab_day_array']) ? $defaults['lab_day_array'] : [];
    if (!$selDays && !empty($defaults['day_of_week'])) {
        $selDays = parse_day_set((string) $defaults['day_of_week']);
    }
    if (!$selLabDays && !empty($defaults['lab_day_of_week'])) {
        $selLabDays = parse_day_set((string) $defaults['lab_day_of_week']);
    }
    if ($showScopedRole) {
        $facultySql = "SELECT id, faculty_id, full_name FROM faculty WHERE status='active' AND college_id=?";
        $facultyParams = [$collegeId];
        if ($programScope !== null) {
            $facultySql .= " AND department=?";
            $facultyParams[] = $programScope;
        }
        $facultySql .= " ORDER BY full_name";
        $st = db()->prepare($facultySql);
        $st->execute($facultyParams);
        $faculty = $st->fetchAll();
        $blockCols = $showBlockScope ? ', year_level, section' : '';
        $courseWhere = " WHERE college_id=?";
        $courseParams = [$collegeId];
        if ($programScope !== null) {
            $courseWhere .= " AND department=?";
            $courseParams[] = $programScope;
        }
        if ($hasLabFlag) {
            $st = db()->prepare("SELECT id, course_code, course_name, is_laboratory, department{$blockCols} FROM courses{$courseWhere} ORDER BY course_code");
        } else {
            $st = db()->prepare("SELECT id, course_code, course_name, 0 AS is_laboratory, department{$blockCols} FROM courses{$courseWhere} ORDER BY course_code");
        }
        $st->execute($courseParams);
        $courses = $st->fetchAll();
        $roomSql = "SELECT id, room_code, room_name FROM rooms WHERE status IN ('available','tba')";
        $roomParams = [];
        if ($collegeId) {
            $roomSql .= " AND college_id=?";
            $roomParams[] = $collegeId;
        }
        $roomSql .= " ORDER BY room_code";
        $st = db()->prepare($roomSql);
        $st->execute($roomParams);
        $rooms = $st->fetchAll();
    } else {
        $faculty = db()->query("SELECT id, faculty_id, full_name FROM faculty WHERE status='active' ORDER BY full_name")->fetchAll();
        if ($hasLabFlag) {
            $courses = db()->query('SELECT id, course_code, course_name, is_laboratory, department FROM courses ORDER BY course_code')->fetchAll();
        } else {
            $courses = db()->query('SELECT id, course_code, course_name, 0 AS is_laboratory, department FROM courses ORDER BY course_code')->fetchAll();
        }
        $rooms = db()->query("SELECT id, room_code, room_name FROM rooms WHERE status IN ('available','tba') ORDER BY room_code")->fetchAll();
    }
    $semesters = ['1st Semester', '2nd Semester', 'Summer'];
    $types = ['MW', 'TTH', 'MWF', 'TTHS', 'Saturday', 'Sunday', 'MW_TTH', 'Custom'];
    $scheduleYlDyn = null;
    if ($showBlockScope) {
        $scheduleYlDyn = [
            'byProgramDean' => $programYearLevelsByName,
            'courseByProgram' => $courseYearLevelsByProgramName,
            'deanCollegeUnion' => $mergedDeanYearLevelsCollege,
            'courseCollegeWide' => $courseYearLevelsCollegeWide ?? [],
            'numericFallback' => ['1', '2', '3', '4', '5'],
            'lockedProgram' => $programScope !== null ? trim((string) $programScope) : '',
        ];
    }
    ?>
    <div class="row g-3">
        <?php if (($showProgramFilter || $showProgramLocked) && $programOptions === []): ?>
            <div class="col-12">
                <div class="alert alert-light border small mb-0 py-2">No active programs yet. Add them under <strong>Programs</strong> to filter courses by program on this form.</div>
            </div>
        <?php endif; ?>
        <?php if ($showBlockScope): ?>
            <div class="col-12">
                <div class="alert alert-light border small mb-0 py-2">Select <strong>year level</strong> and <strong>section</strong> to filter courses (must match the course record). Year levels listed here match what the dean configures under Programs for each program.</div>
            </div>
        <?php endif; ?>
        <div class="<?= $colPick ?>">
            <label class="form-label">Faculty</label>
            <select name="faculty_id" class="form-select" required>
                <option value="">— Select —</option>
                <?php foreach ($faculty as $f): ?>
                    <option value="<?= (int) $f['id'] ?>" <?= (int) ($defaults['faculty_id'] ?? 0) === (int) $f['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($f['full_name'] . ' (' . $f['faculty_id'] . ')') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($showProgramFilter && $programOptions !== []): ?>
        <div class="<?= $colPick ?>">
            <label class="form-label">Program</label>
            <select name="program" id="schedule_program_filter" class="form-select">
                <option value="">All programs</option>
                <?php foreach ($programOptions as $pn): ?>
                    <option value="<?= htmlspecialchars((string) $pn) ?>" <?= $selectedProgram === (string) $pn ? 'selected' : '' ?>><?= htmlspecialchars((string) $pn) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="form-text">Filter courses by program (same list as in Courses).</div>
        </div>
        <?php endif; ?>
        <?php if ($showProgramLocked): ?>
        <div class="<?= $colPick ?>">
            <label class="form-label">Program</label>
            <input type="text" class="form-control" value="<?= htmlspecialchars($selectedProgram) ?>" readonly>
            <input type="hidden" name="program" value="<?= htmlspecialchars($selectedProgram) ?>">
            <div class="form-text">Locked to your assigned program.</div>
        </div>
        <?php endif; ?>
        <?php if ($showBlockScope): ?>
        <div class="<?= $colPick ?>">
            <label class="form-label">Year level</label>
            <select name="year_level" id="schedule_year_level" class="form-select" required>
                <option value="">— Select —</option>
                <?php foreach ($yearLevelOptions as $yl): ?>
                    <option value="<?= htmlspecialchars((string) $yl) ?>" <?= ((string) ($defaults['year_level'] ?? '')) === (string) $yl ? 'selected' : '' ?>><?= htmlspecialchars((string) $yl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="<?= $colPick ?>">
            <label class="form-label">Section</label>
            <select name="section" id="schedule_section" class="form-select" required>
                <option value="">— Select —</option>
                <?php foreach ($sectionOptions as $sec): ?>
                    <option value="<?= htmlspecialchars((string) $sec) ?>" <?= ((string) ($defaults['section'] ?? '')) === (string) $sec ? 'selected' : '' ?>><?= htmlspecialchars((string) $sec) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="<?= $colPick ?>">
            <label class="form-label">Course</label>
            <select name="course_id" id="course_id" class="form-select" required>
                <option value="">— Select —</option>
                <?php foreach ($courses as $c): ?>
                    <?php
                    $dep = trim((string) ($c['department'] ?? ''));
                    $cy = trim((string) ($c['year_level'] ?? ''));
                    $cs = trim((string) ($c['section'] ?? ''));
                    ?>
                    <option value="<?= (int) $c['id'] ?>" data-is-lab="<?= !empty($c['is_laboratory']) ? '1' : '0' ?>" data-department="<?= htmlspecialchars($dep) ?>"<?php if ($showBlockScope): ?> data-year-level="<?= htmlspecialchars($cy) ?>" data-section="<?= htmlspecialchars($cs) ?>"<?php endif; ?> <?= (int) ($defaults['course_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['course_code'] . ' — ' . $c['course_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="<?= $colPick ?>">
            <label class="form-label">Room</label>
            <select name="room_id" class="form-select">
                <option value="">— Select —</option>
                <?php foreach ($rooms as $r): ?>
                    <option value="<?= (int) $r['id'] ?>" <?= (int) ($defaults['room_id'] ?? 0) === (int) $r['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($r['room_code'] . ($r['room_name'] ? ' — ' . $r['room_name'] : '')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">Schedule type</label>
            <select name="schedule_type" class="form-select" id="schedule_type">
                <?php foreach ($types as $t): ?>
                    <option value="<?= $t ?>" <?= ($defaults['schedule_type'] ?? 'Custom') === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12">
            <label class="form-label">Days</label>
            <div class="d-flex flex-wrap gap-3">
                <?php foreach ($days as $d): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="days[]" value="<?= htmlspecialchars($d) ?>"
                               id="day_<?= htmlspecialchars($d) ?>"
                            <?= in_array($d, $selDays, true) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="day_<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="col-md-3">
            <label class="form-label">Start time</label>
            <input type="time" name="start_time" class="form-control" required
                   value="<?= htmlspecialchars(substr((string) ($defaults['start_time'] ?? '08:00'), 0, 5)) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label">End time</label>
            <input type="time" name="end_time" class="form-control" required
                   value="<?= htmlspecialchars(substr((string) ($defaults['end_time'] ?? '09:00'), 0, 5)) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label">Semester</label>
            <select name="semester" class="form-select" required>
                <?php foreach ($semesters as $s): ?>
                    <option value="<?= htmlspecialchars($s) ?>" <?= ($defaults['semester'] ?? '') === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">School year</label>
            <input type="text" name="school_year" class="form-control" required placeholder="e.g. 2025-2026"
                   value="<?= htmlspecialchars((string) ($defaults['school_year'] ?? '')) ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label">Academic year</label>
            <input type="text" name="academic_year" class="form-control" placeholder="Optional label"
                   value="<?= htmlspecialchars((string) ($defaults['academic_year'] ?? '')) ?>">
        </div>
    </div>
    <?php if (!$editSingleRow): ?>
    <div class="mt-4 p-3 border rounded bg-light" id="labScheduleSection" style="display:none;">
        <h6 class="mb-3">Laboratory Schedule (separate days/time)</h6>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Laboratory room</label>
                <select name="lab_room_id" class="form-select">
                    <option value="">— Select lab room —</option>
                    <?php foreach ($rooms as $r): ?>
                        <option value="<?= (int) $r['id'] ?>" <?= (int) ($defaults['lab_room_id'] ?? 0) === (int) $r['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($r['room_code'] . ($r['room_name'] ? ' — ' . $r['room_name'] : '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label">Laboratory days</label>
                <div class="d-flex flex-wrap gap-3">
                    <?php foreach ($days as $d): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="lab_days[]" value="<?= htmlspecialchars($d) ?>"
                                   id="lab_day_<?= htmlspecialchars($d) ?>" <?= in_array($d, $selLabDays, true) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="lab_day_<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label">Laboratory start time</label>
                <input type="time" name="lab_start_time" class="form-control" value="<?= htmlspecialchars(substr((string) ($defaults['lab_start_time'] ?? '13:00'), 0, 5)) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Laboratory end time</label>
                <input type="time" name="lab_end_time" class="form-control" value="<?= htmlspecialchars(substr((string) ($defaults['lab_end_time'] ?? '16:00'), 0, 5)) ?>">
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if (($role ?? '') === 'admin'): ?>
        <div class="mt-4 p-3 border rounded bg-light">
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" name="override_conflicts" value="1" id="override_conflicts">
                <label class="form-check-label" for="override_conflicts">Override conflicts (admin password required)</label>
            </div>
            <label class="form-label small text-muted">Admin password</label>
            <input type="password" name="admin_password" class="form-control form-control-sm" style="max-width:320px" autocomplete="new-password"
                   placeholder="Only if overriding">
        </div>
    <?php endif; ?>
    <script>
        (function () {
            const courseSel = document.getElementById('course_id');
            if (!courseSel) return;
            const labSection = document.getElementById('labScheduleSection');
            const programSel = document.getElementById('schedule_program_filter');
            const yearSel = document.getElementById('schedule_year_level');
            const sectionSel = document.getElementById('schedule_section');
            const ylDyn = <?= ($scheduleYlDyn !== null ? json_encode($scheduleYlDyn, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 'null') ?>;

            function syncLabSection() {
                if (!labSection) return;
                const opt = courseSel.options[courseSel.selectedIndex];
                const isLab = opt && opt.getAttribute('data-is-lab') === '1';
                labSection.style.display = isLab ? 'block' : 'none';
            }

            function currentProgramScope() {
                if (ylDyn && ylDyn.lockedProgram && String(ylDyn.lockedProgram).trim()) {
                    return String(ylDyn.lockedProgram).trim();
                }
                return programSel ? (programSel.value || '').trim() : '';
            }

            function resolveLevelsList(progRaw) {
                if (!ylDyn) {
                    return null;
                }
                const deanMap = ylDyn.byProgramDean || {};
                const courseMap = ylDyn.courseByProgram || {};
                if (progRaw && Array.isArray(deanMap[progRaw]) && deanMap[progRaw].length) {
                    return deanMap[progRaw];
                }
                if (progRaw && Array.isArray(courseMap[progRaw]) && courseMap[progRaw].length) {
                    return courseMap[progRaw];
                }
                if (!progRaw && Array.isArray(ylDyn.deanCollegeUnion) && ylDyn.deanCollegeUnion.length) {
                    return ylDyn.deanCollegeUnion;
                }
                if (Array.isArray(ylDyn.courseCollegeWide) && ylDyn.courseCollegeWide.length) {
                    return ylDyn.courseCollegeWide;
                }
                return ylDyn.numericFallback || [];
            }

            function refreshYearLevelOptions() {
                if (!yearSel || !ylDyn) {
                    filterCourses();
                    return;
                }
                const prev = String(yearSel.value || '');
                const prog = currentProgramScope();
                const list = resolveLevelsList(prog) || [];
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
                if (prev && list.indexOf(prev) !== -1) {
                    yearSel.value = prev;
                } else {
                    yearSel.value = '';
                }
                filterCourses();
            }

            function filterCourses() {
                const program = programSel ? (programSel.value || '').trim() : '';
                let programEffective = program;
                if ((!programSel || program === '') && ylDyn && ylDyn.lockedProgram) {
                    programEffective = String(ylDyn.lockedProgram).trim();
                }
                const year = yearSel ? (yearSel.value || '').trim() : '';
                const section = sectionSel ? (sectionSel.value || '').trim() : '';
                for (let i = 0; i < courseSel.options.length; i++) {
                    const opt = courseSel.options[i];
                    if (!opt.value) {
                        opt.hidden = false;
                        continue;
                    }
                    const dep = (opt.getAttribute('data-department') || '').trim();
                    const yl = (opt.getAttribute('data-year-level') || '').trim();
                    const sec = (opt.getAttribute('data-section') || '').trim();
                    let hide = false;
                    if (programSel && program && dep !== program) {
                        hide = true;
                    }
                    if (!programSel && ylDyn && ylDyn.lockedProgram && dep !== programEffective) {
                        hide = true;
                    }
                    if (yearSel && year && yl !== year) {
                        hide = true;
                    }
                    if (sectionSel && section && sec !== section) {
                        hide = true;
                    }
                    opt.hidden = hide;
                }
                const sel = courseSel.options[courseSel.selectedIndex];
                if (sel && sel.hidden) {
                    courseSel.value = '';
                }
                syncLabSection();
            }
            if (labSection) {
                courseSel.addEventListener('change', syncLabSection);
            }
            if (programSel && ylDyn) {
                programSel.addEventListener('change', refreshYearLevelOptions);
            }
            if (yearSel) {
                yearSel.addEventListener('change', filterCourses);
            }
            if (sectionSel) {
                sectionSel.addEventListener('change', filterCourses);
            }
            if (yearSel && ylDyn) {
                refreshYearLevelOptions();
            } else {
                filterCourses();
            }
        })();
    </script>
    <?php
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

function schedule_program_scope_key(int $collegeId, string $programName): string
{
    return $collegeId . '|' . trim($programName);
}

function schedule_program_option_value(int $collegeId, string $programName, bool $useCollegeScope): string
{
    $programName = trim($programName);
    if ($useCollegeScope && $collegeId > 0) {
        return $collegeId . '::' . $programName;
    }

    return $programName;
}

/** @return array{college_id:int,program_name:string} */
function schedule_program_option_parse(string $value): array
{
    $value = trim($value);
    if ($value !== '' && preg_match('/^(\d+)::(.+)$/s', $value, $m)) {
        return ['college_id' => (int) $m[1], 'program_name' => trim($m[2])];
    }

    return ['college_id' => 0, 'program_name' => $value];
}

/**
 * @param array<string,mixed> $defaults
 * @param array<string,mixed> $options Pass `edit_single_row` => true when editing one schedule row (no lab split block).
 */
function render_schedule_form(array $defaults = [], array $options = []): void
{
    $editSingleRow = !empty($options['edit_single_row']);
    $unlockProgram = !empty($options['unlock_program']);
    $allCollegePrograms = !empty($options['all_college_programs']);
    $role = (string) ($_SESSION['role'] ?? '');
    $collegeId = isset($_SESSION['college_id']) ? (int) $_SESSION['college_id'] : null;
    $programScope = $role === 'program_chair' ? current_program_scope() : null;
    $effectiveProgramScope = $unlockProgram ? null : $programScope;
    $hasLabFlag = db_column_exists('courses', 'is_laboratory');
    $hasProgramsTable = db_table_exists('programs');
    $hasYearLevel = db_column_exists('courses', 'year_level');
    $hasSection = db_column_exists('courses', 'section');
    $geProgramChair = is_ge_program_scope($programScope);
    $showScopedRole = in_array($role, ['dean', 'program_chair'], true) && $collegeId;
    $showGeTargetScope = $geProgramChair && $hasProgramsTable;
    $showBlockScope = $showScopedRole && $hasYearLevel && $hasSection && !$geProgramChair;
    $showProgramFilter = $hasProgramsTable && $collegeId && ($role === 'dean' || ($unlockProgram && $role === 'program_chair'));
    $showProgramLocked = !$unlockProgram && $hasProgramsTable && $role === 'program_chair' && $collegeId && $programScope !== null;
    $programOptionsList = [];
    if ($showProgramFilter || $showProgramLocked) {
        if ($allCollegePrograms) {
            $st = db()->query(
                "SELECT p.college_id, p.program_name, c.college_code
                 FROM programs p
                 INNER JOIN colleges c ON c.id = p.college_id
                 WHERE p.status='active'
                 ORDER BY c.college_code, p.program_name"
            );
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $name = (string) ($row['program_name'] ?? '');
                $progCollegeId = (int) ($row['college_id'] ?? 0);
                if ($name === '' || $progCollegeId < 1) {
                    continue;
                }
                $programOptionsList[] = [
                    'college_id' => $progCollegeId,
                    'name' => $name,
                    'value' => schedule_program_option_value($progCollegeId, $name, true),
                    'label' => (string) ($row['college_code'] ?? '') . ' — ' . $name,
                ];
            }
        } else {
            $st = db()->prepare("SELECT program_name FROM programs WHERE college_id=? AND status='active' ORDER BY program_name");
            $st->execute([$collegeId]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $pn) {
                $name = (string) $pn;
                if ($name === '') {
                    continue;
                }
                $programOptionsList[] = [
                    'college_id' => (int) $collegeId,
                    'name' => $name,
                    'value' => schedule_program_option_value((int) $collegeId, $name, false),
                    'label' => $name,
                ];
            }
        }
    }
    $selectedProgram = (!$unlockProgram && $programScope !== null)
        ? schedule_program_option_value((int) $collegeId, (string) $programScope, $allCollegePrograms)
        : (string) ($defaults['program'] ?? '');
    $yearLevelOptions = ['1', '2', '3', '4', '5'];
    $sectionOptions = [];
    $programYearLevelsByName = [];
    $courseYearLevelsByProgramName = [];
    $sectionsByScope = [];
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

        $scopeCourseParams = [];
        $scopeCourseSqlExtra = '';
        if ($effectiveProgramScope !== null) {
            $scopeCourseSqlExtra = ' AND department=? ';
            $scopeCourseParams[] = $effectiveProgramScope;
        }
        if ($allCollegePrograms) {
            $scopeCourseSql = 'SELECT DISTINCT college_id, TRIM(COALESCE(department,\'\')) AS dep, TRIM(year_level) AS yl, TRIM(section) AS sec
             FROM courses
             WHERE college_id IS NOT NULL
               AND TRIM(COALESCE(year_level,\'\'))<>\'\'
               AND TRIM(COALESCE(department,\'\'))<>\'\'
               ' . $scopeCourseSqlExtra . '
             ORDER BY college_id, dep, yl, sec';
        } else {
            $scopeCourseParams[] = $collegeId;
            $scopeCourseSql = 'SELECT DISTINCT college_id, TRIM(COALESCE(department,\'\')) AS dep, TRIM(year_level) AS yl, TRIM(section) AS sec
             FROM courses
             WHERE college_id=?
               AND TRIM(COALESCE(year_level,\'\'))<>\'\'
               AND TRIM(COALESCE(department,\'\'))<>\'\'
               ' . $scopeCourseSqlExtra . '
             ORDER BY dep, yl, sec';
        }

        $st = db()->prepare($scopeCourseSql);
        $st->execute($scopeCourseParams);
        while ($crow = $st->fetch(PDO::FETCH_ASSOC)) {
            $cid = (int) ($crow['college_id'] ?? 0);
            $dp = trim((string) ($crow['dep'] ?? ''));
            $yl = trim((string) ($crow['yl'] ?? ''));
            $sec = trim((string) ($crow['sec'] ?? ''));
            if ($cid < 1 || $dp === '' || $yl === '') {
                continue;
            }
            $scopeKey = $allCollegePrograms ? schedule_program_scope_key($cid, $dp) : $dp;
            if (!isset($courseYearLevelsByProgramName[$scopeKey])) {
                $courseYearLevelsByProgramName[$scopeKey] = [];
            }
            if (!in_array($yl, $courseYearLevelsByProgramName[$scopeKey], true)) {
                $courseYearLevelsByProgramName[$scopeKey][] = $yl;
            }
            if ($sec !== '') {
                $sectionKey = $scopeKey . '|' . $yl;
                if (!isset($sectionsByScope[$sectionKey])) {
                    $sectionsByScope[$sectionKey] = [];
                }
                if (!in_array($sec, $sectionsByScope[$sectionKey], true)) {
                    $sectionsByScope[$sectionKey][] = $sec;
                }
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

        $selectedProgramParsed = schedule_program_option_parse($selectedProgram);
        $progFocus = $selectedProgramParsed['program_name'];
        $progFocusCollegeId = $selectedProgramParsed['college_id'];
        $progFocusKey = ($allCollegePrograms && $progFocusCollegeId > 0 && $progFocus !== '')
            ? schedule_program_scope_key($progFocusCollegeId, $progFocus)
            : $progFocus;

        $pick = [];
        if ($progFocus !== '' && !$allCollegePrograms && isset($programYearLevelsByName[$progFocus]) && $programYearLevelsByName[$progFocus] !== []) {
            $pick = $programYearLevelsByName[$progFocus];
        } elseif ($progFocusKey !== '' && isset($courseYearLevelsByProgramName[$progFocusKey]) && $courseYearLevelsByProgramName[$progFocusKey] !== []) {
            $pick = $courseYearLevelsByProgramName[$progFocusKey];
        } elseif ($progFocus === '' && $mergedDeanYearLevelsCollege !== []) {
            $pick = $mergedDeanYearLevelsCollege;
        } elseif ($courseYearLevelsCollegeWide !== []) {
            $pick = $courseYearLevelsCollegeWide;
        } else {
            $pick = $yearLevelOptions;
        }

        $yearLevelOptions = $pick;

        if ($progFocusKey !== '' && $sectionsByScope !== []) {
            $mergedSections = [];
            foreach ($sectionsByScope as $sectionKey => $secs) {
                if (str_starts_with($sectionKey, $progFocusKey . '|')) {
                    foreach ($secs as $sec) {
                        if (!in_array($sec, $mergedSections, true)) {
                            $mergedSections[] = $sec;
                        }
                    }
                }
            }
            $sectionOptions = $mergedSections;
        } else {
            $secSql = "SELECT DISTINCT TRIM(section) AS sec
             FROM courses
             WHERE TRIM(COALESCE(section,''))<>''";
            $secParams = [];
            if ($allCollegePrograms) {
                $secSql .= ' AND college_id IS NOT NULL';
            } else {
                $secSql .= ' AND college_id=?';
                $secParams[] = $collegeId;
            }
            if ($effectiveProgramScope !== null) {
                $secSql .= ' AND department=?';
                $secParams[] = $effectiveProgramScope;
            }
            $secSql .= ' ORDER BY sec';
            $st = db()->prepare($secSql);
            $st->execute($secParams);
            $sectionOptions = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
        }
        if ($sectionOptions === []) {
            $sectionOptions = ['A', 'B', 'C', 'D', 'E'];
        }
    }
    $collegeCodesById = [];
    if ($allCollegePrograms) {
        foreach (db()->query("SELECT id, college_code FROM colleges WHERE status='active'")->fetchAll(PDO::FETCH_ASSOC) as $colRow) {
            $collegeCodesById[(int) ($colRow['id'] ?? 0)] = (string) ($colRow['college_code'] ?? '');
        }
    }
    $colPick = $showBlockScope
        ? 'col-12 col-sm-6 col-lg-2'
        : (($showProgramFilter || $showProgramLocked) && $programOptionsList !== [] ? 'col-md-3' : 'col-md-4');
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
        if ($effectiveProgramScope !== null) {
            $facultySql .= " AND (department=? OR department='')";
            $facultyParams[] = $effectiveProgramScope;
        }
        $facultySql .= " ORDER BY full_name";
        $st = db()->prepare($facultySql);
        $st->execute($facultyParams);
        $faculty = $st->fetchAll();
        $blockCols = $showBlockScope ? ', year_level, section' : '';
        $courses = [];
        if ($geProgramChair && $collegeId && db_column_exists('courses', 'is_gened')) {
            foreach (ge_courses_offered_to_college($collegeId) as $geCourse) {
                $gid = (int) ($geCourse['id'] ?? 0);
                if ($gid < 1) {
                    continue;
                }
                $courses[] = [
                    'id' => $gid,
                    'course_code' => (string) ($geCourse['course_code'] ?? ''),
                    'course_name' => (string) ($geCourse['course_name'] ?? ''),
                    'is_laboratory' => !empty($geCourse['is_laboratory']) ? 1 : 0,
                    'department' => (string) ($geCourse['department'] ?? ge_program_chair_label()),
                    'year_level' => (string) ($geCourse['year_level'] ?? ''),
                    'section' => (string) ($geCourse['section'] ?? ''),
                ];
            }
            usort($courses, static fn ($a, $b) => strcmp((string) $a['course_code'], (string) $b['course_code']));
        } else {
            if ($allCollegePrograms) {
                $courseWhere = ' WHERE college_id IS NOT NULL';
                $courseParams = [];
            } else {
                $courseWhere = ' WHERE college_id=?';
                $courseParams = [$collegeId];
            }
            if ($effectiveProgramScope !== null) {
                $courseWhere .= ' AND department=?';
                $courseParams[] = $effectiveProgramScope;
            }
            $collegeCol = $allCollegePrograms ? ', college_id' : '';
            if ($hasLabFlag) {
                $st = db()->prepare("SELECT id, course_code, course_name, is_laboratory, department{$blockCols}{$collegeCol} FROM courses{$courseWhere} ORDER BY course_code");
            } else {
                $st = db()->prepare("SELECT id, course_code, course_name, 0 AS is_laboratory, department{$blockCols}{$collegeCol} FROM courses{$courseWhere} ORDER BY course_code");
            }
            $st->execute($courseParams);
            $courses = $st->fetchAll();
        }
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
    $geTargetProgramsByCollege = [];
    $geTargetSectionsByScope = [];
    $geTargetCollegeList = [];
    if ($showGeTargetScope) {
        $geTargetCollegeList = db()->query("SELECT id, college_code, college_name FROM colleges WHERE status='active' ORDER BY college_code")->fetchAll();
        $programRows = db()->query(
            "SELECT p.college_id, p.program_name, c.college_code
             FROM programs p
             INNER JOIN colleges c ON c.id = p.college_id
             WHERE p.status='active'
             ORDER BY c.college_code, p.program_name"
        )->fetchAll();
        $hasProgYlTable = db_table_exists('programs_year_levels');
        $programYearLevelsByCollege = [];
        if ($hasProgYlTable) {
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
        foreach ($programRows as $pr) {
            $cid = (int) $pr['college_id'];
            if (!isset($geTargetProgramsByCollege[$cid])) {
                $geTargetProgramsByCollege[$cid] = [];
            }
            $nm = (string) $pr['program_name'];
            $deanYl = isset($programYearLevelsByCollege[$cid][$nm])
                ? sort_schedule_year_levels($programYearLevelsByCollege[$cid][$nm])
                : [];
            $geTargetProgramsByCollege[$cid][] = [
                'name' => $nm,
                'college_code' => (string) $pr['college_code'],
                'year_levels' => ($deanYl !== []) ? $deanYl : ['1', '2', '3', '4', '5'],
            ];
        }
        if ($hasYearLevel && $hasSection) {
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
            foreach ($deanSectionRows as $sr) {
                $cid = (int) ($sr['college_id'] ?? 0);
                $program = (string) ($sr['department'] ?? '');
                $yl = (string) ($sr['year_level'] ?? '');
                $sec = (string) ($sr['section'] ?? '');
                if ($cid < 1 || $program === '' || $yl === '' || $sec === '') {
                    continue;
                }
                if (!isset($geTargetSectionsByScope[$cid])) {
                    $geTargetSectionsByScope[$cid] = [];
                }
                if (!isset($geTargetSectionsByScope[$cid][$program])) {
                    $geTargetSectionsByScope[$cid][$program] = [];
                }
                if (!isset($geTargetSectionsByScope[$cid][$program][$yl])) {
                    $geTargetSectionsByScope[$cid][$program][$yl] = [];
                }
                if (!in_array($sec, $geTargetSectionsByScope[$cid][$program][$yl], true)) {
                    $geTargetSectionsByScope[$cid][$program][$yl][] = $sec;
                }
            }
        }
    }
    if ($showBlockScope) {
        $scheduleYlDyn = [
            'byProgramDean' => $programYearLevelsByName,
            'courseByProgram' => $courseYearLevelsByProgramName,
            'sectionsByScope' => $sectionsByScope,
            'deanCollegeUnion' => $mergedDeanYearLevelsCollege,
            'courseCollegeWide' => $courseYearLevelsCollegeWide ?? [],
            'numericFallback' => ['1', '2', '3', '4', '5'],
            'lockedProgram' => $effectiveProgramScope !== null ? trim((string) $effectiveProgramScope) : '',
            'useCollegeScope' => $allCollegePrograms,
        ];
    }
    ?>
    <div class="row g-3">
        <?php if ($showGeTargetScope): ?>
            <div class="col-12">
                <div class="alert alert-light border small mb-0 py-2">
                    Schedule a <strong>General Education</strong> class for students in any college program. Teaching is assigned to faculty in your college (<?= htmlspecialchars(college_name_by_id($collegeId)) ?>).
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Target college</label>
                <select name="target_college_id" id="ge_target_college_id" class="form-select" required>
                    <option value="">— Select —</option>
                    <?php foreach ($geTargetCollegeList as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= (int) ($defaults['target_college_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['college_code'] . ' — ' . $c['college_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Target program</label>
                <select name="target_program" id="ge_target_program" class="form-select" required>
                    <option value="">— Select program —</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Target year level</label>
                <select name="target_year_level" id="ge_target_year_level" class="form-select" required>
                    <option value="">— Select —</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Target section</label>
                <?php if ($hasYearLevel && $hasSection): ?>
                    <select name="target_section" id="ge_target_section" class="form-select" required>
                        <option value="">— Select —</option>
                    </select>
                <?php else: ?>
                    <input type="text" name="target_section" class="form-control" maxlength="20" required value="<?= htmlspecialchars((string) ($defaults['target_section'] ?? '')) ?>">
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if (($showProgramFilter || $showProgramLocked) && $programOptionsList === []): ?>
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
        <?php if ($showProgramFilter && $programOptionsList !== []): ?>
        <div class="<?= $colPick ?>">
            <label class="form-label">Program</label>
            <select name="program" id="schedule_program_filter" class="form-select">
                <option value="">All programs</option>
                <?php foreach ($programOptionsList as $po): ?>
                    <option value="<?= htmlspecialchars($po['value']) ?>" <?= $selectedProgram === $po['value'] ? 'selected' : '' ?>><?= htmlspecialchars($po['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="form-text"><?= $allCollegePrograms ? 'Filter courses by program (all colleges).' : 'Filter courses by program (same list as in Courses).' ?></div>
        </div>
        <?php endif; ?>
        <?php if ($showProgramLocked): ?>
        <div class="<?= $colPick ?>">
            <label class="form-label">Program</label>
            <input type="text" class="form-control" value="<?= htmlspecialchars((string) $programScope) ?>" readonly>
            <?php if (!$geProgramChair): ?>
            <input type="hidden" name="program" value="<?= htmlspecialchars((string) $programScope) ?>">
            <?php endif; ?>
            <div class="form-text"><?= $geProgramChair ? 'GE scheduling uses the target program above.' : 'Locked to your assigned program.' ?></div>
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
                    $courseCollegeId = (int) ($c['college_id'] ?? $collegeId ?? 0);
                    $courseLabel = $c['course_code'] . ' — ' . $c['course_name'];
                    if ($allCollegePrograms && $courseCollegeId > 0) {
                        $courseCodePrefix = $collegeCodesById[$courseCollegeId] ?? '';
                        if ($courseCodePrefix !== '') {
                            $courseLabel = $courseCodePrefix . ' — ' . $courseLabel;
                        }
                    }
                    ?>
                    <option value="<?= (int) $c['id'] ?>" data-is-lab="<?= !empty($c['is_laboratory']) ? '1' : '0' ?>" data-department="<?= htmlspecialchars($dep) ?>" data-college-id="<?= $courseCollegeId ?>"<?php if ($showBlockScope): ?> data-year-level="<?= htmlspecialchars($cy) ?>" data-section="<?= htmlspecialchars($cs) ?>"<?php endif; ?> <?= (int) ($defaults['course_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($courseLabel) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="<?= $colPick ?>">
            <label class="form-label" id="scheduleMainRoomLabel">Room</label>
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
                const roomLabel = document.getElementById('scheduleMainRoomLabel');
                if (roomLabel) {
                    roomLabel.textContent = isLab ? 'Lecture room' : 'Room';
                }
            }

            function currentProgramSelection() {
                if (ylDyn && ylDyn.lockedProgram && String(ylDyn.lockedProgram).trim()) {
                    return { collegeId: '', program: String(ylDyn.lockedProgram).trim() };
                }
                if (!programSel) {
                    return { collegeId: '', program: '' };
                }
                const val = (programSel.value || '').trim();
                if (!val) {
                    return { collegeId: '', program: '' };
                }
                if (ylDyn && ylDyn.useCollegeScope && val.includes('::')) {
                    const splitAt = val.indexOf('::');
                    return {
                        collegeId: val.slice(0, splitAt),
                        program: val.slice(splitAt + 2),
                    };
                }
                return { collegeId: '', program: val };
            }

            function programScopeKey(collegeId, program) {
                if (!program) {
                    return '';
                }
                if (ylDyn && ylDyn.useCollegeScope && collegeId) {
                    return String(collegeId) + '|' + program;
                }
                return program;
            }

            function resolveLevelsList(collegeId, progRaw) {
                if (!ylDyn) {
                    return null;
                }
                const scopeKey = programScopeKey(collegeId, progRaw);
                const deanMap = ylDyn.byProgramDean || {};
                const courseMap = ylDyn.courseByProgram || {};
                if (scopeKey && Array.isArray(courseMap[scopeKey]) && courseMap[scopeKey].length) {
                    return courseMap[scopeKey];
                }
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

            function refreshSectionOptions() {
                if (!sectionSel || !ylDyn || !ylDyn.sectionsByScope) {
                    filterCourses();
                    return;
                }
                const prev = String(sectionSel.value || '');
                const selection = currentProgramSelection();
                const year = yearSel ? (yearSel.value || '').trim() : '';
                const scopeKey = programScopeKey(selection.collegeId, selection.program);
                const sectionKey = scopeKey && year ? (scopeKey + '|' + year) : '';
                const list = sectionKey ? (ylDyn.sectionsByScope[sectionKey] || []) : [];
                sectionSel.innerHTML = '';
                const z = document.createElement('option');
                z.value = '';
                z.textContent = '\u2014 Select \u2014';
                sectionSel.appendChild(z);
                for (let i = 0; i < list.length; i++) {
                    const sec = String(list[i]);
                    const opt = document.createElement('option');
                    opt.value = sec;
                    opt.textContent = sec;
                    sectionSel.appendChild(opt);
                }
                if (prev && list.indexOf(prev) !== -1) {
                    sectionSel.value = prev;
                } else {
                    sectionSel.value = '';
                }
                filterCourses();
            }

            function refreshYearLevelOptions() {
                if (!yearSel || !ylDyn) {
                    filterCourses();
                    return;
                }
                const prev = String(yearSel.value || '');
                const selection = currentProgramSelection();
                const list = resolveLevelsList(selection.collegeId, selection.program) || [];
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
                refreshSectionOptions();
            }

            function filterCourses() {
                const selection = currentProgramSelection();
                const program = selection.program;
                const programCollegeId = selection.collegeId;
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
                    const courseCollegeId = (opt.getAttribute('data-college-id') || '').trim();
                    let hide = false;
                    if (programSel && program) {
                        if (dep !== program) {
                            hide = true;
                        }
                        if (!hide && programCollegeId && courseCollegeId && courseCollegeId !== programCollegeId) {
                            hide = true;
                        }
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
            if (programSel) {
                programSel.addEventListener('change', function () {
                    if (ylDyn) {
                        refreshYearLevelOptions();
                    } else {
                        filterCourses();
                    }
                });
            }
            if (yearSel) {
                yearSel.addEventListener('change', function () {
                    if (ylDyn && ylDyn.sectionsByScope) {
                        refreshSectionOptions();
                    } else {
                        filterCourses();
                    }
                });
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
    <?php if ($showGeTargetScope): ?>
    <script>
    (() => {
        const data = <?= json_encode($geTargetProgramsByCollege, JSON_UNESCAPED_SLASHES) ?>;
        const sectionData = <?= json_encode($geTargetSectionsByScope, JSON_UNESCAPED_SLASHES) ?>;
        const collegeSel = document.getElementById('ge_target_college_id');
        const programSel = document.getElementById('ge_target_program');
        const yearSel = document.getElementById('ge_target_year_level');
        const sectionSel = document.getElementById('ge_target_section');
        if (!collegeSel || !programSel || !yearSel) return;
        const selectedProgram = <?= json_encode((string) ($defaults['target_program'] ?? ''), JSON_UNESCAPED_SLASHES) ?>;
        const selectedSection = <?= json_encode((string) ($defaults['target_section'] ?? ''), JSON_UNESCAPED_SLASHES) ?>;
        const selectedYearInitial = <?= json_encode((string) ($defaults['target_year_level'] ?? ''), JSON_UNESCAPED_SLASHES) ?>;
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
            if (!sectionSel) return;
            const cid = collegeSel.value;
            const program = programSel.value;
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
        programSel.addEventListener('change', () => {
            renderYearLevels();
            renderSections();
        });
        yearSel.addEventListener('change', renderSections);

        renderPrograms();
        renderYearLevels();
        renderSections();
    })();
    </script>
    <?php endif; ?>
    <?php if (in_array($role, ['dean', 'program_chair'], true)): ?>
    <script>
    (() => {
        const facultySel = document.querySelector('select[name="faculty_id"]');
        const courseSel = document.getElementById('course_id');
        const semesterSel = document.querySelector('select[name="semester"]');
        const schoolYearInput = document.querySelector('input[name="school_year"]');
        if (!facultySel || !courseSel || !semesterSel || !schoolYearInput) {
            return;
        }

        const geCollegeSel = document.getElementById('ge_target_college_id');
        const geProgramSel = document.getElementById('ge_target_program');
        const geYearSel = document.getElementById('ge_target_year_level');
        const geSectionSel = document.getElementById('ge_target_section');

        let lastAlertKey = '';

        function currentLoadCheckKey() {
            const parts = [
                facultySel.value || '',
                courseSel.value || '',
                semesterSel.value || '',
                schoolYearInput.value || '',
            ];
            if (geCollegeSel) parts.push(geCollegeSel.value || '');
            if (geProgramSel) parts.push(geProgramSel.value || '');
            if (geYearSel) parts.push(geYearSel.value || '');
            if (geSectionSel) parts.push(geSectionSel.value || '');
            return parts.join('|');
        }

        function checkCourseLoadAssignment() {
            const courseId = parseInt(courseSel.value || '0', 10);
            const facultyId = parseInt(facultySel.value || '0', 10);
            const semester = (semesterSel.value || '').trim();
            const schoolYear = (schoolYearInput.value || '').trim();
            if (courseId < 1 || facultyId < 1 || semester === '' || schoolYear === '') {
                return;
            }

            const params = new URLSearchParams({
                course_id: String(courseId),
                faculty_id: String(facultyId),
                semester,
                school_year: schoolYear,
            });
            if (geCollegeSel && geCollegeSel.value) {
                params.set('target_college_id', geCollegeSel.value);
            }
            if (geProgramSel && geProgramSel.value) {
                params.set('target_program', geProgramSel.value);
            }
            if (geYearSel && geYearSel.value) {
                params.set('target_year_level', geYearSel.value);
            }
            if (geSectionSel && geSectionSel.value) {
                params.set('target_section', geSectionSel.value);
            }

            fetch('api/check_course_load_assignment.php?' + params.toString(), {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
            })
                .then((res) => res.json())
                .then((data) => {
                    if (!data || !data.assigned || !data.message) {
                        lastAlertKey = '';
                        return;
                    }
                    const key = currentLoadCheckKey() + '|' + (data.faculty_code || data.faculty_name || '');
                    if (key === lastAlertKey) {
                        return;
                    }
                    lastAlertKey = key;
                    window.alert(data.message);
                })
                .catch(() => {});
        }

        const triggerFields = [facultySel, courseSel, semesterSel, schoolYearInput];
        if (geCollegeSel) triggerFields.push(geCollegeSel);
        if (geProgramSel) triggerFields.push(geProgramSel);
        if (geYearSel) triggerFields.push(geYearSel);
        if (geSectionSel) triggerFields.push(geSectionSel);

        triggerFields.forEach((el) => {
            el.addEventListener('change', checkCourseLoadAssignment);
        });
        schoolYearInput.addEventListener('blur', checkCourseLoadAssignment);
    })();
    </script>
    <?php endif; ?>
    <?php
}

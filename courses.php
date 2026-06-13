<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/admin_activity_log.php';

require_role(['dean', 'program_chair']);
$collegeId = dean_or_program_chair_college_id_or_fail();
$programScope = is_program_chair() ? program_scope_or_fail() : null;
$collegeName = college_name_by_id($collegeId);

$hasLabFlag = db_column_exists('courses', 'is_laboratory');
$hasLectureUnits = db_column_exists('courses', 'lecture_units');
$hasLaboratoryUnits = db_column_exists('courses', 'laboratory_units');
$hasYearLevel = db_column_exists('courses', 'year_level');
$hasSection = db_column_exists('courses', 'section');
$hasCourseBlock = $hasYearLevel && $hasSection;
$hasProgramsTable = db_table_exists('programs');
$hasClassroomCode = db_column_exists('courses', 'classroom_code');

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        // Auto-heal legacy DBs that still have UNIQUE(course_code).
        ensure_course_code_duplicates_allowed();
        $code = strtoupper(trim((string) ($_POST['course_code'] ?? '')));
        $name = trim((string) ($_POST['course_name'] ?? ''));
        $program = $programScope ?? trim((string) ($_POST['program'] ?? $_POST['department'] ?? ''));
        $yearLevel = trim((string) ($_POST['year_level'] ?? ''));
        $section = trim((string) ($_POST['section'] ?? ''));
        $isLab = !empty($_POST['is_laboratory']) ? 1 : 0;

        $lecUnits = $hasLectureUnits ? max(0.0, (float) ($_POST['lecture_units'] ?? 0)) : max(0.5, (float) ($_POST['units'] ?? 3.0));
        $labUnits = $hasLaboratoryUnits ? max(0.0, (float) ($_POST['laboratory_units'] ?? 0)) : ($isLab ? max(0.5, (float) ($_POST['units'] ?? 3.0)) : 0.0);
        $legacyUnits = max(0.5, $lecUnits > 0 ? $lecUnits : ($isLab && $labUnits > 0 ? $labUnits : 3.0));
        if (($action === 'add' || $action === 'edit') && $hasCourseBlock && ($yearLevel === '' || $section === '')) {
            throw new RuntimeException('Year level and section are required.');
        }
        if (($action === 'add' || $action === 'edit') && $hasProgramsTable) {
            if ($program === '') {
                throw new RuntimeException('Program is required.');
            }
            $stProgram = db()->prepare('SELECT COUNT(*) FROM programs WHERE college_id = ? AND program_name = ?');
            $stProgram->execute([$collegeId, $program]);
            if ((int) $stProgram->fetchColumn() < 1) {
                throw new RuntimeException('Please select a valid program from the Programs module.');
            }
        }
        if ($action === 'edit' && isset($_POST['id']) && $programScope !== null) {
            $stOwn = db()->prepare('SELECT COUNT(*) FROM courses WHERE id=? AND college_id=? AND department=?');
            $stOwn->execute([(int) $_POST['id'], $collegeId, $programScope]);
            if ((int) $stOwn->fetchColumn() < 1) {
                throw new RuntimeException('You can only edit courses in your assigned program.');
            }
        }

        $classroomCodeForWrite = null;
        $allocatedCourseClassroomCodeOnEdit = null;
        if ($hasClassroomCode && ($action === 'add' || $action === 'edit')) {
            if ($action === 'add' && is_program_chair()) {
                $classroomCodeForWrite = course_alloc_unique_classroom_code();
            } elseif ($action === 'edit' && isset($_POST['id'])) {
                $eid = (int) $_POST['id'];
                $sqlSel = 'SELECT classroom_code FROM courses WHERE id=? AND college_id=?';
                $parSel = [$eid, $collegeId];
                if ($programScope !== null) {
                    $sqlSel .= ' AND department=?';
                    $parSel[] = $programScope;
                }
                $stSel = db()->prepare($sqlSel);
                $stSel->execute($parSel);
                $rowCc = $stSel->fetch(PDO::FETCH_ASSOC);
                if (!$rowCc) {
                    throw new RuntimeException('Course not found.');
                }
                $existCc = trim((string) ($rowCc['classroom_code'] ?? ''));
                if (is_program_chair()) {
                    if ($existCc !== '') {
                        $classroomCodeForWrite = $existCc;
                    } else {
                        $classroomCodeForWrite = course_alloc_unique_classroom_code();
                        $allocatedCourseClassroomCodeOnEdit = $classroomCodeForWrite;
                    }
                } else {
                    $classroomCodeForWrite = $existCc !== '' ? $existCc : null;
                }
            }
        }

        $ccCol = $hasClassroomCode ? ', classroom_code' : '';
        $ccPh = $hasClassroomCode ? ',?' : '';

        if ($action === 'add') {
            if ($hasLabFlag && $hasLectureUnits && $hasLaboratoryUnits && $hasCourseBlock) {
                $st = db()->prepare('INSERT INTO courses (course_code, course_name, units, lecture_units, laboratory_units, is_laboratory, year_level, section, department, college_id' . $ccCol . ') VALUES (?,?,?,?,?,?,?,?,?,?' . $ccPh . ')');
                $params = [$code, $name, $legacyUnits, $lecUnits, $labUnits, $isLab, $yearLevel, $section, $program, $collegeId];
                if ($hasClassroomCode) {
                    $params[] = $classroomCodeForWrite;
                }
                $st->execute($params);
            } elseif ($hasLabFlag && $hasLectureUnits && $hasLaboratoryUnits) {
                $st = db()->prepare('INSERT INTO courses (course_code, course_name, units, lecture_units, laboratory_units, is_laboratory, department, college_id' . $ccCol . ') VALUES (?,?,?,?,?,?,?,?' . $ccPh . ')');
                $params = [$code, $name, $legacyUnits, $lecUnits, $labUnits, $isLab, $program, $collegeId];
                if ($hasClassroomCode) {
                    $params[] = $classroomCodeForWrite;
                }
                $st->execute($params);
            } elseif ($hasLabFlag && $hasCourseBlock) {
                $st = db()->prepare('INSERT INTO courses (course_code, course_name, units, is_laboratory, year_level, section, department, college_id' . $ccCol . ') VALUES (?,?,?,?,?,?,?,?' . $ccPh . ')');
                $params = [$code, $name, $legacyUnits, $isLab, $yearLevel, $section, $program, $collegeId];
                if ($hasClassroomCode) {
                    $params[] = $classroomCodeForWrite;
                }
                $st->execute($params);
            } elseif ($hasLabFlag) {
                $st = db()->prepare('INSERT INTO courses (course_code, course_name, units, is_laboratory, department, college_id' . $ccCol . ') VALUES (?,?,?,?,?,?' . $ccPh . ')');
                $params = [$code, $name, $legacyUnits, $isLab, $program, $collegeId];
                if ($hasClassroomCode) {
                    $params[] = $classroomCodeForWrite;
                }
                $st->execute($params);
            } elseif ($hasCourseBlock) {
                $st = db()->prepare('INSERT INTO courses (course_code, course_name, units, year_level, section, department, college_id' . $ccCol . ') VALUES (?,?,?,?,?,?,?' . $ccPh . ')');
                $params = [$code, $name, $legacyUnits, $yearLevel, $section, $program, $collegeId];
                if ($hasClassroomCode) {
                    $params[] = $classroomCodeForWrite;
                }
                $st->execute($params);
            } else {
                $st = db()->prepare('INSERT INTO courses (course_code, course_name, units, department, college_id' . $ccCol . ') VALUES (?,?,?,?,?' . $ccPh . ')');
                $params = [$code, $name, $legacyUnits, $program, $collegeId];
                if ($hasClassroomCode) {
                    $params[] = $classroomCodeForWrite;
                }
                $st->execute($params);
            }
            $newCourseId = (int) db()->lastInsertId();
            $snap = db()->prepare('SELECT * FROM courses WHERE id = ? LIMIT 1');
            $snap->execute([$newCourseId]);
            $afterCourse = $snap->fetch(PDO::FETCH_ASSOC);
            log_user_activity('add', 'Courses', 'Course #' . $newCourseId, null, $afterCourse ? (array) $afterCourse : null);
            log_dean_activity('course_create', 'Created course ' . $code);
            $_SESSION['flash'] = 'Course added.';
            if ($hasClassroomCode && is_program_chair() && $classroomCodeForWrite !== null && $classroomCodeForWrite !== '') {
                $_SESSION['flash'] .= ' Classroom code for this subject: ' . $classroomCodeForWrite . '.';
            }
        } elseif ($action === 'edit' && isset($_POST['id'])) {
            $id = (int) $_POST['id'];
            $sqlBefore = 'SELECT * FROM courses WHERE id=? AND college_id=?';
            $parBefore = [$id, $collegeId];
            if ($programScope !== null) {
                $sqlBefore .= ' AND department=?';
                $parBefore[] = $programScope;
            }
            $stBeforeC = db()->prepare($sqlBefore);
            $stBeforeC->execute($parBefore);
            $beforeCourse = $stBeforeC->fetch(PDO::FETCH_ASSOC);
            $ccSet = $hasClassroomCode ? ', classroom_code=?' : '';
            if ($hasLabFlag && $hasLectureUnits && $hasLaboratoryUnits && $hasCourseBlock) {
                $st = db()->prepare('UPDATE courses SET course_code=?, course_name=?, units=?, lecture_units=?, laboratory_units=?, is_laboratory=?, year_level=?, section=?, department=?' . $ccSet . ' WHERE id=? AND college_id=?');
                $params = [$code, $name, $legacyUnits, $lecUnits, $labUnits, $isLab, $yearLevel, $section, $program];
                if ($hasClassroomCode) {
                    $params[] = $classroomCodeForWrite;
                }
                $params[] = $id;
                $params[] = $collegeId;
                $st->execute($params);
            } elseif ($hasLabFlag && $hasLectureUnits && $hasLaboratoryUnits) {
                $st = db()->prepare('UPDATE courses SET course_code=?, course_name=?, units=?, lecture_units=?, laboratory_units=?, is_laboratory=?, department=?' . $ccSet . ' WHERE id=? AND college_id=?');
                $params = [$code, $name, $legacyUnits, $lecUnits, $labUnits, $isLab, $program];
                if ($hasClassroomCode) {
                    $params[] = $classroomCodeForWrite;
                }
                $params[] = $id;
                $params[] = $collegeId;
                $st->execute($params);
            } elseif ($hasLabFlag && $hasCourseBlock) {
                $st = db()->prepare('UPDATE courses SET course_code=?, course_name=?, units=?, is_laboratory=?, year_level=?, section=?, department=?' . $ccSet . ' WHERE id=? AND college_id=?');
                $params = [$code, $name, $legacyUnits, $isLab, $yearLevel, $section, $program];
                if ($hasClassroomCode) {
                    $params[] = $classroomCodeForWrite;
                }
                $params[] = $id;
                $params[] = $collegeId;
                $st->execute($params);
            } elseif ($hasLabFlag) {
                $st = db()->prepare('UPDATE courses SET course_code=?, course_name=?, units=?, is_laboratory=?, department=?' . $ccSet . ' WHERE id=? AND college_id=?');
                $params = [$code, $name, $legacyUnits, $isLab, $program];
                if ($hasClassroomCode) {
                    $params[] = $classroomCodeForWrite;
                }
                $params[] = $id;
                $params[] = $collegeId;
                $st->execute($params);
            } elseif ($hasCourseBlock) {
                $st = db()->prepare('UPDATE courses SET course_code=?, course_name=?, units=?, year_level=?, section=?, department=?' . $ccSet . ' WHERE id=? AND college_id=?');
                $params = [$code, $name, $legacyUnits, $yearLevel, $section, $program];
                if ($hasClassroomCode) {
                    $params[] = $classroomCodeForWrite;
                }
                $params[] = $id;
                $params[] = $collegeId;
                $st->execute($params);
            } else {
                $st = db()->prepare('UPDATE courses SET course_code=?, course_name=?, units=?, department=?' . $ccSet . ' WHERE id=? AND college_id=?');
                $params = [$code, $name, $legacyUnits, $program];
                if ($hasClassroomCode) {
                    $params[] = $classroomCodeForWrite;
                }
                $params[] = $id;
                $params[] = $collegeId;
                $st->execute($params);
            }
            $stAfterC = db()->prepare('SELECT * FROM courses WHERE id = ? LIMIT 1');
            $stAfterC->execute([$id]);
            $afterCourse = $stAfterC->fetch(PDO::FETCH_ASSOC);
            log_user_activity('edit', 'Courses', 'Course #' . $id, $beforeCourse ? (array) $beforeCourse : null, $afterCourse ? (array) $afterCourse : null);
            log_dean_activity('course_update', 'Updated course ID #' . $id);
            $_SESSION['flash'] = 'Course updated.';
            if ($allocatedCourseClassroomCodeOnEdit !== null) {
                $_SESSION['flash'] .= ' Classroom code for this subject: ' . $allocatedCourseClassroomCodeOnEdit . '.';
            }
        } elseif ($action === 'delete' && isset($_POST['id'])) {
            $delId = (int) $_POST['id'];
            $sqlSel = 'SELECT * FROM courses WHERE id=? AND college_id=?';
            $parSel = [$delId, $collegeId];
            if ($programScope !== null) {
                $sqlSel .= ' AND department=?';
                $parSel[] = $programScope;
            }
            $stSelDel = db()->prepare($sqlSel);
            $stSelDel->execute($parSel);
            $beforeDel = $stSelDel->fetch(PDO::FETCH_ASSOC);
            $sql = 'DELETE FROM courses WHERE id=? AND college_id=?';
            $params = [$delId, $collegeId];
            if ($programScope !== null) {
                $sql .= ' AND department=?';
                $params[] = $programScope;
            }
            $st = db()->prepare($sql);
            $st->execute($params);
            log_user_activity('delete', 'Courses', 'Course #' . $delId, $beforeDel ? (array) $beforeDel : null, null);
            log_dean_activity('course_delete', 'Deleted course ID #' . $delId);
            $_SESSION['flash'] = 'Course removed.';
        }
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if ($e instanceof PDOException && str_contains($msg, '1062') && str_contains(strtolower($msg), 'course_code')) {
            // Retry auto-heal message if DB user cannot alter schema.
            $msg = 'Your database still blocks duplicate course codes (legacy UNIQUE index). Please run upgrade_roles.php once.';
        }
        $_SESSION['flash'] = 'Error: ' . $msg;
    }
    header('Location: courses.php');
    exit;
}

$editRow = null;
if (isset($_GET['edit'])) {
    $sql = 'SELECT * FROM courses WHERE id=? AND college_id=?';
    $params = [(int) $_GET['edit'], $collegeId];
    if ($programScope !== null) {
        $sql .= ' AND department=?';
        $params[] = $programScope;
    }
    $st = db()->prepare($sql);
    $st->execute($params);
    $editRow = $st->fetch() ?: null;
}

$search = trim((string) ($_GET['q'] ?? ''));
$sort = (string) ($_GET['sort'] ?? 'course_code');
$dir = strtolower((string) ($_GET['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

$sortMap = [
    'course_code' => 'course_code',
    'course_name' => 'course_name',
    'program' => 'department',
    'lecture_units' => $hasLectureUnits ? 'lecture_units' : 'units',
    'laboratory_units' => $hasLaboratoryUnits ? 'laboratory_units' : 'units',
];
if ($hasYearLevel) {
    $sortMap['year_level'] = 'year_level';
}
if ($hasSection) {
    $sortMap['section'] = 'section';
}
if ($hasLabFlag) {
    $sortMap['type'] = 'is_laboratory';
}
if ($hasClassroomCode) {
    $sortMap['classroom_code'] = 'classroom_code';
}

$sortExpr = $sortMap[$sort] ?? 'course_code';

$programOptions = [];
if ($hasProgramsTable) {
    $sql = 'SELECT program_name, status FROM programs WHERE college_id=?';
    $params = [$collegeId];
    if ($programScope !== null) {
        $sql .= ' AND program_name=?';
        $params[] = $programScope;
    }
    $sql .= ' ORDER BY (status = "active") DESC, program_name ASC';
    $st = db()->prepare($sql);
    $st->execute($params);
    $programOptions = $st->fetchAll();
}

$sql = 'SELECT * FROM courses WHERE college_id=?';
$params = [$collegeId];
if ($programScope !== null) {
    $sql .= ' AND department=?';
    $params[] = $programScope;
}
if ($search !== '') {
    $sql .= ' AND (course_code LIKE ? OR course_name LIKE ? OR department LIKE ?';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    if ($hasYearLevel) {
        $sql .= ' OR year_level LIKE ?';
        $params[] = '%' . $search . '%';
    }
    if ($hasSection) {
        $sql .= ' OR section LIKE ?';
        $params[] = '%' . $search . '%';
    }
    if ($hasClassroomCode) {
        $sql .= ' OR classroom_code LIKE ?';
        $params[] = '%' . $search . '%';
    }
    $sql .= ' OR EXISTS (
        SELECT 1
        FROM schedules s
        INNER JOIN faculty f ON f.id = s.faculty_id
        WHERE s.course_id = courses.id
          AND (f.full_name LIKE ? OR f.faculty_id LIKE ?)
    )';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $sql .= ')';
}
$sql .= " ORDER BY {$sortExpr} {$dir}, course_code ASC";
$st = db()->prepare($sql);
$st->execute($params);
$list = $st->fetchAll();

$geOfferings = [];
if (is_dean() && $programScope === null && db_column_exists('courses', 'is_gened') && db_table_exists('ge_course_colleges')) {
    $geOfferings = ge_courses_offered_to_college($collegeId);
}

$nextDirFor = static function (string $col, string $currentSort, string $currentDir): string {
    if ($col !== $currentSort) {
        return 'asc';
    }
    return strtoupper($currentDir) === 'ASC' ? 'desc' : 'asc';
};
$sortArrowFor = static function (string $col, string $currentSort, string $currentDir): string {
    if ($col !== $currentSort) {
        return '';
    }
    return strtoupper($currentDir) === 'ASC' ? ' ▲' : ' ▼';
};

$pageTitle = 'Courses';
require_once __DIR__ . '/includes/header.php';
?>
<style>
    .courses-page .app-page-title {
        font-size: 1.5rem;
    }

    .courses-page .app-card-header,
    .courses-page .app-table thead th {
        font-size: 0.875rem;
    }

    .courses-page .app-table thead th {
        letter-spacing: 0.03em;
    }

    .courses-page .app-table tbody td {
        font-size: 0.9rem;
    }

    .courses-page .app-table .app-table-actions .btn {
        font-size: 0.8rem;
    }

    @media (max-width: 767.98px) {
        .courses-page .app-page-title {
            font-size: 1.5rem;
            line-height: 1.25;
        }

        .courses-page .app-page-lead {
            font-size: 0.875rem;
        }

        .courses-page .app-card-body {
            padding: 1rem;
        }

        .courses-page .app-table {
            min-width: 980px;
        }

        .courses-page .app-form-actions .btn {
            width: 100%;
        }
    }
</style>
<div class="courses-page">
<header class="app-page-header">
    <h1 class="app-page-title"><i class="fa-solid fa-book me-2 app-page-title-icon" aria-hidden="true"></i>Courses</h1>
    <p class="app-page-lead">Maintain the course catalog for scheduling and online classrooms. Subject classroom codes help students join the right class with their instructor.</p>
    <div class="app-meta-row" role="group" aria-label="Scope">
        <span class="app-chip"><i class="fa-solid fa-building-columns text-primary" aria-hidden="true"></i><?= htmlspecialchars($collegeName) ?></span>
        <?php if ($programScope !== null): ?>
            <span class="app-chip"><i class="fa-solid fa-graduation-cap text-primary" aria-hidden="true"></i><?= htmlspecialchars($programScope) ?></span>
        <?php endif; ?>
    </div>
</header>

<?php if ($flash): ?>
    <div class="alert alert-info app-alert border-info-subtle" role="status"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>
<?php if (!$hasLabFlag || !$hasLectureUnits || !$hasLaboratoryUnits): ?>
    <div class="alert alert-warning app-alert">Split unit fields are disabled until you run <a href="upgrade_roles.php">upgrade_roles.php</a>.</div>
<?php endif; ?>
<?php if (!$hasCourseBlock): ?>
    <div class="alert alert-warning app-alert">Year level/section fields are disabled until you run <a href="upgrade_roles.php">upgrade_roles.php</a>.</div>
<?php endif; ?>
<?php if (!$hasProgramsTable): ?>
    <div class="alert alert-warning app-alert">Program dropdown is disabled until you run <a href="upgrade_roles.php">upgrade_roles.php</a>.</div>
<?php endif; ?>
<?php if ($programScope !== null && !$hasClassroomCode): ?>
    <div class="alert alert-warning app-alert">Subject classroom codes are not available until you run <a href="upgrade_roles.php">upgrade_roles.php</a> once.</div>
<?php endif; ?>

<div class="app-card">
    <div class="app-card-header">Find and sort</div>
    <div class="app-card-body">
    <form method="get" class="row g-3 align-items-end">
        <div class="col-md-5">
            <label class="form-label" for="courses-q">Search</label>
            <input id="courses-q" type="search" name="q" class="form-control" placeholder="Code, name, program, year, section, faculty<?= $hasClassroomCode ? ', classroom code' : '' ?>" value="<?= htmlspecialchars($search) ?>" autocomplete="off">
        </div>
        <div class="col-md-3">
            <label class="form-label" for="courses-sort">Sort by</label>
            <select id="courses-sort" name="sort" class="form-select">
                <option value="course_code" <?= $sort === 'course_code' ? 'selected' : '' ?>>Code</option>
                <?php if ($hasClassroomCode): ?><option value="classroom_code" <?= $sort === 'classroom_code' ? 'selected' : '' ?>>Classroom code</option><?php endif; ?>
                <option value="course_name" <?= $sort === 'course_name' ? 'selected' : '' ?>>Name</option>
                <option value="lecture_units" <?= $sort === 'lecture_units' ? 'selected' : '' ?>>Lecture units</option>
                <option value="laboratory_units" <?= $sort === 'laboratory_units' ? 'selected' : '' ?>>Laboratory units</option>
                <option value="program" <?= $sort === 'program' ? 'selected' : '' ?>>Program</option>
                <?php if ($hasYearLevel): ?><option value="year_level" <?= $sort === 'year_level' ? 'selected' : '' ?>>Year level</option><?php endif; ?>
                <?php if ($hasSection): ?><option value="section" <?= $sort === 'section' ? 'selected' : '' ?>>Section</option><?php endif; ?>
                <?php if ($hasLabFlag): ?><option value="type" <?= $sort === 'type' ? 'selected' : '' ?>>Type</option><?php endif; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="courses-dir">Direction</label>
            <select id="courses-dir" name="dir" class="form-select">
                <option value="asc" <?= $dir === 'ASC' ? 'selected' : '' ?>>Ascending</option>
                <option value="desc" <?= $dir === 'DESC' ? 'selected' : '' ?>>Descending</option>
            </select>
        </div>
        <div class="col-md-2 d-grid">
            <button type="submit" class="btn btn-primary rounded-pill"<?= app_tooltip_attr('Applies search, sort, and direction to the course list. Use this after changing how you want the catalog ordered.') ?>>Apply</button>
        </div>
    </form>
    </div>
</div>

<div class="app-card">
    <div class="app-card-header"><?= $editRow ? 'Edit course' : 'Add course' ?></div>
    <div class="app-card-body">
    <form method="post" class="row g-3">
        <input type="hidden" name="action" value="<?= $editRow ? 'edit' : 'add' ?>">
        <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>"><?php endif; ?>
        <div class="col-md-2"><label class="form-label">Code</label><input name="course_code" class="form-control" required maxlength="20" value="<?= htmlspecialchars((string) ($editRow['course_code'] ?? '')) ?>"></div>
        <div class="col-md-4"><label class="form-label">Name</label><input name="course_name" class="form-control" required maxlength="100" value="<?= htmlspecialchars((string) ($editRow['course_name'] ?? '')) ?>"></div>
        <?php if ($hasLectureUnits): ?>
            <div class="col-md-2"><label class="form-label">Lecture Units</label><input name="lecture_units" type="number" step="0.1" min="0" max="12" class="form-control" value="<?= htmlspecialchars((string) ($editRow['lecture_units'] ?? $editRow['units'] ?? '3.0')) ?>"></div>
        <?php else: ?>
            <div class="col-md-2"><label class="form-label">Units</label><input name="units" type="number" step="0.1" min="0.5" max="12" class="form-control" value="<?= htmlspecialchars((string) ($editRow['units'] ?? '3.0')) ?>"></div>
        <?php endif; ?>
        <?php if ($hasLaboratoryUnits): ?>
            <div class="col-md-2"><label class="form-label">Laboratory Units</label><input name="laboratory_units" type="number" step="0.1" min="0" max="12" class="form-control" value="<?= htmlspecialchars((string) ($editRow['laboratory_units'] ?? '0.0')) ?>"></div>
        <?php endif; ?>
        <div class="col-md-2 d-flex align-items-end"><?php if ($hasLabFlag): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="is_laboratory" value="1" <?= !empty($editRow['is_laboratory']) ? 'checked' : '' ?>><label class="form-check-label">Laboratory course</label></div><?php endif; ?></div>
        <?php if ($hasYearLevel): ?>
            <div class="col-md-2"><label class="form-label">Year Level</label><select name="year_level" class="form-select" required><?php foreach (['1','2','3','4','5'] as $yl): ?><option value="<?= $yl ?>" <?= (string) ($editRow['year_level'] ?? '') === $yl ? 'selected' : '' ?>><?= htmlspecialchars($yl) ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
        <?php if ($hasSection): ?>
            <div class="col-md-2"><label class="form-label">Section</label><input name="section" class="form-control" maxlength="20" required value="<?= htmlspecialchars((string) ($editRow['section'] ?? '')) ?>"></div>
        <?php endif; ?>
        <?php if ($hasProgramsTable && $programScope === null): ?>
            <?php
            $selectedProgram = (string) ($editRow['department'] ?? '');
            $programValues = array_map(static fn ($p) => (string) ($p['program_name'] ?? ''), $programOptions);
            ?>
            <div class="col-md-4">
                <label class="form-label">Program</label>
                <select name="program" class="form-select" required>
                    <option value="">Select program</option>
                    <?php if ($selectedProgram !== '' && !in_array($selectedProgram, $programValues, true)): ?>
                        <option value="<?= htmlspecialchars($selectedProgram) ?>" selected><?= htmlspecialchars($selectedProgram) ?> (legacy)</option>
                    <?php endif; ?>
                    <?php foreach ($programOptions as $programRow): ?>
                        <?php
                        $programName = (string) ($programRow['program_name'] ?? '');
                        $programStatus = (string) ($programRow['status'] ?? 'active');
                        $isSelected = $selectedProgram === $programName;
                        ?>
                        <option value="<?= htmlspecialchars($programName) ?>" <?= $isSelected ? 'selected' : '' ?>>
                            <?= htmlspecialchars($programName) ?><?= $programStatus === 'inactive' ? ' (inactive)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php elseif ($programScope !== null): ?>
            <div class="col-md-4">
                <label class="form-label">Program</label>
                <input class="form-control" value="<?= htmlspecialchars($programScope) ?>" readonly>
                <input type="hidden" name="program" value="<?= htmlspecialchars($programScope) ?>">
            </div>
        <?php else: ?>
            <div class="col-md-4"><label class="form-label">Program</label><input name="program" class="form-control" placeholder="e.g. BS Social Work, BS Information Systems" value="<?= htmlspecialchars((string) ($editRow['department'] ?? '')) ?>"></div>
        <?php endif; ?>
        <?php if ($hasClassroomCode): ?>
            <div class="col-md-3">
                <label class="form-label">Subject classroom code</label>
                <?php if ($editRow): ?>
                    <?php $ccVal = trim((string) ($editRow['classroom_code'] ?? '')); ?>
                    <?php if ($ccVal !== ''): ?>
                        <input class="form-control font-monospace" readonly value="<?= htmlspecialchars($ccVal) ?>">
                    <?php elseif ($programScope !== null): ?>
                        <input class="form-control" value="Assigned when you save" readonly tabindex="-1" style="background:#f8f9fa">
                    <?php else: ?>
                        <input class="form-control text-muted" value="—" readonly tabindex="-1" style="background:#f8f9fa" title="Generated when a program chair creates or saves the course">
                    <?php endif; ?>
                <?php elseif ($programScope !== null): ?>
                    <input class="form-control" value="Generated when you save" readonly tabindex="-1" style="background:#f8f9fa">
                <?php endif; ?>
                <?php if ($programScope !== null): ?>
                    <div class="form-text">Auto-generated for each subject; share with faculty or students as needed.</div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <div class="col-12 app-form-actions">
            <button type="submit" class="btn btn-primary px-4 rounded-pill"<?= app_tooltip_attr($editRow ? 'Saves changes to this course record. Use this after editing codes, units, or program assignment.' : 'Creates the new course in the catalog. Use this when adding a subject that scheduling will reference.') ?>>Save</button><?php if ($editRow): ?> <a href="courses.php" class="btn btn-outline-secondary rounded-pill"<?= app_tooltip_attr('Discards unsaved edits and returns to the catalog without saving. Use this if you opened the wrong course.') ?>>Cancel</a><?php endif; ?>
        </div>
    </form>
    </div>
</div>

<section class="app-card mb-0" aria-labelledby="courses-catalog-heading">
    <div class="app-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span id="courses-catalog-heading">Course catalog</span>
        <span class="badge rounded-pill bg-light text-secondary border fw-normal"><?= count($list) ?> <?= count($list) === 1 ? 'course' : 'courses' ?></span>
    </div>
    <div class="table-responsive">
    <table class="table app-table mb-0">
        <thead><tr>
            <?php
            $baseQuery = ['q' => $search];
            ?>
            <th scope="col"><a class="text-decoration-none text-body-secondary" href="?<?= htmlspecialchars(http_build_query($baseQuery + ['sort' => 'course_code', 'dir' => $nextDirFor('course_code', $sort, $dir)])) ?>">Code<?= htmlspecialchars($sortArrowFor('course_code', $sort, $dir)) ?></a></th>
            <?php if ($hasClassroomCode): ?>
                <th scope="col"><a class="text-decoration-none text-body-secondary" href="?<?= htmlspecialchars(http_build_query($baseQuery + ['sort' => 'classroom_code', 'dir' => $nextDirFor('classroom_code', $sort, $dir)])) ?>">Classroom code<?= htmlspecialchars($sortArrowFor('classroom_code', $sort, $dir)) ?></a></th>
            <?php endif; ?>
            <th scope="col"><a class="text-decoration-none text-body-secondary" href="?<?= htmlspecialchars(http_build_query($baseQuery + ['sort' => 'course_name', 'dir' => $nextDirFor('course_name', $sort, $dir)])) ?>">Name<?= htmlspecialchars($sortArrowFor('course_name', $sort, $dir)) ?></a></th>
            <th scope="col"><a class="text-decoration-none text-body-secondary" href="?<?= htmlspecialchars(http_build_query($baseQuery + ['sort' => 'lecture_units', 'dir' => $nextDirFor('lecture_units', $sort, $dir)])) ?>">Lecture units<?= htmlspecialchars($sortArrowFor('lecture_units', $sort, $dir)) ?></a></th>
            <th scope="col"><a class="text-decoration-none text-body-secondary" href="?<?= htmlspecialchars(http_build_query($baseQuery + ['sort' => 'laboratory_units', 'dir' => $nextDirFor('laboratory_units', $sort, $dir)])) ?>">Laboratory units<?= htmlspecialchars($sortArrowFor('laboratory_units', $sort, $dir)) ?></a></th>
            <?php if ($hasLabFlag): ?>
                <th scope="col"><a class="text-decoration-none text-body-secondary" href="?<?= htmlspecialchars(http_build_query($baseQuery + ['sort' => 'type', 'dir' => $nextDirFor('type', $sort, $dir)])) ?>">Type<?= htmlspecialchars($sortArrowFor('type', $sort, $dir)) ?></a></th>
            <?php endif; ?>
            <?php if ($hasYearLevel): ?>
                <th scope="col"><a class="text-decoration-none text-body-secondary" href="?<?= htmlspecialchars(http_build_query($baseQuery + ['sort' => 'year_level', 'dir' => $nextDirFor('year_level', $sort, $dir)])) ?>">Year<?= htmlspecialchars($sortArrowFor('year_level', $sort, $dir)) ?></a></th>
            <?php endif; ?>
            <?php if ($hasSection): ?>
                <th scope="col"><a class="text-decoration-none text-body-secondary" href="?<?= htmlspecialchars(http_build_query($baseQuery + ['sort' => 'section', 'dir' => $nextDirFor('section', $sort, $dir)])) ?>">Section<?= htmlspecialchars($sortArrowFor('section', $sort, $dir)) ?></a></th>
            <?php endif; ?>
            <th scope="col"><a class="text-decoration-none text-body-secondary" href="?<?= htmlspecialchars(http_build_query($baseQuery + ['sort' => 'program', 'dir' => $nextDirFor('program', $sort, $dir)])) ?>">Program<?= htmlspecialchars($sortArrowFor('program', $sort, $dir)) ?></a></th>
            <th scope="col" class="app-table-actions"><span class="visually-hidden">Actions</span></th>
        </tr></thead>
        <tbody>
        <?php foreach ($list as $r): ?>
            <tr>
                <td><?= htmlspecialchars((string) $r['course_code']) ?></td>
                <?php if ($hasClassroomCode): ?>
                    <td class="font-monospace"><?php $rcc = trim((string) ($r['classroom_code'] ?? '')); ?><?= $rcc !== '' ? htmlspecialchars($rcc) : '—' ?></td>
                <?php endif; ?>
                <td><?= htmlspecialchars((string) $r['course_name']) ?></td>
                <td><?= htmlspecialchars((string) ($r['lecture_units'] ?? $r['units'])) ?></td>
                <td><?= htmlspecialchars((string) ($r['laboratory_units'] ?? (!empty($r['is_laboratory']) ? $r['units'] : 0))) ?></td>
                <?php if ($hasLabFlag): ?><td><?= !empty($r['is_laboratory']) ? 'Laboratory' : 'Lecture' ?></td><?php endif; ?>
                <?php if ($hasYearLevel): ?><td><?= htmlspecialchars((string) ($r['year_level'] ?? '')) ?></td><?php endif; ?>
                <?php if ($hasSection): ?><td><?= htmlspecialchars((string) ($r['section'] ?? '')) ?></td><?php endif; ?>
                <td><?= htmlspecialchars((string) $r['department']) ?></td>
                <td class="app-table-actions text-nowrap"><a class="btn btn-sm btn-outline-primary rounded-pill" href="courses.php?edit=<?= (int) $r['id'] ?>" aria-label="Edit course"<?= app_tooltip_attr('Opens this course for editing. Use this to fix codes, units, program, or classroom metadata.') ?>><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i><span class="visually-hidden">Edit</span></a>
                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this course?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button type="submit" class="btn btn-sm btn-outline-danger rounded-pill" aria-label="Delete course"<?= app_tooltip_attr('Permanently removes this course after confirmation. Use this only if no schedules should reference it anymore.') ?>><i class="fa-solid fa-trash" aria-hidden="true"></i><span class="visually-hidden">Delete</span></button></form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<?php if (is_dean() && $programScope === null): ?>
<section class="app-card mt-4 mb-0" aria-labelledby="courses-ge-heading">
    <div class="app-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span id="courses-ge-heading">GE courses (college offerings)</span>
        <span class="badge rounded-pill bg-light text-secondary border fw-normal"><?= count($geOfferings) ?> <?= count($geOfferings) === 1 ? 'course' : 'courses' ?></span>
    </div>
    <div class="app-card-body border-bottom">
        <p class="small text-muted mb-0">General Education subjects assigned to your college for scheduling and conflict monitoring. Official GE timetables are built under <strong>GEN ED → GE schedule</strong>; use <strong>Weekly view</strong> to watch live GE blocks alongside your college schedules.</p>
    </div>
    <div class="table-responsive">
        <table class="table app-table mb-0">
            <thead>
                <tr>
                    <th scope="col">Code</th>
                    <th scope="col">Name</th>
                    <th scope="col">Lecture units</th>
                    <th scope="col">Laboratory units</th>
                    <?php if ($hasLabFlag): ?><th scope="col">Type</th><?php endif; ?>
                    <th scope="col">Program</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($geOfferings === []): ?>
                <tr>
                    <td colspan="<?= $hasLabFlag ? 6 : 5 ?>" class="text-center text-muted py-4">No GE courses are assigned to this college yet. Ask the GEN ED coordinator to configure <strong>GE Offerings</strong>.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($geOfferings as $ge): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) $ge['course_code']) ?></td>
                        <td><?= htmlspecialchars((string) $ge['course_name']) ?></td>
                        <td><?= htmlspecialchars((string) ($ge['lecture_units'] ?? $ge['units'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($ge['laboratory_units'] ?? (!empty($ge['is_laboratory']) ? ($ge['units'] ?? '') : 0))) ?></td>
                        <?php if ($hasLabFlag): ?><td><?= !empty($ge['is_laboratory']) ? 'Laboratory' : 'Lecture' ?></td><?php endif; ?>
                        <td><?= htmlspecialchars((string) ($ge['department'] ?? 'General Education')) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_role(['gened']);

$hasLabFlag = db_column_exists('courses', 'is_laboratory');
$hasLectureUnits = db_column_exists('courses', 'lecture_units');
$hasLaboratoryUnits = db_column_exists('courses', 'laboratory_units');
$hasIsGened = db_column_exists('courses', 'is_gened');
$hasYearLevel = db_column_exists('courses', 'year_level');
$hasSection = db_column_exists('courses', 'section');
$hasCourseBlock = $hasYearLevel && $hasSection;
$hasProgramsTable = db_table_exists('programs');

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$programOptions = [];
if ($hasProgramsTable) {
    $programOptions = db()->query(
        "SELECT p.college_id, p.program_name, c.college_code
         FROM programs p
         INNER JOIN colleges c ON c.id = p.college_id
         WHERE p.status='active'
         ORDER BY c.college_code, p.program_name"
    )->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        // Auto-heal legacy DBs that still have UNIQUE(course_code).
        ensure_course_code_duplicates_allowed();
        $code = strtoupper(trim((string) ($_POST['course_code'] ?? '')));
        $name = trim((string) ($_POST['course_name'] ?? ''));
        $program = trim((string) ($_POST['program'] ?? $_POST['department'] ?? ''));
        $isLab = !empty($_POST['is_laboratory']) ? 1 : 0;
        $yearLevel = trim((string) ($_POST['year_level'] ?? ''));
        $section = trim((string) ($_POST['section'] ?? ''));
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
            $programParts = explode('|', $program, 2);
            $selectedCollegeId = count($programParts) === 2 ? (int) $programParts[0] : 0;
            $selectedProgramName = count($programParts) === 2 ? trim((string) $programParts[1]) : trim($program);
            if ($selectedProgramName === '') {
                throw new RuntimeException('Please select a valid program.');
            }
            if ($selectedCollegeId > 0) {
                $stProgram = db()->prepare('SELECT COUNT(*) FROM programs WHERE college_id = ? AND program_name = ?');
                $stProgram->execute([$selectedCollegeId, $selectedProgramName]);
            } else {
                $stProgram = db()->prepare('SELECT COUNT(*) FROM programs WHERE program_name = ?');
                $stProgram->execute([$selectedProgramName]);
            }
            if ((int) $stProgram->fetchColumn() < 1) {
                throw new RuntimeException('Please select a valid program from the Programs module.');
            }
            $program = $selectedProgramName;
        } elseif ($program === '') {
            $program = 'General Education';
        }

        if ($action === 'add') {
            if ($hasLabFlag && $hasLectureUnits && $hasLaboratoryUnits && $hasIsGened && $hasCourseBlock) {
                db()->prepare(
                    'INSERT INTO courses (course_code, course_name, units, lecture_units, laboratory_units, is_laboratory, is_gened, year_level, section, department, college_id)
                     VALUES (?,?,?,?,?,?,?,?,?,?,NULL)'
                )->execute([$code, $name, $legacyUnits, $lecUnits, $labUnits, $isLab, 1, $yearLevel, $section, $program]);
            } elseif ($hasLabFlag && $hasLectureUnits && $hasLaboratoryUnits && $hasIsGened) {
                db()->prepare(
                    'INSERT INTO courses (course_code, course_name, units, lecture_units, laboratory_units, is_laboratory, is_gened, department, college_id)
                     VALUES (?,?,?,?,?,?,?,?,NULL)'
                )->execute([$code, $name, $legacyUnits, $lecUnits, $labUnits, $isLab, 1, $program]);
            } else {
                throw new RuntimeException('Run upgrade_roles.php first to enable GE course management.');
            }
            $_SESSION['flash'] = 'GE course added.';
        } elseif ($action === 'edit' && isset($_POST['id'])) {
            $id = (int) $_POST['id'];
            if ($hasLabFlag && $hasLectureUnits && $hasLaboratoryUnits && $hasIsGened && $hasCourseBlock) {
                db()->prepare(
                    'UPDATE courses SET course_code=?, course_name=?, units=?, lecture_units=?, laboratory_units=?, is_laboratory=?, year_level=?, section=?, department=?
                     WHERE id=? AND is_gened=1'
                )->execute([$code, $name, $legacyUnits, $lecUnits, $labUnits, $isLab, $yearLevel, $section, $program, $id]);
            } elseif ($hasLabFlag && $hasLectureUnits && $hasLaboratoryUnits && $hasIsGened) {
                db()->prepare(
                    'UPDATE courses SET course_code=?, course_name=?, units=?, lecture_units=?, laboratory_units=?, is_laboratory=?, department=?
                     WHERE id=? AND is_gened=1'
                )->execute([$code, $name, $legacyUnits, $lecUnits, $labUnits, $isLab, $program, $id]);
            } else {
                throw new RuntimeException('Run upgrade_roles.php first to enable GE course management.');
            }
            $_SESSION['flash'] = 'GE course updated.';
        } elseif ($action === 'delete' && isset($_POST['id'])) {
            db()->prepare('DELETE FROM courses WHERE id=? AND is_gened=1')->execute([(int) $_POST['id']]);
            $_SESSION['flash'] = 'GE course removed.';
        }
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if ($e instanceof PDOException && str_contains($msg, '1062') && str_contains(strtolower($msg), 'course_code')) {
            // Retry auto-heal message if DB user cannot alter schema.
            $msg = 'Your database still blocks duplicate course codes (legacy UNIQUE index). Please run upgrade_roles.php once.';
        }
        $_SESSION['flash'] = 'Error: ' . $msg;
    }
    header('Location: gened_courses.php');
    exit;
}

$editRow = null;
if (isset($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM courses WHERE id=? AND is_gened=1');
    $st->execute([(int) $_GET['edit']]);
    $editRow = $st->fetch() ?: null;
}

$search = trim((string) ($_GET['q'] ?? ''));
$programFilter = trim((string) ($_GET['program'] ?? ''));
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
$sortExpr = $sortMap[$sort] ?? 'course_code';

$sql = 'SELECT * FROM courses WHERE is_gened=1';
$params = [];
if ($programFilter !== '') {
    $sql .= ' AND department = ?';
    $params[] = $programFilter;
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
    $sql .= ')';
}
$sql .= " ORDER BY {$sortExpr} {$dir}, course_code ASC";
$st = db()->prepare($sql);
$st->execute($params);
$list = $st->fetchAll();

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

$pageTitle = 'GE Courses';
require_once __DIR__ . '/includes/header.php';
?>
<h1 class="h3 mb-4"><i class="fa-solid fa-book me-2 text-primary"></i>Gen Ed Courses</h1>
<?php if ($flash): ?><div class="alert alert-info"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
<?php if (!$hasLabFlag || !$hasLectureUnits || !$hasLaboratoryUnits || !$hasIsGened): ?>
    <div class="alert alert-warning">Run <a href="upgrade_roles.php">upgrade_roles.php</a> to enable GE course features.</div>
<?php endif; ?>
<?php if (!$hasCourseBlock): ?>
    <div class="alert alert-warning">Year level/section fields are disabled until you run <a href="upgrade_roles.php">upgrade_roles.php</a>.</div>
<?php endif; ?>
<?php if (!$hasProgramsTable): ?>
    <div class="alert alert-warning">Program dropdown is disabled until you run <a href="upgrade_roles.php">upgrade_roles.php</a>.</div>
<?php endif; ?>

<div class="card shadow-sm mb-3"><div class="card-body">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label">Search</label>
            <input type="text" name="q" class="form-control" placeholder="Code, name, year, section" value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label">Program</label>
            <select name="program" class="form-select">
                <option value="">All programs</option>
                <?php foreach ($programOptions as $programRow): ?>
                    <?php
                    $programLabel = (string) ($programRow['college_code'] ?? '') . ' - ' . (string) ($programRow['program_name'] ?? '');
                    $programName = (string) ($programRow['program_name'] ?? '');
                    ?>
                    <option value="<?= htmlspecialchars($programName) ?>" <?= $programFilter === $programName ? 'selected' : '' ?>>
                        <?= htmlspecialchars($programLabel) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Sort by</label>
            <select name="sort" class="form-select">
                <option value="course_code" <?= $sort === 'course_code' ? 'selected' : '' ?>>Code</option>
                <option value="course_name" <?= $sort === 'course_name' ? 'selected' : '' ?>>Name</option>
                <option value="program" <?= $sort === 'program' ? 'selected' : '' ?>>Program</option>
                <option value="lecture_units" <?= $sort === 'lecture_units' ? 'selected' : '' ?>>Lecture Units</option>
                <option value="laboratory_units" <?= $sort === 'laboratory_units' ? 'selected' : '' ?>>Laboratory Units</option>
                <?php if ($hasYearLevel): ?><option value="year_level" <?= $sort === 'year_level' ? 'selected' : '' ?>>Year Level</option><?php endif; ?>
                <?php if ($hasSection): ?><option value="section" <?= $sort === 'section' ? 'selected' : '' ?>>Section</option><?php endif; ?>
                <?php if ($hasLabFlag): ?><option value="type" <?= $sort === 'type' ? 'selected' : '' ?>>Type</option><?php endif; ?>
            </select>
        </div>
        <div class="col-md-1">
            <label class="form-label">Direction</label>
            <select name="dir" class="form-select">
                <option value="asc" <?= $dir === 'ASC' ? 'selected' : '' ?>>Ascending</option>
                <option value="desc" <?= $dir === 'DESC' ? 'selected' : '' ?>>Descending</option>
            </select>
        </div>
        <div class="col-md-1 d-grid">
            <button type="submit" class="btn btn-outline-primary"<?= app_tooltip_attr('Applies search, sort, and filter to the GE course list.') ?>>Apply</button>
        </div>
    </form>
</div></div>

<div class="card shadow-sm mb-4"><div class="card-body">
    <form method="post" class="row g-3">
        <input type="hidden" name="action" value="<?= $editRow ? 'edit' : 'add' ?>">
        <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>"><?php endif; ?>
        <div class="col-md-2"><label class="form-label">Code</label><input name="course_code" class="form-control" required maxlength="20" value="<?= htmlspecialchars((string) ($editRow['course_code'] ?? '')) ?>"></div>
        <div class="col-md-4"><label class="form-label">Name</label><input name="course_name" class="form-control" required maxlength="100" value="<?= htmlspecialchars((string) ($editRow['course_name'] ?? '')) ?>"></div>
        <div class="col-md-2"><label class="form-label">Lecture Units</label><input name="lecture_units" type="number" step="0.1" min="0" max="12" class="form-control" value="<?= htmlspecialchars((string) ($editRow['lecture_units'] ?? $editRow['units'] ?? '3.0')) ?>"></div>
        <div class="col-md-2"><label class="form-label">Laboratory Units</label><input name="laboratory_units" type="number" step="0.1" min="0" max="12" class="form-control" value="<?= htmlspecialchars((string) ($editRow['laboratory_units'] ?? '0.0')) ?>"></div>
        <div class="col-md-2 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_laboratory" value="1" <?= !empty($editRow['is_laboratory']) ? 'checked' : '' ?>><label class="form-check-label">Laboratory</label></div></div>
        <?php if ($hasYearLevel): ?>
            <div class="col-md-2"><label class="form-label">Year Level</label><select name="year_level" class="form-select" required><?php foreach (['1','2','3','4','5'] as $yl): ?><option value="<?= $yl ?>" <?= (string) ($editRow['year_level'] ?? '') === $yl ? 'selected' : '' ?>><?= htmlspecialchars($yl) ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
        <?php if ($hasSection): ?>
            <div class="col-md-2"><label class="form-label">Section</label><input name="section" class="form-control" maxlength="20" required value="<?= htmlspecialchars((string) ($editRow['section'] ?? '')) ?>"></div>
        <?php endif; ?>
        <?php if ($hasProgramsTable): ?>
            <?php
            $selectedProgram = (string) ($editRow['department'] ?? '');
            $selectedProgramKey = '';
            foreach ($programOptions as $programRow) {
                if ((string) ($programRow['program_name'] ?? '') === $selectedProgram) {
                    $selectedProgramKey = (string) ($programRow['college_id'] ?? 0) . '|' . $selectedProgram;
                    break;
                }
            }
            ?>
            <div class="col-md-4">
                <label class="form-label">Program</label>
                <select name="program" class="form-select" required>
                    <option value="">Select program</option>
                    <?php if ($selectedProgram !== '' && $selectedProgramKey === ''): ?>
                        <option value="<?= htmlspecialchars($selectedProgram) ?>" selected><?= htmlspecialchars($selectedProgram) ?> (legacy)</option>
                    <?php endif; ?>
                    <?php foreach ($programOptions as $programRow): ?>
                        <?php
                        $programName = (string) ($programRow['program_name'] ?? '');
                        $programKey = (string) ($programRow['college_id'] ?? 0) . '|' . $programName;
                        $programLabel = (string) ($programRow['college_code'] ?? '') . ' - ' . $programName;
                        ?>
                        <option value="<?= htmlspecialchars($programKey) ?>" <?= $selectedProgramKey === $programKey ? 'selected' : '' ?>>
                            <?= htmlspecialchars($programLabel) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php else: ?>
            <div class="col-md-4"><label class="form-label">Program</label><input name="program" class="form-control" value="<?= htmlspecialchars((string) ($editRow['department'] ?? '')) ?>" placeholder="e.g. BS Information Systems"></div>
        <?php endif; ?>
        <div class="col-12"><button type="submit" class="btn btn-primary"<?= app_tooltip_attr($editRow ? 'Saves changes to this GE course.' : 'Creates the GE course record for scheduling and catalog use.') ?>>Save</button><?php if ($editRow): ?> <a href="gened_courses.php" class="btn btn-outline-secondary"<?= app_tooltip_attr('Discards unsaved edits and returns to the list.') ?>>Cancel</a><?php endif; ?></div>
    </form>
</div></div>

<div class="card shadow-sm"><div class="table-responsive">
    <table class="table mb-0">
        <thead class="table-light"><tr>
            <?php $baseQuery = ['q' => $search, 'program' => $programFilter]; ?>
            <th><a class="text-decoration-none" href="?<?= htmlspecialchars(http_build_query($baseQuery + ['sort' => 'course_code', 'dir' => $nextDirFor('course_code', $sort, $dir)])) ?>">Code<?= htmlspecialchars($sortArrowFor('course_code', $sort, $dir)) ?></a></th>
            <th><a class="text-decoration-none" href="?<?= htmlspecialchars(http_build_query($baseQuery + ['sort' => 'course_name', 'dir' => $nextDirFor('course_name', $sort, $dir)])) ?>">Name<?= htmlspecialchars($sortArrowFor('course_name', $sort, $dir)) ?></a></th>
            <th><a class="text-decoration-none" href="?<?= htmlspecialchars(http_build_query($baseQuery + ['sort' => 'program', 'dir' => $nextDirFor('program', $sort, $dir)])) ?>">Program<?= htmlspecialchars($sortArrowFor('program', $sort, $dir)) ?></a></th>
            <th><a class="text-decoration-none" href="?<?= htmlspecialchars(http_build_query($baseQuery + ['sort' => 'lecture_units', 'dir' => $nextDirFor('lecture_units', $sort, $dir)])) ?>">Lec Units<?= htmlspecialchars($sortArrowFor('lecture_units', $sort, $dir)) ?></a></th>
            <th><a class="text-decoration-none" href="?<?= htmlspecialchars(http_build_query($baseQuery + ['sort' => 'laboratory_units', 'dir' => $nextDirFor('laboratory_units', $sort, $dir)])) ?>">Lab Units<?= htmlspecialchars($sortArrowFor('laboratory_units', $sort, $dir)) ?></a></th>
            <th><a class="text-decoration-none" href="?<?= htmlspecialchars(http_build_query($baseQuery + ['sort' => 'type', 'dir' => $nextDirFor('type', $sort, $dir)])) ?>">Type<?= htmlspecialchars($sortArrowFor('type', $sort, $dir)) ?></a></th>
            <?php if ($hasYearLevel): ?>
                <th><a class="text-decoration-none" href="?<?= htmlspecialchars(http_build_query($baseQuery + ['sort' => 'year_level', 'dir' => $nextDirFor('year_level', $sort, $dir)])) ?>">Year<?= htmlspecialchars($sortArrowFor('year_level', $sort, $dir)) ?></a></th>
            <?php endif; ?>
            <?php if ($hasSection): ?>
                <th><a class="text-decoration-none" href="?<?= htmlspecialchars(http_build_query($baseQuery + ['sort' => 'section', 'dir' => $nextDirFor('section', $sort, $dir)])) ?>">Section<?= htmlspecialchars($sortArrowFor('section', $sort, $dir)) ?></a></th>
            <?php endif; ?>
            <th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($list as $r): ?>
            <tr>
                <td><?= htmlspecialchars((string) $r['course_code']) ?></td>
                <td><?= htmlspecialchars((string) $r['course_name']) ?></td>
                <td><?= htmlspecialchars((string) ($r['department'] ?? '')) ?></td>
                <td><?= htmlspecialchars((string) ($r['lecture_units'] ?? $r['units'])) ?></td>
                <td><?= htmlspecialchars((string) ($r['laboratory_units'] ?? 0)) ?></td>
                <td><?= !empty($r['is_laboratory']) ? 'Laboratory' : 'Lecture' ?></td>
                <?php if ($hasYearLevel): ?><td><?= htmlspecialchars((string) ($r['year_level'] ?? '')) ?></td><?php endif; ?>
                <?php if ($hasSection): ?><td><?= htmlspecialchars((string) ($r['section'] ?? '')) ?></td><?php endif; ?>
                <td class="text-nowrap">
                    <a class="btn btn-sm btn-outline-primary" href="gened_courses.php?edit=<?= (int) $r['id'] ?>"<?= app_tooltip_attr('Opens this GE course for editing.') ?>>Edit</a>
                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this GE course?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button type="submit" class="btn btn-sm btn-outline-danger"<?= app_tooltip_attr('Deletes this GE course after confirmation if no schedules depend on it.') ?>>Delete</button></form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div></div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

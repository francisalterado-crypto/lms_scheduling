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

$currentUser = current_user() ?? [];
$coordinatorName = trim((string) ($currentUser['full_name'] ?? 'GEN ED Coordinator'));
$coordinatorInitials = '';
foreach (preg_split('/\s+/', $coordinatorName) ?: [] as $part) {
    $token = trim((string) $part, " ,.\t\n\r\0\x0B");
    if ($token !== '') {
        $coordinatorInitials .= strtoupper(substr($token, 0, 1));
    }
}
$coordinatorInitials = $coordinatorInitials !== '' ? substr($coordinatorInitials, 0, 2) : 'GE';

$tableColCount = 6 + ($hasYearLevel ? 1 : 0) + ($hasSection ? 1 : 0) + 1;

$pageTitle = 'GE Courses';
require_once __DIR__ . '/includes/header.php';
?>
<style>
  .faculty-dashboard-container { max-width: 1440px; margin: 0 auto; }
  .faculty-university-header { display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; margin-bottom:28px; border-bottom:2px solid rgba(30,64,95,.2); padding-bottom:16px; gap: 12px; }
  .faculty-title-section h1 { font-size:1.9rem; font-weight:700; background:linear-gradient(135deg,#1e405f,#2a6f8f); -webkit-background-clip:text; background-clip:text; color:transparent; letter-spacing:-.3px; }
  .faculty-title-section p { font-size:.9rem; color:#4a627a; margin-top:6px; font-weight:500; }
  .faculty-dean-card { background:#fff; padding:10px 24px; border-radius:60px; box-shadow:0 4px 10px rgba(0,0,0,.02),0 1px 2px rgba(0,0,0,.05); display:flex; align-items:center; gap:16px; border:1px solid #e2edf2; }
  .faculty-dean-avatar { background:#1e5a6f; width:44px; height:44px; border-radius:50%; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:600; font-size:1.2rem; }
  .faculty-dean-info h4 { font-weight:600; font-size:.9rem; color:#2c4c6e; margin: 0; }
  .faculty-dean-info p { font-size:.75rem; color:#4c6a82; font-weight:500; margin: 0; }
  .faculty-layout { display:flex; flex-direction:column; gap:32px; }
  .faculty-card { background:#fff; border-radius:28px; box-shadow:0 12px 28px rgba(0,0,0,.05),0 0 0 1px rgba(0,0,0,.02); overflow:hidden; }
  .faculty-card-header { padding:20px 28px 12px; border-bottom:1px solid #edf2f7; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap: 10px; }
  .faculty-card-header h2 { font-size:1.35rem; font-weight:600; color:#1f3b4c; display:flex; align-items:center; gap:10px; margin: 0; }
  .faculty-badge-manage { background:#eef2ff; padding:6px 14px; border-radius:30px; font-size:.7rem; font-weight:600; color:#2c5f8a; }
  .faculty-form-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:20px 28px; padding:28px; }
  .faculty-input-group { display:flex; flex-direction:column; gap:8px; }
  .faculty-input-group label { font-size:.75rem; font-weight:600; text-transform:uppercase; letter-spacing:.5px; color:#4a6b85; }
  .faculty-input-group input, .faculty-input-group select { padding:12px 14px; border-radius:16px; border:1.5px solid #e2edf2; background:#fefefe; font-size:.9rem; transition:all .2s; }
  .faculty-input-group input:focus, .faculty-input-group select:focus { outline:none; border-color:#2c8cac; box-shadow:0 0 0 3px rgba(44,140,172,.2); }
  .faculty-row-flex { display:flex; gap:20px; flex-wrap:wrap; align-items:flex-end; grid-column: 1 / -1; }
  .faculty-checkbox-row { display:flex; align-items:center; gap:10px; grid-column: 1 / -1; color:#2a445b; }
  .faculty-checkbox-row input[type="checkbox"] { width:1.1rem; height:1.1rem; }
  .faculty-save-btn { background:#1f6e8c; border:none; padding:12px 24px; border-radius:40px; font-weight:600; color:#fff; font-size:.9rem; display:inline-flex; align-items:center; gap:12px; cursor:pointer; transition:.2s; }
  .faculty-save-btn:hover { background:#0e556f; transform:translateY(-1px); box-shadow:0 6px 12px rgba(0,0,0,.05); }
  .faculty-apply-btn { background:#f0f6fa; border:1.5px solid #cfe3ee; padding:12px 24px; border-radius:40px; font-weight:600; color:#1e5a6f; font-size:.9rem; cursor:pointer; transition:.2s; }
  .faculty-apply-btn:hover { background:#e4f0f7; border-color:#b8d4e4; }
  .faculty-cancel-link { border-radius: 40px; padding: 10px 20px; }
  .faculty-table-wrapper { overflow-x:auto; padding:0 0 8px; }
  .faculty-table { width:100%; border-collapse:collapse; font-size:.85rem; }
  .faculty-table th { text-align:left; padding:18px 16px; background:#f9fbfd; font-weight:600; color:#2c4c6e; border-bottom:1px solid #e6edf2; }
  .faculty-table td { padding:14px 16px; border-bottom:1px solid #eff3f8; vertical-align:middle; color:#2a445b; }
  .faculty-table tr:hover td { background:#fafcff; }
  .faculty-status-badge { display:inline-block; padding:4px 12px; border-radius:50px; font-size:.7rem; font-weight:600; text-transform:capitalize; }
  .faculty-type-lecture { background:#e8f4fc; color:#1a5f7a; }
  .faculty-type-lab { background:#f3e8ff; color:#5b2c8a; }
  .faculty-action-buttons { display:flex; gap:8px; align-items:center; }
  .faculty-icon-btn { border:none; border-radius:30px; background:transparent; color:#2c7da0; padding:6px 9px; }
  .faculty-icon-btn.delete { color:#bc6c6c; }
  .faculty-icon-btn:hover { background:#eef2ff; color:#0f5c7c; }
  .faculty-icon-btn.delete:hover { background:#fff0f0; color:#b13e3e; }
  .faculty-manage-programs-link { background:#f7f9fc; border-radius:20px; padding:8px 20px; font-size:.8rem; font-weight:500; color:#2a6f8f; text-decoration:none; transition:.2s; }
  .faculty-manage-programs-link:hover { background:#eaf3f8; color:#2a6f8f; }
  .faculty-footer-note { margin-top:32px; text-align:center; font-size:.7rem; color:#6b89a0; border-top:1px solid #e0eaf0; padding-top:24px; }
  html[data-bs-theme="dark"] .faculty-title-section h1 { background: linear-gradient(135deg, #c5e6ff, #79c5e8); -webkit-background-clip:text; background-clip:text; color: transparent; }
  html[data-bs-theme="dark"] .faculty-title-section p { color: #9db6c8; }
  html[data-bs-theme="dark"] .faculty-dean-card,
  html[data-bs-theme="dark"] .faculty-card { background: #16202a; border-color: #2a3947; box-shadow: 0 10px 24px rgba(0,0,0,.35); }
  html[data-bs-theme="dark"] .faculty-dean-info h4,
  html[data-bs-theme="dark"] .faculty-card-header h2,
  html[data-bs-theme="dark"] .faculty-table th { color: #d3e7f6; }
  html[data-bs-theme="dark"] .faculty-dean-info p,
  html[data-bs-theme="dark"] .faculty-input-group label,
  html[data-bs-theme="dark"] .faculty-footer-note { color: #9ab0c2; }
  html[data-bs-theme="dark"] .faculty-card-header { border-bottom-color: #2b3a49; }
  html[data-bs-theme="dark"] .faculty-input-group input,
  html[data-bs-theme="dark"] .faculty-input-group select { background:#0f1821; border-color:#2e4152; color:#deebf5; }
  html[data-bs-theme="dark"] .faculty-input-group input:focus,
  html[data-bs-theme="dark"] .faculty-input-group select:focus { border-color:#4ea4c6; box-shadow:0 0 0 3px rgba(78,164,198,.28); }
  html[data-bs-theme="dark"] .faculty-checkbox-row { color:#c5d9e7; }
  html[data-bs-theme="dark"] .faculty-manage-programs-link { background:#1d2b38; color:#9ed0eb; }
  html[data-bs-theme="dark"] .faculty-manage-programs-link:hover { background:#253747; color:#b7ddf3; }
  html[data-bs-theme="dark"] .faculty-badge-manage { background:#223243; color:#a9d2ea; }
  html[data-bs-theme="dark"] .faculty-table th { background:#1b2834; border-bottom-color:#30414f; }
  html[data-bs-theme="dark"] .faculty-table td { color:#c8dced; border-bottom-color:#2b3a47; }
  html[data-bs-theme="dark"] .faculty-table tr:hover td { background:#1a2530; }
  html[data-bs-theme="dark"] .faculty-type-lecture { background:#1a3040; color:#9ed4f0; }
  html[data-bs-theme="dark"] .faculty-type-lab { background:#2d2440; color:#d4b8f0; }
  html[data-bs-theme="dark"] .faculty-icon-btn { color:#90c7e5; }
  html[data-bs-theme="dark"] .faculty-icon-btn:hover { background:#233344; color:#c4e6f9; }
  html[data-bs-theme="dark"] .faculty-icon-btn.delete { color:#e09a9a; }
  html[data-bs-theme="dark"] .faculty-icon-btn.delete:hover { background:#3f262b; color:#ffc4c4; }
  html[data-bs-theme="dark"] .faculty-footer-note { border-top-color:#2a3a49; }
  html[data-bs-theme="dark"] .faculty-apply-btn { background:#1d2b38; border-color:#304a5c; color:#9ed0eb; }
  html[data-bs-theme="dark"] .faculty-apply-btn:hover { background:#253747; }
  @media (max-width: 700px) {
    .faculty-card-header { flex-direction:column; align-items:flex-start; }
  }
</style>

<div class="faculty-dashboard-container">
    <div class="faculty-university-header">
        <div class="faculty-title-section">
            <h1><i class="fa-solid fa-book" style="color:#2c7da0; margin-right:8px;"></i>Gen Ed Courses</h1>
            <p>General Education · Western Philippines University</p>
        </div>
        <div class="faculty-dean-card">
            <div class="faculty-dean-avatar"><?= htmlspecialchars($coordinatorInitials) ?></div>
            <div class="faculty-dean-info">
                <h4><?= htmlspecialchars(strtoupper($coordinatorName)) ?></h4>
                <p>GEN ED Coordinator <i class="fa-solid fa-circle-check" style="font-size:.7rem; color:#1f8a4c;"></i></p>
            </div>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-info alert-dismissible fade show no-print">
            <?= htmlspecialchars($flash) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"<?= app_tooltip_attr('Dismisses this notice after you have read it.') ?>></button>
        </div>
    <?php endif; ?>

    <?php if (!$hasLabFlag || !$hasLectureUnits || !$hasLaboratoryUnits || !$hasIsGened): ?>
        <div class="alert alert-warning">Run <a href="upgrade_roles.php">upgrade_roles.php</a> to enable GE course features.</div>
    <?php endif; ?>
    <?php if (!$hasCourseBlock): ?>
        <div class="alert alert-warning">Year level/section fields are disabled until you run <a href="upgrade_roles.php">upgrade_roles.php</a>.</div>
    <?php endif; ?>
    <?php if (!$hasProgramsTable): ?>
        <div class="alert alert-warning">Program dropdown is disabled until you run <a href="upgrade_roles.php">upgrade_roles.php</a>.</div>
    <?php endif; ?>

    <div class="faculty-layout">
        <div class="faculty-card">
            <div class="faculty-card-header">
                <h2><i class="fa-solid fa-magnifying-glass"></i> Search &amp; sort</h2>
                <span class="faculty-badge-manage"><i class="fa-solid fa-filter me-1"></i>Refine the roster</span>
            </div>
            <form method="get" class="faculty-form-grid">
                <div class="faculty-input-group">
                    <label>Search</label>
                    <input type="text" name="q" placeholder="Code, name, program, year, section" value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="faculty-input-group">
                    <label>Program</label>
                    <select name="program">
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
                <div class="faculty-input-group">
                    <label>Sort by</label>
                    <select name="sort">
                        <option value="course_code" <?= $sort === 'course_code' ? 'selected' : '' ?>>Code</option>
                        <option value="course_name" <?= $sort === 'course_name' ? 'selected' : '' ?>>Name</option>
                        <option value="program" <?= $sort === 'program' ? 'selected' : '' ?>>Program</option>
                        <option value="lecture_units" <?= $sort === 'lecture_units' ? 'selected' : '' ?>>Lecture units</option>
                        <option value="laboratory_units" <?= $sort === 'laboratory_units' ? 'selected' : '' ?>>Laboratory units</option>
                        <?php if ($hasYearLevel): ?><option value="year_level" <?= $sort === 'year_level' ? 'selected' : '' ?>>Year level</option><?php endif; ?>
                        <?php if ($hasSection): ?><option value="section" <?= $sort === 'section' ? 'selected' : '' ?>>Section</option><?php endif; ?>
                        <?php if ($hasLabFlag): ?><option value="type" <?= $sort === 'type' ? 'selected' : '' ?>>Type</option><?php endif; ?>
                    </select>
                </div>
                <div class="faculty-input-group">
                    <label>Direction</label>
                    <select name="dir">
                        <option value="asc" <?= $dir === 'ASC' ? 'selected' : '' ?>>Ascending</option>
                        <option value="desc" <?= $dir === 'DESC' ? 'selected' : '' ?>>Descending</option>
                    </select>
                </div>
                <div class="faculty-row-flex" style="justify-content:flex-end;">
                    <button type="submit" class="faculty-apply-btn"<?= app_tooltip_attr('Applies search, sort, and filter to the GE course list.') ?>><i class="fa-solid fa-check"></i> Apply</button>
                </div>
            </form>
        </div>

        <div class="faculty-card">
            <div class="faculty-card-header">
                <h2><i class="fa-solid fa-book-open"></i> <?= $editRow ? 'Edit GE course' : 'Add GE course' ?></h2>
                <a href="gened_faculty.php" class="faculty-manage-programs-link"><i class="fa-solid fa-chalkboard-user me-1"></i>GE faculty</a>
            </div>
            <form method="post" class="faculty-form-grid">
                <input type="hidden" name="action" value="<?= $editRow ? 'edit' : 'add' ?>">
                <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>"><?php endif; ?>
                <div class="faculty-input-group"><label>Course code</label><input name="course_code" required maxlength="20" value="<?= htmlspecialchars((string) ($editRow['course_code'] ?? '')) ?>"></div>
                <div class="faculty-input-group" style="grid-column: span 2; min-width: 280px;"><label>Course name</label><input name="course_name" required maxlength="100" value="<?= htmlspecialchars((string) ($editRow['course_name'] ?? '')) ?>"></div>
                <div class="faculty-input-group"><label>Lecture units</label><input name="lecture_units" type="number" step="0.1" min="0" max="12" value="<?= htmlspecialchars((string) ($editRow['lecture_units'] ?? $editRow['units'] ?? '3.0')) ?>"></div>
                <div class="faculty-input-group"><label>Laboratory units</label><input name="laboratory_units" type="number" step="0.1" min="0" max="12" value="<?= htmlspecialchars((string) ($editRow['laboratory_units'] ?? '0.0')) ?>"></div>
                <div class="faculty-checkbox-row">
                    <input type="checkbox" name="is_laboratory" value="1" id="ge_is_lab" <?= !empty($editRow['is_laboratory']) ? 'checked' : '' ?>>
                    <label for="ge_is_lab"><strong>Laboratory course</strong></label>
                </div>
                <?php if ($hasYearLevel): ?>
                    <div class="faculty-input-group"><label>Year level</label>
                        <select name="year_level" required>
                            <?php foreach (['1','2','3','4','5'] as $yl): ?>
                                <option value="<?= $yl ?>" <?= (string) ($editRow['year_level'] ?? '') === $yl ? 'selected' : '' ?>><?= htmlspecialchars($yl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <?php if ($hasSection): ?>
                    <div class="faculty-input-group"><label>Section</label><input name="section" maxlength="20" required value="<?= htmlspecialchars((string) ($editRow['section'] ?? '')) ?>"></div>
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
                    <div class="faculty-input-group" style="grid-column: span 2; min-width: 280px;">
                        <label>Program</label>
                        <select name="program" required>
                            <option value="" disabled<?= $selectedProgram === '' ? ' selected' : '' ?>>Select program…</option>
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
                    <div class="faculty-input-group" style="grid-column: span 2; min-width: 280px;">
                        <label>Program</label>
                        <input name="program" value="<?= htmlspecialchars((string) ($editRow['department'] ?? '')) ?>" placeholder="e.g. BS Information Systems">
                    </div>
                <?php endif; ?>
                <div style="display:flex; justify-content:flex-end; gap: 10px; grid-column: 1 / -1;">
                    <?php if ($editRow): ?><a href="gened_courses.php" class="btn btn-outline-secondary faculty-cancel-link"<?= app_tooltip_attr('Discards unsaved edits and returns to the list.') ?>>Cancel</a><?php endif; ?>
                    <button type="submit" class="faculty-save-btn"<?= app_tooltip_attr($editRow ? 'Saves changes to this GE course.' : 'Creates the GE course record for scheduling and catalog use.') ?>><i class="fa-solid <?= $editRow ? 'fa-pen' : 'fa-save' ?>"></i> <?= $editRow ? 'Update course' : 'Save course' ?></button>
                </div>
            </form>
        </div>

        <div class="faculty-card">
            <div class="faculty-card-header">
                <h2><i class="fa-solid fa-list-ul"></i> GE course catalog</h2>
                <span class="faculty-badge-manage"><i class="fa-solid fa-pen me-1"></i>Manage GE courses</span>
            </div>
            <div class="faculty-table-wrapper">
                <table class="faculty-table">
                    <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Program</th>
                        <th>Lec units</th>
                        <th>Lab units</th>
                        <th>Type</th>
                        <?php if ($hasYearLevel): ?><th>Year</th><?php endif; ?>
                        <?php if ($hasSection): ?><th>Section</th><?php endif; ?>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($list as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $r['course_code']) ?></td>
                            <td><?= htmlspecialchars((string) $r['course_name']) ?></td>
                            <td><?= htmlspecialchars((string) ($r['department'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($r['lecture_units'] ?? $r['units'])) ?></td>
                            <td><?= htmlspecialchars((string) ($r['laboratory_units'] ?? 0)) ?></td>
                            <td>
                                <?php if (!empty($r['is_laboratory'])): ?>
                                    <span class="faculty-status-badge faculty-type-lab">Laboratory</span>
                                <?php else: ?>
                                    <span class="faculty-status-badge faculty-type-lecture">Lecture</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($hasYearLevel): ?><td><?= htmlspecialchars((string) ($r['year_level'] ?? '')) ?></td><?php endif; ?>
                            <?php if ($hasSection): ?><td><?= htmlspecialchars((string) ($r['section'] ?? '')) ?></td><?php endif; ?>
                            <td class="faculty-action-buttons">
                                <a href="gened_courses.php?edit=<?= (int) $r['id'] ?>" class="faculty-icon-btn" title="Edit GE course"<?= app_tooltip_attr('Opens this GE course for editing.') ?>><i class="fa-solid fa-pen"></i></a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this GE course?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button type="submit" class="faculty-icon-btn delete" title="Delete GE course"<?= app_tooltip_attr('Deletes this GE course after confirmation if no schedules depend on it.') ?>><i class="fa-solid fa-trash-can"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($list === []): ?>
                        <tr><td colspan="<?= (int) $tableColCount ?>" class="text-center text-muted py-4">No GE courses match your filters. Add one above or adjust search.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="faculty-footer-note">
        <i class="fa-solid fa-database"></i> General Education course catalog · Western Philippines University
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

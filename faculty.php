<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/admin_activity_log.php';

require_role(['dean', 'program_chair']);
$collegeId = dean_or_program_chair_college_id_or_fail();
$programScope = is_program_chair() ? program_scope_or_fail() : null;
$collegeName = college_name_by_id($collegeId);
$hasProgramsTable = db_table_exists('programs');
$hasEmploymentStatusColumn = db_column_exists('faculty', 'employment_status');
$deanProgramOptions = [];
if ($hasProgramsTable && $programScope === null) {
    $stProg = db()->prepare("SELECT program_name FROM programs WHERE college_id=? AND status='active' ORDER BY program_name");
    $stProg->execute([$collegeId]);
    $deanProgramOptions = array_map('strval', $stProg->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $maxHoursPerDay = (int) ($_POST['max_hours_per_day'] ?? 8);
    if ($maxHoursPerDay < 1) {
        $maxHoursPerDay = 1;
    } elseif ($maxHoursPerDay > 36) {
        $maxHoursPerDay = 36;
    }

    try {
        if ($action === 'add') {
            $allowedEmploymentStatuses = ['Permanent', 'Contract of Service', 'Temporary'];
            $employmentStatus = trim((string) ($_POST['employment_status'] ?? 'Permanent'));
            if (!in_array($employmentStatus, $allowedEmploymentStatuses, true)) {
                throw new RuntimeException('Please select a valid employment status.');
            }
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            if ($username === '' || $password === '') {
                throw new RuntimeException('Username and password are required.');
            }
            $exists = db()->prepare('SELECT COUNT(*) FROM users WHERE username=?');
            $exists->execute([$username]);
            if ((int) $exists->fetchColumn() > 0) {
                $s = suggest_available_usernames($username, 3);
                throw new RuntimeException('Username already exists.' . ($s ? ' Try: ' . implode(', ', $s) : ''));
            }
            $facultyCode = trim((string) ($_POST['faculty_id'] ?? ''));
            if ($facultyCode === '') {
                throw new RuntimeException('Faculty ID is required.');
            }
            $exists = db()->prepare('SELECT COUNT(*) FROM faculty WHERE faculty_id=?');
            $exists->execute([$facultyCode]);
            if ((int) $exists->fetchColumn() > 0) {
                throw new RuntimeException('Faculty ID already exists.');
            }
            $deptValAdd = trim((string) ($_POST['department'] ?? ''));
            if ($programScope === null) {
                if ($hasProgramsTable && $deanProgramOptions !== []) {
                    if (!in_array($deptValAdd, $deanProgramOptions, true)) {
                        throw new RuntimeException('Please select a valid program from your college list.');
                    }
                } elseif ($hasProgramsTable && $deanProgramOptions === [] && $deptValAdd === '') {
                    throw new RuntimeException('Add at least one active program under Programs before assigning faculty.');
                } elseif ($deptValAdd === '') {
                    throw new RuntimeException('Program is required.');
                }
            }
            db()->beginTransaction();
            db()->prepare('INSERT INTO users (username,password,full_name,role,college_id,is_active) VALUES (?,?,?,?,?,?)')
                ->execute([
                    $username,
                    password_hash($password, PASSWORD_DEFAULT),
                    trim((string) ($_POST['full_name'] ?? '')),
                    'faculty',
                    $collegeId,
                    (($_POST['status'] ?? '') === 'inactive') ? 0 : 1,
                ]);
            $uid = (int) db()->lastInsertId();
            if ($hasEmploymentStatusColumn) {
                db()->prepare(
                    'INSERT INTO faculty (user_id, faculty_id, full_name, department, email, max_hours_per_day, college_id, status, employment_status, is_gened)
                     VALUES (?,?,?,?,?,?,?,?,?,0)'
                )->execute([
                    $uid,
                    $facultyCode,
                    trim((string) ($_POST['full_name'] ?? '')),
                    $programScope ?? trim((string) ($_POST['department'] ?? '')),
                    trim((string) ($_POST['email'] ?? '')),
                    $maxHoursPerDay,
                    $collegeId,
                    (($_POST['status'] ?? '') === 'inactive') ? 'inactive' : 'active',
                    $employmentStatus,
                ]);
            } else {
                db()->prepare(
                    'INSERT INTO faculty (user_id, faculty_id, full_name, department, email, max_hours_per_day, college_id, status, is_gened)
                     VALUES (?,?,?,?,?,?,?,?,0)'
                )->execute([
                    $uid,
                    $facultyCode,
                    trim((string) ($_POST['full_name'] ?? '')),
                    $programScope ?? trim((string) ($_POST['department'] ?? '')),
                    trim((string) ($_POST['email'] ?? '')),
                    $maxHoursPerDay,
                    $collegeId,
                    (($_POST['status'] ?? '') === 'inactive') ? 'inactive' : 'active',
                ]);
            }
            db()->commit();
            $newFacultyId = (int) db()->lastInsertId();
            $stFa = db()->prepare(
                'SELECT f.id, f.user_id, f.faculty_id, f.full_name, f.department, f.email, f.max_hours_per_day, f.status, f.college_id, u.username, u.is_active AS user_is_active
                 FROM faculty f INNER JOIN users u ON u.id = f.user_id WHERE f.id = ? LIMIT 1'
            );
            $stFa->execute([$newFacultyId]);
            $afterFac = $stFa->fetch(PDO::FETCH_ASSOC);
            log_user_activity('add', 'Faculty', 'Faculty #' . $newFacultyId, null, $afterFac ? (array) $afterFac : null);
            log_dean_activity('faculty_create', 'Created faculty ' . $facultyCode);
            $_SESSION['flash'] = 'Faculty member added.';
        } elseif ($action === 'edit' && isset($_POST['id'])) {
            $allowedEmploymentStatuses = ['Permanent', 'Contract of Service', 'Temporary'];
            $employmentStatus = trim((string) ($_POST['employment_status'] ?? 'Permanent'));
            if (!in_array($employmentStatus, $allowedEmploymentStatuses, true)) {
                throw new RuntimeException('Please select a valid employment status.');
            }
            $fid = (int) $_POST['id'];
            $sqlBf = 'SELECT f.*, u.username, u.is_active AS user_is_active FROM faculty f INNER JOIN users u ON u.id = f.user_id WHERE f.id=? AND f.college_id=? AND COALESCE(f.is_gened,0)=0';
            $parBf = [$fid, $collegeId];
            if ($programScope !== null) {
                $sqlBf .= ' AND f.department=?';
                $parBf[] = $programScope;
            }
            $stBf = db()->prepare($sqlBf);
            $stBf->execute($parBf);
            $beforeFac = $stBf->fetch(PDO::FETCH_ASSOC);
            $sql = 'SELECT user_id FROM faculty WHERE id=? AND college_id=? AND COALESCE(is_gened,0)=0';
            $params = [$fid, $collegeId];
            if ($programScope !== null) {
                $sql .= ' AND department=?';
                $params[] = $programScope;
            }
            $chk = db()->prepare($sql);
            $chk->execute($params);
            $userId = (int) ($chk->fetchColumn() ?: 0);
            if ($userId < 1) {
                throw new RuntimeException('Faculty record not found in your college.');
            }
            $newCode = trim((string) ($_POST['faculty_id'] ?? ''));
            if ($newCode === '') {
                throw new RuntimeException('Faculty ID is required.');
            }
            $exists = db()->prepare('SELECT COUNT(*) FROM faculty WHERE faculty_id=? AND id<>?');
            $exists->execute([$newCode, $fid]);
            if ((int) $exists->fetchColumn() > 0) {
                throw new RuntimeException('Faculty ID already exists.');
            }
            $deptValEdit = trim((string) ($_POST['department'] ?? ''));
            if ($programScope === null) {
                if ($hasProgramsTable && $deanProgramOptions !== []) {
                    $legacyDept = trim((string) ($beforeFac['department'] ?? ''));
                    $allowedDept = $deanProgramOptions;
                    if ($legacyDept !== '' && !in_array($legacyDept, $allowedDept, true)) {
                        $allowedDept[] = $legacyDept;
                    }
                    if (!in_array($deptValEdit, $allowedDept, true)) {
                        throw new RuntimeException('Please select a valid program from your college list.');
                    }
                } elseif ($deptValEdit === '') {
                    throw new RuntimeException('Program is required.');
                }
            }
            if ($hasEmploymentStatusColumn) {
                db()->prepare(
                    'UPDATE faculty SET faculty_id=?, full_name=?, department=?, email=?, max_hours_per_day=?, status=?, employment_status=? WHERE id=? AND college_id=? AND COALESCE(is_gened,0)=0'
                )->execute([
                    $newCode,
                    trim((string) ($_POST['full_name'] ?? '')),
                    $programScope ?? trim((string) ($_POST['department'] ?? '')),
                    trim((string) ($_POST['email'] ?? '')),
                    $maxHoursPerDay,
                    (($_POST['status'] ?? '') === 'inactive') ? 'inactive' : 'active',
                    $employmentStatus,
                    $fid,
                    $collegeId,
                ]);
            } else {
                db()->prepare(
                    'UPDATE faculty SET faculty_id=?, full_name=?, department=?, email=?, max_hours_per_day=?, status=? WHERE id=? AND college_id=? AND COALESCE(is_gened,0)=0'
                )->execute([
                    $newCode,
                    trim((string) ($_POST['full_name'] ?? '')),
                    $programScope ?? trim((string) ($_POST['department'] ?? '')),
                    trim((string) ($_POST['email'] ?? '')),
                    $maxHoursPerDay,
                    (($_POST['status'] ?? '') === 'inactive') ? 'inactive' : 'active',
                    $fid,
                    $collegeId,
                ]);
            }
            db()->prepare('UPDATE users SET full_name=?, is_active=? WHERE id=?')
                ->execute([
                    trim((string) ($_POST['full_name'] ?? '')),
                    (($_POST['status'] ?? '') === 'inactive') ? 0 : 1,
                    $userId,
                ]);
            if (!empty($_POST['reset_password'])) {
                db()->prepare('UPDATE users SET password=? WHERE id=?')
                    ->execute([password_hash((string) $_POST['reset_password'], PASSWORD_DEFAULT), $userId]);
            }
            $stAf = db()->prepare(
                'SELECT f.id, f.user_id, f.faculty_id, f.full_name, f.department, f.email, f.max_hours_per_day, f.status, f.college_id, u.username, u.is_active AS user_is_active
                 FROM faculty f INNER JOIN users u ON u.id = f.user_id WHERE f.id = ? LIMIT 1'
            );
            $stAf->execute([$fid]);
            $afterFac = $stAf->fetch(PDO::FETCH_ASSOC);
            if ($afterFac !== false && $afterFac !== [] && (!empty($_POST['reset_password']))) {
                $afterFac['password'] = '[changed]';
            }
            log_user_activity('edit', 'Faculty', 'Faculty #' . $fid, $beforeFac ? (array) $beforeFac : null, $afterFac ? (array) $afterFac : null);
            log_dean_activity('faculty_update', 'Updated faculty ID #' . $fid);
            $_SESSION['flash'] = 'Faculty updated.';
        } elseif ($action === 'delete' && isset($_POST['id'])) {
            $fid = (int) $_POST['id'];
            $sqlDelSnap = 'SELECT f.*, u.username FROM faculty f INNER JOIN users u ON u.id = f.user_id WHERE f.id=? AND f.college_id=? AND COALESCE(f.is_gened,0)=0';
            $parDelSnap = [$fid, $collegeId];
            if ($programScope !== null) {
                $sqlDelSnap .= ' AND f.department=?';
                $parDelSnap[] = $programScope;
            }
            $stDelSnap = db()->prepare($sqlDelSnap);
            $stDelSnap->execute($parDelSnap);
            $beforeDelFac = $stDelSnap->fetch(PDO::FETCH_ASSOC);
            $sql = 'SELECT user_id FROM faculty WHERE id=? AND college_id=? AND COALESCE(is_gened,0)=0';
            $params = [$fid, $collegeId];
            if ($programScope !== null) {
                $sql .= ' AND department=?';
                $params[] = $programScope;
            }
            $q = db()->prepare($sql);
            $q->execute($params);
            $userId = (int) ($q->fetchColumn() ?: 0);
            if ($userId < 1) {
                throw new RuntimeException('Faculty record not found.');
            }
            $sql = 'DELETE FROM faculty WHERE id=? AND college_id=? AND COALESCE(is_gened,0)=0';
            $params = [$fid, $collegeId];
            if ($programScope !== null) {
                $sql .= ' AND department=?';
                $params[] = $programScope;
            }
            db()->prepare($sql)->execute($params);
            db()->prepare('DELETE FROM users WHERE id=? AND role="faculty"')->execute([$userId]);
            log_user_activity('delete', 'Faculty', 'Faculty #' . $fid, $beforeDelFac ? (array) $beforeDelFac : null, null);
            log_dean_activity('faculty_delete', 'Deleted faculty ID #' . $fid);
            $_SESSION['flash'] = 'Faculty member removed.';
        }
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        $_SESSION['flash'] = 'Error: ' . $e->getMessage();
    }
    header('Location: faculty.php');
    exit;
}

$editRow = null;
if (isset($_GET['edit'])) {
    $sql = 'SELECT f.*, u.username FROM faculty f LEFT JOIN users u ON u.id=f.user_id WHERE f.id=? AND f.college_id=? AND COALESCE(f.is_gened,0)=0';
    $params = [(int) $_GET['edit'], $collegeId];
    if ($programScope !== null) {
        $sql .= ' AND f.department=?';
        $params[] = $programScope;
    }
    $st = db()->prepare($sql);
    $st->execute($params);
    $editRow = $st->fetch() ?: null;
}

$sql = 'SELECT f.*, u.username, u.is_active
     FROM faculty f
     LEFT JOIN users u ON u.id = f.user_id
     WHERE f.college_id = ? AND COALESCE(f.is_gened,0) = 0';
$params = [$collegeId];
if ($programScope !== null) {
    $sql .= ' AND f.department = ?';
    $params[] = $programScope;
}
$sql .= ' ORDER BY f.full_name ASC';
$st = db()->prepare($sql);
$st->execute($params);
$list = $st->fetchAll();

$currentUser = current_user() ?? [];
$deanName = trim((string) ($currentUser['full_name'] ?? 'College Administrator'));
$deanInitials = '';
foreach (preg_split('/\s+/', $deanName) ?: [] as $part) {
    $token = trim((string) $part, " ,.\t\n\r\0\x0B");
    if ($token !== '') {
        $deanInitials .= strtoupper(substr($token, 0, 1));
    }
}
$deanInitials = $deanInitials !== '' ? substr($deanInitials, 0, 2) : 'FA';
$totalFacultyCount = count($list);
$permanentCount = 0;
$activeCount = 0;
foreach ($list as $facultyRow) {
    if ((string) ($facultyRow['employment_status'] ?? '') === 'Permanent') {
        $permanentCount++;
    }
    if ((string) ($facultyRow['status'] ?? '') === 'active') {
        $activeCount++;
    }
}

$pageTitle = 'Faculty';
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
  .faculty-stats-row { display:flex; gap:20px; margin-bottom:32px; flex-wrap:wrap; }
  .faculty-stat-card { background:#fff; border-radius:24px; padding:14px 28px; box-shadow:0 2px 6px rgba(0,0,0,.04); display:flex; align-items:center; gap:14px; border:1px solid #eef3f8; flex:1 1 auto; min-width: 200px; }
  .faculty-stat-icon { background:#eaf4fc; width:48px; height:48px; border-radius:32px; display:flex; align-items:center; justify-content:center; color:#1f6e8c; font-size:1.4rem; }
  .faculty-stat-info h3 { font-size:1.6rem; font-weight:700; color:#1e405f; margin: 0; }
  .faculty-stat-info span { font-size:.75rem; color:#5e7f9a; font-weight:500; }
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
  .faculty-radio-group { display:flex; align-items:center; gap:18px; margin-top:8px; grid-column: 1 / -1; color:#2a445b; }
  .faculty-save-btn { background:#1f6e8c; border:none; padding:12px 24px; border-radius:40px; font-weight:600; color:#fff; font-size:.9rem; display:inline-flex; align-items:center; gap:12px; cursor:pointer; transition:.2s; }
  .faculty-save-btn:hover { background:#0e556f; transform:translateY(-1px); box-shadow:0 6px 12px rgba(0,0,0,.05); }
  .faculty-cancel-link { border-radius: 40px; padding: 10px 20px; }
  .faculty-table-wrapper { overflow-x:auto; padding:0 0 8px; }
  .faculty-table { width:100%; border-collapse:collapse; font-size:.85rem; }
  .faculty-table th { text-align:left; padding:18px 16px; background:#f9fbfd; font-weight:600; color:#2c4c6e; border-bottom:1px solid #e6edf2; }
  .faculty-table td { padding:14px 16px; border-bottom:1px solid #eff3f8; vertical-align:middle; color:#2a445b; }
  .faculty-table tr:hover td { background:#fafcff; }
  .faculty-status-badge { display:inline-block; padding:4px 12px; border-radius:50px; font-size:.7rem; font-weight:600; text-transform:capitalize; }
  .faculty-status-active { background:#e0f2e9; color:#1f7840; }
  .faculty-status-inactive { background:#ffe6e5; color:#b33; }
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
  html[data-bs-theme="dark"] .faculty-stat-card,
  html[data-bs-theme="dark"] .faculty-card { background: #16202a; border-color: #2a3947; box-shadow: 0 10px 24px rgba(0,0,0,.35); }
  html[data-bs-theme="dark"] .faculty-dean-info h4,
  html[data-bs-theme="dark"] .faculty-card-header h2,
  html[data-bs-theme="dark"] .faculty-table th { color: #d3e7f6; }
  html[data-bs-theme="dark"] .faculty-dean-info p,
  html[data-bs-theme="dark"] .faculty-stat-info span,
  html[data-bs-theme="dark"] .faculty-input-group label,
  html[data-bs-theme="dark"] .faculty-footer-note { color: #9ab0c2; }
  html[data-bs-theme="dark"] .faculty-stat-icon { background:#203346; color:#87c8ea; }
  html[data-bs-theme="dark"] .faculty-stat-info h3 { color:#e3f3ff; }
  html[data-bs-theme="dark"] .faculty-card-header { border-bottom-color: #2b3a49; }
  html[data-bs-theme="dark"] .faculty-input-group input,
  html[data-bs-theme="dark"] .faculty-input-group select { background:#0f1821; border-color:#2e4152; color:#deebf5; }
  html[data-bs-theme="dark"] .faculty-input-group input:focus,
  html[data-bs-theme="dark"] .faculty-input-group select:focus { border-color:#4ea4c6; box-shadow:0 0 0 3px rgba(78,164,198,.28); }
  html[data-bs-theme="dark"] .faculty-radio-group { color:#c5d9e7; }
  html[data-bs-theme="dark"] .faculty-manage-programs-link { background:#1d2b38; color:#9ed0eb; }
  html[data-bs-theme="dark"] .faculty-manage-programs-link:hover { background:#253747; color:#b7ddf3; }
  html[data-bs-theme="dark"] .faculty-badge-manage { background:#223243; color:#a9d2ea; }
  html[data-bs-theme="dark"] .faculty-table th { background:#1b2834; border-bottom-color:#30414f; }
  html[data-bs-theme="dark"] .faculty-table td { color:#c8dced; border-bottom-color:#2b3a47; }
  html[data-bs-theme="dark"] .faculty-table tr:hover td { background:#1a2530; }
  html[data-bs-theme="dark"] .faculty-status-active { background:#1d3a2b; color:#8fddb1; }
  html[data-bs-theme="dark"] .faculty-status-inactive { background:#45262a; color:#f4aab1; }
  html[data-bs-theme="dark"] .faculty-icon-btn { color:#90c7e5; }
  html[data-bs-theme="dark"] .faculty-icon-btn:hover { background:#233344; color:#c4e6f9; }
  html[data-bs-theme="dark"] .faculty-icon-btn.delete { color:#e09a9a; }
  html[data-bs-theme="dark"] .faculty-icon-btn.delete:hover { background:#3f262b; color:#ffc4c4; }
  html[data-bs-theme="dark"] .faculty-footer-note { border-top-color:#2a3a49; }
  @media (max-width: 700px) {
    .faculty-card-header { flex-direction:column; align-items:flex-start; }
    .faculty-stat-card { padding:10px 20px; }
  }
</style>

<div class="faculty-dashboard-container">
    <div class="faculty-university-header">
        <div class="faculty-title-section">
            <h1><i class="fa-solid fa-chalkboard-user" style="color:#2c7da0; margin-right:8px;"></i>Faculty Management</h1>
            <p><?= htmlspecialchars($collegeName) ?> · Western Philippines University</p>
            <?php if ($programScope !== null): ?>
                <p>Program scope: <strong><?= htmlspecialchars($programScope) ?></strong></p>
            <?php endif; ?>
        </div>
        <div class="faculty-dean-card">
            <div class="faculty-dean-avatar"><?= htmlspecialchars($deanInitials) ?></div>
            <div class="faculty-dean-info">
                <h4><?= htmlspecialchars(strtoupper($deanName)) ?></h4>
                <p><?= is_program_chair() ? 'Program Chair' : 'Dean' ?>, <?= htmlspecialchars($collegeName) ?> <i class="fa-solid fa-circle-check" style="font-size:.7rem; color:#1f8a4c;"></i></p>
            </div>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-info alert-dismissible fade show no-print">
            <?= htmlspecialchars($flash) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"<?= app_tooltip_attr('Dismisses this notice after you have read it.') ?>></button>
        </div>
    <?php endif; ?>

    <?php if (!$hasEmploymentStatusColumn): ?>
        <div class="alert alert-warning py-2">
            Employment Status is in view-only fallback mode. Run <a href="upgrade_roles.php">upgrade_roles.php</a> to save status changes (Permanent / Contract of Service / Temporary).
        </div>
    <?php endif; ?>

    <div class="faculty-stats-row">
        <div class="faculty-stat-card">
            <div class="faculty-stat-icon"><i class="fa-solid fa-users"></i></div>
            <div class="faculty-stat-info"><h3><?= $totalFacultyCount ?></h3><span>Total Faculty</span></div>
        </div>
        <div class="faculty-stat-card">
            <div class="faculty-stat-icon"><i class="fa-solid fa-user-check"></i></div>
            <div class="faculty-stat-info"><h3><?= $permanentCount ?></h3><span>Permanent</span></div>
        </div>
        <div class="faculty-stat-card">
            <div class="faculty-stat-icon"><i class="fa-solid fa-clock"></i></div>
            <div class="faculty-stat-info"><h3><?= $activeCount ?></h3><span>Active members</span></div>
        </div>
    </div>

    <div class="faculty-layout">
        <div class="faculty-card">
            <div class="faculty-card-header">
                <h2><i class="fa-solid fa-user-plus"></i> <?= $editRow ? 'Edit faculty' : 'Add faculty' ?></h2>
                <?php if ($programScope === null): ?>
                    <a href="dean_programs.php" class="faculty-manage-programs-link"><i class="fa-solid fa-layer-group me-1"></i>Manage programs</a>
                <?php endif; ?>
            </div>
            <form method="post" class="faculty-form-grid">
                <input type="hidden" name="action" value="<?= $editRow ? 'edit' : 'add' ?>">
                <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>"><?php endif; ?>
                <div class="faculty-input-group"><label>Faculty ID</label><input type="text" name="faculty_id" required maxlength="20" value="<?= htmlspecialchars((string) ($editRow['faculty_id'] ?? '')) ?>"></div>
                <div class="faculty-input-group"><label>Username</label><input type="text" name="username" maxlength="50" <?= $editRow ? 'readonly' : 'required' ?> value="<?= htmlspecialchars((string) ($editRow['username'] ?? '')) ?>"></div>
                <div class="faculty-input-group"><label><?= $editRow ? 'Reset Password (optional)' : 'Password' ?></label><input type="password" name="<?= $editRow ? 'reset_password' : 'password' ?>" <?= $editRow ? '' : 'required' ?> autocomplete="new-password"></div>
                <div class="faculty-input-group"><label>Full Name</label><input type="text" name="full_name" required maxlength="100" value="<?= htmlspecialchars((string) ($editRow['full_name'] ?? '')) ?>"></div>

                <?php if ($programScope !== null): ?>
                    <div class="faculty-input-group">
                        <label>Program</label>
                        <input type="text" value="<?= htmlspecialchars($programScope) ?>" readonly>
                        <input type="hidden" name="department" value="<?= htmlspecialchars($programScope) ?>">
                    </div>
                <?php else: ?>
                    <?php
                    $currentDept = trim((string) ($editRow['department'] ?? ''));
                    $showProgramDropdown = $hasProgramsTable && $deanProgramOptions !== [];
                    $legacyProgram = $showProgramDropdown && $currentDept !== '' && !in_array($currentDept, $deanProgramOptions, true);
                    ?>
                    <div class="faculty-input-group">
                        <label>Program</label>
                        <?php if ($showProgramDropdown): ?>
                            <select name="department" required<?= app_tooltip_attr('Active programs registered for your college under Programs.') ?>>
                                <option value="" disabled<?= $currentDept === '' ? ' selected' : '' ?>>Select program...</option>
                                <?php foreach ($deanProgramOptions as $pn): ?>
                                    <option value="<?= htmlspecialchars($pn) ?>"<?= $currentDept === $pn ? ' selected' : '' ?>><?= htmlspecialchars($pn) ?></option>
                                <?php endforeach; ?>
                                <?php if ($legacyProgram): ?>
                                    <option value="<?= htmlspecialchars($currentDept) ?>" selected><?= htmlspecialchars($currentDept) ?> (current - not in active list)</option>
                                <?php endif; ?>
                            </select>
                        <?php else: ?>
                            <input type="text" name="department" maxlength="100" placeholder="e.g. BS Computer Science" value="<?= htmlspecialchars($currentDept) ?>"<?= $hasProgramsTable ? '' : ' required' ?>>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="faculty-input-group"><label>Email</label><input type="email" name="email" maxlength="100" value="<?= htmlspecialchars((string) ($editRow['email'] ?? '')) ?>"></div>
                <div class="faculty-row-flex">
                    <div class="faculty-input-group" style="flex:1; min-width: 220px;"><label>Max Hrs/Day</label><input type="number" name="max_hours_per_day" min="1" max="36" value="<?= (int) ($editRow['max_hours_per_day'] ?? 8) ?>"></div>
                    <div class="faculty-input-group" style="flex:1; min-width: 220px;">
                        <label>Employment Status</label>
                        <?php $selectedEmploymentStatus = (string) ($editRow['employment_status'] ?? 'Permanent'); ?>
                        <select name="employment_status">
                            <option value="Permanent" <?= $selectedEmploymentStatus === 'Permanent' ? 'selected' : '' ?>>Permanent</option>
                            <option value="Contract of Service" <?= $selectedEmploymentStatus === 'Contract of Service' ? 'selected' : '' ?>>Contract of Service</option>
                            <option value="Temporary" <?= $selectedEmploymentStatus === 'Temporary' ? 'selected' : '' ?>>Temporary</option>
                        </select>
                    </div>
                </div>
                <div class="faculty-radio-group">
                    <label><strong>Status:</strong></label>
                    <label><input type="radio" name="status" value="active" <?= ($editRow['status'] ?? 'active') === 'active' ? 'checked' : '' ?>> Active</label>
                    <label><input type="radio" name="status" value="inactive" <?= ($editRow['status'] ?? '') === 'inactive' ? 'checked' : '' ?>> Inactive</label>
                </div>
                <div style="display:flex; justify-content:flex-end; gap: 10px; grid-column: 1 / -1;">
                    <?php if ($editRow): ?><a href="faculty.php" class="btn btn-outline-secondary faculty-cancel-link">Cancel</a><?php endif; ?>
                    <button type="submit" class="faculty-save-btn"<?= app_tooltip_attr($editRow ? 'Saves changes to this faculty record. Use this after updating name, ID, or status.' : 'Adds the faculty member to your college roster. Use this before assigning courses or accounts.') ?>><i class="fa-solid <?= $editRow ? 'fa-pen' : 'fa-save' ?>"></i> <?= $editRow ? 'Update Faculty' : 'Save Faculty' ?></button>
                </div>
            </form>
        </div>

        <div class="faculty-card">
            <div class="faculty-card-header">
                <h2><i class="fa-solid fa-list-ul"></i> Faculty roster</h2>
                <span class="faculty-badge-manage"><i class="fa-solid fa-pen me-1"></i>Manage faculty records</span>
            </div>
            <div class="faculty-table-wrapper">
                <table class="faculty-table">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Program</th>
                        <?php if ($hasEmploymentStatusColumn): ?><th>Employment Status</th><?php endif; ?>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Maxhrs/day</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($list as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $r['faculty_id']) ?></td>
                            <td><?= htmlspecialchars((string) $r['full_name']) ?></td>
                            <td><?= htmlspecialchars((string) ($r['department'] ?? '')) ?></td>
                            <?php if ($hasEmploymentStatusColumn): ?><td><?= htmlspecialchars((string) ($r['employment_status'] ?? 'Permanent')) ?></td><?php endif; ?>
                            <td><?= htmlspecialchars((string) ($r['username'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($r['email'] ?? '')) ?></td>
                            <td><?= (int) $r['max_hours_per_day'] ?></td>
                            <td>
                                <span class="faculty-status-badge <?= (($r['status'] ?? '') === 'active') ? 'faculty-status-active' : 'faculty-status-inactive' ?>">
                                    <?= htmlspecialchars((string) $r['status']) ?>
                                </span>
                            </td>
                            <td class="faculty-action-buttons">
                                <a href="faculty.php?edit=<?= (int) $r['id'] ?>" class="faculty-icon-btn" title="Edit faculty"><i class="fa-solid fa-pen"></i></a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this faculty member? Their schedules for this college will be removed too.');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button type="submit" class="faculty-icon-btn delete" title="Delete faculty"><i class="fa-solid fa-trash-can"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($list === []): ?>
                        <tr><td colspan="<?= $hasEmploymentStatusColumn ? '9' : '8' ?>" class="text-center text-muted py-4">No faculty yet. Add one above.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="faculty-footer-note">
        <i class="fa-solid fa-database"></i> Faculty directory · Real-time management | Western Philippines University
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

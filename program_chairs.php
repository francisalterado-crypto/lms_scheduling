<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mail_helpers.php';
require_once __DIR__ . '/includes/admin_activity_log.php';

require_role(['dean']);
$collegeId = dean_college_id_or_fail();
$collegeName = college_name_by_id($collegeId);
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$hasUserEmail = db_column_exists('users', 'email');
$hasAssignedProgram = db_column_exists('users', 'assigned_program');
$hasProgramsTable = db_table_exists('programs');

$programOptions = [];
if ($hasProgramsTable) {
    $st = db()->prepare("SELECT program_name FROM programs WHERE college_id=? AND status='active' ORDER BY program_name");
    $st->execute([$collegeId]);
    $programOptions = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if (!$hasAssignedProgram) {
            throw new RuntimeException('Run upgrade_roles.php first to enable Program Chair accounts.');
        }
        if (!$hasProgramsTable) {
            throw new RuntimeException('Programs table is missing. Run upgrade_roles.php first.');
        }

        if ($action === 'add') {
            $username = trim((string) ($_POST['username'] ?? ''));
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $assignedProgram = trim((string) ($_POST['assigned_program'] ?? ''));
            $email = $hasUserEmail ? trim((string) ($_POST['email'] ?? '')) : '';
            $sendByEmail = !empty($_POST['send_credentials_email']);
            $password = (string) ($_POST['password'] ?? '');
            $plainForMail = '';

            if ($username === '' || $fullName === '' || $assignedProgram === '') {
                throw new RuntimeException('Username, full name, and program are required.');
            }
            if (!in_array($assignedProgram, $programOptions, true)) {
                throw new RuntimeException('Please select a valid program from Programs.');
            }
            if ($hasUserEmail && $sendByEmail) {
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('A valid email address is required to send the temporary password.');
                }
                $plainForMail = generate_temp_password();
                $password = $plainForMail;
            } elseif ($password === '') {
                throw new RuntimeException('Password is required, or enable sending credentials by email.');
            }

            $exists = db()->prepare('SELECT COUNT(*) FROM users WHERE username=?');
            $exists->execute([$username]);
            if ((int) $exists->fetchColumn() > 0) {
                $s = suggest_available_usernames($username, 3);
                throw new RuntimeException('Username already exists.' . ($s ? ' Try: ' . implode(', ', $s) : ''));
            }

            $sql = $hasUserEmail
                ? 'INSERT INTO users (username,password,full_name,email,role,assigned_program,college_id,is_active) VALUES (?,?,?,?,?,?,?,?)'
                : 'INSERT INTO users (username,password,full_name,role,assigned_program,college_id,is_active) VALUES (?,?,?,?,?,?,?)';
            $params = $hasUserEmail
                ? [$username, password_hash($password, PASSWORD_DEFAULT), $fullName, $email, 'program_chair', $assignedProgram, $collegeId, !empty($_POST['is_active']) ? 1 : 0]
                : [$username, password_hash($password, PASSWORD_DEFAULT), $fullName, 'program_chair', $assignedProgram, $collegeId, !empty($_POST['is_active']) ? 1 : 0];
            db()->prepare($sql)->execute($params);
            $newPcId = (int) db()->lastInsertId();
            $stPc = db()->prepare(
                'SELECT id, username, full_name, email, role, assigned_program, college_id, is_active FROM users WHERE id = ? LIMIT 1'
            );
            $stPc->execute([$newPcId]);
            $afterPc = $stPc->fetch(PDO::FETCH_ASSOC);
            if ($afterPc) {
                $afterPc['password'] = '[set at creation]';
            }
            log_user_activity('add', 'Program chairs', 'Program chair user #' . $newPcId, null, $afterPc ? (array) $afterPc : null);
            log_dean_activity('program_chair_create', 'Created program chair for ' . $assignedProgram);

            $mailOk = false;
            if ($hasUserEmail && $sendByEmail && $email !== '' && $plainForMail !== '') {
                $mailOk = send_account_credentials_mail($email, $fullName, $username, $plainForMail, 'program_chair');
            }
            if ($hasUserEmail && $sendByEmail && $email !== '' && $plainForMail !== '') {
                $_SESSION['flash'] = $mailOk
                    ? 'Program Chair account created. Temporary password sent to ' . $email . '.'
                    : 'Program Chair account created, but email could not be sent. Temporary password: ' . $plainForMail;
            } else {
                $_SESSION['flash'] = 'Program Chair account created.';
            }
        } elseif ($action === 'edit' && isset($_POST['id'])) {
            $id = (int) $_POST['id'];
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $assignedProgram = trim((string) ($_POST['assigned_program'] ?? ''));
            $email = $hasUserEmail ? trim((string) ($_POST['email'] ?? '')) : '';
            $resetPassword = trim((string) ($_POST['reset_password'] ?? ''));
            $generateAndEmail = !empty($_POST['generate_temp_password_email']);

            if ($fullName === '' || $assignedProgram === '') {
                throw new RuntimeException('Full name and program are required.');
            }
            if (!in_array($assignedProgram, $programOptions, true)) {
                throw new RuntimeException('Please select a valid program from Programs.');
            }
            if ($hasUserEmail && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Invalid email address.');
            }

            $st = db()->prepare(
                'SELECT id, username, full_name, email, role, assigned_program, college_id, is_active FROM users WHERE id=? AND role="program_chair" AND college_id=?'
            );
            $st->execute([$id, $collegeId]);
            $beforePc = $st->fetch(PDO::FETCH_ASSOC);
            $row = $beforePc ? ['username' => $beforePc['username'], 'full_name' => $beforePc['full_name']] : false;
            if (!$row) {
                throw new RuntimeException('Program Chair account not found.');
            }

            $sql = $hasUserEmail
                ? 'UPDATE users SET full_name=?, email=?, assigned_program=?, is_active=? WHERE id=? AND role="program_chair" AND college_id=?'
                : 'UPDATE users SET full_name=?, assigned_program=?, is_active=? WHERE id=? AND role="program_chair" AND college_id=?';
            $params = $hasUserEmail
                ? [$fullName, $email, $assignedProgram, !empty($_POST['is_active']) ? 1 : 0, $id, $collegeId]
                : [$fullName, $assignedProgram, !empty($_POST['is_active']) ? 1 : 0, $id, $collegeId];
            db()->prepare($sql)->execute($params);

            $plainForMail = '';
            if ($generateAndEmail) {
                if (!$hasUserEmail || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('A valid email is required to generate and send a temporary password.');
                }
                $plainForMail = generate_temp_password();
                db()->prepare('UPDATE users SET password=? WHERE id=?')->execute([
                    password_hash($plainForMail, PASSWORD_DEFAULT),
                    $id,
                ]);
            } elseif ($resetPassword !== '') {
                db()->prepare('UPDATE users SET password=? WHERE id=?')->execute([
                    password_hash($resetPassword, PASSWORD_DEFAULT),
                    $id,
                ]);
                if (!empty($_POST['email_reset_password']) && $hasUserEmail && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $plainForMail = $resetPassword;
                }
            }

            if ($plainForMail !== '') {
                $mailOk = send_account_credentials_mail($email, $fullName, (string) $row['username'], $plainForMail, 'program_chair');
                $_SESSION['flash'] = $mailOk
                    ? 'Program Chair updated. Password instructions sent to ' . $email . '.'
                    : 'Program Chair updated, but email could not be sent. Temporary password: ' . $plainForMail;
            } else {
                $_SESSION['flash'] = 'Program Chair updated.';
            }
            $stApc = db()->prepare(
                'SELECT id, username, full_name, email, role, assigned_program, college_id, is_active FROM users WHERE id=? AND role="program_chair" AND college_id=? LIMIT 1'
            );
            $stApc->execute([$id, $collegeId]);
            $afterPc = $stApc->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($afterPc !== [] && ($generateAndEmail || $resetPassword !== '')) {
                $afterPc['password'] = '[changed]';
            }
            log_user_activity(
                'edit',
                'Program chairs',
                'Program chair user #' . $id,
                $beforePc ? (array) $beforePc : null,
                $afterPc !== [] ? $afterPc : null
            );
            log_dean_activity('program_chair_update', 'Updated program chair #' . $id);
        } elseif ($action === 'delete' && isset($_POST['id'])) {
            $id = (int) $_POST['id'];
            $stBpc = db()->prepare(
                'SELECT id, username, full_name, email, role, assigned_program, college_id, is_active FROM users WHERE id=? AND role="program_chair" AND college_id=? LIMIT 1'
            );
            $stBpc->execute([$id, $collegeId]);
            $beforeDelPc = $stBpc->fetch(PDO::FETCH_ASSOC);
            $st = db()->prepare('DELETE FROM users WHERE id=? AND role="program_chair" AND college_id=?');
            $st->execute([$id, $collegeId]);
            if ($st->rowCount() < 1) {
                throw new RuntimeException('Program Chair account not found.');
            }
            log_user_activity('delete', 'Program chairs', 'Program chair user #' . $id, $beforeDelPc ? (array) $beforeDelPc : null, null);
            log_dean_activity('program_chair_delete', 'Deleted program chair #' . $id);
            $_SESSION['flash'] = 'Program Chair account deleted.';
        }
    } catch (Throwable $e) {
        $_SESSION['flash'] = 'Error: ' . $e->getMessage();
    }
    header('Location: program_chairs.php');
    exit;
}

$editRow = null;
if (isset($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM users WHERE id=? AND role="program_chair" AND college_id=?');
    $st->execute([(int) $_GET['edit'], $collegeId]);
    $editRow = $st->fetch() ?: null;
}

$chairs = [];
if ($hasAssignedProgram) {
    $st = db()->prepare(
        'SELECT id, username, full_name, email, assigned_program, is_active
         FROM users
         WHERE role="program_chair" AND college_id=?
         ORDER BY assigned_program, full_name'
    );
    $st->execute([$collegeId]);
    $chairs = $st->fetchAll();
}

$currentUser = current_user() ?? [];
$deanName = trim((string) ($currentUser['full_name'] ?? 'College Administrator'));
$deanInitials = '';
foreach (preg_split('/\s+/', $deanName) ?: [] as $part) {
    $token = trim((string) $part, " ,.\t\n\r\0\x0B");
    if ($token !== '') {
        $deanInitials .= strtoupper(substr($token, 0, 1));
    }
}
$deanInitials = $deanInitials !== '' ? substr($deanInitials, 0, 2) : 'PC';
$totalProgramChairs = count($chairs);
$activeProgramChairs = 0;
foreach ($chairs as $chairRow) {
    if ((int) ($chairRow['is_active'] ?? 0) === 1) {
        $activeProgramChairs++;
    }
}
$programCount = count($programOptions);

$pageTitle = 'Program Chairs';
require_once __DIR__ . '/includes/header.php';
?>
<style>
  .pc-dashboard-container { max-width: 1440px; margin: 0 auto; }
  .pc-university-header { display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; margin-bottom:28px; border-bottom:2px solid rgba(30,64,95,.2); padding-bottom:16px; gap:12px; }
  .pc-title-section h1 { font-size:1.9rem; font-weight:700; background:linear-gradient(135deg,#1e405f,#2a6f8f); -webkit-background-clip:text; background-clip:text; color:transparent; letter-spacing:-.3px; }
  .pc-title-section p { font-size:.9rem; color:#4a627a; margin-top:6px; font-weight:500; }
  .pc-dean-card { background:#fff; padding:10px 24px; border-radius:60px; box-shadow:0 4px 10px rgba(0,0,0,.02),0 1px 2px rgba(0,0,0,.05); display:flex; align-items:center; gap:16px; border:1px solid #e2edf2; }
  .pc-dean-avatar { background:#1e5a6f; width:44px; height:44px; border-radius:50%; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:600; font-size:1.2rem; }
  .pc-dean-info h4 { font-weight:600; font-size:.9rem; color:#2c4c6e; margin:0; }
  .pc-dean-info p { font-size:.75rem; color:#4c6a82; font-weight:500; margin:0; }
  .pc-stats-row { display:flex; gap:20px; margin-bottom:32px; flex-wrap:wrap; }
  .pc-stat-card { background:#fff; border-radius:24px; padding:14px 28px; box-shadow:0 2px 6px rgba(0,0,0,.04); display:flex; align-items:center; gap:14px; border:1px solid #eef3f8; flex:1 1 auto; min-width:200px; }
  .pc-stat-icon { background:#eaf4fc; width:48px; height:48px; border-radius:32px; display:flex; align-items:center; justify-content:center; color:#1f6e8c; font-size:1.4rem; }
  .pc-stat-info h3 { font-size:1.6rem; font-weight:700; color:#1e405f; margin:0; }
  .pc-stat-info span { font-size:.75rem; color:#5e7f9a; font-weight:500; }
  .pc-layout { display:flex; flex-direction:column; gap:32px; }
  .pc-card { background:#fff; border-radius:28px; box-shadow:0 12px 28px rgba(0,0,0,.05),0 0 0 1px rgba(0,0,0,.02); overflow:hidden; }
  .pc-card-header { padding:20px 28px 12px; border-bottom:1px solid #edf2f7; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; }
  .pc-card-header h2 { font-size:1.35rem; font-weight:600; color:#1f3b4c; display:flex; align-items:center; gap:10px; margin:0; }
  .pc-badge-manage { background:#eef2ff; padding:6px 14px; border-radius:30px; font-size:.7rem; font-weight:600; color:#2c5f8a; }
  .pc-form-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:20px 28px; padding:28px; }
  .pc-input-group { display:flex; flex-direction:column; gap:8px; }
  .pc-input-group label { font-size:.75rem; font-weight:600; text-transform:uppercase; letter-spacing:.5px; color:#4a6b85; }
  .pc-input-group input, .pc-input-group select { padding:12px 14px; border-radius:16px; border:1.5px solid #e2edf2; background:#fefefe; font-size:.9rem; transition:all .2s; }
  .pc-input-group input:focus, .pc-input-group select:focus { outline:none; border-color:#2c8cac; box-shadow:0 0 0 3px rgba(44,140,172,.2); }
  .pc-checkbox-group { display:flex; align-items:center; gap:8px; color:#2a445b; margin-top:8px; font-size:.85rem; }
  .pc-checkbox-group input[type="checkbox"] { width:16px; height:16px; accent-color:#1f6e8c; }
  .pc-save-btn { background:#1f6e8c; border:none; padding:12px 24px; border-radius:40px; font-weight:600; color:#fff; font-size:.9rem; display:inline-flex; align-items:center; gap:12px; cursor:pointer; transition:.2s; }
  .pc-save-btn:hover { background:#0e556f; transform:translateY(-1px); box-shadow:0 6px 12px rgba(0,0,0,.05); }
  .pc-cancel-link { border-radius:40px; padding:10px 20px; }
  .pc-table-wrapper { overflow-x:auto; padding:0 0 8px; }
  .pc-table { width:100%; border-collapse:collapse; font-size:.85rem; }
  .pc-table th { text-align:left; padding:18px 16px; background:#f9fbfd; font-weight:600; color:#2c4c6e; border-bottom:1px solid #e6edf2; }
  .pc-table td { padding:14px 16px; border-bottom:1px solid #eff3f8; vertical-align:middle; color:#2a445b; }
  .pc-table tr:hover td { background:#fafcff; }
  .pc-status-badge { display:inline-block; padding:4px 12px; border-radius:50px; font-size:.7rem; font-weight:600; text-transform:capitalize; }
  .pc-status-active { background:#e0f2e9; color:#1f7840; }
  .pc-status-inactive { background:#ffe6e5; color:#b33; }
  .pc-action-buttons { display:flex; gap:8px; align-items:center; }
  .pc-icon-btn { border:none; border-radius:30px; background:transparent; color:#2c7da0; padding:6px 9px; }
  .pc-icon-btn.delete { color:#bc6c6c; }
  .pc-icon-btn:hover { background:#eef2ff; color:#0f5c7c; }
  .pc-icon-btn.delete:hover { background:#fff0f0; color:#b13e3e; }
  .pc-footer-note { margin-top:32px; text-align:center; font-size:.7rem; color:#6b89a0; border-top:1px solid #e0eaf0; padding-top:24px; }
  html[data-bs-theme="dark"] .pc-title-section h1 { background:linear-gradient(135deg,#c5e6ff,#79c5e8); -webkit-background-clip:text; background-clip:text; color:transparent; }
  html[data-bs-theme="dark"] .pc-title-section p { color:#9db6c8; }
  html[data-bs-theme="dark"] .pc-dean-card,
  html[data-bs-theme="dark"] .pc-stat-card,
  html[data-bs-theme="dark"] .pc-card { background:#16202a; border-color:#2a3947; box-shadow:0 10px 24px rgba(0,0,0,.35); }
  html[data-bs-theme="dark"] .pc-dean-info h4,
  html[data-bs-theme="dark"] .pc-card-header h2,
  html[data-bs-theme="dark"] .pc-table th { color:#d3e7f6; }
  html[data-bs-theme="dark"] .pc-dean-info p,
  html[data-bs-theme="dark"] .pc-stat-info span,
  html[data-bs-theme="dark"] .pc-input-group label,
  html[data-bs-theme="dark"] .pc-footer-note { color:#9ab0c2; }
  html[data-bs-theme="dark"] .pc-stat-icon { background:#203346; color:#87c8ea; }
  html[data-bs-theme="dark"] .pc-stat-info h3 { color:#e3f3ff; }
  html[data-bs-theme="dark"] .pc-card-header { border-bottom-color:#2b3a49; }
  html[data-bs-theme="dark"] .pc-input-group input,
  html[data-bs-theme="dark"] .pc-input-group select { background:#0f1821; border-color:#2e4152; color:#deebf5; }
  html[data-bs-theme="dark"] .pc-input-group input:focus,
  html[data-bs-theme="dark"] .pc-input-group select:focus { border-color:#4ea4c6; box-shadow:0 0 0 3px rgba(78,164,198,.28); }
  html[data-bs-theme="dark"] .pc-checkbox-group { color:#c5d9e7; }
  html[data-bs-theme="dark"] .pc-badge-manage { background:#223243; color:#a9d2ea; }
  html[data-bs-theme="dark"] .pc-table th { background:#1b2834; border-bottom-color:#30414f; }
  html[data-bs-theme="dark"] .pc-table td { color:#c8dced; border-bottom-color:#2b3a47; }
  html[data-bs-theme="dark"] .pc-table tr:hover td { background:#1a2530; }
  html[data-bs-theme="dark"] .pc-status-active { background:#1d3a2b; color:#8fddb1; }
  html[data-bs-theme="dark"] .pc-status-inactive { background:#45262a; color:#f4aab1; }
  html[data-bs-theme="dark"] .pc-icon-btn { color:#90c7e5; }
  html[data-bs-theme="dark"] .pc-icon-btn:hover { background:#233344; color:#c4e6f9; }
  html[data-bs-theme="dark"] .pc-icon-btn.delete { color:#e09a9a; }
  html[data-bs-theme="dark"] .pc-icon-btn.delete:hover { background:#3f262b; color:#ffc4c4; }
  html[data-bs-theme="dark"] .pc-footer-note { border-top-color:#2a3a49; }
  @media (max-width: 700px) {
    .pc-card-header { flex-direction:column; align-items:flex-start; }
    .pc-stat-card { padding:10px 20px; }
  }
</style>

<div class="pc-dashboard-container">
    <div class="pc-university-header">
        <div class="pc-title-section">
            <h1><i class="fa-solid fa-user-tie" style="color:#2c7da0; margin-right:8px;"></i>Program Chairs</h1>
            <p><?= htmlspecialchars($collegeName) ?> · Western Philippines University</p>
        </div>
        <div class="pc-dean-card">
            <div class="pc-dean-avatar"><?= htmlspecialchars($deanInitials) ?></div>
            <div class="pc-dean-info">
                <h4><?= htmlspecialchars(strtoupper($deanName)) ?></h4>
                <p>Dean, <?= htmlspecialchars($collegeName) ?> <i class="fa-solid fa-circle-check" style="font-size:.7rem; color:#1f8a4c;"></i></p>
            </div>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-info alert-dismissible fade show no-print">
            <?= htmlspecialchars($flash) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!$hasAssignedProgram || !$hasProgramsTable): ?>
        <div class="alert alert-warning">Program Chair accounts require the upgraded schema. Run <a href="upgrade_roles.php">upgrade_roles.php</a> first.</div>
    <?php else: ?>
    <div class="pc-stats-row">
        <div class="pc-stat-card">
            <div class="pc-stat-icon"><i class="fa-solid fa-user-tie"></i></div>
            <div class="pc-stat-info"><h3><?= $totalProgramChairs ?></h3><span>Total Program Chairs</span></div>
        </div>
        <div class="pc-stat-card">
            <div class="pc-stat-icon"><i class="fa-solid fa-user-check"></i></div>
            <div class="pc-stat-info"><h3><?= $activeProgramChairs ?></h3><span>Active Chairs</span></div>
        </div>
        <div class="pc-stat-card">
            <div class="pc-stat-icon"><i class="fa-solid fa-layer-group"></i></div>
            <div class="pc-stat-info"><h3><?= $programCount ?></h3><span>Active Programs</span></div>
        </div>
    </div>

    <div class="pc-layout">
        <div class="pc-card">
            <div class="pc-card-header">
                <h2><i class="fa-solid fa-user-plus"></i> <?= $editRow ? 'Edit Program Chair' : 'Add Program Chair' ?></h2>
                <span class="pc-badge-manage"><i class="fa-solid fa-pen me-1"></i>Manage chair records</span>
            </div>
            <form method="post" class="pc-form-grid">
                <input type="hidden" name="action" value="<?= $editRow ? 'edit' : 'add' ?>">
                <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>"><?php endif; ?>

                <div class="pc-input-group"><label>Username</label><input name="username" <?= $editRow ? 'readonly' : 'required' ?> value="<?= htmlspecialchars((string) ($editRow['username'] ?? '')) ?>"></div>
                <div class="pc-input-group"><label>Full Name</label><input name="full_name" required value="<?= htmlspecialchars((string) ($editRow['full_name'] ?? '')) ?>"></div>
                <div class="pc-input-group">
                    <label>Assigned Program</label>
                    <select name="assigned_program" required>
                        <option value="">Select program...</option>
                        <?php foreach ($programOptions as $programName): ?>
                            <option value="<?= htmlspecialchars($programName) ?>" <?= ((string) ($editRow['assigned_program'] ?? '')) === $programName ? 'selected' : '' ?>><?= htmlspecialchars($programName) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($hasUserEmail): ?>
                    <div class="pc-input-group"><label>Email</label><input type="email" name="email" value="<?= htmlspecialchars((string) ($editRow['email'] ?? '')) ?>"></div>
                <?php endif; ?>

                <?php if (!$editRow): ?>
                    <div class="pc-input-group">
                        <label>Password</label>
                        <input name="password" id="pcPassword" autocomplete="new-password" placeholder="Required if not emailing">
                        <?php if ($hasUserEmail): ?>
                            <label class="pc-checkbox-group">
                                <input type="checkbox" name="send_credentials_email" id="pc_send_credentials_email" value="1" checked>
                                <span>Generate temporary password and email it</span>
                            </label>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="pc-input-group">
                        <label>Reset Password (optional)</label>
                        <input name="reset_password" autocomplete="new-password">
                        <?php if ($hasUserEmail): ?>
                            <label class="pc-checkbox-group">
                                <input type="checkbox" name="email_reset_password" id="pc_email_reset_password" value="1">
                                <span>Email this new password</span>
                            </label>
                            <label class="pc-checkbox-group">
                                <input type="checkbox" name="generate_temp_password_email" id="pc_generate_temp_password_email" value="1">
                                <span>Generate temporary password and email it</span>
                            </label>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="pc-input-group">
                    <label>Status</label>
                    <label class="pc-checkbox-group">
                        <input type="checkbox" name="is_active" value="1" <?= !isset($editRow['is_active']) || (int) ($editRow['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                        <span>Active account</span>
                    </label>
                </div>

                <div style="display:flex; justify-content:flex-end; gap:10px; grid-column:1 / -1;">
                    <?php if ($editRow): ?><a class="btn btn-outline-secondary pc-cancel-link" href="program_chairs.php">Cancel</a><?php endif; ?>
                    <button class="pc-save-btn" type="submit"><i class="fa-solid <?= $editRow ? 'fa-pen' : 'fa-save' ?>"></i> <?= $editRow ? 'Update Program Chair' : 'Save Program Chair' ?></button>
                </div>
            </form>
            <?php if (!$editRow && $hasUserEmail): ?>
            <script>
                (function () {
                    const cb = document.getElementById('pc_send_credentials_email');
                    const pw = document.getElementById('pcPassword');
                    if (!cb || !pw) return;
                    function sync() {
                        pw.required = !cb.checked;
                        pw.disabled = cb.checked;
                        if (cb.checked) pw.value = '';
                    }
                    cb.addEventListener('change', sync);
                    sync();
                })();
            </script>
            <?php endif; ?>
        </div>

        <div class="pc-card">
            <div class="pc-card-header">
                <h2><i class="fa-solid fa-list-ul"></i> Program chair roster</h2>
                <span class="pc-badge-manage"><i class="fa-solid fa-users me-1"></i>Account directory</span>
            </div>
            <div class="pc-table-wrapper">
                <table class="pc-table">
                    <thead>
                    <tr>
                        <th>Username</th>
                        <th>Name</th>
                        <th>Program</th>
                        <?php if ($hasUserEmail): ?><th>Email</th><?php endif; ?>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($chairs as $chair): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $chair['username']) ?></td>
                            <td><?= htmlspecialchars((string) $chair['full_name']) ?></td>
                            <td><?= htmlspecialchars((string) $chair['assigned_program']) ?></td>
                            <?php if ($hasUserEmail): ?><td><?= htmlspecialchars((string) ($chair['email'] ?? '')) ?></td><?php endif; ?>
                            <td>
                                <span class="pc-status-badge <?= (int) $chair['is_active'] === 1 ? 'pc-status-active' : 'pc-status-inactive' ?>">
                                    <?= (int) $chair['is_active'] === 1 ? 'Active' : 'Disabled' ?>
                                </span>
                            </td>
                            <td class="pc-action-buttons">
                                <a href="program_chairs.php?edit=<?= (int) $chair['id'] ?>" class="pc-icon-btn" title="Edit Program Chair"><i class="fa-solid fa-pen"></i></a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this Program Chair account?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $chair['id'] ?>">
                                    <button type="submit" class="pc-icon-btn delete" title="Delete Program Chair"><i class="fa-solid fa-trash-can"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($chairs === []): ?>
                        <tr><td colspan="<?= $hasUserEmail ? '6' : '5' ?>" class="text-center text-muted py-4">No Program Chair accounts yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="pc-footer-note">
        <i class="fa-solid fa-database"></i> Program chair directory · Real-time management | Western Philippines University
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

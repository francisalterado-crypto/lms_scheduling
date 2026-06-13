<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mail_helpers.php';

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

            $st = db()->prepare('SELECT username, full_name FROM users WHERE id=? AND role="program_chair" AND college_id=?');
            $st->execute([$id, $collegeId]);
            $row = $st->fetch();
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
            log_dean_activity('program_chair_update', 'Updated program chair #' . $id);
        } elseif ($action === 'delete' && isset($_POST['id'])) {
            $id = (int) $_POST['id'];
            $st = db()->prepare('DELETE FROM users WHERE id=? AND role="program_chair" AND college_id=?');
            $st->execute([$id, $collegeId]);
            if ($st->rowCount() < 1) {
                throw new RuntimeException('Program Chair account not found.');
            }
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

$pageTitle = 'Program Chairs';
require_once __DIR__ . '/includes/header.php';
?>
<h1 class="h3 mb-4"><i class="fa-solid fa-user-tie me-2 text-primary"></i>Program Chairs</h1>
<p class="text-muted">Managing: <strong><?= htmlspecialchars($collegeName) ?></strong></p>
<?php if ($flash): ?><div class="alert alert-info"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
<?php if (!$hasAssignedProgram || !$hasProgramsTable): ?>
    <div class="alert alert-warning">Program Chair accounts require the upgraded schema. Run <a href="upgrade_roles.php">upgrade_roles.php</a> first.</div>
<?php else: ?>
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white"><strong><?= $editRow ? 'Edit Program Chair' : 'Add Program Chair' ?></strong></div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <input type="hidden" name="action" value="<?= $editRow ? 'edit' : 'add' ?>">
            <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>"><?php endif; ?>
            <div class="col-md-3"><label class="form-label">Username</label><input name="username" class="form-control" <?= $editRow ? 'readonly' : 'required' ?> value="<?= htmlspecialchars((string) ($editRow['username'] ?? '')) ?>"></div>
            <div class="col-md-3"><label class="form-label">Full Name</label><input name="full_name" class="form-control" required value="<?= htmlspecialchars((string) ($editRow['full_name'] ?? '')) ?>"></div>
            <div class="col-md-3">
                <label class="form-label">Assigned Program</label>
                <select name="assigned_program" class="form-select" required>
                    <option value="">— Select program —</option>
                    <?php foreach ($programOptions as $programName): ?>
                        <option value="<?= htmlspecialchars($programName) ?>" <?= ((string) ($editRow['assigned_program'] ?? '')) === $programName ? 'selected' : '' ?>><?= htmlspecialchars($programName) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($hasUserEmail): ?>
                <div class="col-md-3"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= htmlspecialchars((string) ($editRow['email'] ?? '')) ?>"></div>
            <?php endif; ?>
            <?php if (!$editRow): ?>
                <div class="col-md-3">
                    <label class="form-label">Password</label>
                    <input name="password" id="pcPassword" class="form-control" autocomplete="new-password" placeholder="If not emailing">
                    <?php if ($hasUserEmail): ?>
                        <div class="form-check mt-2">
                            <input type="checkbox" class="form-check-input" name="send_credentials_email" id="pc_send_credentials_email" value="1" checked>
                            <label class="form-check-label" for="pc_send_credentials_email">Generate temporary password and email it</label>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="col-md-3">
                    <label class="form-label">Reset password (optional)</label>
                    <input name="reset_password" class="form-control" autocomplete="new-password">
                    <?php if ($hasUserEmail): ?>
                        <div class="form-check mt-2">
                            <input type="checkbox" class="form-check-input" name="email_reset_password" id="pc_email_reset_password" value="1">
                            <label class="form-check-label" for="pc_email_reset_password">Email this new password</label>
                        </div>
                        <div class="form-check mt-1">
                            <input type="checkbox" class="form-check-input" name="generate_temp_password_email" id="pc_generate_temp_password_email" value="1">
                            <label class="form-check-label" for="pc_generate_temp_password_email">Generate temporary password and email it</label>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="col-md-2 d-flex align-items-end">
                <div class="form-check"><input type="checkbox" class="form-check-input" name="is_active" value="1" <?= !isset($editRow['is_active']) || (int) ($editRow['is_active'] ?? 1) === 1 ? 'checked' : '' ?>><label class="form-check-label">Active</label></div>
            </div>
            <div class="col-12">
                <button class="btn btn-primary" type="submit">Save</button>
                <?php if ($editRow): ?><a class="btn btn-outline-secondary" href="program_chairs.php">Cancel</a><?php endif; ?>
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
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table mb-0">
            <thead class="table-light"><tr><th>Username</th><th>Name</th><th>Program</th><?php if ($hasUserEmail): ?><th>Email</th><?php endif; ?><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($chairs as $chair): ?>
                <tr>
                    <td><?= htmlspecialchars((string) $chair['username']) ?></td>
                    <td><?= htmlspecialchars((string) $chair['full_name']) ?></td>
                    <td><?= htmlspecialchars((string) $chair['assigned_program']) ?></td>
                    <?php if ($hasUserEmail): ?><td><?= htmlspecialchars((string) ($chair['email'] ?? '')) ?></td><?php endif; ?>
                    <td><?= (int) $chair['is_active'] === 1 ? 'Active' : 'Disabled' ?></td>
                    <td class="text-nowrap">
                        <a class="btn btn-sm btn-outline-primary" href="program_chairs.php?edit=<?= (int) $chair['id'] ?>">Edit</a>
                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this Program Chair account?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $chair['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
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
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

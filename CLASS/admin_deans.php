<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mail_helpers.php';

require_role(['admin']);
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$hasUserEmail = db_column_exists('users', 'email');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'add') {
            $username = trim((string) $_POST['username']);
            $fullName = trim((string) $_POST['full_name']);
            $email = $hasUserEmail ? trim((string) ($_POST['email'] ?? '')) : '';
            $sendByEmail = !empty($_POST['send_credentials_email']);
            $password = (string) ($_POST['password'] ?? '');
            $plainForMail = '';

            if ($username === '' || $fullName === '') {
                throw new RuntimeException('Username and full name are required.');
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
                $msg = 'Username already exists.';
                if ($s) {
                    $msg .= ' Try: ' . implode(', ', $s);
                }
                throw new RuntimeException($msg);
            }

            if ($hasUserEmail) {
                db()->prepare('INSERT INTO users (username,password,full_name,email,role,college_id,is_active) VALUES (?,?,?,?,?,?,?)')
                    ->execute([
                        $username,
                        password_hash($password, PASSWORD_DEFAULT),
                        $fullName,
                        $email,
                        'dean',
                        ($_POST['college_id'] ?? '') !== '' ? (int) $_POST['college_id'] : null,
                        !empty($_POST['is_active']) ? 1 : 0,
                    ]);
            } else {
                db()->prepare('INSERT INTO users (username,password,full_name,role,college_id,is_active) VALUES (?,?,?,?,?,?)')
                    ->execute([
                        $username,
                        password_hash($password, PASSWORD_DEFAULT),
                        $fullName,
                        'dean',
                        ($_POST['college_id'] ?? '') !== '' ? (int) $_POST['college_id'] : null,
                        !empty($_POST['is_active']) ? 1 : 0,
                    ]);
            }
            $newId = (int) db()->lastInsertId();
            if (!empty($_POST['college_id'])) {
                db()->prepare('UPDATE colleges SET dean_user_id=? WHERE id=?')->execute([$newId, (int) $_POST['college_id']]);
            }

            $mailOk = false;
            if ($hasUserEmail && $sendByEmail && $email !== '' && $plainForMail !== '') {
                $mailOk = send_dean_credentials_mail($email, $fullName, $username, $plainForMail);
            }
            if ($hasUserEmail && $sendByEmail && $email !== '' && $plainForMail !== '') {
                $_SESSION['flash'] = $mailOk
                    ? 'Dean account created. Temporary password sent to ' . $email . '.'
                    : 'Dean account created, but the email could not be sent. Check MAIL_* in config/config.php and your PHP mail setup. Temporary password: ' . $plainForMail;
            } else {
                $_SESSION['flash'] = 'Dean account created.';
            }
        } elseif ($action === 'edit') {
            $id = (int) $_POST['id'];
            $fullName = trim((string) $_POST['full_name']);
            $email = $hasUserEmail ? trim((string) ($_POST['email'] ?? '')) : '';
            $resetPassword = trim((string) ($_POST['reset_password'] ?? ''));
            $generateAndEmail = !empty($_POST['generate_temp_password_email']);

            if ($hasUserEmail && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Invalid email address.');
            }

            $st = db()->prepare('SELECT username, full_name FROM users WHERE id=? AND role="dean"');
            $st->execute([$id]);
            $row = $st->fetch();
            if (!$row) {
                throw new RuntimeException('Dean not found.');
            }
            $uname = (string) $row['username'];
            $displayName = $fullName !== '' ? $fullName : (string) $row['full_name'];

            if ($hasUserEmail) {
                db()->prepare('UPDATE users SET full_name=?, email=?, college_id=?, is_active=? WHERE id=? AND role="dean"')
                    ->execute([
                        $fullName,
                        $email,
                        ($_POST['college_id'] ?? '') !== '' ? (int) $_POST['college_id'] : null,
                        !empty($_POST['is_active']) ? 1 : 0,
                        $id,
                    ]);
            } else {
                db()->prepare('UPDATE users SET full_name=?, college_id=?, is_active=? WHERE id=? AND role="dean"')
                    ->execute([
                        $fullName,
                        ($_POST['college_id'] ?? '') !== '' ? (int) $_POST['college_id'] : null,
                        !empty($_POST['is_active']) ? 1 : 0,
                        $id,
                    ]);
            }

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
                $mailOk = send_dean_credentials_mail($email, $displayName, $uname, $plainForMail);
                $_SESSION['flash'] = $mailOk
                    ? 'Dean updated. Password instructions sent to ' . $email . '.'
                    : 'Dean updated, but email could not be sent. Check mail configuration. Temporary password: ' . $plainForMail;
            } else {
                $_SESSION['flash'] = 'Dean account updated.';
            }
        } elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id < 1) {
                throw new RuntimeException('Invalid dean account.');
            }

            db()->beginTransaction();
            db()->prepare('UPDATE colleges SET dean_user_id=NULL WHERE dean_user_id=?')->execute([$id]);
            $st = db()->prepare('DELETE FROM users WHERE id=? AND role="dean"');
            $st->execute([$id]);
            if ($st->rowCount() < 1) {
                throw new RuntimeException('Dean not found.');
            }
            db()->commit();
            $_SESSION['flash'] = 'Dean account deleted.';
        }
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        if ($e instanceof PDOException && (string) $e->getCode() === '23000' && str_contains($e->getMessage(), 'username')) {
            $username = trim((string) ($_POST['username'] ?? 'user'));
            $s = suggest_available_usernames($username, 3);
            $_SESSION['flash'] = 'Error: Username already exists.'
                . ($s ? ' Try: ' . implode(', ', $s) : '');
        } else {
            $_SESSION['flash'] = 'Error: ' . $e->getMessage();
        }
    }
    header('Location: admin_deans.php');
    exit;
}

$edit = null;
if (isset($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM users WHERE id=? AND role="dean"');
    $st->execute([(int) $_GET['edit']]);
    $edit = $st->fetch() ?: null;
}

$colleges = db()->query('SELECT id, college_code, college_name FROM colleges ORDER BY college_code')->fetchAll();
$deans = db()->query(
    'SELECT u.*, c.college_name
     FROM users u
     LEFT JOIN colleges c ON c.id = u.college_id
     WHERE u.role="dean"
     ORDER BY u.full_name'
)->fetchAll();

$pageTitle = 'Manage Deans';
require_once __DIR__ . '/includes/header.php';
?>
<h1 class="h3 mb-4"><i class="fa-solid fa-user-tie me-2 text-primary"></i>Manage Deans</h1>
<?php if ($flash): ?><div class="alert alert-info"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

<?php if (!$hasUserEmail): ?>
    <div class="alert alert-warning">The <code>users.email</code> column is missing. Run <a href="upgrade_roles.php">upgrade_roles.php</a> once to add it, then reload this page.</div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white"><strong><?= $edit ? 'Edit Dean' : 'Add Dean' ?></strong></div>
    <div class="card-body">
        <form method="post" class="row g-3" id="deanForm">
            <input type="hidden" name="action" value="<?= $edit ? 'edit' : 'add' ?>">
            <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>
            <div class="col-md-3"><label class="form-label">Username</label><input name="username" class="form-control" <?= $edit ? 'readonly' : 'required' ?> value="<?= htmlspecialchars((string) ($edit['username'] ?? '')) ?>"></div>
            <?php if ($hasUserEmail): ?>
            <div class="col-md-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" placeholder="dean@school.edu" value="<?= htmlspecialchars((string) ($edit['email'] ?? '')) ?>">
                <div class="form-text">Used to send temporary passwords.</div>
            </div>
            <?php endif; ?>
            <?php if (!$edit): ?>
            <div class="col-md-3">
                <label class="form-label">Password</label>
                <input name="password" id="deanPassword" class="form-control" autocomplete="new-password" placeholder="If not emailing">
                <div class="form-check mt-2">
                    <input type="checkbox" class="form-check-input" name="send_credentials_email" id="send_credentials_email" value="1" checked>
                    <label class="form-check-label" for="send_credentials_email">Generate temporary password and email it</label>
                </div>
            </div>
            <?php else: ?>
            <div class="col-md-3">
                <label class="form-label">Reset password (optional)</label>
                <input name="reset_password" id="deanResetPassword" class="form-control" autocomplete="new-password" placeholder="New password">
                <?php if ($hasUserEmail): ?>
                <div class="form-check mt-2">
                    <input type="checkbox" class="form-check-input" name="email_reset_password" id="email_reset_password" value="1">
                    <label class="form-check-label" for="email_reset_password">Email this new password to the dean</label>
                </div>
                <div class="form-check mt-1">
                    <input type="checkbox" class="form-check-input" name="generate_temp_password_email" id="generate_temp_password_email" value="1">
                    <label class="form-check-label" for="generate_temp_password_email">Generate temporary password and email it (ignores manual reset above)</label>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <div class="col-md-3"><label class="form-label">Full Name</label><input name="full_name" class="form-control" required value="<?= htmlspecialchars((string) ($edit['full_name'] ?? '')) ?>"></div>
            <div class="col-md-2">
                <label class="form-label">College</label>
                <select name="college_id" class="form-select">
                    <option value="">Unassigned</option>
                    <?php foreach ($colleges as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= (int) ($edit['college_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['college_code']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1 d-flex align-items-end">
                <div class="form-check"><input type="checkbox" class="form-check-input" name="is_active" value="1" <?= !isset($edit['is_active']) || (int) $edit['is_active'] === 1 ? 'checked' : '' ?>><label class="form-check-label">Active</label></div>
            </div>
            <div class="col-12">
                <button class="btn btn-primary" type="submit"<?= app_tooltip_attr($edit ? 'Saves changes to this dean account.' : 'Creates the dean user and links them to a college.') ?>>Save</button>
                <?php if ($edit): ?><a class="btn btn-outline-secondary" href="admin_deans.php"<?= app_tooltip_attr('Closes the editor without saving further changes.') ?>>Cancel</a><?php endif; ?>
            </div>
        </form>
        <?php if (!$edit && $hasUserEmail): ?>
        <script>
            (function () {
                const cb = document.getElementById('send_credentials_email');
                const pw = document.getElementById('deanPassword');
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
            <thead class="table-light"><tr><th>Username</th><th>Name</th><?php if ($hasUserEmail): ?><th>Email</th><?php endif; ?><th>College</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($deans as $d): ?>
                <tr>
                    <td><?= htmlspecialchars($d['username']) ?></td>
                    <td><?= htmlspecialchars($d['full_name']) ?></td>
                    <?php if ($hasUserEmail): ?><td><?= htmlspecialchars((string) ($d['email'] ?? '')) ?></td><?php endif; ?>
                    <td><?= htmlspecialchars((string) ($d['college_name'] ?? 'Unassigned')) ?></td>
                    <td><?= (int) $d['is_active'] === 1 ? 'Active' : 'Disabled' ?></td>
                    <td class="text-nowrap">
                        <a class="btn btn-sm btn-outline-primary" href="admin_deans.php?edit=<?= (int) $d['id'] ?>"<?= app_tooltip_attr('Edits this dean’s profile, college, or password options.') ?>>Edit</a>
                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this dean account?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"<?= app_tooltip_attr('Deactivates or removes this dean account after confirmation.') ?>>Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

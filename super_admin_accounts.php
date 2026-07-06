<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/admin_activity_log.php';
require_once __DIR__ . '/includes/account_registration_helpers.php';

require_role(['super_admin']);

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$hasUserEmail = db_column_exists('users', 'email');

function super_admin_active_count(): int
{
    return (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'super_admin' AND is_active = 1")->fetchColumn();
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;
if ($editId > 0) {
    $selCols = 'id, username, full_name, is_active';
    if ($hasUserEmail) {
        $selCols .= ', email';
    }
    $st = db()->prepare("SELECT {$selCols} FROM users WHERE id = ? AND role = 'super_admin' LIMIT 1");
    $st->execute([$editId]);
    $editRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$editRow) {
        $editId = 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'add') {
            $username = trim((string) ($_POST['username'] ?? ''));
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $email = $hasUserEmail ? trim((string) ($_POST['email'] ?? '')) : '';
            $hasValidEmail = $hasUserEmail && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL);
            $password = (string) ($_POST['password'] ?? '');
            $isActive = !empty($_POST['is_active']) ? 1 : 0;

            if ($username === '' || $fullName === '') {
                throw new RuntimeException('Username and full name are required.');
            }
            if ($hasUserEmail && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Invalid email address.');
            }

            $cred = prepare_new_account_password($password, $hasValidEmail);
            $plainForMail = $hasValidEmail ? $cred['plain'] : '';

            $exists = db()->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
            $exists->execute([$username]);
            if ((int) $exists->fetchColumn() > 0) {
                $s = suggest_available_usernames($username, 3);
                $msg = 'Username already exists.';
                if ($s) {
                    $msg .= ' Try: ' . implode(', ', $s);
                }
                throw new RuntimeException($msg);
            }

            $fields = ['username', 'password', 'full_name'];
            $params = [
                $username,
                $cred['hash'],
                $fullName,
            ];
            if ($hasUserEmail) {
                $fields[] = 'email';
                $params[] = $email;
            }
            $fields[] = 'role';
            $params[] = 'super_admin';
            $fields[] = 'is_active';
            $params[] = $isActive;

            $ph = implode(', ', array_fill(0, count($fields), '?'));
            $sql = 'INSERT INTO users (' . implode(', ', $fields) . ') VALUES (' . $ph . ')';
            db()->prepare($sql)->execute($params);
            $newId = (int) db()->lastInsertId();

            $selAfter = 'id, username, full_name, role, is_active';
            if ($hasUserEmail) {
                $selAfter .= ', email';
            }
            $stAfter = db()->prepare("SELECT {$selAfter} FROM users WHERE id = ? LIMIT 1");
            $stAfter->execute([$newId]);
            $afterRow = $stAfter->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($afterRow !== []) {
                $afterRow['password'] = '[set at creation]';
            }
            log_admin_activity('add', 'Super Administrator accounts', 'Super Admin user #' . $newId, null, $afterRow !== [] ? $afterRow : null);

            $mailOk = $hasValidEmail
                ? notify_new_account_credentials($newId, $email, $fullName, $username, $plainForMail, 'super_admin')
                : null;
            if (!$hasValidEmail) {
                mark_new_account_requires_password_change($newId);
            }
            $_SESSION['flash'] = registration_email_flash_message($mailOk, $email, 'Super Administrator account');
            header('Location: super_admin_accounts.php');
            exit;
        }

        if ($action === 'edit') {
            $id = (int) ($_POST['id'] ?? 0);
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $email = $hasUserEmail ? trim((string) ($_POST['email'] ?? '')) : '';
            $resetPassword = (string) ($_POST['reset_password'] ?? '');
            $isActive = !empty($_POST['is_active']) ? 1 : 0;

            if ($id < 1) {
                throw new RuntimeException('Invalid account.');
            }
            if ($fullName === '') {
                throw new RuntimeException('Full name is required.');
            }
            if ($hasUserEmail && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Invalid email address.');
            }
            if ($resetPassword !== '' && strlen($resetPassword) < 8) {
                throw new RuntimeException('New password must be at least 8 characters.');
            }

            $selCols = 'id, username, full_name, is_active';
            if ($hasUserEmail) {
                $selCols .= ', email';
            }
            $st = db()->prepare("SELECT {$selCols} FROM users WHERE id = ? AND role = 'super_admin' LIMIT 1");
            $st->execute([$id]);
            $before = $st->fetch(PDO::FETCH_ASSOC);
            if (!$before) {
                throw new RuntimeException('Super Administrator not found.');
            }

            if ($isActive === 0 && (int) ($before['is_active'] ?? 0) === 1 && super_admin_active_count() <= 1) {
                throw new RuntimeException('At least one active Super Administrator account is required.');
            }

            if ($resetPassword !== '') {
                if ($hasUserEmail) {
                    db()->prepare(
                        'UPDATE users SET full_name = ?, email = ?, password = ?, is_active = ? WHERE id = ? AND role = ?'
                    )->execute([
                        $fullName,
                        $email,
                        password_hash($resetPassword, PASSWORD_DEFAULT),
                        $isActive,
                        $id,
                        'super_admin',
                    ]);
                } else {
                    db()->prepare(
                        'UPDATE users SET full_name = ?, password = ?, is_active = ? WHERE id = ? AND role = ?'
                    )->execute([
                        $fullName,
                        password_hash($resetPassword, PASSWORD_DEFAULT),
                        $isActive,
                        $id,
                        'super_admin',
                    ]);
                }
            } elseif ($hasUserEmail) {
                db()->prepare(
                    'UPDATE users SET full_name = ?, email = ?, is_active = ? WHERE id = ? AND role = ?'
                )->execute([$fullName, $email, $isActive, $id, 'super_admin']);
            } else {
                db()->prepare(
                    'UPDATE users SET full_name = ?, is_active = ? WHERE id = ? AND role = ?'
                )->execute([$fullName, $isActive, $id, 'super_admin']);
            }

            $selAfter = 'id, username, full_name, role, is_active';
            if ($hasUserEmail) {
                $selAfter .= ', email';
            }
            $stAfter = db()->prepare("SELECT {$selAfter} FROM users WHERE id = ? LIMIT 1");
            $stAfter->execute([$id]);
            $afterRow = $stAfter->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($afterRow !== []) {
                $afterRow['password'] = $resetPassword !== '' ? '[changed]' : '[unchanged]';
            }
            log_admin_activity(
                'edit',
                'Super Administrator accounts',
                'Super Admin user #' . $id,
                $before ? (array) $before : null,
                $afterRow !== [] ? $afterRow : null
            );

            $_SESSION['flash'] = 'Super Administrator account updated.';
            header('Location: super_admin_accounts.php');
            exit;
        }

        throw new RuntimeException('Unknown action.');
    } catch (Throwable $e) {
        $_SESSION['flash'] = 'Error: ' . $e->getMessage();
        header('Location: super_admin_accounts.php' . ($editId > 0 ? '?edit=' . $editId : ''));
        exit;
    }
}

$listCols = 'id, username, full_name, is_active';
if ($hasUserEmail) {
    $listCols .= ', email';
}
$superAdmins = db()->query("SELECT {$listCols} FROM users WHERE role = 'super_admin' ORDER BY username")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$pageTitle = 'Super Administrator accounts';
require_once __DIR__ . '/includes/header.php';
?>
<h1 class="h3 mb-4"><i class="fa-solid fa-user-secret me-2 text-primary"></i>Super Administrator accounts</h1>
<?php if ($flash): ?><div class="alert alert-info"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

<p class="text-muted">Create or update <strong>super_admin</strong> logins for institution-wide oversight (administrator provisioning, faculty inventory, teaching load reports). Day-to-day scheduling uses separate <strong>admin</strong> accounts.</p>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header bg-white"><strong><?= $editRow ? 'Edit Super Administrator' : 'Add Super Administrator' ?></strong></div>
            <div class="card-body">
                <?php if ($editRow): ?>
                    <form method="post" class="row g-3">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
                        <div class="col-12">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars((string) $editRow['username']) ?>" disabled autocomplete="username">
                            <div class="form-text">Username cannot be changed here.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Full name</label>
                            <input type="text" name="full_name" class="form-control" required value="<?= htmlspecialchars((string) $editRow['full_name']) ?>" autocomplete="name">
                        </div>
                        <?php if ($hasUserEmail): ?>
                            <div class="col-12">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars((string) ($editRow['email'] ?? '')) ?>" autocomplete="email">
                            </div>
                        <?php endif; ?>
                        <div class="col-12">
                            <label class="form-label">New password</label>
                            <input type="password" name="reset_password" class="form-control" minlength="8" autocomplete="new-password">
                            <div class="form-text">Leave blank to keep the current password.</div>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active_edit" <?= (int) $editRow['is_active'] === 1 ? 'checked' : '' ?><?= super_admin_active_count() <= 1 && (int) $editRow['is_active'] === 1 ? ' disabled' : '' ?>>
                                <label class="form-check-label" for="is_active_edit">Account active</label>
                                <?php if (super_admin_active_count() <= 1 && (int) $editRow['is_active'] === 1): ?>
                                    <input type="hidden" name="is_active" value="1">
                                    <div class="form-text">The last active Super Administrator cannot be disabled.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-12 d-flex flex-wrap gap-2">
                            <button type="submit" class="btn btn-primary">Save changes</button>
                            <a class="btn btn-outline-secondary" href="super_admin_accounts.php">Cancel</a>
                        </div>
                    </form>
                <?php else: ?>
                    <form method="post" class="row g-3">
                        <input type="hidden" name="action" value="add">
                        <div class="col-12">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" class="form-control" required maxlength="50" autocomplete="username">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Full name</label>
                            <input type="text" name="full_name" class="form-control" required maxlength="100" autocomplete="name">
                        </div>
                        <?php if ($hasUserEmail): ?>
                            <div class="col-12">
                                <label class="form-label">Email <span class="text-muted">(optional)</span></label>
                                <input type="email" name="email" class="form-control" maxlength="190" autocomplete="email">
                            </div>
                        <?php endif; ?>
                        <div class="col-12">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control" required minlength="8" autocomplete="new-password">
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active_add" checked>
                                <label class="form-check-label" for="is_active_add">Account active</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">Create Super Administrator</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header bg-white"><strong>Existing Super Administrators</strong></div>
            <div class="card-body p-0">
                <?php if (!$superAdmins): ?>
                    <p class="text-muted p-3 mb-0">No Super Administrator accounts found. Run <a href="upgrade_roles.php">upgrade_roles.php</a> or create one using the form.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Username</th>
                                    <th>Full name</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($superAdmins as $sa): ?>
                                <tr>
                                    <td>
                                        <?= htmlspecialchars((string) $sa['username']) ?>
                                        <?php if ((int) $sa['id'] === $currentUserId): ?>
                                            <span class="badge bg-primary-subtle text-primary-emphasis border ms-1">You</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars((string) $sa['full_name']) ?></td>
                                    <td>
                                        <?php if ((int) $sa['is_active'] === 1): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Disabled</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-outline-primary" href="super_admin_accounts.php?edit=<?= (int) $sa['id'] ?>">Edit</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php';

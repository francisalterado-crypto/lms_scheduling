<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/admin_activity_log.php';

require_role(['super_admin']);

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$hasUserEmail = db_column_exists('users', 'email');
$hasAdminLogTitle = db_column_exists('users', 'admin_log_title');

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;
if ($editId > 0) {
    $selCols = 'id, username, full_name, is_active';
    if ($hasUserEmail) {
        $selCols .= ', email';
    }
    if ($hasAdminLogTitle) {
        $selCols .= ', admin_log_title';
    }
    $st = db()->prepare("SELECT {$selCols} FROM users WHERE id = ? AND role = 'admin' LIMIT 1");
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
            $adminLogTitle = $hasAdminLogTitle ? trim((string) ($_POST['admin_log_title'] ?? '')) : '';
            $password = (string) ($_POST['password'] ?? '');
            $isActive = !empty($_POST['is_active']) ? 1 : 0;

            if ($username === '' || $fullName === '') {
                throw new RuntimeException('Username and full name are required.');
            }
            if ($password === '' || strlen($password) < 8) {
                throw new RuntimeException('Password is required and must be at least 8 characters.');
            }
            if ($hasUserEmail && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Invalid email address.');
            }

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
                password_hash($password, PASSWORD_DEFAULT),
                $fullName,
            ];
            if ($hasUserEmail) {
                $fields[] = 'email';
                $params[] = $email;
            }
            if ($hasAdminLogTitle) {
                $fields[] = 'admin_log_title';
                $params[] = $adminLogTitle;
            }
            $fields[] = 'role';
            $params[] = 'admin';
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
            if ($hasAdminLogTitle) {
                $selAfter .= ', admin_log_title';
            }
            $stAfter = db()->prepare("SELECT {$selAfter} FROM users WHERE id = ? LIMIT 1");
            $stAfter->execute([$newId]);
            $afterRow = $stAfter->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($afterRow !== []) {
                $afterRow['password'] = '[set at creation]';
            }
            log_admin_activity('add', 'Administrator accounts', 'Admin user #' . $newId, null, $afterRow !== [] ? $afterRow : null);

            $_SESSION['flash'] = 'Administrator account created.';
            header('Location: super_admin_admins.php');
            exit;
        }

        if ($action === 'edit') {
            $id = (int) ($_POST['id'] ?? 0);
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $email = $hasUserEmail ? trim((string) ($_POST['email'] ?? '')) : '';
            $adminLogTitle = $hasAdminLogTitle ? trim((string) ($_POST['admin_log_title'] ?? '')) : '';
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
            if ($hasAdminLogTitle) {
                $selCols .= ', admin_log_title';
            }
            $st = db()->prepare("SELECT {$selCols} FROM users WHERE id = ? AND role = 'admin' LIMIT 1");
            $st->execute([$id]);
            $before = $st->fetch(PDO::FETCH_ASSOC);
            if (!$before) {
                throw new RuntimeException('Administrator not found.');
            }

            if ($resetPassword !== '') {
                if ($hasUserEmail && $hasAdminLogTitle) {
                    db()->prepare(
                        'UPDATE users SET full_name = ?, email = ?, admin_log_title = ?, password = ?, is_active = ? WHERE id = ? AND role = ?'
                    )->execute([
                        $fullName,
                        $email,
                        $adminLogTitle,
                        password_hash($resetPassword, PASSWORD_DEFAULT),
                        $isActive,
                        $id,
                        'admin',
                    ]);
                } elseif ($hasUserEmail) {
                    db()->prepare(
                        'UPDATE users SET full_name = ?, email = ?, password = ?, is_active = ? WHERE id = ? AND role = ?'
                    )->execute([
                        $fullName,
                        $email,
                        password_hash($resetPassword, PASSWORD_DEFAULT),
                        $isActive,
                        $id,
                        'admin',
                    ]);
                } elseif ($hasAdminLogTitle) {
                    db()->prepare(
                        'UPDATE users SET full_name = ?, admin_log_title = ?, password = ?, is_active = ? WHERE id = ? AND role = ?'
                    )->execute([
                        $fullName,
                        $adminLogTitle,
                        password_hash($resetPassword, PASSWORD_DEFAULT),
                        $isActive,
                        $id,
                        'admin',
                    ]);
                } else {
                    db()->prepare(
                        'UPDATE users SET full_name = ?, password = ?, is_active = ? WHERE id = ? AND role = ?'
                    )->execute([
                        $fullName,
                        password_hash($resetPassword, PASSWORD_DEFAULT),
                        $isActive,
                        $id,
                        'admin',
                    ]);
                }
            } else {
                if ($hasUserEmail && $hasAdminLogTitle) {
                    db()->prepare(
                        'UPDATE users SET full_name = ?, email = ?, admin_log_title = ?, is_active = ? WHERE id = ? AND role = ?'
                    )->execute([$fullName, $email, $adminLogTitle, $isActive, $id, 'admin']);
                } elseif ($hasUserEmail) {
                    db()->prepare(
                        'UPDATE users SET full_name = ?, email = ?, is_active = ? WHERE id = ? AND role = ?'
                    )->execute([$fullName, $email, $isActive, $id, 'admin']);
                } elseif ($hasAdminLogTitle) {
                    db()->prepare(
                        'UPDATE users SET full_name = ?, admin_log_title = ?, is_active = ? WHERE id = ? AND role = ?'
                    )->execute([$fullName, $adminLogTitle, $isActive, $id, 'admin']);
                } else {
                    db()->prepare(
                        'UPDATE users SET full_name = ?, is_active = ? WHERE id = ? AND role = ?'
                    )->execute([$fullName, $isActive, $id, 'admin']);
                }
            }

            $selAfter = 'id, username, full_name, role, is_active';
            if ($hasUserEmail) {
                $selAfter .= ', email';
            }
            if ($hasAdminLogTitle) {
                $selAfter .= ', admin_log_title';
            }
            $stAfter = db()->prepare("SELECT {$selAfter} FROM users WHERE id = ? LIMIT 1");
            $stAfter->execute([$id]);
            $afterRow = $stAfter->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($afterRow !== []) {
                $afterRow['password'] = $resetPassword !== '' ? '[changed]' : '[unchanged]';
            }
            log_admin_activity(
                'edit',
                'Administrator accounts',
                'Admin user #' . $id,
                $before ? (array) $before : null,
                $afterRow !== [] ? $afterRow : null
            );

            $_SESSION['flash'] = 'Administrator account updated.';
            header('Location: super_admin_admins.php');
            exit;
        }

        throw new RuntimeException('Unknown action.');
    } catch (Throwable $e) {
        $_SESSION['flash'] = 'Error: ' . $e->getMessage();
        header('Location: super_admin_admins.php' . ($editId > 0 ? '?edit=' . $editId : ''));
        exit;
    }
}

$listCols = 'id, username, full_name, is_active';
if ($hasUserEmail) {
    $listCols .= ', email';
}
if ($hasAdminLogTitle) {
    $listCols .= ', admin_log_title';
}
$admins = db()->query("SELECT {$listCols} FROM users WHERE role = 'admin' ORDER BY username")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$pageTitle = 'Administrator accounts';
require_once __DIR__ . '/includes/header.php';
?>
<h1 class="h3 mb-4"><i class="fa-solid fa-users-gear me-2 text-primary"></i>Administrator accounts</h1>
<?php if ($flash): ?><div class="alert alert-info"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

<p class="text-muted">Create or update <strong>admin</strong> logins for day-to-day scheduling. Use <strong>Log title / office</strong> so activity in Settings shows which administrator performed each action (shown with full name and username).</p>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header bg-white"><strong><?= $editRow ? 'Edit administrator' : 'Add administrator' ?></strong></div>
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
                        <?php if ($hasAdminLogTitle): ?>
                            <div class="col-12">
                                <label class="form-label">Log title / office</label>
                                <input type="text" name="admin_log_title" class="form-control" maxlength="120" value="<?= htmlspecialchars((string) ($editRow['admin_log_title'] ?? '')) ?>" placeholder="e.g. Registrar, ICT — Scheduling">
                                <div class="form-text">Shown in Settings → activity log next to this person’s name.</div>
                            </div>
                        <?php endif; ?>
                        <div class="col-12">
                            <label class="form-label">New password</label>
                            <input type="password" name="reset_password" class="form-control" minlength="8" autocomplete="new-password">
                            <div class="form-text">Leave blank to keep the current password.</div>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active_edit" <?= (int) $editRow['is_active'] === 1 ? 'checked' : '' ?>>
                                <label class="form-check-label" for="is_active_edit">Account active</label>
                            </div>
                        </div>
                        <div class="col-12 d-flex flex-wrap gap-2">
                            <button type="submit" class="btn btn-primary">Save changes</button>
                            <a class="btn btn-outline-secondary" href="super_admin_admins.php">Cancel</a>
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
                        <?php if ($hasAdminLogTitle): ?>
                            <div class="col-12">
                                <label class="form-label">Log title / office <span class="text-muted">(optional)</span></label>
                                <input type="text" name="admin_log_title" class="form-control" maxlength="120" placeholder="e.g. Registrar, ICT — Scheduling">
                                <div class="form-text">Helps identify this admin in the activity log.</div>
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
                            <button type="submit" class="btn btn-primary">Create administrator</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header bg-white"><strong>Existing administrators</strong></div>
            <div class="card-body p-0">
                <?php if (!$admins): ?>
                    <p class="text-muted p-3 mb-0">No administrator accounts yet. Create one using the form.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Username</th>
                                    <th>Full name</th>
                                    <?php if ($hasAdminLogTitle): ?><th>Log title</th><?php endif; ?>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($admins as $a): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) $a['username']) ?></td>
                                    <td><?= htmlspecialchars((string) $a['full_name']) ?></td>
                                    <?php if ($hasAdminLogTitle): ?>
                                        <td class="small text-muted"><?= htmlspecialchars(trim((string) ($a['admin_log_title'] ?? ''))) ?: '—' ?></td>
                                    <?php endif; ?>
                                    <td>
                                        <?php if ((int) $a['is_active'] === 1): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Disabled</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-outline-primary" href="super_admin_admins.php?edit=<?= (int) $a['id'] ?>">Edit</a>
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

<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mail_helpers.php';
require_once __DIR__ . '/includes/admin_activity_log.php';

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

            $stAfter = db()->prepare(
                'SELECT id, username, full_name, email, role, college_id, is_active FROM users WHERE id=? LIMIT 1'
            );
            $stAfter->execute([$newId]);
            $afterRow = $stAfter->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($afterRow !== []) {
                $afterRow['password'] = '[set at creation]';
            }
            log_admin_activity('add', 'Deans', 'Dean user #' . $newId, null, $afterRow !== [] ? $afterRow : null);

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

            $st = db()->prepare(
                'SELECT id, username, full_name, email, role, college_id, is_active FROM users WHERE id=? AND role="dean"'
            );
            $st->execute([$id]);
            $beforeDean = $st->fetch(PDO::FETCH_ASSOC);
            $row = $beforeDean ? ['username' => $beforeDean['username'], 'full_name' => $beforeDean['full_name']] : false;
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

            $stAfter = db()->prepare(
                'SELECT id, username, full_name, email, role, college_id, is_active FROM users WHERE id=? AND role="dean" LIMIT 1'
            );
            $stAfter->execute([$id]);
            $afterDean = $stAfter->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($afterDean !== []) {
                if ($generateAndEmail || $resetPassword !== '') {
                    $afterDean['password'] = '[changed]';
                }
            }
            log_admin_activity(
                'edit',
                'Deans',
                'Dean user #' . $id,
                $beforeDean ? (array) $beforeDean : null,
                $afterDean !== [] ? $afterDean : null
            );
        } elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id < 1) {
                throw new RuntimeException('Invalid dean account.');
            }

            $stDel = db()->prepare(
                'SELECT id, username, full_name, email, role, college_id, is_active FROM users WHERE id=? AND role="dean" LIMIT 1'
            );
            $stDel->execute([$id]);
            $beforeDelete = $stDel->fetch(PDO::FETCH_ASSOC);

            db()->beginTransaction();
            db()->prepare('UPDATE colleges SET dean_user_id=NULL WHERE dean_user_id=?')->execute([$id]);
            $st = db()->prepare('DELETE FROM users WHERE id=? AND role="dean"');
            $st->execute([$id]);
            if ($st->rowCount() < 1) {
                throw new RuntimeException('Dean not found.');
            }
            db()->commit();
            log_admin_activity('delete', 'Deans', 'Dean user #' . $id, $beforeDelete ? (array) $beforeDelete : null, null);
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
<style>
    .deans-dashboard {
        color: #0f172a;
    }

    .deans-top {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .deans-title h1 {
        margin: 0;
        font-size: 1.8rem;
        font-weight: 700;
    }

    .deans-title p {
        margin: 0.2rem 0 0;
        color: #475569;
        font-size: 0.92rem;
    }

    .deans-add-btn {
        border: 0;
        background: #1e462f;
        color: #fff;
        border-radius: 999px;
        padding: 0.65rem 1.15rem;
        font-weight: 600;
    }

    .deans-add-btn:hover {
        background: #163624;
    }

    .dean-form-card {
        border-radius: 22px;
        border: 1px solid #e6ebf2;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
        overflow: hidden;
    }

    .dean-form-header {
        border-bottom: 1px solid #edf2f7;
        background: #fbfdff;
        padding: 1rem 1.4rem;
        font-weight: 600;
    }

    .dean-form-body {
        padding: 1.4rem;
    }

    .dean-form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 1rem;
    }

    .dean-form-grid .form-label {
        font-size: 0.78rem;
        letter-spacing: 0.4px;
        text-transform: uppercase;
        color: #334155;
        font-weight: 700;
        margin-bottom: 0.45rem;
    }

    .dean-form-grid .form-control,
    .dean-form-grid .form-select {
        border-radius: 14px;
        border: 1px solid #cbd5e1;
        padding: 0.6rem 0.85rem;
    }

    .dean-form-grid .form-control:focus,
    .dean-form-grid .form-select:focus {
        border-color: #2c6e3c;
        box-shadow: 0 0 0 0.2rem rgba(44, 110, 60, 0.15);
    }

    .dean-form-actions {
        border-top: 1px solid #edf2f7;
        margin-top: 1.2rem;
        padding-top: 1rem;
        display: flex;
        justify-content: flex-end;
        gap: 0.75rem;
    }

    .deans-table-card {
        border-radius: 22px;
        border: 1px solid #e6ebf2;
        overflow: hidden;
        box-shadow: 0 4px 14px rgba(15, 23, 42, 0.03);
    }

    .deans-table {
        margin: 0;
    }

    .deans-table thead th {
        font-size: 0.82rem;
        letter-spacing: 0.2px;
        text-transform: uppercase;
        background: #f8fbff;
        color: #1e293b;
        border-bottom: 1px solid #e2e8f0;
    }

    .deans-table tbody td {
        vertical-align: middle;
    }

    .status-pill {
        display: inline-block;
        padding: 0.23rem 0.7rem;
        border-radius: 999px;
        font-size: 0.72rem;
        font-weight: 700;
    }

    .status-pill.active {
        background: #dcfce7;
        color: #166534;
    }

    .status-pill.inactive {
        background: #f1f5f9;
        color: #475569;
    }

    .deans-action {
        border: none;
        background: transparent;
        color: #64748b;
        padding: 0.2rem 0.35rem;
    }

    .deans-action:hover {
        color: #1f2937;
    }

    .deans-action.delete:hover {
        color: #b91c1c;
    }
</style>

<div class="deans-dashboard">
    <div class="deans-top">
        <div class="deans-title">
            <h1><i class="fa-solid fa-user-tie me-2 text-success"></i>Manage Deans</h1>
            <p>Add, edit, or manage college deans across all campuses.</p>
        </div>
        <?php if (!$edit): ?>
            <button id="showDeanFormBtn" type="button" class="deans-add-btn"><i class="fa-solid fa-plus-circle me-1"></i>Add Dean</button>
        <?php endif; ?>
    </div>

    <?php if ($flash): ?><div class="alert alert-info"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
    <?php if (!$hasUserEmail): ?>
        <div class="alert alert-warning">The <code>users.email</code> column is missing. Run <a href="upgrade_roles.php">upgrade_roles.php</a> once to add it, then reload this page.</div>
    <?php endif; ?>

    <div id="deanFormCard" class="card dean-form-card mb-4"<?= $edit ? '' : ' style="display:none;"' ?>>
        <div class="dean-form-header">
            <i class="fa-solid fa-user-pen me-2 text-success"></i><?= $edit ? 'Edit Dean' : 'Add New Dean' ?>
        </div>
        <div class="dean-form-body">
            <form method="post" id="deanForm">
                <input type="hidden" name="action" value="<?= $edit ? 'edit' : 'add' ?>">
                <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>

                <div class="dean-form-grid">
                    <div>
                        <label class="form-label">Username</label>
                        <input name="username" class="form-control" <?= $edit ? 'readonly' : 'required' ?> value="<?= htmlspecialchars((string) ($edit['username'] ?? '')) ?>">
                    </div>
                    <div>
                        <label class="form-label">Full Name</label>
                        <input name="full_name" class="form-control" required value="<?= htmlspecialchars((string) ($edit['full_name'] ?? '')) ?>">
                    </div>
                    <?php if ($hasUserEmail): ?>
                    <div>
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" placeholder="dean@school.edu" value="<?= htmlspecialchars((string) ($edit['email'] ?? '')) ?>">
                        <div class="form-text">Used to send temporary passwords.</div>
                    </div>
                    <?php endif; ?>
                    <?php if (!$edit): ?>
                    <div>
                        <label class="form-label">Password</label>
                        <input name="password" id="deanPassword" class="form-control" autocomplete="new-password" placeholder="If not emailing">
                        <?php if ($hasUserEmail): ?>
                        <div class="form-check mt-2">
                            <input type="checkbox" class="form-check-input" name="send_credentials_email" id="send_credentials_email" value="1" checked>
                            <label class="form-check-label" for="send_credentials_email">Generate temporary password and email it</label>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div>
                        <label class="form-label">Reset Password (Optional)</label>
                        <input name="reset_password" id="deanResetPassword" class="form-control" autocomplete="new-password" placeholder="New password">
                        <?php if ($hasUserEmail): ?>
                        <div class="form-check mt-2">
                            <input type="checkbox" class="form-check-input" name="email_reset_password" id="email_reset_password" value="1">
                            <label class="form-check-label" for="email_reset_password">Email this new password to the dean</label>
                        </div>
                        <div class="form-check mt-1">
                            <input type="checkbox" class="form-check-input" name="generate_temp_password_email" id="generate_temp_password_email" value="1">
                            <label class="form-check-label" for="generate_temp_password_email">Generate temporary password and email it</label>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <div>
                        <label class="form-label">College</label>
                        <select name="college_id" class="form-select">
                            <option value="">Unassigned</option>
                            <?php foreach ($colleges as $c): ?>
                                <option value="<?= (int) $c['id'] ?>" <?= (int) ($edit['college_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) $c['college_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="d-flex align-items-end">
                        <div class="form-check mb-2">
                            <input type="checkbox" class="form-check-input" name="is_active" id="is_active" value="1" <?= !isset($edit['is_active']) || (int) $edit['is_active'] === 1 ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_active">Active</label>
                        </div>
                    </div>
                </div>

                <div class="dean-form-actions">
                    <?php if (!$edit): ?>
                        <button id="cancelDeanFormBtn" type="button" class="btn btn-light border">Cancel</button>
                    <?php else: ?>
                        <a class="btn btn-light border" href="admin_deans.php">Cancel</a>
                    <?php endif; ?>
                    <button class="btn btn-success" type="submit"<?= app_tooltip_attr($edit ? 'Saves changes to this dean account.' : 'Creates the dean user and links them to a college.') ?>>
                        <i class="fa-solid fa-floppy-disk me-1"></i>Save Dean
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="card deans-table-card">
        <div class="table-responsive">
            <table class="table deans-table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Name</th>
                        <?php if ($hasUserEmail): ?><th>Email</th><?php endif; ?>
                        <th>College</th>
                        <th>Status</th>
                        <th style="width: 90px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$deans): ?>
                    <tr><td colspan="<?= $hasUserEmail ? '6' : '5' ?>" class="text-center text-muted py-4">No deans found.</td></tr>
                <?php endif; ?>
                <?php foreach ($deans as $d): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($d['username']) ?></strong></td>
                        <td><?= htmlspecialchars($d['full_name']) ?></td>
                        <?php if ($hasUserEmail): ?><td><?= htmlspecialchars((string) ($d['email'] ?? '')) ?></td><?php endif; ?>
                        <td><?= htmlspecialchars((string) ($d['college_name'] ?? 'Unassigned')) ?></td>
                        <td>
                            <span class="status-pill <?= (int) $d['is_active'] === 1 ? 'active' : 'inactive' ?>">
                                <?= (int) $d['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td>
                            <a class="deans-action" href="admin_deans.php?edit=<?= (int) $d['id'] ?>" title="Edit Dean"<?= app_tooltip_attr('Edits this dean profile and account settings.') ?>>
                                <i class="fa-solid fa-pen-to-square"></i>
                            </a>
                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this dean account?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                                <button type="submit" class="deans-action delete" title="Delete Dean"<?= app_tooltip_attr('Removes this dean account after confirmation.') ?>>
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    (function () {
        const isEdit = <?= $edit ? 'true' : 'false' ?>;
        const formCard = document.getElementById('deanFormCard');
        const showBtn = document.getElementById('showDeanFormBtn');
        const cancelBtn = document.getElementById('cancelDeanFormBtn');
        const sendCredentials = document.getElementById('send_credentials_email');
        const deanPassword = document.getElementById('deanPassword');
        const generateTempPasswordEmail = document.getElementById('generate_temp_password_email');
        const deanResetPassword = document.getElementById('deanResetPassword');
        const emailResetPassword = document.getElementById('email_reset_password');

        if (showBtn && formCard) {
            showBtn.addEventListener('click', function () {
                formCard.style.display = 'block';
                showBtn.style.display = 'none';
                window.scrollTo({ top: formCard.offsetTop - 12, behavior: 'smooth' });
            });
        }

        if (cancelBtn && formCard && showBtn) {
            cancelBtn.addEventListener('click', function () {
                formCard.style.display = 'none';
                showBtn.style.display = 'inline-block';
            });
        }

        if (!isEdit && sendCredentials && deanPassword) {
            const syncAddPasswordState = function () {
                deanPassword.required = !sendCredentials.checked;
                deanPassword.disabled = sendCredentials.checked;
                if (sendCredentials.checked) {
                    deanPassword.value = '';
                }
            };
            sendCredentials.addEventListener('change', syncAddPasswordState);
            syncAddPasswordState();
        }

        if (isEdit && generateTempPasswordEmail && deanResetPassword) {
            const syncEditPasswordState = function () {
                const disableManual = generateTempPasswordEmail.checked;
                deanResetPassword.disabled = disableManual;
                if (disableManual) {
                    deanResetPassword.value = '';
                    if (emailResetPassword) {
                        emailResetPassword.checked = false;
                    }
                }
            };
            generateTempPasswordEmail.addEventListener('change', syncEditPasswordState);
            syncEditPasswordState();
        }
    })();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

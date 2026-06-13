<?php
declare(strict_types=1);

/**
 * Dean: program chair account(s) for "General Education" within the college.
 * Separate from the institution-wide GEN ED coordinator (admin → GEN ED Account).
 */
const GE_PROGRAM_CHAIR_LABEL = 'General Education';

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

if ($hasProgramsTable) {
    ensure_college_program_name($collegeId, GE_PROGRAM_CHAIR_LABEL);
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
        ensure_college_program_name($collegeId, GE_PROGRAM_CHAIR_LABEL);

        if ($action === 'add') {
            $username = trim((string) ($_POST['username'] ?? ''));
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $assignedProgram = GE_PROGRAM_CHAIR_LABEL;
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
                throw new RuntimeException('Username already exists.' . ($s ? ' Try: ' . implode(', ', $s) : ''));
            }

            $sql = $hasUserEmail
                ? 'INSERT INTO users (username,password,full_name,email,role,assigned_program,college_id,is_active) VALUES (?,?,?,?,?,?,?,?)'
                : 'INSERT INTO users (username,password,full_name,role,assigned_program,college_id,is_active) VALUES (?,?,?,?,?,?,?)';
            $params = $hasUserEmail
                ? [$username, password_hash($password, PASSWORD_DEFAULT), $fullName, $email, 'program_chair', $assignedProgram, $collegeId, !empty($_POST['is_active']) ? 1 : 0]
                : [$username, password_hash($password, PASSWORD_DEFAULT), $fullName, 'program_chair', $assignedProgram, $collegeId, !empty($_POST['is_active']) ? 1 : 0];
            db()->prepare($sql)->execute($params);
            log_dean_activity('gened_program_chair_create', 'Created GE program chair for ' . GE_PROGRAM_CHAIR_LABEL);

            $mailOk = false;
            if ($hasUserEmail && $sendByEmail && $email !== '' && $plainForMail !== '') {
                $mailOk = send_account_credentials_mail($email, $fullName, $username, $plainForMail, 'program_chair');
            }
            if ($hasUserEmail && $sendByEmail && $email !== '' && $plainForMail !== '') {
                $_SESSION['flash'] = $mailOk
                    ? 'General Education Program Chair created. Temporary password sent to ' . $email . '.'
                    : 'General Education Program Chair created, but email could not be sent. Temporary password: ' . $plainForMail;
            } else {
                $_SESSION['flash'] = 'General Education Program Chair account created.';
            }
        } elseif ($action === 'edit' && isset($_POST['id'])) {
            $id = (int) $_POST['id'];
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $assignedProgram = GE_PROGRAM_CHAIR_LABEL;
            $email = $hasUserEmail ? trim((string) ($_POST['email'] ?? '')) : '';
            $resetPassword = trim((string) ($_POST['reset_password'] ?? ''));
            $generateAndEmail = !empty($_POST['generate_temp_password_email']);

            if ($fullName === '') {
                throw new RuntimeException('Full name is required.');
            }
            if ($hasUserEmail && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Invalid email address.');
            }

            $st = db()->prepare('SELECT username, full_name FROM users WHERE id=? AND role="program_chair" AND college_id=? AND assigned_program=?');
            $st->execute([$id, $collegeId, GE_PROGRAM_CHAIR_LABEL]);
            $row = $st->fetch();
            if (!$row) {
                throw new RuntimeException('General Education Program Chair account not found.');
            }

            $sql = $hasUserEmail
                ? 'UPDATE users SET full_name=?, email=?, assigned_program=?, is_active=? WHERE id=? AND role="program_chair" AND college_id=? AND assigned_program=?'
                : 'UPDATE users SET full_name=?, assigned_program=?, is_active=? WHERE id=? AND role="program_chair" AND college_id=? AND assigned_program=?';
            $params = $hasUserEmail
                ? [$fullName, $email, $assignedProgram, !empty($_POST['is_active']) ? 1 : 0, $id, $collegeId, GE_PROGRAM_CHAIR_LABEL]
                : [$fullName, $assignedProgram, !empty($_POST['is_active']) ? 1 : 0, $id, $collegeId, GE_PROGRAM_CHAIR_LABEL];
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
                    ? 'General Education Program Chair updated. Password instructions sent to ' . $email . '.'
                    : 'General Education Program Chair updated, but email could not be sent. Temporary password: ' . $plainForMail;
            } else {
                $_SESSION['flash'] = 'General Education Program Chair updated.';
            }
            log_dean_activity('gened_program_chair_update', 'Updated GE program chair #' . $id);
        } elseif ($action === 'delete' && isset($_POST['id'])) {
            $id = (int) $_POST['id'];
            $st = db()->prepare('DELETE FROM users WHERE id=? AND role="program_chair" AND college_id=? AND assigned_program=?');
            $st->execute([$id, $collegeId, GE_PROGRAM_CHAIR_LABEL]);
            if ($st->rowCount() < 1) {
                throw new RuntimeException('General Education Program Chair account not found.');
            }
            log_dean_activity('gened_program_chair_delete', 'Deleted GE program chair #' . $id);
            $_SESSION['flash'] = 'General Education Program Chair account deleted.';
        }
    } catch (Throwable $e) {
        $_SESSION['flash'] = 'Error: ' . $e->getMessage();
    }
    header('Location: dean_gened_chair.php');
    exit;
}

$editRow = null;
if (isset($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM users WHERE id=? AND role="program_chair" AND college_id=? AND assigned_program=?');
    $st->execute([(int) $_GET['edit'], $collegeId, GE_PROGRAM_CHAIR_LABEL]);
    $editRow = $st->fetch() ?: null;
}

$chairs = [];
if ($hasAssignedProgram) {
    $st = db()->prepare(
        'SELECT id, username, full_name, email, assigned_program, is_active
         FROM users
         WHERE role="program_chair" AND college_id=? AND assigned_program=?
         ORDER BY full_name'
    );
    $st->execute([$collegeId, GE_PROGRAM_CHAIR_LABEL]);
    $chairs = $st->fetchAll();
}

$pageTitle = 'General Education Program Chair';
require_once __DIR__ . '/includes/header.php';
?>
<h1 class="h3 mb-4"><i class="fa-solid fa-graduation-cap me-2 text-primary"></i>General Education Program Chair</h1>
<p class="text-muted">College: <strong><?= htmlspecialchars($collegeName) ?></strong></p>
<p class="small text-muted">Creates a <strong>program chair</strong> account scoped to the <strong><?= htmlspecialchars(GE_PROGRAM_CHAIR_LABEL) ?></strong> program (scheduling, courses, faculty for your college). This is separate from the institution-wide <strong>GEN ED</strong> coordinator account managed under <strong>Admin → GEN ED</strong>.</p>
<?php if ($flash): ?><div class="alert alert-info"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
<?php if (!$hasAssignedProgram || !$hasProgramsTable): ?>
    <div class="alert alert-warning">Program Chair accounts require the upgraded schema. Run <a href="upgrade_roles.php">upgrade_roles.php</a> first.</div>
<?php else: ?>
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white"><strong><?= $editRow ? 'Edit General Education Program Chair' : 'Add General Education Program Chair' ?></strong></div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <input type="hidden" name="action" value="<?= $editRow ? 'edit' : 'add' ?>">
            <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>"><?php endif; ?>
            <div class="col-md-3"><label class="form-label">Username</label><input name="username" class="form-control" <?= $editRow ? 'readonly' : 'required' ?> value="<?= htmlspecialchars((string) ($editRow['username'] ?? '')) ?>"></div>
            <div class="col-md-3"><label class="form-label">Full Name</label><input name="full_name" class="form-control" required value="<?= htmlspecialchars((string) ($editRow['full_name'] ?? '')) ?>"></div>
            <div class="col-md-3">
                <label class="form-label">Program</label>
                <input class="form-control" value="<?= htmlspecialchars(GE_PROGRAM_CHAIR_LABEL) ?>" readonly tabindex="-1" style="background:#f8f9fa" title="Fixed for this page">
            </div>
            <?php if ($hasUserEmail): ?>
                <div class="col-md-3"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= htmlspecialchars((string) ($editRow['email'] ?? '')) ?>"></div>
            <?php endif; ?>
            <?php if (!$editRow): ?>
                <div class="col-md-3">
                    <label class="form-label">Password</label>
                    <input name="password" id="gePcPassword" class="form-control" autocomplete="new-password" placeholder="If not emailing">
                    <?php if ($hasUserEmail): ?>
                        <div class="form-check mt-2">
                            <input type="checkbox" class="form-check-input" name="send_credentials_email" id="ge_pc_send_credentials_email" value="1" checked>
                            <label class="form-check-label" for="ge_pc_send_credentials_email">Generate temporary password and email it</label>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="col-md-3">
                    <label class="form-label">Reset password (optional)</label>
                    <input name="reset_password" class="form-control" autocomplete="new-password">
                    <?php if ($hasUserEmail): ?>
                        <div class="form-check mt-2">
                            <input type="checkbox" class="form-check-input" name="email_reset_password" id="ge_pc_email_reset_password" value="1">
                            <label class="form-check-label" for="ge_pc_email_reset_password">Email this new password</label>
                        </div>
                        <div class="form-check mt-1">
                            <input type="checkbox" class="form-check-input" name="generate_temp_password_email" id="ge_pc_generate_temp_password_email" value="1">
                            <label class="form-check-label" for="ge_pc_generate_temp_password_email">Generate temporary password and email it</label>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="col-md-2 d-flex align-items-end">
                <div class="form-check"><input type="checkbox" class="form-check-input" name="is_active" value="1" <?= !isset($editRow['is_active']) || (int) ($editRow['is_active'] ?? 1) === 1 ? 'checked' : '' ?>><label class="form-check-label">Active</label></div>
            </div>
            <div class="col-12">
                <button class="btn btn-primary" type="submit">Save</button>
                <?php if ($editRow): ?><a class="btn btn-outline-secondary" href="dean_gened_chair.php">Cancel</a><?php endif; ?>
            </div>
        </form>
        <?php if (!$editRow && $hasUserEmail): ?>
        <script>
            (function () {
                const cb = document.getElementById('ge_pc_send_credentials_email');
                const pw = document.getElementById('gePcPassword');
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
                        <a class="btn btn-sm btn-outline-primary" href="dean_gened_chair.php?edit=<?= (int) $chair['id'] ?>">Edit</a>
                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this General Education Program Chair account?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $chair['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($chairs === []): ?>
                <tr><td colspan="<?= $hasUserEmail ? '6' : '5' ?>" class="text-center text-muted py-4">No General Education Program Chair accounts yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

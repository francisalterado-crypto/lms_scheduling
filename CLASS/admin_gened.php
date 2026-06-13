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
    try {
        $st = db()->prepare('SELECT id FROM users WHERE role="gened" ORDER BY id LIMIT 1');
        $st->execute();
        $id = (int) ($st->fetchColumn() ?: 0);
        if ($id < 1) {
            throw new RuntimeException('GEN ED account not found. Run upgrade_roles.php first.');
        }

        $fullName = trim((string) $_POST['full_name']);
        $email = $hasUserEmail ? trim((string) ($_POST['email'] ?? '')) : '';
        $resetPassword = trim((string) ($_POST['reset_password'] ?? ''));
        $generateAndEmail = !empty($_POST['generate_temp_password_email']);

        if ($hasUserEmail && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid email address.');
        }

        $stU = db()->prepare('SELECT username, full_name FROM users WHERE id=? AND role="gened"');
        $stU->execute([$id]);
        $rowBefore = $stU->fetch();
        if (!$rowBefore) {
            throw new RuntimeException('GEN ED account not found.');
        }
        $uname = (string) $rowBefore['username'];
        $displayName = $fullName !== '' ? $fullName : (string) $rowBefore['full_name'];

        if ($hasUserEmail) {
            db()->prepare('UPDATE users SET username=?, full_name=?, email=?, is_active=? WHERE id=?')
                ->execute([
                    trim((string) $_POST['username']),
                    $fullName,
                    $email,
                    !empty($_POST['is_active']) ? 1 : 0,
                    $id,
                ]);
        } else {
            db()->prepare('UPDATE users SET username=?, full_name=?, is_active=? WHERE id=?')
                ->execute([
                    trim((string) $_POST['username']),
                    $fullName,
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
            $mailOk = send_account_credentials_mail($email, $displayName, $uname, $plainForMail, 'gened');
            $_SESSION['flash'] = $mailOk
                ? 'GEN ED account updated. Password instructions sent to ' . $email . '.'
                : 'GEN ED account updated, but email could not be sent. Check MAIL_* in config/config.php. Temporary password: ' . $plainForMail;
        } else {
            $_SESSION['flash'] = 'GEN ED account updated.';
        }
    } catch (Throwable $e) {
        $_SESSION['flash'] = 'Error: ' . $e->getMessage();
    }
    header('Location: admin_gened.php');
    exit;
}

$gened = db()->query('SELECT * FROM users WHERE role="gened" ORDER BY id LIMIT 1')->fetch() ?: null;
$pageTitle = 'GEN ED Account';
require_once __DIR__ . '/includes/header.php';
?>
<h1 class="h3 mb-4"><i class="fa-solid fa-user-gear me-2 text-primary"></i>GEN ED Admin Account</h1>
<p class="small text-muted">Institution-wide General Education coordinator (GE schedule, GE faculty, GE courses).</p>
<?php if ($flash): ?><div class="alert alert-info"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
<?php if (!$hasUserEmail): ?>
    <div class="alert alert-warning">The <code>users.email</code> column is missing. Run <a href="upgrade_roles.php">upgrade_roles.php</a> to enable email for credentials.</div>
<?php endif; ?>
<?php if (!$gened): ?>
    <div class="alert alert-warning">No GEN ED account found. Run <a href="upgrade_roles.php">upgrade_roles.php</a> first.</div>
<?php else: ?>
<div class="card shadow-sm">
    <div class="card-body">
        <form method="post" class="row g-3">
            <div class="col-md-4"><label class="form-label">Username</label><input name="username" class="form-control" required value="<?= htmlspecialchars((string) $gened['username']) ?>"></div>
            <div class="col-md-4"><label class="form-label">Full Name</label><input name="full_name" class="form-control" required value="<?= htmlspecialchars((string) $gened['full_name']) ?>"></div>
            <?php if ($hasUserEmail): ?>
                <div class="col-md-4"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= htmlspecialchars((string) ($gened['email'] ?? '')) ?>"></div>
            <?php endif; ?>
            <div class="col-md-4"><label class="form-label">Reset Password (optional)</label><input name="reset_password" class="form-control" autocomplete="new-password"></div>
            <?php if ($hasUserEmail): ?>
                <div class="col-md-4 d-flex flex-column justify-content-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="email_reset_password" id="ag_email_reset_password" value="1">
                        <label class="form-check-label" for="ag_email_reset_password">Email the manual password above</label>
                    </div>
                    <div class="form-check mt-1">
                        <input class="form-check-input" type="checkbox" name="generate_temp_password_email" id="ag_generate_temp_password_email" value="1">
                        <label class="form-check-label" for="ag_generate_temp_password_email">Generate temporary password and email it</label>
                    </div>
                </div>
            <?php endif; ?>
            <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1" <?= (int) $gened['is_active'] === 1 ? 'checked' : '' ?>><label class="form-check-label">Active</label></div></div>
            <div class="col-12"><button type="submit" class="btn btn-primary"<?= app_tooltip_attr('Saves the GEN ED coordinator account and optional password email.') ?>>Save</button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

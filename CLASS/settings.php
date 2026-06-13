<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

require_role(['admin', 'dean', 'program_chair', 'gened', 'faculty', 'student']);

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    try {
        if ($userId < 1) {
            throw new RuntimeException('Invalid user session. Please log in again.');
        }
        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            throw new RuntimeException('All password fields are required.');
        }
        if ($newPassword !== $confirmPassword) {
            throw new RuntimeException('New password and confirmation do not match.');
        }
        if (strlen($newPassword) < 8) {
            throw new RuntimeException('New password must be at least 8 characters.');
        }
        if ($newPassword === $currentPassword) {
            throw new RuntimeException('New password must be different from current password.');
        }

        $st = db()->prepare('SELECT password FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
        $st->execute([$userId]);
        $hash = (string) ($st->fetchColumn() ?: '');
        if ($hash === '' || !password_verify($currentPassword, $hash)) {
            throw new RuntimeException('Current password is incorrect.');
        }

        db()->prepare('UPDATE users SET password = ? WHERE id = ?')
            ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);

        $_SESSION['flash'] = 'Password updated successfully.';
        header('Location: settings.php');
        exit;
    } catch (Throwable $e) {
        $_SESSION['flash'] = 'Error: ' . $e->getMessage();
        header('Location: settings.php');
        exit;
    }
}

$pageTitle = 'Settings';
require_once __DIR__ . '/includes/header.php';
?>
<h1 class="h3 mb-4"><i class="fa-solid fa-gear me-2 text-primary"></i>Account Settings</h1>
<?php if ($flash): ?><div class="alert alert-info"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

<div class="card shadow-sm" style="max-width: 640px;">
    <div class="card-header bg-white"><strong>Change Password</strong></div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <div class="col-12">
                <label class="form-label">Current password</label>
                <input type="password" name="current_password" class="form-control" required autocomplete="current-password">
            </div>
            <div class="col-md-6">
                <label class="form-label">New password</label>
                <input type="password" name="new_password" class="form-control" required minlength="8" autocomplete="new-password">
            </div>
            <div class="col-md-6">
                <label class="form-label">Confirm new password</label>
                <input type="password" name="confirm_password" class="form-control" required minlength="8" autocomplete="new-password">
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary"<?= app_tooltip_attr('Saves your new password after verifying your current password. Use this to strengthen security or meet institutional rules.') ?>>Update Password</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

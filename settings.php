<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/admin_activity_log.php';
require_once __DIR__ . '/includes/profile_photo_helpers.php';

require_role(['super_admin', 'admin', 'dean', 'program_chair', 'gened', 'faculty', 'student']);

if (is_admin() || is_super_admin()) {
    admin_activity_log_cleanup();
}

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $settingsAction = (string) ($_POST['settings_action'] ?? 'password');

    try {
        if ($userId < 1) {
            throw new RuntimeException('Invalid user session. Please log in again.');
        }

        if ($settingsAction === 'upload_profile_photo') {
            profile_photo_store($userId, $_FILES['profile_photo'] ?? []);
            log_user_activity(
                'edit',
                'Account',
                'Profile photo updated (own account)',
                null,
                ['profile_photo' => '[updated]']
            );
            $_SESSION['flash'] = 'Profile photo updated.';
            header('Location: settings.php');
            exit;
        }

        if ($settingsAction === 'remove_profile_photo') {
            profile_photo_remove($userId);
            log_user_activity(
                'edit',
                'Account',
                'Profile photo removed (own account)',
                null,
                ['profile_photo' => '[removed]']
            );
            $_SESSION['flash'] = 'Profile photo removed.';
            header('Location: settings.php');
            exit;
        }

        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

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

        log_user_activity(
            'edit',
            'Account',
            'Password changed (own account)',
            null,
            ['password' => '[changed]']
        );
        $_SESSION['flash'] = 'Password updated successfully.';
        header('Location: settings.php');
        exit;
    } catch (Throwable $e) {
        $_SESSION['flash'] = 'Error: ' . $e->getMessage();
        header('Location: settings.php');
        exit;
    }
}

$activityLogOrder = (string) ($_GET['log_sort'] ?? 'newest');
if ($activityLogOrder !== 'oldest') {
    $activityLogOrder = 'newest';
}
$activityLogTableReady = false;
$activityLogs = [];
if (is_admin() || is_super_admin()) {
    $activityLogTableReady = admin_activity_log_table_ready();
    $activityLogs = $activityLogTableReady ? admin_activity_log_list_sorted($activityLogOrder) : [];
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$profilePhotoReady = profile_photo_column_ready();
$profilePhotoUrl = $profilePhotoReady ? profile_photo_url($userId) : null;

$pageTitle = 'Settings';
require_once __DIR__ . '/includes/header.php';
?>
<h1 class="h3 mb-4"><i class="fa-solid fa-gear me-2 app-role-icon"></i>Account Settings</h1>
<?php if ($flash): ?><div class="alert alert-info"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

<div class="card shadow-sm mb-4" style="max-width: 640px;">
    <div class="card-header bg-white"><strong>Profile Photo</strong></div>
    <div class="card-body">
        <?php if (!$profilePhotoReady): ?>
            <div class="alert alert-warning mb-0 py-2 small">
                Profile photos are not installed yet. Run <a href="upgrade_roles.php" class="alert-link">upgrade_roles.php</a> once, then reload this page.
            </div>
        <?php else: ?>
            <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
                <?php if ($profilePhotoUrl): ?>
                    <img src="<?= htmlspecialchars($profilePhotoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" class="rounded-circle border settings-profile-preview" width="80" height="80">
                <?php else: ?>
                    <span class="admin-topbar-avatar rounded-circle d-inline-flex align-items-center justify-content-center settings-profile-preview" style="width: 5rem; height: 5rem; font-size: 1.5rem;">
                        <?php
                        $previewRole = (string) ($_SESSION['role'] ?? '');
                        $previewIcon = app_sidebar_topbar_icon_class($previewRole);
                        ?>
                        <i class="fa-solid <?= htmlspecialchars($previewIcon, ENT_QUOTES, 'UTF-8') ?> admin-topbar-avatar-icon" aria-hidden="true"></i>
                    </span>
                <?php endif; ?>
                <div class="small text-muted">
                    This photo appears in the top bar instead of the role icon. JPEG, PNG, or WebP, max 2&nbsp;MB.
                </div>
            </div>
            <form method="post" enctype="multipart/form-data" class="row g-3 mb-2">
                <input type="hidden" name="settings_action" value="upload_profile_photo">
                <div class="col-12">
                    <label class="form-label" for="profile_photo">Choose photo</label>
                    <input type="file" name="profile_photo" id="profile_photo" class="form-control" accept="image/jpeg,image/png,image/webp" required>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary"<?= app_tooltip_attr('Uploads your profile photo and shows it in the top bar menu.') ?>>Upload Photo</button>
                </div>
            </form>
            <?php if ($profilePhotoUrl): ?>
                <form method="post" class="mt-2" onsubmit="return confirm('Remove your profile photo and use the default icon again?');">
                    <input type="hidden" name="settings_action" value="remove_profile_photo">
                    <button type="submit" class="btn btn-outline-danger btn-sm"<?= app_tooltip_attr('Deletes your profile photo and restores the role icon in the top bar.') ?>>Remove Photo</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

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

<?php if (is_admin() || is_super_admin()): ?>
<div class="card shadow-sm mt-4">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
        <strong><i class="fa-solid fa-clipboard-list me-2 text-primary" aria-hidden="true"></i>Log Activity Monitoring</strong>
        <?php if ($activityLogTableReady): ?>
            <form method="get" class="d-flex align-items-center gap-2">
                <label class="small text-muted mb-0" for="log_sort">Sort</label>
                <select name="log_sort" id="log_sort" class="form-select form-select-sm" style="width: auto;" onchange="this.form.submit()">
                    <option value="newest" <?= $activityLogOrder === 'newest' ? 'selected' : '' ?>>Newest first</option>
                    <option value="oldest" <?= $activityLogOrder === 'oldest' ? 'selected' : '' ?>>Oldest first</option>
                </select>
                <noscript><button type="submit" class="btn btn-sm btn-outline-secondary">Apply</button></noscript>
            </form>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <p class="text-muted small px-3 pt-3 mb-2">Read-only audit trail for <strong>all signed-in roles</strong> (admin, dean, program chair, GEN ED, faculty, student, etc.). Data changes and sign-in / sign-out are recorded where instrumented. Entries older than <?= (int) ADMIN_ACTIVITY_LOG_RETENTION_DAYS ?> days are removed automatically.</p>
        <?php if (!$activityLogTableReady): ?>
            <div class="alert alert-warning mx-3 mb-3 py-2 small" role="alert">
                The activity log table is not installed yet. Run <a href="upgrade_roles.php" class="alert-link">upgrade_roles.php</a> once (or re-run install) so admin actions can be recorded and shown here.
            </div>
        <?php elseif (!$activityLogs): ?>
            <div class="px-3 pb-3 text-muted small">No activity has been logged yet. Entries appear when users perform recorded actions (for example saving courses, rooms, schedules, or signing in).</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-striped mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">When</th>
                            <th scope="col">Account</th>
                            <th scope="col">Action</th>
                            <th scope="col">Module</th>
                            <th scope="col">Target</th>
                            <th scope="col">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($activityLogs as $log): ?>
                        <?php
                        $rawDetails = (string) ($log['details_json'] ?? '');
                        [$dBefore, $dAfter, $detailsInvalid] = admin_activity_log_decode_details_for_display($rawDetails);
                        $showBefore = admin_activity_log_detail_block_nonempty($dBefore);
                        $showAfter = admin_activity_log_detail_block_nonempty($dAfter);
                        $hasStructured = $showBefore || $showAfter;
                        $summaryLabel = $showBefore && $showAfter
                            ? 'Before / after'
                            : ($showBefore ? 'Before' : ($showAfter ? 'After' : ''));
                        $explainLines = admin_activity_log_explain_human_lines($log, $dBefore, $dAfter);
                        ?>
                        <tr>
                            <td class="text-nowrap small"><?= htmlspecialchars((string) ($log['created_at'] ?? '')) ?></td>
                            <td class="small">
                                <?php
                                $logActorName = trim((string) ($log['actor_full_name'] ?? ''));
                                $logActorTitle = trim((string) ($log['actor_log_title'] ?? ''));
                                ?>
                                <?php if ($logActorName !== ''): ?>
                                    <div class="fw-semibold"><?= htmlspecialchars($logActorName) ?></div>
                                <?php endif; ?>
                                <div>
                                    <?= htmlspecialchars((string) ($log['admin_username'] ?? '')) ?>
                                    <span class="text-muted">(#<?= (int) ($log['admin_user_id'] ?? 0) ?>)</span>
                                </div>
                                <?php if ($logActorTitle !== ''): ?>
                                    <div class="text-muted"><?= htmlspecialchars($logActorTitle) ?></div>
                                <?php endif; ?>
                                <span class="badge bg-light text-dark border mt-1"><?= htmlspecialchars((string) ($log['user_role'] ?? '')) ?></span>
                            </td>
                            <td>
                                <?php
                                $at = (string) ($log['action_type'] ?? '');
                                $badgeClass = match ($at) {
                                    'login' => 'bg-success',
                                    'logout' => 'bg-info text-dark',
                                    'delete' => 'bg-danger',
                                    'add' => 'bg-primary',
                                    default => 'bg-secondary',
                                };
                                ?>
                                <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($at) ?></span>
                            </td>
                            <td class="small"><?= htmlspecialchars((string) ($log['target_module'] ?? '')) ?></td>
                            <td class="small"><?= htmlspecialchars((string) ($log['target_reference'] ?? '')) ?></td>
                            <td class="small align-top" style="min-width: 260px; max-width: 520px;">
                                <div class="activity-log-explain border-start border-primary border-2 ps-2 mb-2">
                                    <?php foreach ($explainLines as $i => $sentence): ?>
                                        <?php if ($i === 0): ?>
                                            <p class="small fw-semibold mb-1"><?= htmlspecialchars($sentence) ?></p>
                                        <?php else: ?>
                                            <div class="small text-body-secondary mb-1"><?= htmlspecialchars($sentence) ?></div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                                <?php if ($hasStructured || ($detailsInvalid && $rawDetails !== '')): ?>
                                    <details class="activity-log-details mt-1">
                                        <summary class="d-flex flex-wrap align-items-center gap-1 text-muted user-select-none small" style="cursor: pointer;">
                                            <span>Technical payload (JSON)</span>
                                            <?php if ($summaryLabel !== ''): ?>
                                                <span class="text-body-secondary">·</span>
                                                <span><?= htmlspecialchars($summaryLabel) ?></span>
                                            <?php endif; ?>
                                            <?php if ($showBefore): ?><span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis border">Before</span><?php endif; ?>
                                            <?php if ($showAfter): ?><span class="badge rounded-pill bg-primary-subtle text-primary-emphasis border">After</span><?php endif; ?>
                                            <?php if ($detailsInvalid): ?><span class="badge rounded-pill bg-warning-subtle text-warning-emphasis border">Raw</span><?php endif; ?>
                                        </summary>
                                        <div class="border rounded mt-2 mb-0 bg-body-secondary overflow-hidden" style="max-height: 240px; overflow-y: auto;">
                                            <?php if ($showBefore): ?>
                                                <div class="border-bottom border-secondary-subtle p-2">
                                                    <div class="fw-semibold text-uppercase small text-muted mb-1">Before</div>
                                                    <pre class="small font-monospace mb-0 text-wrap" style="white-space: pre-wrap;"><?= htmlspecialchars(admin_activity_log_format_json_pretty($dBefore)) ?></pre>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($showAfter): ?>
                                                <div class="p-2">
                                                    <div class="fw-semibold text-uppercase small text-muted mb-1">After</div>
                                                    <pre class="small font-monospace mb-0 text-wrap" style="white-space: pre-wrap;"><?= htmlspecialchars(admin_activity_log_format_json_pretty($dAfter)) ?></pre>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($detailsInvalid && $rawDetails !== ''): ?>
                                                <div class="p-2 <?= ($showBefore || $showAfter) ? 'border-top border-secondary-subtle' : '' ?>">
                                                    <div class="fw-semibold text-uppercase small text-muted mb-1">Raw JSON</div>
                                                    <pre class="small font-monospace mb-0 text-wrap" style="white-space: pre-wrap;"><?= htmlspecialchars($rawDetails) ?></pre>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </details>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php
declare(strict_types=1);

require_once __DIR__ . '/messaging_helpers.php';
require_once __DIR__ . '/student_tooltip.php';
require_once __DIR__ . '/profile_photo_helpers.php';

$pageTitle = $pageTitle ?? 'WPU SABLAe Portal';
$mainContainerClass = $mainContainerClass ?? 'container py-4 py-md-5 app-main';
$u = current_user();
$messagingNavUnread = ($u && messaging_table_exists()) ? messaging_unread_count((int) $u['id']) : 0;
$appSidebarShell = false;
$adminNavPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$bodyRoleClass = '';
if ($u) {
    $bodyRoleClass = preg_replace('/[^a-z0-9_-]/', '', (string) ($u['role'] ?? ''));
}
$appCursorTooltips = $u && app_cursor_tooltips_active();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
    (function () {
        try {
            var k = 'app_theme', t = localStorage.getItem(k);
            if (t !== 'dark' && t !== 'light') {
                t = localStorage.getItem('schedule_theme');
                if (t === 'dark' || t === 'light') localStorage.setItem(k, t);
            }
            if (t !== 'dark' && t !== 'light') {
                t = (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
            }
            document.documentElement.setAttribute('data-bs-theme', t);
        } catch (e) {
            document.documentElement.setAttribute('data-bs-theme', 'light');
        }
    })();
    </script>
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link href="assets/css/print.css" rel="stylesheet" media="print">
</head>
<body class="app-shell<?= $u ? ' app-shell-sidebar' . ($bodyRoleClass !== '' ? ' app-shell-sidebar--' . htmlspecialchars($bodyRoleClass, ENT_QUOTES, 'UTF-8') : '') : '' ?>">
<a class="visually-hidden-focusable btn btn-primary position-fixed top-0 start-0 m-2 px-3 py-2 rounded-3 shadow z-3" href="#main-content" style="z-index: 1080;"<?= $appCursorTooltips ? app_tooltip_attr('Jumps past the sidebar and menus to the main page content. Use this for faster keyboard access to what you are reading.') : '' ?>>Skip to main content</a>
<?php if ($u): ?>
<?php
$appSidebarShell = true;
require_once __DIR__ . '/admin_nav.php';
$navRole = (string) ($u['role'] ?? '');
$topbarProfilePhotoUrl = profile_photo_url((int) ($u['id'] ?? 0));
?>
<div class="admin-layout d-flex min-vh-100 w-100">
    <aside class="admin-sidebar d-none d-lg-flex flex-column flex-shrink-0 no-print" aria-label="Main navigation">
        <div class="admin-sidebar-brand">
            <a class="admin-sidebar-brand-link" href="dashboard.php"<?= $appCursorTooltips ? app_tooltip_attr('Returns to your dashboard home. Use this when you want a quick way back from another page.') : '' ?>>
                <span class="admin-sidebar-brand-icon"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i></span>
                <span class="admin-sidebar-brand-text">WPU SABLAe Portal</span>
            </a>
            <div class="admin-sidebar-brand-meta"><?= htmlspecialchars(app_sidebar_brand_meta($navRole)) ?></div>
        </div>
        <nav class="admin-sidebar-nav flex-grow-1" aria-label="Primary sections">
            <?php if ($navRole === 'admin'): ?>
                <?php render_admin_nav_sections($adminNavPage, $messagingNavUnread); ?>
            <?php elseif ($navRole === 'super_admin'): ?>
                <?php render_super_admin_nav_sections($adminNavPage, $messagingNavUnread); ?>
            <?php else: ?>
                <?php render_role_nav_sections($navRole, $adminNavPage, $messagingNavUnread); ?>
            <?php endif; ?>
        </nav>
        <div class="admin-sidebar-footer">
            <a class="admin-sidebar-foot-link" href="settings.php"<?= $appCursorTooltips ? app_tooltip_attr('Opens account settings where you can change your password. Same as Settings in the top menu.') : '' ?>><i class="fa-solid fa-gear me-2" aria-hidden="true"></i>Settings</a>
            <a class="admin-sidebar-foot-link admin-sidebar-logout" href="logout.php"<?= $appCursorTooltips ? app_tooltip_attr('Signs you out of the system. Use when you are done or when someone else needs this device.') : '' ?>><i class="fa-solid fa-right-from-bracket me-2" aria-hidden="true"></i>Logout</a>
        </div>
    </aside>
    <div class="offcanvas offcanvas-start d-lg-none admin-offcanvas-nav no-print" tabindex="-1" id="adminNavOffcanvas" aria-labelledby="adminNavOffcanvasLabel">
        <div class="offcanvas-header border-bottom">
            <div>
                <div class="fw-semibold" id="adminNavOffcanvasLabel">Menu</div>
                <div class="small text-muted text-uppercase"><?= htmlspecialchars($navRole) ?></div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close menu"<?= $appCursorTooltips ? app_tooltip_attr('Closes the slide-out navigation menu. Use this after you choose a page or if you opened the menu by mistake.') : '' ?>></button>
        </div>
        <div class="offcanvas-body p-0 d-flex flex-column">
            <nav class="admin-sidebar-nav flex-grow-1 px-2 pb-3" aria-label="Primary sections">
                <?php if ($navRole === 'admin'): ?>
                    <?php render_admin_nav_sections($adminNavPage, $messagingNavUnread, true); ?>
                <?php elseif ($navRole === 'super_admin'): ?>
                    <?php render_super_admin_nav_sections($adminNavPage, $messagingNavUnread, true); ?>
                <?php else: ?>
                    <?php render_role_nav_sections($navRole, $adminNavPage, $messagingNavUnread, true); ?>
                <?php endif; ?>
            </nav>
            <div class="admin-sidebar-footer border-top px-3 py-3 mt-auto">
                <a class="admin-sidebar-foot-link d-block py-1" href="settings.php" data-bs-dismiss="offcanvas"<?= $appCursorTooltips ? app_tooltip_attr('Opens account settings where you can change your password. The mobile menu closes when you tap this.') : '' ?>><i class="fa-solid fa-gear me-2" aria-hidden="true"></i>Settings</a>
                <a class="admin-sidebar-foot-link admin-sidebar-logout d-block py-1" href="logout.php" data-bs-dismiss="offcanvas"<?= $appCursorTooltips ? app_tooltip_attr('Signs you out. The mobile menu closes when you tap this.') : '' ?>><i class="fa-solid fa-right-from-bracket me-2" aria-hidden="true"></i>Logout</a>
            </div>
        </div>
    </div>
    <div class="admin-main-wrap flex-grow-1 d-flex flex-column min-vh-100 min-w-0">
        <header class="admin-topbar border-bottom bg-body shadow-sm sticky-top no-print">
            <div class="d-flex align-items-center gap-3 px-3 px-lg-4 py-2 py-lg-3">
                <button class="btn btn-outline-secondary btn-sm d-lg-none rounded-3" type="button" data-bs-toggle="offcanvas" data-bs-target="#adminNavOffcanvas" aria-controls="adminNavOffcanvas" aria-label="Open navigation menu"<?= $appCursorTooltips ? app_tooltip_attr('Opens the navigation menu on phones and small screens. Use this when the left sidebar is hidden to reach Dashboard, Messages, schedules, and other app sections.') : '' ?>>
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="flex-grow-1 min-w-0">
                    <div class="admin-topbar-title text-truncate"><?= htmlspecialchars($pageTitle) ?></div>
                    <div class="admin-topbar-subtitle small text-muted text-truncate d-none d-sm-block">Western Philippines University</div>
                </div>
                <button type="button" class="btn app-theme-toggle align-items-center gap-1 d-inline-flex no-print" id="appThemeToggle" aria-label="Switch color theme" title="Switch color theme" aria-pressed="false"<?= $appCursorTooltips ? app_tooltip_attr('Switches between dark and light appearance for the whole app. Your choice is remembered on this device.') : '' ?>>
                    <i class="fa-solid fa-moon app-theme-icon-dark" aria-hidden="true"></i>
                    <i class="fa-solid fa-sun app-theme-icon-light d-none" aria-hidden="true"></i>
                    <span class="small" id="appThemeToggleLabel">Dark</span>
                </button>
                <div class="dropdown admin-topbar-user">
                    <button class="btn btn-outline-secondary rounded-pill d-flex align-items-center gap-2 py-1 px-2 px-sm-3" type="button" id="adminUserMenuBtn" data-bs-toggle="dropdown" aria-expanded="false" aria-haspopup="true"<?= $appCursorTooltips ? app_tooltip_attr('Opens your account menu for Settings and Logout. Use this to change your password or sign out when you are done.') : '' ?>>
                        <?php app_render_topbar_avatar($navRole, $topbarProfilePhotoUrl); ?>
                        <span class="d-none d-md-inline text-start lh-sm">
                            <span class="d-block small fw-semibold text-truncate" style="max-width: 10rem;"><?= htmlspecialchars($u['full_name']) ?></span>
                            <span class="d-block small text-muted text-uppercase" style="font-size: 0.65rem;"><?= htmlspecialchars($navRole) ?></span>
                        </span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 rounded-3 py-2" aria-labelledby="adminUserMenuBtn" style="min-width: 12rem;">
                        <li><a class="dropdown-item rounded-2" href="settings.php"<?= $appCursorTooltips ? app_tooltip_attr('Opens account settings where you can change your password. Use this to keep your login secure on shared or public devices.') : '' ?>><i class="fa-solid fa-gear me-2 text-muted"></i>Settings</a></li>
                        <li><hr class="dropdown-divider my-1"></li>
                        <li><a class="dropdown-item rounded-2 text-danger" href="logout.php"<?= $appCursorTooltips ? app_tooltip_attr('Signs you out of the scheduling system on this browser. Use this when you finish on a shared computer or switch accounts.') : '' ?>><i class="fa-solid fa-right-from-bracket me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </header>
<?php endif; ?>
<main id="main-content" class="<?= htmlspecialchars(trim($mainContainerClass . ($appSidebarShell ? ' flex-grow-1' : '')), ENT_QUOTES, 'UTF-8') ?>" tabindex="-1">

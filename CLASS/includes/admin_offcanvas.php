<?php
declare(strict_types=1);

/**
 * Mobile navigation drawer (pure CSS toggle, no JavaScript required).
 * The hidden checkbox #adminNavToggle is flipped by <label> controls
 * (the topbar hamburger, the backdrop, and the close button), and CSS
 * sibling selectors slide the panel in/out. Rendered at body level.
 */
?>
<input type="checkbox" id="adminNavToggle" class="admin-nav-toggle-input no-print" tabindex="-1" aria-hidden="true">
<label for="adminNavToggle" class="admin-offcanvas-backdrop no-print" aria-label="Close navigation menu"></label>
<div class="admin-offcanvas-nav no-print" id="adminNavOffcanvas" aria-label="Mobile navigation">
    <div class="offcanvas-header border-bottom d-flex align-items-center justify-content-between">
        <div>
            <div class="fw-semibold" id="adminNavOffcanvasLabel">Menu</div>
            <div class="small text-muted text-uppercase"><?= htmlspecialchars($navRole) ?></div>
        </div>
        <label for="adminNavToggle" class="btn-close" role="button" tabindex="0" aria-label="Close menu"<?= $appCursorTooltips ? app_tooltip_attr('Closes the slide-out navigation menu. Use this after you choose a page or if you opened the menu by mistake.') : '' ?>></label>
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
            <a class="admin-sidebar-foot-link d-block py-1" href="settings.php"<?= $appCursorTooltips ? app_tooltip_attr('Opens account settings where you can change your password.') : '' ?>><i class="fa-solid fa-gear me-2" aria-hidden="true"></i>Settings</a>
            <a class="admin-sidebar-foot-link admin-sidebar-logout d-block py-1" href="logout.php"<?= $appCursorTooltips ? app_tooltip_attr('Signs you out.') : '' ?>><i class="fa-solid fa-right-from-bracket me-2" aria-hidden="true"></i>Logout</a>
        </div>
    </div>
</div>

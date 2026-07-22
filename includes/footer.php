</main>
<?php
$appSidebarShell = $appSidebarShell ?? false;
if ($appSidebarShell):
    ?>
    </div>
</div>
<?php
    require __DIR__ . '/admin_offcanvas.php';
endif;
?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/theme-toggle.js" defer></script>
<?php if (!empty($appCursorTooltips ?? false)): ?>
<script src="assets/js/app_tooltips.js" defer></script>
<?php endif; ?>
<?php if (!empty($footerScripts) && is_array($footerScripts)): ?>
    <?php foreach ($footerScripts as $footerScript): ?>
        <?php if (is_string($footerScript) && $footerScript !== ''): ?>
<script src="<?= htmlspecialchars($footerScript) ?>"></script>
        <?php endif; ?>
    <?php endforeach; ?>
<?php endif; ?>
</body>
</html>

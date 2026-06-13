</main>
<?php
$appSidebarShell = $appSidebarShell ?? false;
if ($appSidebarShell):
    ?>
    </div>
</div>
<?php
endif;
?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/theme-toggle.js" defer></script>
<?php if (!empty($appCursorTooltips ?? false)): ?>
<script src="assets/js/app_tooltips.js" defer></script>
<?php endif; ?>
</body>
</html>

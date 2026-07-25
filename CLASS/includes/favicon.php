<?php
declare(strict_types=1);

$faviconVersion = @filemtime(__DIR__ . '/../assets/wpu-logo.png') ?: 1;
?>
<link rel="icon" href="assets/wpu-logo.png?v=<?= (int) $faviconVersion ?>" type="image/png">
<link rel="apple-touch-icon" href="assets/wpu-logo.png?v=<?= (int) $faviconVersion ?>">

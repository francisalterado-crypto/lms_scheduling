<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_role(['student']);

$pageTitle = 'Wellness companion';
$mainContainerClass = 'container-fluid px-3 px-lg-4 py-3 py-md-4 flex-grow-1 student-wellness-page';
require_once __DIR__ . '/includes/header.php';
?>
<div class="mb-3 student-page-header">
    <h1 class="h4 mb-1">
        <i class="fa-solid fa-heart-pulse me-2 text-success"></i>Wellness companion
    </h1>
    <p class="text-muted small mb-0">
        Empathic support for stress, anxiety, loneliness, and low mood—English or Filipino.
        Not a substitute for therapy or emergency services.
    </p>
</div>

<?php require_once __DIR__ . '/includes/student_wellness_ui.php'; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

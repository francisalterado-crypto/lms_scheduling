<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_role(['student']);

$studentId = isset($_SESSION['student_id']) ? (int) $_SESSION['student_id'] : 0;
$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($studentId < 1) {
    $studentId = resolve_student_id_for_user($userId) ?? 0;
    $_SESSION['student_id'] = $studentId > 0 ? $studentId : null;
}

$pageTitle = 'Offline reading';
require_once __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4 student-page-header">
    <div class="min-w-0">
        <h1 class="h3 mb-1"><i class="fa-solid fa-cloud-arrow-down me-2 text-primary"></i>Offline reading</h1>
        <p class="text-muted small mb-0">Click a course box to see saved items, then choose one to review offline.</p>
    </div>
    <div class="d-flex flex-wrap gap-2 student-page-header__actions">
        <button type="button" class="btn btn-primary btn-sm px-3" data-offline-save=""<?= student_tooltip_attr('Downloads all faculty posts from your enrolled classes and stores them on this device for offline reading.') ?>>
            <i class="fa-solid fa-download me-1"></i>Save all classes
        </button>
        <a href="student_classrooms.php" class="btn btn-outline-secondary btn-sm px-3"<?= student_tooltip_attr('Returns to your enrolled class list.') ?>>My classes</a>
    </div>
</div>

<p class="small mb-3" data-offline-status="auto">Checking saved offline copy…</p>

<div data-offline-reader></div>

<script src="assets/js/student_offline.js?v=2" defer></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/system_evaluation.php';

require_role(['faculty']);

$userId = (int) ($_SESSION['user_id'] ?? 0);
$role = 'faculty';
$termLabel = system_evaluation_current_term();
$tableReady = system_evaluation_tables_ready();

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tableReady) {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action !== 'save_evaluation') {
            throw new RuntimeException('Unknown action.');
        }

        $ratings = [];
        foreach (array_keys(system_evaluation_questions($role)) as $key) {
            $ratings[$key] = (int) ($_POST['rating_' . $key] ?? 0);
        }
        $comments = (string) ($_POST['comments'] ?? '');
        system_evaluation_save($userId, $role, $termLabel, $ratings, $comments);
        $_SESSION['flash'] = 'Thank you. Your system evaluation has been saved.';
    } catch (Throwable $e) {
        $_SESSION['flash'] = 'Error: ' . $e->getMessage();
    }
    header('Location: faculty_system_evaluation.php');
    exit;
}

$existing = $tableReady ? system_evaluation_get_response($userId, $role, $termLabel) : null;
$answers = $existing ? system_evaluation_get_answers((int) $existing['id']) : [];
$comments = $existing ? (string) ($existing['comments'] ?? '') : '';

$pageTitle = 'System evaluation';
require_once __DIR__ . '/includes/header.php';
?>
<div class="mb-3">
    <h1 class="h4 mb-1">
        <i class="fa-solid fa-clipboard-check me-2 text-primary"></i>System evaluation
    </h1>
    <p class="text-muted small mb-0">
        Share how well the portal supports your teaching. One response per school year; you can update it anytime.
    </p>
</div>

<?php if ($flash): ?><?php render_information_popup((string) $flash); ?><?php endif; ?>

<?php if (!$tableReady): ?>
    <div class="alert alert-warning" style="max-width: 760px;">
        Evaluation is not available yet. Ask an administrator to run <code>upgrade_roles.php</code> once.
    </div>
<?php else: ?>
    <?php system_evaluation_render_form($role, $termLabel, $answers, $comments, $existing !== null); ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/system_evaluation.php';

require_role(['admin', 'super_admin']);

$tableReady = system_evaluation_tables_ready();
$terms = $tableReady ? system_evaluation_available_terms() : [system_evaluation_current_term()];
$currentTerm = system_evaluation_current_term();

$termFilter = trim((string) ($_GET['term'] ?? $currentTerm));
if ($termFilter !== '' && !in_array($termFilter, $terms, true)) {
    $terms[] = $termFilter;
}
$roleFilter = trim((string) ($_GET['role'] ?? ''));
if (!in_array($roleFilter, ['', 'student', 'faculty'], true)) {
    $roleFilter = '';
}

$summary = $tableReady
    ? system_evaluation_summary($termFilter !== '' ? $termFilter : null, $roleFilter !== '' ? $roleFilter : null)
    : [
        'total' => 0,
        'student_count' => 0,
        'faculty_count' => 0,
        'avg_overall' => null,
        'by_question' => [],
    ];
$responses = $tableReady
    ? system_evaluation_list_responses($termFilter !== '' ? $termFilter : null, $roleFilter !== '' ? $roleFilter : null, 150)
    : [];
$labels = system_evaluation_rating_labels();

$pageTitle = 'System evaluations';
require_once __DIR__ . '/includes/header.php';
?>
<div class="mb-3 d-flex flex-wrap align-items-end justify-content-between gap-2">
    <div>
        <h1 class="h4 mb-1">
            <i class="fa-solid fa-clipboard-check me-2 text-primary"></i>System evaluations
        </h1>
        <p class="text-muted small mb-0">
            Student and faculty feedback on portal usability and usefulness.
        </p>
    </div>
</div>

<?php if (!$tableReady): ?>
    <div class="alert alert-warning">
        Tables are missing. Open <a href="upgrade_roles.php" class="alert-link">upgrade_roles.php</a> once to create them.
    </div>
<?php else: ?>

<form method="get" class="row g-2 align-items-end mb-4" style="max-width: 720px;">
    <div class="col-sm-5">
        <label for="term" class="form-label small mb-1">School year</label>
        <select class="form-select form-select-sm" id="term" name="term">
            <?php foreach ($terms as $term): ?>
                <option value="<?= htmlspecialchars($term, ENT_QUOTES, 'UTF-8') ?>"
                    <?= $termFilter === $term ? 'selected' : '' ?>>
                    <?= htmlspecialchars($term, ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-sm-4">
        <label for="role" class="form-label small mb-1">Role</label>
        <select class="form-select form-select-sm" id="role" name="role">
            <option value="" <?= $roleFilter === '' ? 'selected' : '' ?>>All</option>
            <option value="student" <?= $roleFilter === 'student' ? 'selected' : '' ?>>Students</option>
            <option value="faculty" <?= $roleFilter === 'faculty' ? 'selected' : '' ?>>Faculty</option>
        </select>
    </div>
    <div class="col-sm-3">
        <button type="submit" class="btn btn-sm btn-primary w-100">Apply</button>
    </div>
</form>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="border rounded p-3 h-100">
            <div class="text-muted small">Responses</div>
            <div class="fs-4 fw-semibold"><?= (int) $summary['total'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="border rounded p-3 h-100">
            <div class="text-muted small">Students</div>
            <div class="fs-4 fw-semibold"><?= (int) $summary['student_count'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="border rounded p-3 h-100">
            <div class="text-muted small">Faculty</div>
            <div class="fs-4 fw-semibold"><?= (int) $summary['faculty_count'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="border rounded p-3 h-100">
            <div class="text-muted small">Avg. overall</div>
            <div class="fs-4 fw-semibold">
                <?= $summary['avg_overall'] !== null ? htmlspecialchars((string) $summary['avg_overall'], ENT_QUOTES, 'UTF-8') : '—' ?>
                <?php if ($summary['avg_overall'] !== null): ?>
                    <span class="fs-6 text-muted">/ 5</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<h2 class="h6 mb-2">Average by question</h2>
<?php if ($summary['by_question'] === []): ?>
    <p class="text-muted small">No ratings yet for this filter.</p>
<?php else: ?>
    <div class="table-responsive mb-4">
        <table class="table table-sm table-hover align-middle">
            <thead>
            <tr>
                <th>Role</th>
                <th>Question</th>
                <th class="text-end">Avg</th>
                <th class="text-end">n</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($summary['by_question'] as $item): ?>
                <?php if ((int) $item['count'] < 1) {
                    continue;
                } ?>
                <tr>
                    <td class="text-capitalize"><?= htmlspecialchars((string) $item['role'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) $item['label'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="text-end"><?= htmlspecialchars((string) $item['avg'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="text-end"><?= (int) $item['count'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<h2 class="h6 mb-2">Recent submissions</h2>
<?php if ($responses === []): ?>
    <p class="text-muted small">No submissions yet.</p>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
            <thead>
            <tr>
                <th>Name</th>
                <th>Role</th>
                <th>Term</th>
                <th class="text-end">Overall</th>
                <th>Comments</th>
                <th>Updated</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($responses as $row): ?>
                <?php
                $overall = (int) ($row['overall_rating'] ?? 0);
                $overallLabel = $labels[$overall] ?? '';
                $comment = trim((string) ($row['comments'] ?? ''));
                ?>
                <tr>
                    <td>
                        <?= htmlspecialchars((string) ($row['full_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        <div class="small text-muted"><?= htmlspecialchars((string) ($row['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                    </td>
                    <td class="text-capitalize"><?= htmlspecialchars((string) ($row['role'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) ($row['term_label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="text-end">
                        <?= $overall ?>
                        <?php if ($overallLabel !== ''): ?>
                            <span class="text-muted small">(<?= htmlspecialchars($overallLabel, ENT_QUOTES, 'UTF-8') ?>)</span>
                        <?php endif; ?>
                    </td>
                    <td style="max-width: 280px;">
                        <?php if ($comment === ''): ?>
                            <span class="text-muted">—</span>
                        <?php else: ?>
                            <?= htmlspecialchars($comment, ENT_QUOTES, 'UTF-8') ?>
                        <?php endif; ?>
                    </td>
                    <td class="small text-nowrap"><?= htmlspecialchars((string) ($row['updated_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

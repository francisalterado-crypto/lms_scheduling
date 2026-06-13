<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/student_registration_helpers.php';

require_role(['program_chair']);
$collegeId = dean_or_program_chair_college_id_or_fail();
$programScope = program_scope_or_fail();
$collegeName = college_name_by_id($collegeId);

if (!student_registration_table_ready()) {
    exit('Run upgrade_roles.php first to enable student registration approvals.');
}

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $requestId = (int) ($_POST['request_id'] ?? 0);
    try {
        if ($action === 'approve' && $requestId > 0) {
            $approval = approve_student_registration_request(
                $requestId,
                (int) $_SESSION['user_id'],
                $collegeId,
                $programScope
            );
            if (!empty($approval['already_approved'])) {
                $_SESSION['flash'] = 'This registration was already approved. The student can sign in with their existing account.';
            } else {
                log_dean_activity(
                    'student_registration_approve',
                    'Approved student registration for program ' . $programScope . ' (request #' . $requestId . ')'
                );
                $studentEmail = trim((string) ($approval['email'] ?? ''));
                if ($studentEmail !== '' && filter_var($studentEmail, FILTER_VALIDATE_EMAIL)) {
                    $_SESSION['flash'] = !empty($approval['mail_sent'])
                        ? 'Registration approved. Temporary password emailed to ' . $studentEmail . '.'
                        : 'Registration approved, but the email could not be sent. Temporary password: '
                            . (string) ($approval['temp_password'] ?? '');
                } else {
                    $_SESSION['flash'] = 'Registration approved. No email on file — share credentials manually. Temporary password: '
                        . (string) ($approval['temp_password'] ?? '');
                }
            }
        } elseif ($action === 'reject' && $requestId > 0) {
            $reason = trim((string) ($_POST['rejection_reason'] ?? ''));
            reject_student_registration_request(
                $requestId,
                (int) $_SESSION['user_id'],
                $collegeId,
                $programScope,
                $reason
            );
            log_dean_activity(
                'student_registration_reject',
                'Rejected student registration for program ' . $programScope . ' (request #' . $requestId . ')'
            );
            $_SESSION['flash'] = 'Registration request rejected.';
        } else {
            throw new RuntimeException('Invalid action.');
        }
    } catch (Throwable $e) {
        $_SESSION['flash'] = 'Error: ' . $e->getMessage();
    }
    header('Location: program_chair_student_registrations.php');
    exit;
}

$pending = pending_registrations_for_program($collegeId, $programScope);

$pageTitle = 'Student registration approvals';
$mainContainerClass = 'container-fluid py-4 py-md-5 app-main';
require_once __DIR__ . '/includes/header.php';
?>
<div class="container" style="max-width: 1100px;">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Student registration approvals</h1>
            <p class="text-muted mb-0">
                <?= htmlspecialchars($collegeName) ?> — <?= htmlspecialchars($programScope) ?>
            </p>
        </div>
        <a href="program_chair_students.php" class="btn btn-outline-secondary">
            <i class="fa-solid fa-user-graduate me-1"></i> Manage students
        </a>
    </div>

    <?php if ($flash !== ''): ?>
        <div class="alert <?= str_starts_with((string) $flash, 'Error:') ? 'alert-danger' : 'alert-success' ?> rounded-3">
            <?= htmlspecialchars($flash) ?>
        </div>
    <?php endif; ?>

    <?php if ($pending === []): ?>
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body text-center py-5 text-muted">
                <i class="fa-solid fa-inbox fa-2x mb-3 d-block opacity-50"></i>
                No pending student registrations for your program.
            </div>
        </div>
    <?php else: ?>
        <div class="card border-0 shadow-sm rounded-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Student</th>
                            <th>Username</th>
                            <th>Student #</th>
                            <th>Year</th>
                            <th>Email</th>
                            <th>Submitted</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending as $row): ?>
                            <tr>
                                <td class="fw-semibold"><?= htmlspecialchars((string) $row['full_name']) ?></td>
                                <td><code><?= htmlspecialchars((string) $row['username']) ?></code></td>
                                <td><?= htmlspecialchars((string) $row['student_number']) ?></td>
                                <td><?= htmlspecialchars((string) ($row['year_level'] ?? '')) ?: '—' ?></td>
                                <td><?= htmlspecialchars((string) ($row['email'] ?? '')) ?: '—' ?></td>
                                <td class="text-muted small"><?= htmlspecialchars((string) ($row['created_at'] ?? '')) ?></td>
                                <td class="text-end text-nowrap">
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="request_id" value="<?= (int) $row['id'] ?>">
                                        <button type="submit" class="btn btn-success btn-sm"
                                                onclick="return confirm('Approve this student registration? They will receive an email with a temporary password to sign in.');">
                                            <i class="fa-solid fa-check me-1"></i> Approve
                                        </button>
                                    </form>
                                    <button type="button" class="btn btn-outline-danger btn-sm ms-1"
                                            data-bs-toggle="modal" data-bs-target="#rejectModal<?= (int) $row['id'] ?>">
                                        <i class="fa-solid fa-xmark me-1"></i> Reject
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php foreach ($pending as $row): ?>
            <div class="modal fade" id="rejectModal<?= (int) $row['id'] ?>" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content rounded-4">
                        <form method="post">
                            <input type="hidden" name="action" value="reject">
                            <input type="hidden" name="request_id" value="<?= (int) $row['id'] ?>">
                            <div class="modal-header">
                                <h5 class="modal-title">Reject registration</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <p>Reject registration for <strong><?= htmlspecialchars((string) $row['full_name']) ?></strong>?</p>
                                <label class="form-label" for="reason<?= (int) $row['id'] ?>">Reason (optional)</label>
                                <textarea class="form-control" id="reason<?= (int) $row['id'] ?>" name="rejection_reason" rows="3"
                                          placeholder="e.g. Wrong program or invalid student number"></textarea>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-danger">Reject</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

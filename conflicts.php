<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/admin_activity_log.php';

require_role(['admin', 'dean', 'program_chair', 'gened', 'faculty']);
$role = (string) ($_SESSION['role'] ?? '');
$collegeId = current_college_id();
$programScope = is_program_chair() ? program_scope_or_fail() : null;
$userId = (int) ($_SESSION['user_id'] ?? 0);
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

if ($role === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_id'])) {
    $rid = (int) $_POST['request_id'];
    $action = (string) ($_POST['action'] ?? '');
    $remarks = trim((string) ($_POST['remarks'] ?? ''));
    $stmt = db()->prepare('SELECT * FROM conflict_requests WHERE id=? AND status="pending"');
    $stmt->execute([$rid]);
    $req = $stmt->fetch();
    if ($req) {
        if ($action === 'approve') {
            $beforeReq = [
                'id' => (int) $req['id'],
                'status' => (string) $req['status'],
                'college_id' => (int) $req['college_id'],
                'faculty_id' => (int) $req['faculty_id'],
                'course_id' => (int) $req['course_id'],
                'room_id' => (int) $req['room_id'],
                'semester' => (string) $req['semester'],
                'school_year' => (string) $req['school_year'],
            ];
            $ins = db()->prepare(
                'INSERT INTO schedules (faculty_id, course_id, room_id, college_id, schedule_type, day_of_week, start_time, end_time, semester, school_year, academic_year, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $ins->execute([
                (int) $req['faculty_id'],
                (int) $req['course_id'],
                (int) $req['room_id'],
                (int) $req['college_id'],
                $req['schedule_type'],
                $req['day_of_week'],
                $req['start_time'],
                $req['end_time'],
                $req['semester'],
                $req['school_year'],
                $req['academic_year'],
                (int) $req['requested_by'],
            ]);
            $newId = (int) db()->lastInsertId();
            db()->prepare('UPDATE conflict_requests SET status="approved", admin_remarks=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?')
                ->execute([$remarks, $userId, $rid]);
            log_admin_activity(
                'edit',
                'Conflict requests',
                'Override request #' . $rid,
                $beforeReq,
                [
                    'id' => $rid,
                    'status' => 'approved',
                    'admin_remarks' => $remarks,
                    'reviewed_by' => $userId,
                    'created_schedule_id' => $newId,
                ]
            );
            $stSched = db()->prepare(
                'SELECT s.id, s.faculty_id, s.course_id, s.room_id, s.college_id, s.semester, s.school_year,
                        s.day_of_week, s.start_time, s.end_time, s.schedule_type,
                        f.full_name AS faculty_name, c.course_code, r.room_code
                 FROM schedules s
                 INNER JOIN faculty f ON f.id = s.faculty_id
                 INNER JOIN courses c ON c.id = s.course_id
                 INNER JOIN rooms r ON r.id = s.room_id
                 WHERE s.id = ? LIMIT 1'
            );
            $stSched->execute([$newId]);
            $schedRow = $stSched->fetch(PDO::FETCH_ASSOC);
            log_admin_activity(
                'add',
                'Schedules',
                'Schedule #' . $newId . ' (approved override request #' . $rid . ')',
                null,
                $schedRow ? (array) $schedRow : ['id' => $newId, 'note' => 'Row fetch after insert failed']
            );
            $_SESSION['flash'] = 'Override request approved and schedule #' . $newId . ' was created.';
        } elseif ($action === 'reject') {
            $beforeReq = [
                'id' => (int) $req['id'],
                'status' => (string) $req['status'],
                'college_id' => (int) $req['college_id'],
                'faculty_id' => (int) $req['faculty_id'],
                'course_id' => (int) $req['course_id'],
                'room_id' => (int) $req['room_id'],
            ];
            db()->prepare('UPDATE conflict_requests SET status="rejected", admin_remarks=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?')
                ->execute([$remarks, $userId, $rid]);
            log_admin_activity(
                'edit',
                'Conflict requests',
                'Override request #' . $rid,
                $beforeReq,
                [
                    'id' => $rid,
                    'status' => 'rejected',
                    'admin_remarks' => $remarks,
                    'reviewed_by' => $userId,
                ]
            );
            $_SESSION['flash'] = 'Override request rejected.';
        }
    }
    header('Location: conflicts.php');
    exit;
}

if (($role === 'dean' || $role === 'program_chair') && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['scr_id'])) {
    $sid = (int) $_POST['scr_id'];
    $action = (string) ($_POST['action'] ?? '');
    $remarks = trim((string) ($_POST['remarks'] ?? ''));
    $status = $action === 'approve' ? 'approved' : 'rejected';
    $sqlFetchScr = 'SELECT scr.id, scr.schedule_id, scr.faculty_user_id, scr.status, scr.message
         FROM schedule_change_requests scr
         INNER JOIN schedules s ON s.id = scr.schedule_id
         INNER JOIN courses c ON c.id = s.course_id
         WHERE scr.id=? AND scr.status="pending" AND s.college_id=?';
    $parFetchScr = [$sid, $collegeId];
    if ($programScope !== null) {
        $sqlFetchScr .= ' AND c.department=?';
        $parFetchScr[] = $programScope;
    }
    $sqlFetchScr .= ' LIMIT 1';
    $stScrB = db()->prepare($sqlFetchScr);
    $stScrB->execute($parFetchScr);
    $beforeScr = $stScrB->fetch(PDO::FETCH_ASSOC);
    $sql = 'UPDATE schedule_change_requests scr
         INNER JOIN schedules s ON s.id = scr.schedule_id
         INNER JOIN courses c ON c.id = s.course_id
         SET scr.status=?, scr.dean_remarks=?, scr.reviewed_by=?, scr.reviewed_at=NOW()
         WHERE scr.id=? AND scr.status="pending" AND s.college_id=?';
    $params = [$status, $remarks, $userId, $sid, $collegeId];
    if ($programScope !== null) {
        $sql .= ' AND c.department=?';
        $params[] = $programScope;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    if ($beforeScr && $stmt->rowCount() > 0) {
        log_user_activity(
            'edit',
            'Schedule change requests',
            'Request #' . $sid,
            (array) $beforeScr,
            [
                'id' => $sid,
                'status' => $status,
                'dean_remarks' => $remarks,
                'reviewed_by' => $userId,
            ]
        );
    }
    log_dean_activity('schedule_change_review', "Reviewed faculty schedule change request #{$sid}: {$status}");
    $_SESSION['flash'] = 'Schedule change request updated.';
    header('Location: conflicts.php');
    exit;
}

$pendingRequests = [];
$myRequests = [];
$changeRequests = [];
$legacyLogs = [];

if ($role === 'admin') {
    $pendingRequests = db()->query(
        'SELECT cr.*, u.full_name AS dean_name, c.college_name, f.full_name AS faculty_name, co.course_code, r.room_code
         FROM conflict_requests cr
         INNER JOIN users u ON u.id = cr.requested_by
         INNER JOIN colleges c ON c.id = cr.college_id
         INNER JOIN faculty f ON f.id = cr.faculty_id
         INNER JOIN courses co ON co.id = cr.course_id
         INNER JOIN rooms r ON r.id = cr.room_id
         WHERE cr.status="pending"
         ORDER BY cr.created_at ASC'
    )->fetchAll();
} elseif (($role === 'dean' || $role === 'program_chair') && $collegeId) {
    if ($role === 'dean') {
        $st = db()->prepare(
            'SELECT cr.*, c.college_name
             FROM conflict_requests cr
             INNER JOIN colleges c ON c.id = cr.college_id
             WHERE cr.college_id=?
             ORDER BY cr.created_at DESC'
        );
        $st->execute([$collegeId]);
        $myRequests = $st->fetchAll();
    }

    $sql = 'SELECT scr.*, f.full_name AS faculty_name, co.course_code
         FROM schedule_change_requests scr
         INNER JOIN schedules s ON s.id = scr.schedule_id
         INNER JOIN faculty f ON f.user_id = scr.faculty_user_id
         INNER JOIN courses co ON co.id = s.course_id
         WHERE s.college_id=? AND scr.status="pending"';
    $params = [$collegeId];
    if ($programScope !== null) {
        $sql .= ' AND co.department=?';
        $params[] = $programScope;
    }
    $sql .= ' ORDER BY scr.created_at DESC';
    $st = db()->prepare($sql);
    $st->execute($params);
    $changeRequests = $st->fetchAll();
} elseif ($role === 'faculty') {
    $st = db()->prepare('SELECT * FROM schedule_change_requests WHERE faculty_user_id=? ORDER BY created_at DESC');
    $st->execute([$userId]);
    $myRequests = $st->fetchAll();
}

$legacyLogs = db()->query('SELECT * FROM conflict_logs ORDER BY created_at DESC LIMIT 100')->fetchAll();

$pageTitle = 'Conflicts and Requests';
require_once __DIR__ . '/includes/header.php';
?>
<h1 class="h3 mb-4"><i class="fa-solid fa-triangle-exclamation me-2 text-warning"></i>Conflicts and Requests</h1>

<?php if ($flash): ?>
    <div class="alert alert-success alert-dismissible fade show no-print">
        <?= htmlspecialchars($flash) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"<?= app_tooltip_attr('Dismisses this confirmation after you have read it.') ?>></button>
    </div>
<?php endif; ?>

<?php if ($role === 'admin'): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white"><strong>Pending Admin Override Requests</strong></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead class="table-light"><tr><th>College</th><th>Dean</th><th>Schedule</th><th>Reason</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php if (!$pendingRequests): ?><tr><td colspan="5" class="p-3 text-muted">No pending requests.</td></tr><?php endif; ?>
                    <?php foreach ($pendingRequests as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['college_name']) ?></td>
                            <td><?= htmlspecialchars($r['dean_name']) ?></td>
                            <td><?= htmlspecialchars($r['course_code']) ?> / <?= htmlspecialchars($r['faculty_name']) ?> / <?= htmlspecialchars($r['room_code']) ?> (<?= htmlspecialchars(substr($r['start_time'], 0, 5)) ?>-<?= htmlspecialchars(substr($r['end_time'], 0, 5)) ?>)</td>
                            <td><?= htmlspecialchars($r['reason']) ?></td>
                            <td>
                                <form method="post" class="d-flex gap-1">
                                    <input type="hidden" name="request_id" value="<?= (int) $r['id'] ?>">
                                    <input type="text" name="remarks" class="form-control form-control-sm" placeholder="Remarks">
                                    <button name="action" value="approve" class="btn btn-sm btn-success"<?= app_tooltip_attr('Approves the override and creates the schedule row. Use optional remarks for the audit trail.') ?>>Approve</button>
                                    <button name="action" value="reject" class="btn btn-sm btn-danger"<?= app_tooltip_attr('Rejects the override request without creating a schedule. Use remarks to explain the decision.') ?>>Reject</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($role === 'dean' || $role === 'program_chair'): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white"><strong>Faculty Schedule Change Requests</strong></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead class="table-light"><tr><th>Faculty</th><th>Message</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php if (!$changeRequests): ?><tr><td colspan="3" class="p-3 text-muted">No pending change requests.</td></tr><?php endif; ?>
                    <?php foreach ($changeRequests as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['faculty_name']) ?></td>
                            <td><?= htmlspecialchars($r['message']) ?></td>
                            <td>
                                <form method="post" class="d-flex gap-1">
                                    <input type="hidden" name="scr_id" value="<?= (int) $r['id'] ?>">
                                    <input type="text" name="remarks" class="form-control form-control-sm" placeholder="Remarks">
                                    <button name="action" value="approve" class="btn btn-sm btn-success"<?= app_tooltip_attr('Approves the faculty member’s schedule change. Remarks are stored for documentation.') ?>>Approve</button>
                                    <button name="action" value="reject" class="btn btn-sm btn-danger"<?= app_tooltip_attr('Rejects the change request so the original schedule stays. Use remarks to tell faculty why.') ?>>Reject</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white"><strong><?= $role === 'admin' ? 'Legacy Conflict Logs' : 'My Requests / Logs' ?></strong></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead class="table-light"><tr><th>Created</th><th>Type</th><th>Description</th><th>Status</th></tr></thead>
                <tbody>
                <?php if ($myRequests): ?>
                    <?php foreach ($myRequests as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $r['created_at']) ?></td>
                            <td>Request</td>
                            <td><?= htmlspecialchars((string) ($r['reason'] ?? $r['message'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) $r['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php foreach ($legacyLogs as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) $r['created_at']) ?></td>
                        <td><?= htmlspecialchars((string) $r['conflict_type']) ?></td>
                        <td><?= htmlspecialchars((string) $r['conflict_description']) ?></td>
                        <td><?= (int) $r['resolved'] === 1 ? 'Resolved' : 'Open' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$myRequests && !$legacyLogs): ?>
                    <tr><td colspan="4" class="p-3 text-muted">No entries.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

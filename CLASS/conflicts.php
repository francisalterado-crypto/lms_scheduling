<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/makeup_helpers.php';
if (is_file(__DIR__ . '/includes/admin_activity_log.php')) {
    require_once __DIR__ . '/includes/admin_activity_log.php';
} elseif (is_file(dirname(__DIR__) . '/includes/admin_activity_log.php')) {
    require_once dirname(__DIR__) . '/includes/admin_activity_log.php';
}

require_role(['admin', 'dean', 'program_chair', 'gened', 'faculty']);
$role = (string) ($_SESSION['role'] ?? '');
$collegeId = current_college_id();
$programScope = is_program_chair() ? program_scope_or_fail() : null;
$userId = (int) ($_SESSION['user_id'] ?? 0);
$flash = $_SESSION['flash'] ?? '';
$flashType = (string) ($_SESSION['flash_type'] ?? 'success');
unset($_SESSION['flash'], $_SESSION['flash_type']);
$makeupWarningPopup = [];
if (!empty($_SESSION['makeup_warning_popup']) && is_array($_SESSION['makeup_warning_popup'])) {
    $makeupWarningPopup = array_values(array_filter(array_map('strval', $_SESSION['makeup_warning_popup'])));
}
unset($_SESSION['makeup_warning_popup']);

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
    if ($action === 'approve' || $action === 'approve_anyway') {
        $status = 'approved';
    } elseif ($action === 'reject') {
        $status = 'rejected';
    } else {
        $_SESSION['flash'] = 'Unknown action.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: conflicts.php');
        exit;
    }
    $hasMakeupSupport = ensure_makeup_schedule_support();
    $sqlFetchScr = 'SELECT scr.*
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

    if (!$beforeScr) {
        $_SESSION['flash'] = 'Request not found or already reviewed.';
        header('Location: conflicts.php');
        exit;
    }

    $requestType = $hasMakeupSupport ? (string) ($beforeScr['request_type'] ?? 'change') : 'change';
    $createdMakeupId = 0;
    $forceMakeup = $action === 'approve_anyway';

    if (($action === 'approve' || $forceMakeup) && $requestType === 'makeup') {
        $status = 'approved';
        $stBase = db()->prepare('SELECT * FROM schedules WHERE id=? LIMIT 1');
        $stBase->execute([(int) $beforeScr['schedule_id']]);
        $baseSchedule = $stBase->fetch(PDO::FETCH_ASSOC) ?: [];
        $created = create_makeup_schedule_from_request($beforeScr, $baseSchedule, $userId, $forceMakeup);
        if (empty($created['ok'])) {
            $msg = (string) ($created['error'] ?? 'Could not create makeup schedule.');
            $descriptions = [];
            if (!empty($created['conflict_messages']) && is_array($created['conflict_messages'])) {
                $descriptions = array_values(array_filter(array_map('strval', $created['conflict_messages'])));
            } elseif (!empty($created['conflicts']) && is_array($created['conflicts'])) {
                foreach ($created['conflicts'] as $c) {
                    if (is_array($c) && isset($c['description'])) {
                        $descriptions[] = (string) $c['description'];
                    } elseif (is_string($c)) {
                        $descriptions[] = $c;
                    }
                }
            }
            if ($descriptions !== []) {
                $_SESSION['makeup_warning_popup'] = $descriptions;
                if (!str_contains($msg, $descriptions[0])) {
                    $msg .= ' ' . implode(' ', $descriptions);
                }
            }
            $_SESSION['flash'] = $msg;
            $_SESSION['flash_type'] = 'danger';
            header('Location: conflicts.php');
            exit;
        }
        $createdMakeupId = (int) ($created['schedule_id'] ?? 0);
        if ($remarks === '') {
            $remarks = 'Makeup schedule #' . $createdMakeupId . ' created.';
        } else {
            $remarks .= ' (Makeup schedule #' . $createdMakeupId . ' created.)';
        }
    } elseif ($forceMakeup) {
        $_SESSION['flash'] = '“Approve anyway” is only for makeup requests.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: conflicts.php');
        exit;
    }

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
            [
                'id' => (int) $beforeScr['id'],
                'schedule_id' => (int) $beforeScr['schedule_id'],
                'faculty_user_id' => (int) $beforeScr['faculty_user_id'],
                'status' => (string) $beforeScr['status'],
                'message' => (string) $beforeScr['message'],
                'request_type' => $requestType,
            ],
            [
                'id' => $sid,
                'status' => $status,
                'dean_remarks' => $remarks,
                'reviewed_by' => $userId,
                'created_makeup_schedule_id' => $createdMakeupId ?: null,
            ]
        );
        if ($createdMakeupId > 0) {
            log_user_activity(
                'add',
                'Schedules',
                'Makeup schedule #' . $createdMakeupId . ' (request #' . $sid . ')',
                null,
                ['id' => $createdMakeupId, 'is_makeup' => 1, 'from_request' => $sid]
            );
        }
    }
    log_dean_activity('schedule_change_review', "Reviewed faculty schedule change request #{$sid}: {$status}");
    $_SESSION['flash'] = $createdMakeupId > 0
        ? 'Makeup request approved. Schedule #' . $createdMakeupId . ' was created. Delete it after the makeup class is held.'
        : 'Schedule change request updated.';
    $_SESSION['flash_type'] = 'success';
    header('Location: conflicts.php');
    exit;
}

$pendingRequests = [];
$myRequests = [];
$changeRequests = [];
$legacyLogs = [];
$hasMakeupSupport = ensure_makeup_schedule_support();

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

    if ($hasMakeupSupport) {
        $sql = 'SELECT scr.*, f.full_name AS faculty_name, co.course_code, r.room_code AS proposed_room_code
             FROM schedule_change_requests scr
             INNER JOIN schedules s ON s.id = scr.schedule_id
             INNER JOIN faculty f ON f.user_id = scr.faculty_user_id
             INNER JOIN courses co ON co.id = s.course_id
             LEFT JOIN rooms r ON r.id = scr.proposed_room_id
             WHERE s.college_id=? AND scr.status="pending"';
    } else {
        $sql = 'SELECT scr.*, f.full_name AS faculty_name, co.course_code, NULL AS proposed_room_code
             FROM schedule_change_requests scr
             INNER JOIN schedules s ON s.id = scr.schedule_id
             INNER JOIN faculty f ON f.user_id = scr.faculty_user_id
             INNER JOIN courses co ON co.id = s.course_id
             WHERE s.college_id=? AND scr.status="pending"';
    }
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

<?php if ($flash): ?><?php render_information_popup((string) $flash); ?><?php endif; ?>

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
        <div class="card-header bg-white"><strong>Faculty Schedule Change / Makeup Requests</strong></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead class="table-light"><tr><th>Faculty</th><th>Course</th><th>Type</th><th>Details</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php if (!$changeRequests): ?><tr><td colspan="5" class="p-3 text-muted">No pending change requests.</td></tr><?php endif; ?>
                    <?php foreach ($changeRequests as $r): ?>
                        <?php
                        $rtype = $hasMakeupSupport ? (string) ($r['request_type'] ?? 'change') : 'change';
                        $isMakeup = $rtype === 'makeup';
                        $detailParts = [(string) $r['message']];
                        $makeupConflictMsgs = [];
                        if ($isMakeup) {
                            $day = str_replace(',', ', ', (string) ($r['proposed_day_of_week'] ?? ''));
                            $st = substr((string) ($r['proposed_start_time'] ?? ''), 0, 5);
                            $en = substr((string) ($r['proposed_end_time'] ?? ''), 0, 5);
                            $room = (string) ($r['proposed_room_code'] ?? '');
                            $slot = trim($day . ' ' . $st . ($st !== '' && $en !== '' ? '–' . $en : '') . ($room !== '' ? ' @ ' . $room : ''));
                            if ($slot !== '') {
                                array_unshift($detailParts, 'Proposed: ' . $slot);
                            }
                            $stBase = db()->prepare('SELECT faculty_id, college_id, semester, school_year FROM schedules WHERE id=? LIMIT 1');
                            $stBase->execute([(int) $r['schedule_id']]);
                            $base = $stBase->fetch(PDO::FETCH_ASSOC) ?: [];
                            if ($base) {
                                $makeupConflictMsgs = makeup_hard_conflict_messages(
                                    (int) $base['faculty_id'],
                                    (int) ($r['proposed_room_id'] ?? 0) ?: 0,
                                    parse_day_set((string) ($r['proposed_day_of_week'] ?? '')),
                                    substr((string) ($r['proposed_start_time'] ?? ''), 0, 5) . ':00',
                                    substr((string) ($r['proposed_end_time'] ?? ''), 0, 5) . ':00',
                                    (string) $base['semester'],
                                    (string) $base['school_year'],
                                    isset($base['college_id']) ? (int) $base['college_id'] : null
                                );
                            }
                            if ($makeupConflictMsgs !== []) {
                                $detailParts[] = 'Conflicts: ' . implode(' | ', $makeupConflictMsgs);
                            }
                        }
                        $conflictConfirm = $makeupConflictMsgs !== []
                            ? ('Warning — This makeup conflicts with faculty or room schedule:\n\n- '
                                . implode("\n- ", $makeupConflictMsgs)
                                . "\n\nClick OK to try Approve (it will be blocked), or use Approve anyway to create it anyway.")
                            : '';
                        ?>
                        <tr<?= $makeupConflictMsgs !== [] ? ' class="table-warning"' : '' ?>>
                            <td><?= htmlspecialchars((string) $r['faculty_name']) ?></td>
                            <td><?= htmlspecialchars((string) $r['course_code']) ?></td>
                            <td>
                                <?php if ($isMakeup): ?>
                                    <span class="badge bg-warning text-dark">Makeup</span>
                                    <?php if ($makeupConflictMsgs !== []): ?>
                                        <span class="badge bg-danger">Conflict</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Change</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= htmlspecialchars(implode(' — ', $detailParts)) ?>
                                <?php if ($makeupConflictMsgs !== []): ?>
                                    <ul class="small text-danger mb-0 mt-1">
                                        <?php foreach ($makeupConflictMsgs as $cm): ?>
                                            <li><?= htmlspecialchars($cm) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="post" class="d-flex flex-wrap gap-1 makeup-review-form"<?= $conflictConfirm !== '' ? ' data-conflict-warning="' . htmlspecialchars($conflictConfirm, ENT_QUOTES) . '"' : '' ?>>
                                    <input type="hidden" name="scr_id" value="<?= (int) $r['id'] ?>">
                                    <input type="text" name="remarks" class="form-control form-control-sm" placeholder="Remarks" style="min-width:8rem">
                                    <button name="action" value="approve" class="btn btn-sm btn-success js-makeup-approve"<?= app_tooltip_attr($isMakeup ? 'Approves the makeup request and creates a temporary schedule slot. Delete that slot after the makeup class is held.' : 'Approves the faculty member’s schedule change. Remarks are stored for documentation.') ?>>Approve</button>
                                    <?php if ($isMakeup): ?>
                                        <button name="action" value="approve_anyway" class="btn btn-sm btn-outline-warning js-makeup-approve-anyway"<?= app_tooltip_attr('Creates the makeup schedule even if faculty/room conflicts exist. Use only when you intentionally allow the overlap.') ?>>Approve anyway</button>
                                    <?php endif; ?>
                                    <button name="action" value="reject" class="btn btn-sm btn-danger"<?= app_tooltip_attr('Rejects the request so no schedule change is applied. Use remarks to tell faculty why.') ?>>Reject</button>
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
                            <td><?= htmlspecialchars(
                                $hasMakeupSupport && (($r['request_type'] ?? '') === 'makeup')
                                    ? 'Makeup'
                                    : ((isset($r['message']) ? 'Schedule request' : 'Request'))
                            ) ?></td>
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

<?php if ($makeupWarningPopup !== []): ?>
    <?php
    render_schedule_errors_warning_popup(
        $makeupWarningPopup,
        'Warning — Makeup conflicts with faculty or room schedule',
        'OK'
    );
    ?>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form.makeup-review-form').forEach(function (form) {
        form.addEventListener('click', function (event) {
            var btn = event.target.closest('button[name="action"]');
            if (!btn || !form.contains(btn)) return;
            var warning = form.getAttribute('data-conflict-warning') || '';
            if (!warning) return;

            if (btn.value === 'approve') {
                event.preventDefault();
                window.alert(warning);
                return;
            }
            if (btn.value === 'approve_anyway') {
                var ok = window.confirm(
                    'Warning — This makeup conflicts with faculty or room schedule.\n\nCreate the makeup schedule anyway?'
                );
                if (!ok) {
                    event.preventDefault();
                }
            }
        });
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

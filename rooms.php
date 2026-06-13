<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/admin_activity_log.php';

require_role(['dean']);
$collegeId = dean_college_id_or_fail();
$collegeName = college_name_by_id($collegeId);

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$roomTypes = ['lecture', 'laboratory', 'conference', 'tba'];
$roomStatuses = ['available', 'tba', 'maintenance'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        $type = strtolower(trim((string) ($_POST['type'] ?? 'lecture')));
        $status = strtolower(trim((string) ($_POST['status'] ?? 'available')));
        if (!in_array($type, $roomTypes, true)) {
            $type = 'lecture';
        }
        if (!in_array($status, $roomStatuses, true)) {
            $status = 'available';
        }
        if ($action === 'add') {
            $useCustomCode = isset($_POST['custom_room_code']) && (string) $_POST['custom_room_code'] === '1';
            if ($useCustomCode) {
                $roomCode = trim((string) ($_POST['room_code'] ?? ''));
                if ($roomCode === '') {
                    throw new RuntimeException('Enter a room code, or leave Custom code unchecked for auto-generated code.');
                }
            } else {
                $roomCode = next_auto_room_code_for_college($collegeId, $collegeName);
            }
            if (room_code_taken_for_college($roomCode, $collegeId)) {
                throw new RuntimeException(
                    'Room code "' . $roomCode . '" is already used in your college. Pick a different code.'
                );
            }
            $stmt = db()->prepare(
                'INSERT INTO rooms (room_code, room_name, capacity, type, status, college_id) VALUES (?,?,?,?,?,?)'
            );
            $stmt->execute([
                $roomCode,
                trim((string) $_POST['room_name']),
                (int) $_POST['capacity'],
                $type,
                $status,
                $collegeId,
            ]);
            $newRid = (int) db()->lastInsertId();
            $stR = db()->prepare('SELECT * FROM rooms WHERE id = ? LIMIT 1');
            $stR->execute([$newRid]);
            $afterR = $stR->fetch(PDO::FETCH_ASSOC);
            log_user_activity('add', 'Rooms', 'Room #' . $newRid, null, $afterR ? (array) $afterR : null);
            log_dean_activity('room_create', 'Created room ' . $roomCode);
            $_SESSION['flash'] = 'Room added.';
        } elseif ($action === 'edit' && isset($_POST['id'])) {
            $roomId = (int) $_POST['id'];
            $stRb = db()->prepare('SELECT * FROM rooms WHERE id=? AND college_id=? LIMIT 1');
            $stRb->execute([$roomId, $collegeId]);
            $beforeR = $stRb->fetch(PDO::FETCH_ASSOC);
            $roomCode = trim((string) $_POST['room_code']);
            if (room_code_taken_for_college($roomCode, $collegeId, $roomId)) {
                throw new RuntimeException(
                    'Room code "' . $roomCode . '" is already used in your college. Pick a different code.'
                );
            }
            $stmt = db()->prepare(
                'UPDATE rooms SET room_code=?, room_name=?, capacity=?, type=?, status=? WHERE id=? AND college_id=?'
            );
            $stmt->execute([
                $roomCode,
                trim((string) $_POST['room_name']),
                (int) $_POST['capacity'],
                $type,
                $status,
                $roomId,
                $collegeId,
            ]);
            $stRa = db()->prepare('SELECT * FROM rooms WHERE id = ? LIMIT 1');
            $stRa->execute([$roomId]);
            $afterR = $stRa->fetch(PDO::FETCH_ASSOC);
            log_user_activity('edit', 'Rooms', 'Room #' . $roomId, $beforeR ? (array) $beforeR : null, $afterR ? (array) $afterR : null);
            log_dean_activity('room_update', 'Updated room ID #' . $roomId);
            $_SESSION['flash'] = 'Room updated.';
        } elseif ($action === 'delete' && isset($_POST['id'])) {
            $ridDel = (int) $_POST['id'];
            $stRd = db()->prepare('SELECT * FROM rooms WHERE id=? AND college_id=? LIMIT 1');
            $stRd->execute([$ridDel, $collegeId]);
            $beforeDelR = $stRd->fetch(PDO::FETCH_ASSOC);
            $stmt = db()->prepare('DELETE FROM rooms WHERE id=? AND college_id=?');
            $stmt->execute([$ridDel, $collegeId]);
            log_user_activity('delete', 'Rooms', 'Room #' . $ridDel, $beforeDelR ? (array) $beforeDelR : null, null);
            log_dean_activity('room_delete', 'Deleted room ID #' . $ridDel);
            $_SESSION['flash'] = 'Room removed.';
        }
    } catch (Throwable $e) {
        $_SESSION['flash'] = 'Error: ' . $e->getMessage();
    }
    header('Location: rooms.php');
    exit;
}

$editRow = null;
if (isset($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM rooms WHERE id=? AND college_id=?');
    $st->execute([(int) $_GET['edit'], $collegeId]);
    $editRow = $st->fetch() ?: null;
}

$st = db()->prepare('SELECT * FROM rooms WHERE college_id=? ORDER BY room_code ASC');
$st->execute([$collegeId]);
$list = $st->fetchAll();

$nextAutoRoomCode = null;
if (!$editRow) {
    try {
        $nextAutoRoomCode = next_auto_room_code_for_college($collegeId, $collegeName);
    } catch (Throwable) {
        $nextAutoRoomCode = null;
    }
}

$pageTitle = 'Rooms';
require_once __DIR__ . '/includes/header.php';
?>
<h1 class="h3 mb-4"><i class="fa-solid fa-door-open me-2 text-primary"></i>Rooms</h1>
<p class="text-muted">Managing: <strong><?= htmlspecialchars($collegeName) ?></strong></p>

<?php if ($flash): ?>
    <div class="alert alert-info alert-dismissible fade show no-print">
        <?= htmlspecialchars($flash) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"<?= app_tooltip_attr('Dismisses this status message after you have read it.') ?>></button>
    </div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white">
        <strong><?= $editRow ? 'Edit room' : 'Add room' ?></strong>
    </div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <input type="hidden" name="action" value="<?= $editRow ? 'edit' : 'add' ?>">
            <?php if ($editRow): ?>
                <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
            <?php endif; ?>
            <div class="col-md-3">
                <label class="form-label">Code</label>
                <?php if (!$editRow): ?>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="custom_room_code" value="1" id="customRoomCode">
                        <label class="form-check-label small" for="customRoomCode">Custom code</label>
                    </div>
                    <input type="text" name="room_code" id="roomCodeInput" class="form-control" maxlength="20"
                           value="" placeholder="Auto if unchecked" autocomplete="off" disabled>
                    <?php if ($nextAutoRoomCode !== null): ?>
                        <div class="form-text small" id="autoCodeHint">Next auto code: <strong><?= htmlspecialchars($nextAutoRoomCode) ?></strong></div>
                    <?php endif; ?>
                <?php else: ?>
                    <input type="text" name="room_code" class="form-control" required maxlength="20"
                           value="<?= htmlspecialchars($editRow['room_code'] ?? '') ?>">
                <?php endif; ?>
            </div>
            <div class="col-md-3">
                <label class="form-label">Name</label>
                <input type="text" name="room_name" class="form-control" maxlength="50"
                       value="<?= htmlspecialchars($editRow['room_name'] ?? '') ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Capacity</label>
                <input type="number" name="capacity" class="form-control" min="0" max="500"
                       value="<?= (int) ($editRow['capacity'] ?? 0) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Type</label>
                <select name="type" class="form-select">
                    <?php foreach ($roomTypes as $t): ?>
                        <?php $label = $t === 'tba' ? 'TBA (To be arranged)' : ucfirst($t); ?>
                        <option value="<?= $t ?>" <?= ($editRow['type'] ?? 'lecture') === $t ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="available" <?= ($editRow['status'] ?? '') === 'available' ? 'selected' : '' ?>>Available</option>
                    <option value="tba" <?= ($editRow['status'] ?? '') === 'tba' ? 'selected' : '' ?>>TBA (To be arranged)</option>
                    <option value="maintenance" <?= ($editRow['status'] ?? '') === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                </select>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary"<?= app_tooltip_attr($editRow ? 'Saves changes to this room. Use this after editing code, capacity, type, or status.' : 'Adds the room to your college inventory. Use this so scheduling and auto-assign can pick it.') ?>><i class="fa-solid fa-floppy-disk me-1"></i>Save</button>
                <?php if ($editRow): ?>
                    <a href="rooms.php" class="btn btn-outline-secondary"<?= app_tooltip_attr('Closes edit mode without saving further changes.') ?>>Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Capacity</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th class="no-print"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($list as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['room_code']) ?></td>
                            <td><?= htmlspecialchars($r['room_name']) ?></td>
                            <td><?= (int) $r['capacity'] ?></td>
                            <td><?= htmlspecialchars($r['type']) ?></td>
                            <td>
                                <?php $badge = $r['status'] === 'available' ? 'success' : ($r['status'] === 'tba' ? 'secondary' : 'warning'); ?>
                                <span class="badge bg-<?= $badge ?>"><?= htmlspecialchars($r['status']) ?></span>
                            </td>
                            <td class="no-print text-nowrap">
                                <a href="rooms.php?edit=<?= (int) $r['id'] ?>" class="btn btn-sm btn-outline-primary"<?= app_tooltip_attr('Edits this room’s code, capacity, or availability. Use this when the physical space changes.') ?>><i class="fa-solid fa-pen"></i></a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this room?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"<?= app_tooltip_attr('Deletes this room after confirmation. Use only if no schedules should reference it.') ?>><i class="fa-solid fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if (!$editRow): ?>
<script>
(function () {
    var cb = document.getElementById('customRoomCode');
    var inp = document.getElementById('roomCodeInput');
    if (!cb || !inp) return;
    cb.addEventListener('change', function () {
        inp.disabled = !cb.checked;
        inp.required = cb.checked;
        if (!cb.checked) inp.value = '';
    });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

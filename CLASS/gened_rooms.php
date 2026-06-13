<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_role(['gened']);
$hasIsGened = db_column_exists('rooms', 'is_gened');
$roomTypes = ['lecture', 'laboratory', 'conference', 'tba'];
$roomStatuses = ['available', 'tba', 'maintenance'];
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        $type = strtolower(trim((string) ($_POST['type'] ?? 'lecture')));
        $status = strtolower(trim((string) ($_POST['status'] ?? 'available')));
        if (!in_array($type, $roomTypes, true)) {
            $type = 'lecture';
        }
        if (!in_array($status, $roomStatuses, true)) {
            $status = 'available';
        }
        if (!$hasIsGened) {
            throw new RuntimeException('Run upgrade_roles.php first to enable GE rooms.');
        }
        if ($action === 'add') {
            $useCustomCode = isset($_POST['custom_room_code']) && (string) $_POST['custom_room_code'] === '1';
            if ($useCustomCode) {
                $roomCode = trim((string) ($_POST['room_code'] ?? ''));
                if ($roomCode === '') {
                    throw new RuntimeException('Enter a room code, or leave Custom code unchecked for auto-generated code.');
                }
            } else {
                $roomCode = next_auto_room_code_gened();
            }
            if (room_code_taken_for_gened($roomCode)) {
                throw new RuntimeException(
                    'Room code "' . $roomCode . '" is already used for another Gen Ed room. Pick a different code.'
                );
            }
            db()->prepare(
                'INSERT INTO rooms (room_code, room_name, capacity, type, status, is_gened, college_id) VALUES (?,?,?,?,?,1,NULL)'
            )->execute([
                $roomCode,
                trim((string) $_POST['room_name']),
                (int) $_POST['capacity'],
                $type,
                $status,
            ]);
            $_SESSION['flash'] = 'GE room added.';
        } elseif ($action === 'edit' && isset($_POST['id'])) {
            $roomId = (int) $_POST['id'];
            $roomCode = trim((string) $_POST['room_code']);
            if (room_code_taken_for_gened($roomCode, $roomId)) {
                throw new RuntimeException(
                    'Room code "' . $roomCode . '" is already used for another Gen Ed room. Pick a different code.'
                );
            }
            db()->prepare(
                'UPDATE rooms SET room_code=?, room_name=?, capacity=?, type=?, status=? WHERE id=? AND is_gened=1'
            )->execute([
                $roomCode,
                trim((string) $_POST['room_name']),
                (int) $_POST['capacity'],
                $type,
                $status,
                $roomId,
            ]);
            $_SESSION['flash'] = 'GE room updated.';
        } elseif ($action === 'delete' && isset($_POST['id'])) {
            db()->prepare('DELETE FROM rooms WHERE id=? AND is_gened=1')->execute([(int) $_POST['id']]);
            $_SESSION['flash'] = 'GE room removed.';
        }
    } catch (Throwable $e) {
        $_SESSION['flash'] = 'Error: ' . $e->getMessage();
    }
    header('Location: gened_rooms.php');
    exit;
}

$editRow = null;
if (isset($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM rooms WHERE id=? AND is_gened=1');
    $st->execute([(int) $_GET['edit']]);
    $editRow = $st->fetch() ?: null;
}

$list = db()->query('SELECT * FROM rooms WHERE is_gened=1 ORDER BY room_code ASC')->fetchAll();

$nextAutoGenedCode = null;
if (!$editRow && $hasIsGened) {
    try {
        $nextAutoGenedCode = next_auto_room_code_gened();
    } catch (Throwable) {
        $nextAutoGenedCode = null;
    }
}

$pageTitle = 'GE Rooms';
require_once __DIR__ . '/includes/header.php';
?>
<h1 class="h3 mb-4"><i class="fa-solid fa-door-open me-2 text-primary"></i>Gen Ed Rooms</h1>
<?php if ($flash): ?><div class="alert alert-info"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
<?php if (!$hasIsGened): ?><div class="alert alert-warning">Run <a href="upgrade_roles.php">upgrade_roles.php</a> to enable GE rooms.</div><?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white"><strong><?= $editRow ? 'Edit GE room' : 'Add GE room' ?></strong></div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <input type="hidden" name="action" value="<?= $editRow ? 'edit' : 'add' ?>">
            <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>"><?php endif; ?>
            <div class="col-md-3">
                <label class="form-label">Code</label>
                <?php if (!$editRow): ?>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="custom_room_code" value="1" id="customGenedRoomCode" <?= !$hasIsGened ? 'disabled' : '' ?>>
                        <label class="form-check-label small" for="customGenedRoomCode">Custom code</label>
                    </div>
                    <input type="text" name="room_code" id="genedRoomCodeInput" class="form-control" maxlength="20" value="" placeholder="Auto if unchecked" autocomplete="off" disabled>
                    <?php if ($nextAutoGenedCode !== null): ?>
                        <div class="form-text small" id="autoGenedCodeHint">Next auto code: <strong><?= htmlspecialchars($nextAutoGenedCode) ?></strong></div>
                    <?php endif; ?>
                <?php else: ?>
                    <input type="text" name="room_code" class="form-control" required maxlength="20" value="<?= htmlspecialchars($editRow['room_code'] ?? '') ?>">
                <?php endif; ?>
            </div>
            <div class="col-md-3"><label class="form-label">Name</label><input type="text" name="room_name" class="form-control" maxlength="50" value="<?= htmlspecialchars($editRow['room_name'] ?? '') ?>"></div>
            <div class="col-md-2"><label class="form-label">Capacity</label><input type="number" name="capacity" class="form-control" min="0" max="500" value="<?= (int) ($editRow['capacity'] ?? 0) ?>"></div>
            <div class="col-md-2"><label class="form-label">Type</label><select name="type" class="form-select"><?php foreach ($roomTypes as $t): ?><?php $label = $t === 'tba' ? 'TBA (To be arranged)' : ucfirst($t); ?><option value="<?= $t ?>" <?= ($editRow['type'] ?? 'lecture') === $t ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label">Status</label><select name="status" class="form-select"><option value="available" <?= ($editRow['status'] ?? '') === 'available' ? 'selected' : '' ?>>Available</option><option value="tba" <?= ($editRow['status'] ?? '') === 'tba' ? 'selected' : '' ?>>TBA</option><option value="maintenance" <?= ($editRow['status'] ?? '') === 'maintenance' ? 'selected' : '' ?>>Maintenance</option></select></div>
            <div class="col-12"><button type="submit" class="btn btn-primary"<?= app_tooltip_attr($editRow ? 'Saves changes to this GE room.' : 'Adds a room usable for GE scheduling.') ?>>Save</button><?php if ($editRow): ?> <a href="gened_rooms.php" class="btn btn-outline-secondary"<?= app_tooltip_attr('Closes the editor without saving.') ?>>Cancel</a><?php endif; ?></div>
        </form>
    </div>
</div>

<div class="card shadow-sm"><div class="table-responsive"><table class="table table-striped mb-0">
    <thead class="table-light"><tr><th>Code</th><th>Name</th><th>Capacity</th><th>Type</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($list as $r): ?>
        <tr>
            <td><?= htmlspecialchars($r['room_code']) ?></td>
            <td><?= htmlspecialchars($r['room_name']) ?></td>
            <td><?= (int) $r['capacity'] ?></td>
            <td><?= htmlspecialchars($r['type']) ?></td>
            <td><?= htmlspecialchars($r['status']) ?></td>
            <td class="text-nowrap">
                <a href="gened_rooms.php?edit=<?= (int) $r['id'] ?>" class="btn btn-sm btn-outline-primary"<?= app_tooltip_attr('Edits this GE room’s code, capacity, or status.') ?>>Edit</a>
                <form method="post" class="d-inline" onsubmit="return confirm('Delete this GE room?');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger"<?= app_tooltip_attr('Removes this GE room after confirmation.') ?>>Delete</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div>

<?php if (!$editRow && $hasIsGened): ?>
<script>
(function () {
    var cb = document.getElementById('customGenedRoomCode');
    var inp = document.getElementById('genedRoomCodeInput');
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

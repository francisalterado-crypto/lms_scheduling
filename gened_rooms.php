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
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700&display=swap">
<style>
    .ge-rooms-page * { box-sizing: border-box; }
    .ge-rooms-page {
        background: linear-gradient(135deg, #f0f4fa 0%, #e9eef4 100%);
        font-family: 'Inter', sans-serif;
        padding: 2rem 1.5rem;
        border-radius: 20px;
        color: #1a2c3e;
    }
    .dashboard-container { max-width: 1400px; margin: 0 auto; }
    .header-section { display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; margin-bottom: 2rem; gap: 1rem; }
    .title-badge h1 {
        font-size: 1.9rem; font-weight: 700; background: linear-gradient(135deg, #1f3b4c, #2a5f6e);
        background-clip: text; -webkit-background-clip: text; color: transparent; letter-spacing: -0.3px;
        display: inline-flex; align-items: center; gap: 12px; margin: 0;
    }
    .title-badge h1 i { color: #2c7a6e; font-size: 2rem; }
    .sub { font-size: 0.9rem; color: #5b6f82; margin-top: 6px; font-weight: 500; display: flex; align-items: center; gap: 8px; }
    .auto-code-card {
        background: rgba(255, 255, 255, 0.8); padding: 0.6rem 1.2rem; border-radius: 60px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.02), 0 1px 2px rgba(0, 0, 0, 0.05); font-size: 0.85rem;
        font-weight: 500; display: flex; align-items: center; gap: 12px; border: 1px solid rgba(72, 187, 150, 0.3);
    }
    .auto-code-card span:first-of-type { color: #3a5e6b; }
    .next-code {
        background: #eef2f7; padding: 0.2rem 0.8rem; border-radius: 40px; font-family: monospace;
        font-weight: 700; color: #1c6e5c; letter-spacing: 0.5px;
    }
    .form-card {
        background: rgba(255, 255, 255, 0.96); border-radius: 32px; box-shadow: 0 20px 35px -12px rgba(0, 0, 0, 0.08), 0 1px 3px rgba(0, 0, 0, 0.02);
        padding: 1.8rem 2rem; margin-bottom: 2.5rem; border: 1px solid rgba(166, 194, 187, 0.3);
    }
    .form-title {
        font-size: 1.3rem; font-weight: 600; margin-bottom: 1.6rem; display: flex; align-items: center;
        gap: 10px; color: #1f4e5c; border-left: 4px solid #2c9b7e; padding-left: 1rem;
    }
    .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
    .input-group { display: flex; flex-direction: column; gap: 8px; }
    .input-group label {
        font-weight: 600; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px;
        color: #4b6a7c; display: flex; align-items: center; gap: 6px;
    }
    .input-group input, .input-group select {
        padding: 0.85rem 1rem; border-radius: 20px; border: 1.5px solid #e2e8f0; background: #fefefe;
        font-family: 'Inter', sans-serif; font-size: 0.95rem; transition: all 0.2s; outline: none; font-weight: 500;
    }
    .input-group input:focus, .input-group select:focus { border-color: #2c9b7e; box-shadow: 0 0 0 3px rgba(44, 155, 126, 0.2); }
    .input-group input:disabled { background: #eef2f7; color: #607283; cursor: not-allowed; }
    .checkbox-row { flex-direction: row; align-items: center; gap: 10px; margin-top: 1.8rem; }
    .checkbox-row label { text-transform: none; font-weight: 500; font-size: 0.85rem; margin: 0; }
    .checkbox-row input[type="checkbox"] { width: 1rem; height: 1rem; margin: 0; accent-color: #2c9b7e; }
    .btn-save {
        background: #1f5e5a; border: none; padding: 0.9rem 2rem; border-radius: 40px; font-weight: 600; font-size: 0.95rem; color: white;
        display: inline-flex; align-items: center; gap: 12px; cursor: pointer; transition: all 0.2s; box-shadow: 0 4px 8px rgba(0,0,0,0.05);
        text-decoration: none;
    }
    .btn-save:hover { background: #134e48; transform: translateY(-1px); box-shadow: 0 10px 20px -8px rgba(28, 78, 65, 0.4); color: #fff; }
    .btn-cancel { background: #cddfda; color: #2b5e4f; }
    .btn-cancel:hover { background: #b6d2ca; color: #244e43; }
    .table-wrapper {
        background: white; border-radius: 28px; box-shadow: 0 12px 30px rgba(0, 0, 0, 0.05);
        overflow-x: auto; padding: 0 0 0.2rem; border: 1px solid #eef2f0;
    }
    .rooms-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; min-width: 680px; }
    .rooms-table th {
        text-align: left; padding: 1.2rem 1rem; background-color: #f9fbfd; font-weight: 600; color: #2b5e6b;
        border-bottom: 1.5px solid #e2ede8; font-size: 0.85rem; letter-spacing: 0.3px;
    }
    .rooms-table td { padding: 1rem; border-bottom: 1px solid #edf2f1; vertical-align: middle; font-weight: 500; color: #1f3e48; }
    .status-badge {
        display: inline-flex; align-items: center; gap: 6px; padding: 0.25rem 0.75rem; border-radius: 40px;
        font-size: 0.75rem; font-weight: 600; width: fit-content;
    }
    .status-badge.available { background: #dff3e6; color: #1c6e46; }
    .status-badge.maintenance { background: #ffe6e3; color: #b13e3e; }
    .status-badge.tba { background: #eef2f7; color: #516575; }
    .action-btns { display: inline-flex; align-items: center; gap: 10px; }
    .edit-btn, .delete-btn {
        border: none; font-size: 0.9rem; cursor: pointer; padding: 6px 10px; border-radius: 40px;
        transition: all 0.15s; font-weight: 500; display: inline-flex; align-items: center; gap: 6px; text-decoration: none;
    }
    .edit-btn { color: #2c7a6e; background: #eef8f5; }
    .edit-btn:hover { background: #d4f0e8; color: #205e55; }
    .delete-btn { color: #c25b5b; background: #fff3f0; }
    .delete-btn:hover { background: #ffe0db; color: #a03e3e; }
    .empty-row td { text-align: center; padding: 2.5rem; color: #8ea3af; font-style: italic; }
    .ge-inline-alert {
        margin: 0 auto 1rem; max-width: 1400px; background: rgba(255, 255, 255, 0.95); border: 1px solid #dbe6eb;
        color: #29404b; border-radius: 16px; padding: 0.75rem 1rem;
    }
    .ge-inline-alert.warning { border-color: #f4d9a5; color: #7b5a1f; background: #fff8ec; }
    html[data-bs-theme="dark"] .ge-rooms-page {
        background: linear-gradient(135deg, #111c25 0%, #162632 100%);
        color: #dbe8f2;
    }
    html[data-bs-theme="dark"] .title-badge h1 {
        background: linear-gradient(135deg, #d9edf7, #9cd4e3);
        background-clip: text;
        -webkit-background-clip: text;
        color: transparent;
    }
    html[data-bs-theme="dark"] .title-badge h1 i { color: #62bea8; }
    html[data-bs-theme="dark"] .sub { color: #9eb3c2; }
    html[data-bs-theme="dark"] .auto-code-card {
        background: rgba(20, 35, 46, 0.82);
        border-color: rgba(98, 190, 168, 0.35);
        color: #d6e7f2;
        box-shadow: 0 8px 18px rgba(0, 0, 0, 0.25);
    }
    html[data-bs-theme="dark"] .auto-code-card span:first-of-type { color: #9cc2d5; }
    html[data-bs-theme="dark"] .next-code {
        background: #243746;
        color: #8de5c9;
    }
    html[data-bs-theme="dark"] .form-card {
        background: rgba(19, 33, 43, 0.96);
        border-color: rgba(117, 151, 163, 0.28);
        box-shadow: 0 16px 30px rgba(0, 0, 0, 0.28);
    }
    html[data-bs-theme="dark"] .form-title { color: #b9dbe7; border-left-color: #3bb495; }
    html[data-bs-theme="dark"] .input-group label { color: #9cb6c6; }
    html[data-bs-theme="dark"] .input-group input,
    html[data-bs-theme="dark"] .input-group select {
        background: #111d27;
        border-color: #2f4454;
        color: #dbe8f2;
    }
    html[data-bs-theme="dark"] .input-group input::placeholder { color: #8ba1b0; }
    html[data-bs-theme="dark"] .input-group input:disabled { background: #223341; color: #98afbf; }
    html[data-bs-theme="dark"] .btn-save { background: #1c7467; color: #ecfffa; }
    html[data-bs-theme="dark"] .btn-save:hover { background: #165d53; color: #ecfffa; }
    html[data-bs-theme="dark"] .btn-cancel { background: #344a57; color: #d9e9f3; }
    html[data-bs-theme="dark"] .btn-cancel:hover { background: #425c6b; color: #ebf7ff; }
    html[data-bs-theme="dark"] .table-wrapper {
        background: #16242f;
        border-color: #2a3d4b;
        box-shadow: 0 14px 30px rgba(0, 0, 0, 0.25);
    }
    html[data-bs-theme="dark"] .rooms-table th {
        background: #1b2e3b;
        color: #b9d4e3;
        border-bottom-color: #2f4858;
    }
    html[data-bs-theme="dark"] .rooms-table td {
        color: #d4e5ef;
        border-bottom-color: #263a49;
    }
    html[data-bs-theme="dark"] .status-badge.available { background: #1f4738; color: #a3f0cd; }
    html[data-bs-theme="dark"] .status-badge.maintenance { background: #4a2a2a; color: #ffb9b9; }
    html[data-bs-theme="dark"] .status-badge.tba { background: #2d3f4d; color: #bed2df; }
    html[data-bs-theme="dark"] .edit-btn { background: #244a45; color: #9ce2d0; }
    html[data-bs-theme="dark"] .edit-btn:hover { background: #2c5a54; color: #baf2e4; }
    html[data-bs-theme="dark"] .delete-btn { background: #4b2e2c; color: #ffb9b1; }
    html[data-bs-theme="dark"] .delete-btn:hover { background: #633936; color: #ffd0ca; }
    html[data-bs-theme="dark"] .empty-row td { color: #8ea6b5; }
    html[data-bs-theme="dark"] .ge-inline-alert {
        background: #172630;
        border-color: #314553;
        color: #c9dce8;
    }
    html[data-bs-theme="dark"] .ge-inline-alert.warning {
        background: #3b3322;
        border-color: #6b5a34;
        color: #f0dba7;
    }
    @media (max-width: 640px) {
        .ge-rooms-page { padding: 1rem; }
        .form-card { padding: 1.2rem; }
    }
</style>

<?php if ($flash): ?>
    <div class="ge-inline-alert"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>
<?php if (!$hasIsGened): ?>
    <div class="ge-inline-alert warning">Run <a href="upgrade_roles.php">upgrade_roles.php</a> to enable GE rooms.</div>
<?php endif; ?>

<div class="ge-rooms-page">
    <div class="dashboard-container">
        <div class="header-section">
            <div class="title-badge">
                <h1><i class="fas fa-chalkboard-teacher"></i> GE Rooms</h1>
                <div class="sub"><i class="fas fa-door-open"></i> General Education Rooms · Smart Scheduling</div>
            </div>
            <div class="auto-code-card">
                <i class="fas fa-magic"></i>
                <span>Auto-code (if custom is off)</span>
                <span class="next-code"><?= htmlspecialchars((string) ($nextAutoGenedCode ?? 'N/A')) ?></span>
            </div>
        </div>

        <div class="form-card">
            <div class="form-title">
                <i class="fas <?= $editRow ? 'fa-pen-alt' : 'fa-plus-circle' ?>"></i>
                <span><?= $editRow ? 'Edit GE room' : 'Add GE room' ?></span>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="<?= $editRow ? 'edit' : 'add' ?>">
                <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>"><?php endif; ?>
                <div class="form-grid">
                    <div class="input-group">
                        <label for="genedRoomCodeInput"><i class="fas fa-tag"></i> Code</label>
                        <input
                            type="text"
                            name="room_code"
                            id="genedRoomCodeInput"
                            maxlength="20"
                            value="<?= htmlspecialchars((string) ($editRow['room_code'] ?? '')) ?>"
                            placeholder="<?= $editRow ? 'Room code' : 'Auto if custom is off' ?>"
                            autocomplete="off"
                            <?= $editRow ? 'required' : 'disabled' ?>
                        >
                    </div>
                    <div class="input-group">
                        <label for="genedRoomNameInput"><i class="fas fa-building"></i> Name</label>
                        <input type="text" name="room_name" id="genedRoomNameInput" maxlength="50" value="<?= htmlspecialchars((string) ($editRow['room_name'] ?? '')) ?>" placeholder="Room name (e.g., CCD)" required>
                    </div>
                    <div class="input-group">
                        <label for="genedCapacityInput"><i class="fas fa-users"></i> Capacity</label>
                        <input type="number" name="capacity" id="genedCapacityInput" min="0" max="500" value="<?= (int) ($editRow['capacity'] ?? 0) ?>">
                    </div>
                    <div class="input-group">
                        <label for="genedTypeInput"><i class="fas fa-chalkboard"></i> Type</label>
                        <select name="type" id="genedTypeInput">
                            <?php foreach ($roomTypes as $t): ?>
                                <?php $label = $t === 'tba' ? 'TBA (To be arranged)' : ucfirst($t); ?>
                                <option value="<?= $t ?>" <?= ($editRow['type'] ?? 'lecture') === $t ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="input-group">
                        <label for="genedStatusInput"><i class="fas fa-circle-info"></i> Status</label>
                        <select name="status" id="genedStatusInput">
                            <option value="available" <?= ($editRow['status'] ?? '') === 'available' ? 'selected' : '' ?>>Available</option>
                            <option value="tba" <?= ($editRow['status'] ?? '') === 'tba' ? 'selected' : '' ?>>TBA</option>
                            <option value="maintenance" <?= ($editRow['status'] ?? '') === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                        </select>
                    </div>
                    <?php if (!$editRow): ?>
                        <div class="input-group checkbox-row">
                            <input type="checkbox" name="custom_room_code" value="1" id="customGenedRoomCode" <?= !$hasIsGened ? 'disabled' : '' ?>>
                            <label for="customGenedRoomCode">Use custom code (unchecked = auto next code)</label>
                        </div>
                    <?php endif; ?>
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 12px; flex-wrap: wrap;">
                    <button type="submit" class="btn-save"<?= app_tooltip_attr($editRow ? 'Saves changes to this GE room.' : 'Adds a room usable for GE scheduling.') ?>>
                        <i class="fas fa-save"></i> <?= $editRow ? 'Save changes' : 'Save Room' ?>
                    </button>
                    <?php if ($editRow): ?>
                        <a href="gened_rooms.php" class="btn-save btn-cancel"<?= app_tooltip_attr('Closes the editor without saving.') ?>><i class="fas fa-times"></i> Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="table-wrapper">
            <table class="rooms-table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Capacity</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th style="text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$list): ?>
                        <tr class="empty-row"><td colspan="6"><i class="fas fa-door-closed"></i> No rooms created. Add your first GE room!</td></tr>
                    <?php endif; ?>
                    <?php foreach ($list as $r): ?>
                        <?php
                        $status = strtolower((string) ($r['status'] ?? ''));
                        $statusClass = in_array($status, ['available', 'maintenance', 'tba'], true) ? $status : 'tba';
                        $statusIcon = 'fa-clock';
                        if ($status === 'available') {
                            $statusIcon = 'fa-check-circle';
                        } elseif ($status === 'maintenance') {
                            $statusIcon = 'fa-tools';
                        }
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars((string) $r['room_code']) ?></strong></td>
                            <td><?= htmlspecialchars((string) $r['room_name']) ?></td>
                            <td><?= (int) $r['capacity'] ?></td>
                            <td><?= htmlspecialchars(ucfirst((string) $r['type'])) ?></td>
                            <td><span class="status-badge <?= $statusClass ?>"><i class="fas <?= $statusIcon ?>"></i> <?= htmlspecialchars(ucfirst((string) $r['status'])) ?></span></td>
                            <td style="text-align: center;">
                                <div class="action-btns">
                                    <a href="gened_rooms.php?edit=<?= (int) $r['id'] ?>" class="edit-btn"<?= app_tooltip_attr('Edits this GE room’s code, capacity, or status.') ?>><i class="fas fa-edit"></i> Edit</a>
                                    <form method="post" onsubmit="return confirm('Delete this GE room?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                        <button type="submit" class="delete-btn"<?= app_tooltip_attr('Removes this GE room after confirmation.') ?>><i class="fas fa-trash-alt"></i> Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

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

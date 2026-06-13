<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_role(['admin']);
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'add') {
            db()->prepare('INSERT INTO colleges (college_code, college_name, status) VALUES (?,?,?)')
                ->execute([
                    trim((string) $_POST['college_code']),
                    trim((string) $_POST['college_name']),
                    (string) $_POST['status'],
                ]);
            $_SESSION['flash'] = 'College added.';
        } elseif ($action === 'edit') {
            db()->prepare('UPDATE colleges SET college_code=?, college_name=?, status=?, dean_user_id=? WHERE id=?')
                ->execute([
                    trim((string) $_POST['college_code']),
                    trim((string) $_POST['college_name']),
                    (string) $_POST['status'],
                    ($_POST['dean_user_id'] ?? '') !== '' ? (int) $_POST['dean_user_id'] : null,
                    (int) $_POST['id'],
                ]);
            $deanId = ($_POST['dean_user_id'] ?? '') !== '' ? (int) $_POST['dean_user_id'] : null;
            if ($deanId) {
                db()->prepare('UPDATE users SET college_id=? WHERE id=? AND role="dean"')->execute([(int) $_POST['id'], $deanId]);
            }
            $_SESSION['flash'] = 'College updated.';
        } elseif ($action === 'delete') {
            db()->prepare('DELETE FROM colleges WHERE id=?')->execute([(int) $_POST['id']]);
            $_SESSION['flash'] = 'College deleted.';
        }
    } catch (Throwable $e) {
        $_SESSION['flash'] = 'Error: ' . $e->getMessage();
    }
    header('Location: admin_colleges.php');
    exit;
}

$edit = null;
if (isset($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM colleges WHERE id=?');
    $st->execute([(int) $_GET['edit']]);
    $edit = $st->fetch() ?: null;
}

$deans = db()->query('SELECT id, full_name FROM users WHERE role="dean" ORDER BY full_name')->fetchAll();
$rows = db()->query(
    'SELECT c.*, u.full_name AS dean_name
     FROM colleges c
     LEFT JOIN users u ON u.id = c.dean_user_id
     ORDER BY c.college_code'
)->fetchAll();

$pageTitle = 'Manage Colleges';
require_once __DIR__ . '/includes/header.php';
?>
<h1 class="h3 mb-4"><i class="fa-solid fa-building-columns me-2 text-primary"></i>Manage Colleges</h1>
<?php if ($flash): ?><div class="alert alert-info"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white"><strong><?= $edit ? 'Edit College' : 'Add College' ?></strong></div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <input type="hidden" name="action" value="<?= $edit ? 'edit' : 'add' ?>">
            <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>
            <div class="col-md-2"><label class="form-label">Code</label><input name="college_code" class="form-control" required value="<?= htmlspecialchars((string) ($edit['college_code'] ?? '')) ?>"></div>
            <div class="col-md-4"><label class="form-label">Name</label><input name="college_name" class="form-control" required value="<?= htmlspecialchars((string) ($edit['college_name'] ?? '')) ?>"></div>
            <div class="col-md-3">
                <label class="form-label">Assigned Dean</label>
                <select name="dean_user_id" class="form-select">
                    <option value="">Unassigned</option>
                    <?php foreach ($deans as $d): ?>
                        <option value="<?= (int) $d['id'] ?>" <?= (int) ($edit['dean_user_id'] ?? 0) === (int) $d['id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="active" <?= ($edit['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= ($edit['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div class="col-md-1 d-flex align-items-end"><button type="submit" class="btn btn-primary w-100"<?= app_tooltip_attr($edit ? 'Saves changes to this college record.' : 'Creates the college entry for deans and scheduling scope.') ?>>Save</button></div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table mb-0">
            <thead class="table-light"><tr><th>Code</th><th>Name</th><th>Dean</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['college_code']) ?></td>
                    <td><?= htmlspecialchars($r['college_name']) ?></td>
                    <td><?= htmlspecialchars((string) ($r['dean_name'] ?? 'Unassigned')) ?></td>
                    <td><?= htmlspecialchars($r['status']) ?></td>
                    <td>
                        <a href="admin_colleges.php?edit=<?= (int) $r['id'] ?>" class="btn btn-sm btn-outline-primary"<?= app_tooltip_attr('Edits college code, name, or assigned dean.') ?>>Edit</a>
                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this college?');">
                            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"<?= app_tooltip_attr('Deletes this college after confirmation when safe for your data.') ?>>Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

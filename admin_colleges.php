<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/admin_activity_log.php';

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
            $newId = (int) db()->lastInsertId();
            $after = [
                'id' => $newId,
                'college_code' => trim((string) $_POST['college_code']),
                'college_name' => trim((string) $_POST['college_name']),
                'status' => (string) $_POST['status'],
            ];
            log_admin_activity('add', 'Colleges', 'College #' . $newId, null, $after);
            $_SESSION['flash'] = 'College added.';
        } elseif ($action === 'edit') {
            $cid = (int) $_POST['id'];
            $stBefore = db()->prepare('SELECT id, college_code, college_name, status, dean_user_id FROM colleges WHERE id=?');
            $stBefore->execute([$cid]);
            $before = $stBefore->fetch(PDO::FETCH_ASSOC) ?: null;
            db()->prepare('UPDATE colleges SET college_code=?, college_name=?, status=?, dean_user_id=? WHERE id=?')
                ->execute([
                    trim((string) $_POST['college_code']),
                    trim((string) $_POST['college_name']),
                    (string) $_POST['status'],
                    ($_POST['dean_user_id'] ?? '') !== '' ? (int) $_POST['dean_user_id'] : null,
                    $cid,
                ]);
            $deanId = ($_POST['dean_user_id'] ?? '') !== '' ? (int) $_POST['dean_user_id'] : null;
            if ($deanId) {
                db()->prepare('UPDATE users SET college_id=? WHERE id=? AND role="dean"')->execute([(int) $_POST['id'], $deanId]);
            }
            $after = [
                'id' => $cid,
                'college_code' => trim((string) $_POST['college_code']),
                'college_name' => trim((string) $_POST['college_name']),
                'status' => (string) $_POST['status'],
                'dean_user_id' => ($_POST['dean_user_id'] ?? '') !== '' ? (int) $_POST['dean_user_id'] : null,
            ];
            log_admin_activity('edit', 'Colleges', 'College #' . $cid, $before ? (array) $before : null, $after);
            $_SESSION['flash'] = 'College updated.';
        } elseif ($action === 'delete') {
            $cid = (int) $_POST['id'];
            $stBefore = db()->prepare('SELECT id, college_code, college_name, status, dean_user_id FROM colleges WHERE id=?');
            $stBefore->execute([$cid]);
            $before = $stBefore->fetch(PDO::FETCH_ASSOC) ?: null;
            db()->prepare('DELETE FROM colleges WHERE id=?')->execute([$cid]);
            log_admin_activity('delete', 'Colleges', 'College #' . $cid, $before ? (array) $before : null, null);
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
<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');

    :root {
        --mc-bg: #f1f5f9;
        --mc-text: #0f172a;
        --mc-title: #0f2b3d;
        --mc-muted: #54708f;
        --mc-role-bg: #e9ecef;
        --mc-role-text: #1e4663;
        --mc-btn-bg: #fff;
        --mc-btn-border: #cbd5e1;
        --mc-btn-text: #1e293b;
        --mc-btn-hover-bg: #f8fafc;
        --mc-btn-hover-border: #94a3b8;
        --mc-card-bg: #fff;
        --mc-card-border: #eef2f6;
        --mc-card-header-border: #eef2f8;
        --mc-form-bg: #fefefe;
        --mc-form-border: #edf2f7;
        --mc-input-bg: #fff;
        --mc-input-border: #cfdfed;
        --mc-table-head-bg: #fafcff;
        --mc-table-head-text: #2c4c6e;
        --mc-table-row-border: #f0f4fa;
        --mc-table-row-hover: #fafdff;
        --mc-row-text: #1f2f3e;
        --mc-empty-text: #7e95ae;
    }

    [data-bs-theme="dark"] {
        --mc-bg: #121a27;
        --mc-text: #e2e8f0;
        --mc-title: #f1f5f9;
        --mc-muted: #a9bdd3;
        --mc-role-bg: #223146;
        --mc-role-text: #d7e5f5;
        --mc-btn-bg: #1a2434;
        --mc-btn-border: #344861;
        --mc-btn-text: #d7e2f0;
        --mc-btn-hover-bg: #233249;
        --mc-btn-hover-border: #4a6588;
        --mc-card-bg: #182334;
        --mc-card-border: #2b3b53;
        --mc-card-header-border: #2b3b53;
        --mc-form-bg: #141f2f;
        --mc-form-border: #2b3b53;
        --mc-input-bg: #101a29;
        --mc-input-border: #3e5673;
        --mc-table-head-bg: #202f45;
        --mc-table-head-text: #c8d9ef;
        --mc-table-row-border: #2b3b53;
        --mc-table-row-hover: #223149;
        --mc-row-text: #d9e3ef;
        --mc-empty-text: #9eb4cb;
    }

    body {
        background: var(--mc-bg);
        font-family: 'Inter', sans-serif;
        color: var(--mc-text);
    }
    .dashboard-container {
        max-width: 1440px;
        margin: 0 auto;
    }
    .page-header {
        margin-bottom: 1.5rem;
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
    }
    .title-section h1 {
        font-size: 1.85rem;
        font-weight: 700;
        color: var(--mc-title);
        letter-spacing: -0.3px;
        display: flex;
        align-items: center;
        gap: 12px;
        margin: 0;
    }
    .title-section h1 i {
        color: #2c6e9e;
        font-size: 1.8rem;
    }
    .badge-role {
        background: var(--mc-role-bg);
        padding: 0.3rem 0.8rem;
        border-radius: 40px;
        font-size: 0.75rem;
        font-weight: 500;
        color: var(--mc-role-text);
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin-top: 8px;
    }
    .admin-actions { display: flex; gap: 12px; }
    .btn-outline-light {
        background: var(--mc-btn-bg);
        border: 1px solid var(--mc-btn-border);
        padding: 0.5rem 1rem;
        border-radius: 60px;
        font-weight: 500;
        font-size: 0.85rem;
        color: var(--mc-btn-text);
        transition: all 0.2s;
        cursor: pointer;
        text-decoration: none;
    }
    .btn-outline-light:hover {
        background: var(--mc-btn-hover-bg);
        border-color: var(--mc-btn-hover-border);
        color: var(--mc-btn-text);
    }
    .modern-card {
        background: var(--mc-card-bg);
        border-radius: 28px;
        box-shadow: 0 8px 20px rgba(0,0,0,0.02), 0 2px 6px rgba(0,0,0,0.05);
        margin-bottom: 1.5rem;
        overflow: hidden;
        border: 1px solid var(--mc-card-border);
    }
    .modern-card-header {
        padding: 1.2rem 1.8rem;
        border-bottom: 1px solid var(--mc-card-header-border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
    }
    .modern-card-header h2 {
        font-size: 1.3rem;
        font-weight: 600;
        color: var(--mc-text);
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 0;
    }
    .modern-card-header h2 i { color: #2c6e9e; }
    .form-grid {
        padding: 1.4rem 1.8rem 1.8rem;
        background: var(--mc-form-bg);
        border-bottom: 1px solid var(--mc-form-border);
    }
    .row-inputs {
        display: flex;
        flex-wrap: wrap;
        gap: 1rem;
        align-items: flex-end;
    }
    .input-group { flex: 1 1 200px; min-width: 170px; }
    .input-group label {
        display: block;
        font-size: 0.72rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: var(--mc-muted);
        margin-bottom: 6px;
    }
    .input-group input,
    .input-group select {
        width: 100%;
        padding: 0.7rem 0.9rem;
        border-radius: 16px;
        border: 1px solid var(--mc-input-border);
        background: var(--mc-input-bg);
        color: var(--mc-text);
        font-size: 0.9rem;
        transition: 0.2s;
        outline: none;
    }
    .input-group input:focus,
    .input-group select:focus {
        border-color: #2c6e9e;
        box-shadow: 0 0 0 3px rgba(44,110,158,0.2);
    }
    .status-toggle {
        display: flex;
        align-items: center;
        gap: 12px;
        background: var(--mc-form-bg);
        padding: 0.45rem 1rem;
        border-radius: 40px;
        border: 1px solid var(--mc-form-border);
        margin-bottom: 0.4rem;
    }
    .status-toggle span { font-size: 0.85rem; font-weight: 500; }
    .radio-group { display: flex; gap: 10px; }
    .radio-group label {
        display: flex;
        align-items: center;
        gap: 5px;
        font-weight: 500;
        text-transform: none;
        font-size: 0.85rem;
        margin: 0;
    }
    .btn-save {
        background: #1f6390;
        border: none;
        padding: 0.7rem 1.5rem;
        border-radius: 40px;
        font-weight: 600;
        color: #fff;
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.85rem;
    }
    .btn-save:hover { background: #0f4b6e; color: #fff; }
    .table-wrapper { overflow-x: auto; }
    .colleges-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
    .colleges-table th {
        text-align: left;
        padding: 1rem 1.2rem;
        background-color: var(--mc-table-head-bg);
        font-weight: 600;
        color: var(--mc-table-head-text);
        border-bottom: 1px solid var(--mc-form-border);
        font-size: 0.8rem;
    }
    .colleges-table td {
        padding: 1rem 1.2rem;
        border-bottom: 1px solid var(--mc-table-row-border);
        vertical-align: middle;
        color: var(--mc-row-text);
    }
    .colleges-table tr:hover td { background-color: var(--mc-table-row-hover); }
    .status-badge {
        display: inline-flex;
        align-items: center;
        background: #e3f6ec;
        color: #1e7b48;
        padding: 0.2rem 0.7rem;
        border-radius: 30px;
        font-size: 0.72rem;
        font-weight: 600;
        gap: 6px;
    }
    .status-badge.inactive { background: #ffe6e5; color: #b91c1c; }
    .unassigned-text {
        color: #b38f40;
        background: #fff1e0;
        padding: 0.2rem 0.6rem;
        border-radius: 50px;
        font-size: 0.72rem;
        font-weight: 500;
        display: inline-block;
    }
    .action-icons {
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    .action-icon-btn {
        border: none;
        background: transparent;
        color: #5c7f9c;
        width: 32px;
        height: 32px;
        border-radius: 20px;
        cursor: pointer;
    }
    .action-icon-btn:hover { background: #eef3fc; color: #1f6390; }
    .action-icon-btn.delete:hover { color: #d9534f; }
    .empty-row td {
        text-align: center;
        padding: 2rem;
        color: var(--mc-empty-text);
        font-style: italic;
    }
    .toast-msg {
        position: fixed;
        bottom: 20px;
        right: 20px;
        background: #1e2f3e;
        color: #fff;
        padding: 12px 20px;
        border-radius: 60px;
        font-size: 0.85rem;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 10px;
        box-shadow: 0 8px 20px rgba(0,0,0,0.12);
        z-index: 1000;
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.2s ease;
    }
    .toast-msg.show { opacity: 1; }

    @media (max-width: 700px) {
        .row-inputs { flex-direction: column; align-items: stretch; }
        .modern-card-header { flex-direction: column; align-items: flex-start; }
    }
</style>

<div class="dashboard-container">
    <div class="page-header">
        <div class="title-section">
            <h1><i class="fa-solid fa-building-columns"></i> Manage Colleges</h1>
            <div class="badge-role">
                <i class="fa-solid fa-user-shield"></i> System Administrator · Western Philippines University
            </div>
        </div>
        <div class="admin-actions">
            <a class="btn-outline-light" href="admin_colleges.php"><i class="fa-solid fa-arrows-rotate"></i> Sync</a>
        </div>
    </div>

    <div class="modern-card">
        <div class="modern-card-header">
            <h2><i class="fa-solid fa-plus-circle"></i> <?= $edit ? 'Edit College' : 'Add New College' ?></h2>
            <span style="font-size:.75rem;color:#5e7e9e;"><i class="fa-solid fa-circle-info"></i> Fill all required fields</span>
        </div>
        <div class="form-grid">
            <form method="post" class="row-inputs">
                <input type="hidden" name="action" value="<?= $edit ? 'edit' : 'add' ?>">
                <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>

                <div class="input-group">
                    <label><i class="fa-solid fa-hashtag"></i> Code</label>
                    <input name="college_code" maxlength="20" required autocomplete="off" value="<?= htmlspecialchars((string) ($edit['college_code'] ?? '')) ?>">
                </div>
                <div class="input-group" style="flex:2 1 350px;">
                    <label><i class="fa-solid fa-font"></i> Name</label>
                    <input name="college_name" required autocomplete="off" value="<?= htmlspecialchars((string) ($edit['college_name'] ?? '')) ?>">
                </div>
                <div class="input-group">
                    <label><i class="fa-solid fa-chalkboard-user"></i> Assigned Dean</label>
                    <select name="dean_user_id">
                        <option value="">Unassigned</option>
                        <?php foreach ($deans as $d): ?>
                            <option value="<?= (int) $d['id'] ?>" <?= (int) ($edit['dean_user_id'] ?? 0) === (int) $d['id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#54708f;margin-bottom:6px;">Status</label>
                    <div class="status-toggle">
                        <span><i class="fa-solid fa-circle-info"></i></span>
                        <div class="radio-group">
                            <label><input type="radio" name="status" value="active" <?= ($edit['status'] ?? 'active') === 'active' ? 'checked' : '' ?>> Active</label>
                            <label><input type="radio" name="status" value="inactive" <?= ($edit['status'] ?? '') === 'inactive' ? 'checked' : '' ?>> Inactive</label>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn-save"<?= app_tooltip_attr($edit ? 'Saves changes to this college record.' : 'Creates the college entry for deans and scheduling scope.') ?>>
                    <i class="fa-solid <?= $edit ? 'fa-pen' : 'fa-save' ?>"></i> <?= $edit ? 'Update College' : 'Save College' ?>
                </button>
            </form>
        </div>
    </div>

    <div class="modern-card">
        <div class="modern-card-header">
            <h2><i class="fa-solid fa-list-ul"></i> All Colleges</h2>
            <div><i class="fa-solid fa-school"></i> Total: <strong><?= count($rows) ?></strong> records</div>
        </div>
        <div class="table-wrapper">
            <table class="colleges-table">
                <thead>
                    <tr><th>Code</th><th>Name</th><th>Dean</th><th>Status</th><th style="width: 100px;">Actions</th></tr>
                </thead>
                <tbody>
                    <?php if (!$rows): ?>
                        <tr class="empty-row"><td colspan="5">No colleges added yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $r): ?>
                        <?php $isActive = ((string) $r['status']) === 'active'; ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($r['college_code']) ?></strong></td>
                            <td><?= htmlspecialchars($r['college_name']) ?></td>
                            <td>
                                <?php if (empty($r['dean_name'])): ?>
                                    <span class="unassigned-text"><i class="fa-solid fa-user-graduate"></i> Unassigned</span>
                                <?php else: ?>
                                    <span><i class="fa-solid fa-user-tie"></i> <?= htmlspecialchars((string) $r['dean_name']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge<?= $isActive ? '' : ' inactive' ?>">
                                    <i class="fa-solid <?= $isActive ? 'fa-check-circle' : 'fa-ban' ?>"></i> <?= $isActive ? 'active' : 'inactive' ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-icons">
                                    <a href="admin_colleges.php?edit=<?= (int) $r['id'] ?>" class="action-icon-btn" title="Edit College"<?= app_tooltip_attr('Edits college code, name, or assigned dean.') ?>>
                                        <i class="fa-solid fa-pen"></i>
                                    </a>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this college?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                        <button type="submit" class="action-icon-btn delete" title="Delete College"<?= app_tooltip_attr('Deletes this college after confirmation when safe for your data.') ?>>
                                            <i class="fa-solid fa-trash-alt"></i>
                                        </button>
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

<div id="toastMsg" class="toast-msg"><i class="fa-solid fa-circle-check"></i> <span id="toastText"></span></div>
<script>
    (function () {
        const message = <?= json_encode($flash !== '' ? $flash : null) ?>;
        if (!message) return;
        const toast = document.getElementById('toastMsg');
        const text = document.getElementById('toastText');
        if (!toast || !text) return;
        text.textContent = message;
        if (/^Error:/i.test(message)) {
            toast.style.background = '#b91c1c';
        }
        toast.classList.add('show');
        setTimeout(function () {
            toast.classList.remove('show');
            toast.style.background = '#1e2f3e';
        }, 2600);
    })();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

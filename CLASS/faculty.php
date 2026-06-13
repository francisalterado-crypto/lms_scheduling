<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_role(['dean', 'program_chair']);
$collegeId = dean_or_program_chair_college_id_or_fail();
$programScope = is_program_chair() ? program_scope_or_fail() : null;
$collegeName = college_name_by_id($collegeId);
$hasEmploymentStatusColumn = db_column_exists('faculty', 'employment_status');

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $maxHoursPerDay = (int) ($_POST['max_hours_per_day'] ?? 8);
    if ($maxHoursPerDay < 1) {
        $maxHoursPerDay = 1;
    } elseif ($maxHoursPerDay > 36) {
        $maxHoursPerDay = 36;
    }

    try {
        if ($action === 'add') {
            $allowedEmploymentStatuses = ['Permanent', 'Contract of Service', 'Temporary'];
            $employmentStatus = trim((string) ($_POST['employment_status'] ?? 'Permanent'));
            if (!in_array($employmentStatus, $allowedEmploymentStatuses, true)) {
                throw new RuntimeException('Please select a valid employment status.');
            }
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            if ($username === '' || $password === '') {
                throw new RuntimeException('Username and password are required.');
            }
            $exists = db()->prepare('SELECT COUNT(*) FROM users WHERE username=?');
            $exists->execute([$username]);
            if ((int) $exists->fetchColumn() > 0) {
                $s = suggest_available_usernames($username, 3);
                throw new RuntimeException('Username already exists.' . ($s ? ' Try: ' . implode(', ', $s) : ''));
            }
            $facultyCode = trim((string) ($_POST['faculty_id'] ?? ''));
            if ($facultyCode === '') {
                throw new RuntimeException('Faculty ID is required.');
            }
            $exists = db()->prepare('SELECT COUNT(*) FROM faculty WHERE faculty_id=?');
            $exists->execute([$facultyCode]);
            if ((int) $exists->fetchColumn() > 0) {
                throw new RuntimeException('Faculty ID already exists.');
            }
            db()->beginTransaction();
            db()->prepare('INSERT INTO users (username,password,full_name,role,college_id,is_active) VALUES (?,?,?,?,?,?)')
                ->execute([
                    $username,
                    password_hash($password, PASSWORD_DEFAULT),
                    trim((string) ($_POST['full_name'] ?? '')),
                    'faculty',
                    $collegeId,
                    (($_POST['status'] ?? '') === 'inactive') ? 0 : 1,
                ]);
            $uid = (int) db()->lastInsertId();
            if ($hasEmploymentStatusColumn) {
                db()->prepare(
                    'INSERT INTO faculty (user_id, faculty_id, full_name, department, email, max_hours_per_day, college_id, status, employment_status, is_gened)
                     VALUES (?,?,?,?,?,?,?,?,?,0)'
                )->execute([
                    $uid,
                    $facultyCode,
                    trim((string) ($_POST['full_name'] ?? '')),
                    $programScope ?? trim((string) ($_POST['department'] ?? '')),
                    trim((string) ($_POST['email'] ?? '')),
                    $maxHoursPerDay,
                    $collegeId,
                    (($_POST['status'] ?? '') === 'inactive') ? 'inactive' : 'active',
                    $employmentStatus,
                ]);
            } else {
                db()->prepare(
                    'INSERT INTO faculty (user_id, faculty_id, full_name, department, email, max_hours_per_day, college_id, status, is_gened)
                     VALUES (?,?,?,?,?,?,?,?,0)'
                )->execute([
                    $uid,
                    $facultyCode,
                    trim((string) ($_POST['full_name'] ?? '')),
                    $programScope ?? trim((string) ($_POST['department'] ?? '')),
                    trim((string) ($_POST['email'] ?? '')),
                    $maxHoursPerDay,
                    $collegeId,
                    (($_POST['status'] ?? '') === 'inactive') ? 'inactive' : 'active',
                ]);
            }
            db()->commit();
            log_dean_activity('faculty_create', 'Created faculty ' . $facultyCode);
            $_SESSION['flash'] = 'Faculty member added.';
        } elseif ($action === 'edit' && isset($_POST['id'])) {
            $allowedEmploymentStatuses = ['Permanent', 'Contract of Service', 'Temporary'];
            $employmentStatus = trim((string) ($_POST['employment_status'] ?? 'Permanent'));
            if (!in_array($employmentStatus, $allowedEmploymentStatuses, true)) {
                throw new RuntimeException('Please select a valid employment status.');
            }
            $fid = (int) $_POST['id'];
            $sql = 'SELECT user_id FROM faculty WHERE id=? AND college_id=? AND COALESCE(is_gened,0)=0';
            $params = [$fid, $collegeId];
            if ($programScope !== null) {
                $sql .= ' AND department=?';
                $params[] = $programScope;
            }
            $chk = db()->prepare($sql);
            $chk->execute($params);
            $userId = (int) ($chk->fetchColumn() ?: 0);
            if ($userId < 1) {
                throw new RuntimeException('Faculty record not found in your college.');
            }
            $newCode = trim((string) ($_POST['faculty_id'] ?? ''));
            if ($newCode === '') {
                throw new RuntimeException('Faculty ID is required.');
            }
            $exists = db()->prepare('SELECT COUNT(*) FROM faculty WHERE faculty_id=? AND id<>?');
            $exists->execute([$newCode, $fid]);
            if ((int) $exists->fetchColumn() > 0) {
                throw new RuntimeException('Faculty ID already exists.');
            }
            if ($hasEmploymentStatusColumn) {
                db()->prepare(
                    'UPDATE faculty SET faculty_id=?, full_name=?, department=?, email=?, max_hours_per_day=?, status=?, employment_status=? WHERE id=? AND college_id=? AND COALESCE(is_gened,0)=0'
                )->execute([
                    $newCode,
                    trim((string) ($_POST['full_name'] ?? '')),
                    $programScope ?? trim((string) ($_POST['department'] ?? '')),
                    trim((string) ($_POST['email'] ?? '')),
                    $maxHoursPerDay,
                    (($_POST['status'] ?? '') === 'inactive') ? 'inactive' : 'active',
                    $employmentStatus,
                    $fid,
                    $collegeId,
                ]);
            } else {
                db()->prepare(
                    'UPDATE faculty SET faculty_id=?, full_name=?, department=?, email=?, max_hours_per_day=?, status=? WHERE id=? AND college_id=? AND COALESCE(is_gened,0)=0'
                )->execute([
                    $newCode,
                    trim((string) ($_POST['full_name'] ?? '')),
                    $programScope ?? trim((string) ($_POST['department'] ?? '')),
                    trim((string) ($_POST['email'] ?? '')),
                    $maxHoursPerDay,
                    (($_POST['status'] ?? '') === 'inactive') ? 'inactive' : 'active',
                    $fid,
                    $collegeId,
                ]);
            }
            db()->prepare('UPDATE users SET full_name=?, is_active=? WHERE id=?')
                ->execute([
                    trim((string) ($_POST['full_name'] ?? '')),
                    (($_POST['status'] ?? '') === 'inactive') ? 0 : 1,
                    $userId,
                ]);
            if (!empty($_POST['reset_password'])) {
                db()->prepare('UPDATE users SET password=? WHERE id=?')
                    ->execute([password_hash((string) $_POST['reset_password'], PASSWORD_DEFAULT), $userId]);
            }
            log_dean_activity('faculty_update', 'Updated faculty ID #' . $fid);
            $_SESSION['flash'] = 'Faculty updated.';
        } elseif ($action === 'delete' && isset($_POST['id'])) {
            $fid = (int) $_POST['id'];
            $sql = 'SELECT user_id FROM faculty WHERE id=? AND college_id=? AND COALESCE(is_gened,0)=0';
            $params = [$fid, $collegeId];
            if ($programScope !== null) {
                $sql .= ' AND department=?';
                $params[] = $programScope;
            }
            $q = db()->prepare($sql);
            $q->execute($params);
            $userId = (int) ($q->fetchColumn() ?: 0);
            if ($userId < 1) {
                throw new RuntimeException('Faculty record not found.');
            }
            $sql = 'DELETE FROM faculty WHERE id=? AND college_id=? AND COALESCE(is_gened,0)=0';
            $params = [$fid, $collegeId];
            if ($programScope !== null) {
                $sql .= ' AND department=?';
                $params[] = $programScope;
            }
            db()->prepare($sql)->execute($params);
            db()->prepare('DELETE FROM users WHERE id=? AND role="faculty"')->execute([$userId]);
            log_dean_activity('faculty_delete', 'Deleted faculty ID #' . $fid);
            $_SESSION['flash'] = 'Faculty member removed.';
        }
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        $_SESSION['flash'] = 'Error: ' . $e->getMessage();
    }
    header('Location: faculty.php');
    exit;
}

$editRow = null;
if (isset($_GET['edit'])) {
    $sql = 'SELECT f.*, u.username FROM faculty f LEFT JOIN users u ON u.id=f.user_id WHERE f.id=? AND f.college_id=? AND COALESCE(f.is_gened,0)=0';
    $params = [(int) $_GET['edit'], $collegeId];
    if ($programScope !== null) {
        $sql .= ' AND f.department=?';
        $params[] = $programScope;
    }
    $st = db()->prepare($sql);
    $st->execute($params);
    $editRow = $st->fetch() ?: null;
}

$sql = 'SELECT f.*, u.username, u.is_active
     FROM faculty f
     LEFT JOIN users u ON u.id = f.user_id
     WHERE f.college_id = ? AND COALESCE(f.is_gened,0) = 0';
$params = [$collegeId];
if ($programScope !== null) {
    $sql .= ' AND f.department = ?';
    $params[] = $programScope;
}
$sql .= ' ORDER BY f.full_name ASC';
$st = db()->prepare($sql);
$st->execute($params);
$list = $st->fetchAll();

$pageTitle = 'Faculty';
require_once __DIR__ . '/includes/header.php';
?>
<h1 class="h3 mb-4"><i class="fa-solid fa-chalkboard-user me-2 text-primary"></i>Faculty</h1>
<p class="text-muted">Managing: <strong><?= htmlspecialchars($collegeName) ?></strong></p>
<?php if ($programScope !== null): ?>
    <p class="text-muted">Program scope: <strong><?= htmlspecialchars($programScope) ?></strong></p>
<?php endif; ?>

<?php if ($flash): ?>
    <div class="alert alert-info alert-dismissible fade show no-print">
        <?= htmlspecialchars($flash) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"<?= app_tooltip_attr('Dismisses this notice after you have read it.') ?>></button>
    </div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white"><strong><?= $editRow ? 'Edit faculty' : 'Add faculty' ?></strong></div>
    <div class="card-body">
        <?php if (!$hasEmploymentStatusColumn): ?>
            <div class="alert alert-warning py-2">
                Employment Status is in view-only fallback mode. Run <a href="upgrade_roles.php">upgrade_roles.php</a> to save status changes (Permanent / Contract of Service / Temporary).
            </div>
        <?php endif; ?>
        <form method="post" class="row g-3">
            <input type="hidden" name="action" value="<?= $editRow ? 'edit' : 'add' ?>">
            <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>"><?php endif; ?>
            <div class="col-md-3"><label class="form-label">Faculty ID</label><input type="text" name="faculty_id" class="form-control" required maxlength="20" value="<?= htmlspecialchars((string) ($editRow['faculty_id'] ?? '')) ?>"></div>
            <div class="col-md-3"><label class="form-label">Username</label><input type="text" name="username" class="form-control" maxlength="50" <?= $editRow ? 'readonly' : 'required' ?> value="<?= htmlspecialchars((string) ($editRow['username'] ?? '')) ?>"></div>
            <div class="col-md-3"><label class="form-label"><?= $editRow ? 'Reset password (optional)' : 'Password' ?></label><input type="password" name="<?= $editRow ? 'reset_password' : 'password' ?>" class="form-control" <?= $editRow ? '' : 'required' ?> autocomplete="new-password"></div>
            <div class="col-md-3"><label class="form-label">Full name</label><input type="text" name="full_name" class="form-control" required maxlength="100" value="<?= htmlspecialchars((string) ($editRow['full_name'] ?? '')) ?>"></div>
            <?php if ($programScope !== null): ?>
                <div class="col-md-3"><label class="form-label">Program</label><input type="text" class="form-control" value="<?= htmlspecialchars($programScope) ?>" readonly><input type="hidden" name="department" value="<?= htmlspecialchars($programScope) ?>"></div>
            <?php else: ?>
                <div class="col-md-3"><label class="form-label">Program</label><input type="text" name="department" class="form-control" maxlength="100" placeholder="e.g. Computer Science" value="<?= htmlspecialchars((string) ($editRow['department'] ?? '')) ?>"></div>
            <?php endif; ?>
            <div class="col-md-3"><label class="form-label">Email</label><input type="email" name="email" class="form-control" maxlength="100" value="<?= htmlspecialchars((string) ($editRow['email'] ?? '')) ?>"></div>
            <div class="col-md-2"><label class="form-label">Max hrs/day</label><input type="number" name="max_hours_per_day" class="form-control" min="1" max="36" value="<?= (int) ($editRow['max_hours_per_day'] ?? 8) ?>"></div>
            <div class="col-md-3">
                <label class="form-label">Employment Status</label>
                <?php $selectedEmploymentStatus = (string) ($editRow['employment_status'] ?? 'Permanent'); ?>
                <select name="employment_status" class="form-select">
                    <option value="Permanent" <?= $selectedEmploymentStatus === 'Permanent' ? 'selected' : '' ?>>Permanent</option>
                    <option value="Contract of Service" <?= $selectedEmploymentStatus === 'Contract of Service' ? 'selected' : '' ?>>Contract of Service</option>
                    <option value="Temporary" <?= $selectedEmploymentStatus === 'Temporary' ? 'selected' : '' ?>>Temporary</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="active" <?= ($editRow['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= ($editRow['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary"<?= app_tooltip_attr($editRow ? 'Saves changes to this faculty record. Use this after updating name, ID, or status.' : 'Adds the faculty member to your college roster. Use this before assigning courses or accounts.') ?>>Save</button>
                <?php if ($editRow): ?><a href="faculty.php" class="btn btn-outline-secondary"<?= app_tooltip_attr('Closes the editor without keeping unsaved changes.') ?>>Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-striped mb-0">
            <thead class="table-light">
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Program</th>
                <?php if ($hasEmploymentStatusColumn): ?><th>Employment Status</th><?php endif; ?>
                <th>Username</th>
                <th>Email</th>
                <th>Max hrs/day</th>
                <th>Status</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($list as $r): ?>
                <tr>
                    <td><?= htmlspecialchars((string) $r['faculty_id']) ?></td>
                    <td><?= htmlspecialchars((string) $r['full_name']) ?></td>
                    <td><?= htmlspecialchars((string) ($r['department'] ?? '')) ?></td>
                    <?php if ($hasEmploymentStatusColumn): ?><td><?= htmlspecialchars((string) ($r['employment_status'] ?? 'Permanent')) ?></td><?php endif; ?>
                    <td><?= htmlspecialchars((string) ($r['username'] ?? '')) ?></td>
                    <td><?= htmlspecialchars((string) ($r['email'] ?? '')) ?></td>
                    <td><?= (int) $r['max_hours_per_day'] ?></td>
                    <td><?= htmlspecialchars((string) $r['status']) ?></td>
                    <td class="text-nowrap">
                        <a href="faculty.php?edit=<?= (int) $r['id'] ?>" class="btn btn-sm btn-outline-primary"<?= app_tooltip_attr('Opens this faculty profile for editing. Use this to fix employment data or link a user account.') ?>>Edit</a>
                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this faculty member? Their schedules for this college will be removed too.');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"<?= app_tooltip_attr('Removes this faculty row after confirmation. Use only when they should no longer appear in scheduling.') ?>>Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($list === []): ?>
                <tr><td colspan="<?= $hasEmploymentStatusColumn ? '9' : '8' ?>" class="text-center text-muted py-4">No faculty yet. Add one above.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

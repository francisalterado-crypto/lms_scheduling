<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/admin_activity_log.php';

require_role(['super_admin']);

$hasProgramsTable = db_table_exists('programs');
$hasProgYlTable = db_table_exists('programs_year_levels');
$hasCollegesTable = db_table_exists('colleges');

$filterCollegeId = isset($_GET['college_id']) ? (int) $_GET['college_id'] : 0;
$statusFilter = trim((string) ($_GET['status'] ?? 'all'));
if (!in_array($statusFilter, ['all', 'active', 'inactive'], true)) {
    $statusFilter = 'all';
}

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$redirectSelf = static function () use ($filterCollegeId, $statusFilter): never {
    $query = [];
    if ($filterCollegeId > 0) {
        $query['college_id'] = (string) $filterCollegeId;
    }
    if ($statusFilter !== 'all') {
        $query['status'] = $statusFilter;
    }
    $url = 'super_admin_programs.php';
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }
    header('Location: ' . $url);
    exit;
};

$colleges = [];
if ($hasCollegesTable) {
    $colleges = db()->query(
        "SELECT id, college_code, college_name, status FROM colleges ORDER BY college_code"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if (!$hasProgramsTable) {
            throw new RuntimeException('Programs module is unavailable until you run upgrade_roles.php.');
        }
        if (!$hasCollegesTable) {
            throw new RuntimeException('Colleges table is missing.');
        }

        if ($action === 'add') {
            $collegeId = (int) ($_POST['college_id'] ?? 0);
            $programName = trim((string) ($_POST['program_name'] ?? ''));
            $status = (string) ($_POST['status'] ?? 'active');
            if ($collegeId < 1) {
                throw new RuntimeException('College is required.');
            }
            if ($programName === '') {
                throw new RuntimeException('Program name is required.');
            }
            if (!in_array($status, ['active', 'inactive'], true)) {
                $status = 'active';
            }
            $chk = db()->prepare('SELECT COUNT(*) FROM colleges WHERE id = ?');
            $chk->execute([$collegeId]);
            if ((int) $chk->fetchColumn() < 1) {
                throw new RuntimeException('Selected college was not found.');
            }
            $st = db()->prepare('INSERT INTO programs (college_id, program_name, status) VALUES (?,?,?)');
            $st->execute([$collegeId, $programName, $status]);
            $newId = (int) db()->lastInsertId();
            if ($hasProgYlTable) {
                program_year_levels_replace($newId, parse_dean_program_year_levels_post($_POST));
            }
            log_admin_activity(
                'add',
                'Program offers',
                'Program #' . $newId,
                null,
                [
                    'id' => $newId,
                    'college_id' => $collegeId,
                    'program_name' => $programName,
                    'status' => $status,
                ]
            );
            $_SESSION['flash'] = 'Program offer added.';
        } elseif ($action === 'edit' && isset($_POST['id'])) {
            $id = (int) $_POST['id'];
            $collegeId = (int) ($_POST['college_id'] ?? 0);
            $programName = trim((string) ($_POST['program_name'] ?? ''));
            $status = (string) ($_POST['status'] ?? 'active');
            if ($id < 1) {
                throw new RuntimeException('Invalid program.');
            }
            if ($collegeId < 1) {
                throw new RuntimeException('College is required.');
            }
            if ($programName === '') {
                throw new RuntimeException('Program name is required.');
            }
            if (!in_array($status, ['active', 'inactive'], true)) {
                $status = 'active';
            }
            $stBefore = db()->prepare('SELECT id, college_id, program_name, status FROM programs WHERE id = ? LIMIT 1');
            $stBefore->execute([$id]);
            $before = $stBefore->fetch(PDO::FETCH_ASSOC);
            if (!$before) {
                throw new RuntimeException('Program not found.');
            }
            $chk = db()->prepare('SELECT COUNT(*) FROM colleges WHERE id = ?');
            $chk->execute([$collegeId]);
            if ((int) $chk->fetchColumn() < 1) {
                throw new RuntimeException('Selected college was not found.');
            }
            $st = db()->prepare('UPDATE programs SET college_id=?, program_name=?, status=? WHERE id=?');
            $st->execute([$collegeId, $programName, $status, $id]);
            if ($hasProgYlTable) {
                program_year_levels_replace($id, parse_dean_program_year_levels_post($_POST));
            }
            log_admin_activity(
                'edit',
                'Program offers',
                'Program #' . $id,
                $before,
                [
                    'id' => $id,
                    'college_id' => $collegeId,
                    'program_name' => $programName,
                    'status' => $status,
                ]
            );
            $_SESSION['flash'] = 'Program offer updated.';
        } elseif ($action === 'delete' && isset($_POST['id'])) {
            $id = (int) $_POST['id'];
            if ($id < 1) {
                throw new RuntimeException('Invalid program.');
            }
            $stBefore = db()->prepare('SELECT id, college_id, program_name, status FROM programs WHERE id = ? LIMIT 1');
            $stBefore->execute([$id]);
            $before = $stBefore->fetch(PDO::FETCH_ASSOC);
            if (!$before) {
                throw new RuntimeException('Program not found.');
            }
            db()->prepare('DELETE FROM programs WHERE id = ?')->execute([$id]);
            log_admin_activity(
                'delete',
                'Program offers',
                'Program #' . $id,
                $before,
                null
            );
            $_SESSION['flash'] = 'Program offer deleted.';
        }
    } catch (Throwable $e) {
        $_SESSION['flash'] = 'Error: ' . $e->getMessage();
    }
    $redirectSelf();
}

$editRow = null;
if ($hasProgramsTable && isset($_GET['edit'])) {
    $st = db()->prepare(
        'SELECT p.*, c.college_code, c.college_name
         FROM programs p
         LEFT JOIN colleges c ON c.id = p.college_id
         WHERE p.id = ?
         LIMIT 1'
    );
    $st->execute([(int) $_GET['edit']]);
    $editRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

$list = [];
if ($hasProgramsTable) {
    $sql = 'SELECT p.*, c.college_code, c.college_name
            FROM programs p
            LEFT JOIN colleges c ON c.id = p.college_id
            WHERE 1=1';
    $params = [];
    if ($filterCollegeId > 0) {
        $sql .= ' AND p.college_id = ?';
        $params[] = $filterCollegeId;
    }
    if ($statusFilter !== 'all') {
        $sql .= ' AND p.status = ?';
        $params[] = $statusFilter;
    }
    $sql .= ' ORDER BY c.college_code ASC, p.program_name ASC';
    $st = db()->prepare($sql);
    $st->execute($params);
    $list = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$levelsByProgId = [];
if ($hasProgYlTable && $list !== []) {
    $ids = array_map(static fn ($r): int => (int) ($r['id'] ?? 0), $list);
    $ids = array_values(array_filter(array_unique($ids), static fn (int $i): bool => $i > 0));
    if ($ids !== []) {
        $holds = implode(',', array_fill(0, count($ids), '?'));
        $q = db()->prepare("SELECT program_id, year_level FROM programs_year_levels WHERE program_id IN ({$holds}) ORDER BY year_level");
        $q->execute($ids);
        while ($lr = $q->fetch(PDO::FETCH_ASSOC)) {
            $pid = (int) ($lr['program_id'] ?? 0);
            $yl = trim((string) ($lr['year_level'] ?? ''));
            if ($pid < 1 || $yl === '') {
                continue;
            }
            if (!isset($levelsByProgId[$pid])) {
                $levelsByProgId[$pid] = [];
            }
            $levelsByProgId[$pid][] = $yl;
        }
    }
}

$prefillCanon = [];
$prefillExtraCsv = '';
if ($hasProgYlTable && $editRow) {
    $defined = program_defined_year_levels((int) $editRow['id']);
    $canonLabels = ['1', '2', '3', '4', '5'];
    foreach ($defined as $d) {
        if (in_array($d, $canonLabels, true)) {
            $prefillCanon[] = $d;
        }
    }
    $extra = [];
    foreach ($defined as $d) {
        if (!in_array($d, $canonLabels, true)) {
            $extra[] = $d;
        }
    }
    $prefillExtraCsv = $extra !== [] ? implode(', ', $extra) : '';
}

$listQuery = [];
if ($filterCollegeId > 0) {
    $listQuery['college_id'] = (string) $filterCollegeId;
}
if ($statusFilter !== 'all') {
    $listQuery['status'] = $statusFilter;
}
$listBaseUrl = 'super_admin_programs.php' . ($listQuery !== [] ? '?' . http_build_query($listQuery) : '');
$editBaseUrl = 'super_admin_programs.php';
$editQuery = $listQuery;

$pageTitle = 'Program offers';
require_once __DIR__ . '/includes/header.php';

$canonicalYl = ['1', '2', '3', '4', '5'];
$editCollegeId = (int) ($editRow['college_id'] ?? 0);
?>
<h1 class="h3 mb-2"><i class="fa-solid fa-graduation-cap me-2 text-primary"></i>Program offers</h1>
<p class="text-muted mb-4">University-wide catalog of programs offered by each college. Super Admin can add, edit, or delete any offer.</p>
<?php if ($flash): ?><div class="alert alert-info"><?= htmlspecialchars((string) $flash) ?></div><?php endif; ?>
<?php if (!$hasProgramsTable || !$hasCollegesTable): ?>
    <div class="alert alert-warning">Programs module is unavailable. Please run <a href="upgrade_roles.php">upgrade_roles.php</a>.</div>
<?php else: ?>
<?php if (!$hasProgYlTable): ?>
    <div class="alert alert-warning">Run <a href="upgrade_roles.php">upgrade_roles.php</a> to enable program year levels.</div>
<?php endif; ?>

<form method="get" class="card shadow-sm mb-4">
    <div class="card-body row g-3 align-items-end">
        <div class="col-md-5">
            <label class="form-label">Filter by college</label>
            <select name="college_id" class="form-select">
                <option value="0">All colleges</option>
                <?php foreach ($colleges as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= $filterCollegeId === (int) $c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string) $c['college_code']) ?> — <?= htmlspecialchars((string) $c['college_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <div class="col-md-4">
            <button type="submit" class="btn btn-outline-primary">Apply filters</button>
            <?php if ($filterCollegeId > 0 || $statusFilter !== 'all'): ?>
                <a href="super_admin_programs.php" class="btn btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </div>
    </div>
</form>

<div class="card shadow-sm mb-4"><div class="card-body">
    <h2 class="h5 mb-3"><?= $editRow ? 'Edit program offer' : 'Add program offer' ?></h2>
    <form method="post" class="row g-3">
        <input type="hidden" name="action" value="<?= $editRow ? 'edit' : 'add' ?>">
        <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>"><?php endif; ?>
        <div class="col-md-5">
            <label class="form-label">College</label>
            <select name="college_id" class="form-select" required>
                <option value="">Select college…</option>
                <?php foreach ($colleges as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= $editCollegeId === (int) $c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string) $c['college_code']) ?> — <?= htmlspecialchars((string) $c['college_name']) ?>
                        <?= ((string) ($c['status'] ?? '') === 'inactive') ? ' (inactive)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-5">
            <label class="form-label">Program name</label>
            <input name="program_name" class="form-control" required maxlength="120" placeholder="e.g. BS Social Work" value="<?= htmlspecialchars((string) ($editRow['program_name'] ?? '')) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
                <option value="active" <?= (($editRow['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= (($editRow['status'] ?? '') === 'inactive') ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <?php if ($hasProgYlTable): ?>
        <div class="col-12">
            <label class="form-label">Year levels offered</label>
            <div class="d-flex flex-wrap gap-3 mb-2">
                <?php foreach ($canonicalYl as $yl): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="program_year_levels[]" value="<?= htmlspecialchars($yl) ?>"
                               id="sa_yl_<?= htmlspecialchars($yl) ?>" <?= in_array($yl, $prefillCanon, true) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="sa_yl_<?= htmlspecialchars($yl) ?>">Year <?= htmlspecialchars($yl) ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
            <label class="form-label small text-muted">Additional levels (comma-separated)</label>
            <input type="text" name="program_year_level_extra" class="form-control" maxlength="200" placeholder="e.g. 6, grad1"
                   value="<?= htmlspecialchars($prefillExtraCsv) ?>">
            <div class="form-text">These levels appear in scheduling forms for the college and when GEN ED coordinators target the program.</div>
        </div>
        <?php endif; ?>
        <div class="col-12">
            <button type="submit" class="btn btn-primary"<?= app_tooltip_attr($editRow ? 'Saves changes to this university program offer.' : 'Adds a new program offer under the selected college.') ?>>Save</button>
            <?php if ($editRow): ?>
                <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($listBaseUrl) ?>"<?= app_tooltip_attr('Closes the editor without saving.') ?>>Cancel</a>
            <?php endif; ?>
        </div>
    </form>
</div></div>

<div class="card shadow-sm"><div class="table-responsive">
    <table class="table mb-0 align-middle">
        <thead class="table-light">
            <tr>
                <th>College</th>
                <th>Program</th>
                <?php if ($hasProgYlTable): ?><th>Year levels</th><?php endif; ?>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($list as $r): ?>
            <?php
            $editQuery['edit'] = (string) (int) $r['id'];
            $editHref = $editBaseUrl . '?' . http_build_query($editQuery);
            ?>
            <tr>
                <td>
                    <div class="fw-semibold"><?= htmlspecialchars((string) ($r['college_code'] ?? '—')) ?></div>
                    <div class="small text-muted"><?= htmlspecialchars((string) ($r['college_name'] ?? '')) ?></div>
                </td>
                <td><?= htmlspecialchars((string) $r['program_name']) ?></td>
                <?php if ($hasProgYlTable): ?>
                <td>
                    <?php
                    $lid = (int) $r['id'];
                    $yrs = isset($levelsByProgId[$lid]) ? sort_schedule_year_levels($levelsByProgId[$lid]) : [];
                    ?>
                    <?php if ($yrs === []): ?>
                        <span class="text-muted small">— Not configured</span>
                    <?php else: ?>
                        <?php foreach ($yrs as $y): ?>
                            <span class="badge bg-primary-subtle text-primary-emphasis me-1 mb-1"><?= htmlspecialchars((string) $y) ?></span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
                <td>
                    <span class="badge <?= ($r['status'] === 'active') ? 'bg-success-subtle text-success-emphasis' : 'bg-secondary-subtle text-secondary-emphasis' ?>">
                        <?= htmlspecialchars((string) $r['status']) ?>
                    </span>
                </td>
                <td class="text-nowrap">
                    <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars($editHref) ?>"<?= app_tooltip_attr('Edits this program offer (college, name, status, or year levels).') ?>>Edit</a>
                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this program offer from the university catalog?');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger"<?= app_tooltip_attr('Removes this program offer after confirmation.') ?>>Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$list): ?>
            <tr>
                <td colspan="<?= $hasProgYlTable ? '5' : '4' ?>" class="text-center text-muted py-4">
                    No program offers found<?= ($filterCollegeId > 0 || $statusFilter !== 'all') ? ' for the current filters' : '' ?>.
                </td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
</div></div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

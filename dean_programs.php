<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_role(['dean']);
$collegeId = dean_college_id_or_fail();
$collegeName = college_name_by_id($collegeId);
$hasProgramsTable = db_table_exists('programs');
$hasProgYlTable = db_table_exists('programs_year_levels');

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if (!$hasProgramsTable) {
            throw new RuntimeException('Programs module is unavailable until you run upgrade_roles.php.');
        }

        if ($action === 'add') {
            $programName = trim((string) ($_POST['program_name'] ?? ''));
            $status = (string) ($_POST['status'] ?? 'active');
            if ($programName === '') {
                throw new RuntimeException('Program name is required.');
            }
            if (!in_array($status, ['active', 'inactive'], true)) {
                $status = 'active';
            }
            $st = db()->prepare('INSERT INTO programs (college_id, program_name, status) VALUES (?,?,?)');
            $st->execute([$collegeId, $programName, $status]);
            $newId = (int) db()->lastInsertId();
            if ($hasProgYlTable) {
                program_year_levels_replace($newId, parse_dean_program_year_levels_post($_POST));
            }
            log_dean_activity('program_create', 'Created program ' . $programName);
            $_SESSION['flash'] = 'Program added.';
        } elseif ($action === 'edit' && isset($_POST['id'])) {
            $id = (int) $_POST['id'];
            $programName = trim((string) ($_POST['program_name'] ?? ''));
            $status = (string) ($_POST['status'] ?? 'active');
            if ($programName === '') {
                throw new RuntimeException('Program name is required.');
            }
            if (!in_array($status, ['active', 'inactive'], true)) {
                $status = 'active';
            }
            $st = db()->prepare('UPDATE programs SET program_name=?, status=? WHERE id=? AND college_id=?');
            $st->execute([$programName, $status, $id, $collegeId]);
            if ($hasProgYlTable) {
                program_year_levels_replace($id, parse_dean_program_year_levels_post($_POST));
            }
            log_dean_activity('program_update', 'Updated program ID #' . $id);
            $_SESSION['flash'] = 'Program updated.';
        } elseif ($action === 'delete' && isset($_POST['id'])) {
            $id = (int) $_POST['id'];
            $st = db()->prepare('DELETE FROM programs WHERE id=? AND college_id=?');
            $st->execute([$id, $collegeId]);
            log_dean_activity('program_delete', 'Deleted program ID #' . $id);
            $_SESSION['flash'] = 'Program removed.';
        }
    } catch (Throwable $e) {
        $_SESSION['flash'] = 'Error: ' . $e->getMessage();
    }
    header('Location: dean_programs.php');
    exit;
}

$editRow = null;
if ($hasProgramsTable && isset($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM programs WHERE id=? AND college_id=?');
    $st->execute([(int) $_GET['edit'], $collegeId]);
    $editRow = $st->fetch() ?: null;
}

$list = [];
if ($hasProgramsTable) {
    $st = db()->prepare('SELECT * FROM programs WHERE college_id=? ORDER BY program_name ASC');
    $st->execute([$collegeId]);
    $list = $st->fetchAll();
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

$pageTitle = 'Programs';
require_once __DIR__ . '/includes/header.php';

$canonicalYl = ['1', '2', '3', '4', '5'];
?>
<h1 class="h3 mb-4"><i class="fa-solid fa-graduation-cap me-2 text-primary"></i>Programs</h1>
<p class="text-muted">Managing: <strong><?= htmlspecialchars($collegeName) ?></strong></p>
<?php if ($flash): ?><div class="alert alert-info"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
<?php if (!$hasProgramsTable): ?>
    <div class="alert alert-warning">Programs table is missing. Please run <a href="upgrade_roles.php">upgrade_roles.php</a>.</div>
<?php else: ?>
<?php if (!$hasProgYlTable): ?>
    <div class="alert alert-warning">Run <a href="upgrade_roles.php">upgrade_roles.php</a> to enable program year levels so they propagate to scheduling forms.</div>
<?php endif; ?>
<div class="card shadow-sm mb-4"><div class="card-body">
    <form method="post" class="row g-3">
        <input type="hidden" name="action" value="<?= $editRow ? 'edit' : 'add' ?>">
        <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>"><?php endif; ?>
        <div class="col-md-8">
            <label class="form-label">Program Name</label>
            <input name="program_name" class="form-control" required maxlength="120" placeholder="e.g. BS Social Work" value="<?= htmlspecialchars((string) ($editRow['program_name'] ?? '')) ?>">
        </div>
        <div class="col-md-4">
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
                               id="yl_<?= htmlspecialchars($yl) ?>" <?= in_array($yl, $prefillCanon, true) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="yl_<?= htmlspecialchars($yl) ?>">Year <?= htmlspecialchars($yl) ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
            <label class="form-label small text-muted">Additional levels (comma-separated)</label>
            <input type="text" name="program_year_level_extra" class="form-control" maxlength="200" placeholder="e.g. 6, grad1"
                   value="<?= htmlspecialchars($prefillExtraCsv) ?>">
            <div class="form-text">These lists appear first in Add schedule for your college and when GEN ED coordinators target your programs.</div>
        </div>
        <?php endif; ?>
        <div class="col-12"><button type="submit" class="btn btn-primary"<?= app_tooltip_attr($editRow ? 'Saves changes to this program under your college.' : 'Adds a new program your college offers.') ?>>Save</button><?php if ($editRow): ?> <a class="btn btn-outline-secondary" href="dean_programs.php"<?= app_tooltip_attr('Closes the editor without saving.') ?>>Cancel</a><?php endif; ?></div>
    </form>
</div></div>

<div class="card shadow-sm"><div class="table-responsive">
    <table class="table mb-0">
        <thead class="table-light"><tr><th>Program</th><?php if ($hasProgYlTable): ?><th>Year levels</th><?php endif; ?><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($list as $r): ?>
            <tr>
                <td><?= htmlspecialchars((string) $r['program_name']) ?></td>
                <?php if ($hasProgYlTable): ?>
                <td>
                    <?php
                    $lid = (int) $r['id'];
                    $yrs = isset($levelsByProgId[$lid]) ? sort_schedule_year_levels($levelsByProgId[$lid]) : [];
                    ?>
                    <?php if ($yrs === []): ?>
                        <span class="text-muted small">— Uses course catalogs in scheduling until configured</span>
                    <?php else: ?>
                        <?php foreach ($yrs as $y): ?>
                            <span class="badge bg-primary-subtle text-primary-emphasis me-1 mb-1"><?= htmlspecialchars((string) $y) ?></span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
                <td><span class="badge <?= ($r['status'] === 'active') ? 'bg-success-subtle text-success-emphasis' : 'bg-secondary-subtle text-secondary-emphasis' ?>"><?= htmlspecialchars((string) $r['status']) ?></span></td>
                <td class="text-nowrap">
                    <a class="btn btn-sm btn-outline-primary" href="dean_programs.php?edit=<?= (int) $r['id'] ?>"<?= app_tooltip_attr('Edits this program’s name or status.') ?>>Edit</a>
                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this program?');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger"<?= app_tooltip_attr('Removes this program after confirmation if no data blocks deletion.') ?>>Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$list): ?>
            <tr><td colspan="<?= $hasProgYlTable ? '4' : '3' ?>" class="text-center text-muted py-4">No programs yet. Add one to use Program dropdown in Courses.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div></div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_role(['gened']);
$hasIsGened = db_column_exists('courses', 'is_gened');
$hasAssignTable = true;
try {
    db()->query('SELECT 1 FROM ge_course_colleges LIMIT 1');
} catch (Throwable $e) {
    $hasAssignTable = false;
}

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$hasIsGened || !$hasAssignTable) {
            throw new RuntimeException('Run upgrade_roles.php first to enable GE course offerings.');
        }
        $courseId = (int) ($_POST['course_id'] ?? 0);
        $collegeIds = isset($_POST['college_ids']) && is_array($_POST['college_ids'])
            ? array_values(array_unique(array_map('intval', $_POST['college_ids'])))
            : [];
        if ($courseId < 1) {
            throw new RuntimeException('Please select a GE course.');
        }

        db()->prepare('DELETE FROM ge_course_colleges WHERE course_id=?')->execute([$courseId]);
        $ins = db()->prepare('INSERT INTO ge_course_colleges (course_id, college_id) VALUES (?,?)');
        foreach ($collegeIds as $cid) {
            if ($cid > 0) {
                $ins->execute([$courseId, $cid]);
            }
        }
        $_SESSION['flash'] = 'GE course offering assignments updated.';
    } catch (Throwable $e) {
        $_SESSION['flash'] = 'Error: ' . $e->getMessage();
    }
    header('Location: gened_assignments.php');
    exit;
}

$courses = $hasIsGened ? db()->query('SELECT id, course_code, course_name FROM courses WHERE is_gened=1 ORDER BY course_code')->fetchAll() : [];
$colleges = db()->query("SELECT id, college_code, college_name FROM colleges WHERE status='active' ORDER BY college_code")->fetchAll();

$selectedCourseId = (int) ($_GET['course_id'] ?? ($courses[0]['id'] ?? 0));
$assigned = [];
if ($selectedCourseId > 0 && $hasAssignTable) {
    $st = db()->prepare('SELECT college_id FROM ge_course_colleges WHERE course_id=?');
    $st->execute([$selectedCourseId]);
    $assigned = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

$matrix = [];
if ($hasAssignTable) {
    $rows = db()->query(
        'SELECT gcc.course_id, c.course_code, c.course_name, col.college_code
         FROM ge_course_colleges gcc
         INNER JOIN courses c ON c.id = gcc.course_id
         INNER JOIN colleges col ON col.id = gcc.college_id
         WHERE c.is_gened=1
         ORDER BY c.course_code, col.college_code'
    )->fetchAll();
    foreach ($rows as $r) {
        $k = (int) $r['course_id'];
        if (!isset($matrix[$k])) {
            $matrix[$k] = [
                'course' => $r['course_code'] . ' - ' . $r['course_name'],
                'colleges' => [],
            ];
        }
        $matrix[$k]['colleges'][] = $r['college_code'];
    }
}

$pageTitle = 'GE Course Offerings';
require_once __DIR__ . '/includes/header.php';
?>
<h1 class="h3 mb-4"><i class="fa-solid fa-building-user me-2 text-primary"></i>GE Course Offerings by College</h1>
<?php if ($flash): ?><div class="alert alert-info"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
<?php if (!$hasIsGened || !$hasAssignTable): ?>
    <div class="alert alert-warning">Run <a href="upgrade_roles.php">upgrade_roles.php</a> to enable GE assignments.</div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white"><strong>Assign colleges to a GE course</strong></div>
    <div class="card-body">
        <form method="get" class="row g-3 mb-3">
            <div class="col-md-6">
                <label class="form-label">GE Course</label>
                <select name="course_id" class="form-select" onchange="this.form.submit()">
                    <?php foreach ($courses as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= $selectedCourseId === (int) $c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['course_code'] . ' - ' . $c['course_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
        <form method="post">
            <input type="hidden" name="course_id" value="<?= $selectedCourseId ?>">
            <label class="form-label">Colleges where this GE course is offered</label>
            <div class="row">
                <?php foreach ($colleges as $col): ?>
                    <div class="col-md-4">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="college_ids[]" value="<?= (int) $col['id'] ?>" id="col_<?= (int) $col['id'] ?>" <?= in_array((int) $col['id'], $assigned, true) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="col_<?= (int) $col['id'] ?>">
                                <?= htmlspecialchars($col['college_code'] . ' - ' . $col['college_name']) ?>
                            </label>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="btn btn-primary mt-2"<?= app_tooltip_attr('Saves which colleges or cohorts receive this GE offering. Use after adjusting checkboxes or targets.') ?>>Save offerings</button>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white"><strong>Current GE offerings</strong></div>
    <div class="table-responsive">
        <table class="table table-striped mb-0">
            <thead class="table-light"><tr><th>GE Course</th><th>Assigned Colleges</th></tr></thead>
            <tbody>
            <?php if (!$matrix): ?>
                <tr><td colspan="2" class="text-muted p-3">No GE course assignments yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($matrix as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['course']) ?></td>
                    <td><?= htmlspecialchars(implode(', ', $row['colleges'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

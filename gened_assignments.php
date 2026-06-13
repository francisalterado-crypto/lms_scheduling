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

$geTableRows = [];
foreach ($courses as $c) {
    $cid = (int) $c['id'];
    $geTableRows[] = [
        'course' => $c['course_code'] . ' - ' . $c['course_name'],
        'colleges' => $matrix[$cid]['colleges'] ?? [],
    ];
}

$collegeNameByCode = [];
foreach ($colleges as $col) {
    $collegeNameByCode[(string) $col['college_code']] = (string) $col['college_name'];
}

$pageTitle = 'GE Course Offerings';
require_once __DIR__ . '/includes/header.php';
?>
<link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700&amp;display=swap" rel="stylesheet">
<style>
    .ge-offerings-page {
        --ge-text: #1a2c3e;
        font-family: 'Inter', system-ui, sans-serif;
        color: var(--ge-text);
        background: linear-gradient(145deg, #eef2f7 0%, #d9e0e8 100%);
        margin: -1.5rem -0.75rem 0;
        padding: 2rem 1.5rem 2.5rem;
        border-radius: 0;
    }
    @media (min-width: 768px) {
        .ge-offerings-page {
            margin: -1.5rem -1rem 0;
            padding: 2rem 1.75rem 2.5rem;
            border-radius: 0 0 1rem 1rem;
        }
    }
    .ge-offerings-page * { box-sizing: border-box; }
    .ge-offerings-page .ge-dashboard-container { max-width: 1400px; margin: 0 auto; }
    .ge-offerings-page .ge-module-header { margin-bottom: 2rem; text-align: left; }
    .ge-offerings-page .ge-module-header h1 {
        font-size: 1.9rem;
        font-weight: 700;
        background: linear-gradient(135deg, #1e4668, #0f2c44);
        background-clip: text;
        -webkit-background-clip: text;
        color: transparent;
        letter-spacing: -0.3px;
        display: inline-flex;
        align-items: center;
        gap: 12px;
    }
    .ge-offerings-page .ge-module-header h1 i {
        background: none;
        -webkit-text-fill-color: initial;
        color: #2c6e9e;
        font-size: 2rem;
    }
    .ge-offerings-page .ge-subhead {
        color: #2c4e6e;
        font-weight: 500;
        margin-top: 8px;
        font-size: 0.95rem;
        border-left: 4px solid #3b82f6;
        padding-left: 14px;
    }
    .ge-offerings-page .ge-grid {
        display: grid;
        grid-template-columns: 1fr 1.2fr;
        gap: 28px;
        margin-bottom: 40px;
    }
    .ge-offerings-page .ge-card {
        background: rgba(255, 255, 255, 0.96);
        border-radius: 32px;
        box-shadow: 0 20px 35px -12px rgba(0, 0, 0, 0.12), 0 1px 2px rgba(0,0,0,0.02);
        overflow: hidden;
        transition: box-shadow 0.2s;
        border: 1px solid rgba(255,255,255,0.5);
    }
    .ge-offerings-page .ge-card:hover { box-shadow: 0 25px 40px -16px rgba(0, 0, 0, 0.2); }
    .ge-offerings-page .ge-card-header {
        padding: 1.2rem 1.8rem;
        background: #ffffffdd;
        border-bottom: 1px solid #e9edf2;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .ge-offerings-page .ge-card-header i { font-size: 1.6rem; color: #2c7da0; }
    .ge-offerings-page .ge-card-header h2 {
        font-size: 1.35rem;
        font-weight: 600;
        color: #1f3b4c;
        margin: 0;
    }
    .ge-offerings-page .ge-card-body { padding: 1.6rem 1.8rem; }
    .ge-offerings-page .ge-selector { margin-bottom: 2rem; }
    .ge-offerings-page .ge-selector label {
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 12px;
        color: #1f3e53;
        font-size: 0.9rem;
    }
    .ge-offerings-page .ge-custom-select {
        width: 100%;
        padding: 12px 16px;
        border-radius: 20px;
        border: 1.5px solid #cfdfed;
        background: white;
        font-family: 'Inter', system-ui, sans-serif;
        font-weight: 500;
        font-size: 0.95rem;
        color: #1f2f3c;
        cursor: pointer;
        transition: all 0.2s;
        outline: none;
    }
    .ge-offerings-page .ge-custom-select:focus {
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59,130,246,0.2);
    }
    .ge-offerings-page .ge-college-check-group {
        background: #f8fafd;
        border-radius: 24px;
        padding: 0.8rem 1rem;
        margin: 1.5rem 0 1.8rem;
        border: 1px solid #e2e8f0;
    }
    .ge-offerings-page .ge-college-check-group h3 {
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: #4a6f8f;
        margin: 0 0 14px;
        font-weight: 600;
    }
    .ge-offerings-page .ge-checkbox-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 6px;
        border-radius: 16px;
        transition: background 0.1s ease;
    }
    .ge-offerings-page .ge-checkbox-item:hover { background: #eef3fc; }
    .ge-offerings-page .ge-checkbox-item input {
        width: 18px;
        height: 18px;
        accent-color: #2c7da0;
        cursor: pointer;
        flex-shrink: 0;
    }
    .ge-offerings-page .ge-checkbox-item label {
        font-weight: 500;
        color: #1f3e53;
        cursor: pointer;
        font-size: 0.95rem;
        margin: 0;
    }
    .ge-offerings-page .ge-btn-save {
        background: linear-gradient(100deg, #1f6392, #134b6e);
        border: none;
        padding: 12px 24px;
        border-radius: 40px;
        font-weight: 600;
        font-size: 0.9rem;
        color: white;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        cursor: pointer;
        transition: 0.2s;
        box-shadow: 0 5px 12px rgba(0,0,0,0.08);
        width: 100%;
        justify-content: center;
    }
    .ge-offerings-page .ge-btn-save:hover:not(:disabled) {
        background: linear-gradient(100deg, #0f547c, #0c3f5c);
        transform: translateY(-2px);
        box-shadow: 0 10px 20px -5px rgba(0,0,0,0.15);
    }
    .ge-offerings-page .ge-btn-save:active:not(:disabled) { transform: translateY(1px); }
    .ge-offerings-page .ge-btn-save:disabled { opacity: 0.55; cursor: not-allowed; }
    .ge-offerings-page .ge-offerings-table-wrapper { overflow-x: auto; border-radius: 24px; }
    .ge-offerings-page .ge-offerings-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.9rem;
    }
    .ge-offerings-page .ge-offerings-table th {
        text-align: left;
        padding: 1rem 1.2rem;
        background: #eef3fa;
        font-weight: 600;
        color: #1f4662;
        border-bottom: 2px solid #cddfec;
    }
    .ge-offerings-page .ge-offerings-table td {
        padding: 1rem 1.2rem;
        border-bottom: 1px solid #e2edf5;
        vertical-align: middle;
        color: #1f2f3c;
    }
    .ge-offerings-page .ge-offerings-table tr:last-child td { border-bottom: none; }
    .ge-offerings-page .ge-college-badge {
        background: #e0edf5;
        padding: 5px 12px;
        border-radius: 30px;
        font-size: 0.8rem;
        font-weight: 600;
        display: inline-block;
        color: #1c5679;
        margin: 2px 4px 2px 0;
    }
    .ge-offerings-page .ge-college-badge.ge-badge-multiple {
        background: #cbdde9;
        color: #1e4a6e;
    }
    .ge-offerings-page .ge-empty-message {
        text-align: center;
        padding: 2rem;
        color: #6c8eaa;
        font-style: italic;
    }
    .ge-offerings-page .ge-empty-inline {
        color: #6c8eaa;
        font-style: italic;
        font-weight: 400;
    }
    .ge-offerings-page .ge-options-legend {
        background: #f0f4f9;
        border-radius: 28px;
        padding: 1.2rem 1.5rem;
        margin-top: 12px;
        display: flex;
        flex-wrap: wrap;
        gap: 1rem;
        align-items: center;
        justify-content: flex-start;
        border: 1px solid #e2eaf1;
    }
    .ge-offerings-page .ge-legend-title {
        font-weight: 700;
        font-size: 0.8rem;
        color: #2b5a7a;
        letter-spacing: 0.5px;
    }
    .ge-offerings-page .ge-legend-badge {
        background: white;
        padding: 5px 14px;
        border-radius: 40px;
        font-size: 0.8rem;
        font-weight: 500;
        color: #1e3e55;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        border: 1px solid #cfdfec;
    }
    .ge-offerings-page .ge-toast-msg {
        position: fixed;
        bottom: 30px;
        left: 50%;
        transform: translateX(-50%);
        color: white;
        padding: 12px 26px;
        border-radius: 50px;
        font-size: 0.85rem;
        font-weight: 500;
        backdrop-filter: blur(6px);
        background: #1f3b4cee;
        box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        z-index: 2000;
        transition: opacity 0.2s;
        pointer-events: none;
        opacity: 0;
        max-width: min(90vw, 32rem);
        text-align: center;
    }
    .ge-offerings-page .ge-toast-msg.ge-show { opacity: 1; }
    .ge-offerings-page .ge-toast-msg.ge-toast--ok { background: rgba(43, 110, 71, 0.92); }
    .ge-offerings-page .ge-toast-msg.ge-toast--err { background: rgba(139, 47, 47, 0.92); }
    .ge-offerings-page .ge-flash-banner {
        border-radius: 20px;
        padding: 0.85rem 1.2rem;
        margin-bottom: 1.25rem;
        font-size: 0.9rem;
        font-weight: 500;
    }
    .ge-offerings-page .ge-flash-banner--warn {
        background: #fff8e6;
        border: 1px solid #e9d48a;
        color: #6b5a1a;
    }
    .ge-offerings-page .ge-flash-banner a { color: #0d5a9e; font-weight: 600; }
    html[data-bs-theme="dark"] .ge-offerings-page {
        --ge-text: #d9e8f3;
        background: linear-gradient(145deg, #101b24 0%, #172633 100%);
    }
    html[data-bs-theme="dark"] .ge-offerings-page .ge-module-header h1 {
        background: linear-gradient(135deg, #cae9ff, #90d3fb);
        background-clip: text;
        -webkit-background-clip: text;
        color: transparent;
    }
    html[data-bs-theme="dark"] .ge-offerings-page .ge-module-header h1 i { color: #69b8e8; }
    html[data-bs-theme="dark"] .ge-offerings-page .ge-subhead {
        color: #9db4c5;
        border-left-color: #4a9de6;
    }
    html[data-bs-theme="dark"] .ge-offerings-page .ge-card {
        background: rgba(20, 33, 44, 0.95);
        border-color: #2c3f4f;
        box-shadow: 0 20px 35px -14px rgba(0, 0, 0, 0.45), 0 1px 2px rgba(0, 0, 0, 0.2);
    }
    html[data-bs-theme="dark"] .ge-offerings-page .ge-card:hover {
        box-shadow: 0 24px 42px -16px rgba(0, 0, 0, 0.52);
    }
    html[data-bs-theme="dark"] .ge-offerings-page .ge-card-header {
        background: rgba(22, 37, 49, 0.95);
        border-bottom-color: #2d4150;
    }
    html[data-bs-theme="dark"] .ge-offerings-page .ge-card-header i { color: #6fb8de; }
    html[data-bs-theme="dark"] .ge-offerings-page .ge-card-header h2 { color: #d1e6f4; }
    html[data-bs-theme="dark"] .ge-offerings-page .ge-selector label,
    html[data-bs-theme="dark"] .ge-offerings-page .ge-checkbox-item label { color: #c4d9e8; }
    html[data-bs-theme="dark"] .ge-offerings-page .ge-custom-select {
        background: #101c27;
        border-color: #355167;
        color: #deecf7;
    }
    html[data-bs-theme="dark"] .ge-offerings-page .ge-custom-select:focus {
        border-color: #5da8ef;
        box-shadow: 0 0 0 3px rgba(93, 168, 239, 0.24);
    }
    html[data-bs-theme="dark"] .ge-offerings-page .ge-college-check-group {
        background: #162633;
        border-color: #324959;
    }
    html[data-bs-theme="dark"] .ge-offerings-page .ge-college-check-group h3 { color: #a8c3d8; }
    html[data-bs-theme="dark"] .ge-offerings-page .ge-checkbox-item:hover { background: #1f3444; }
    html[data-bs-theme="dark"] .ge-offerings-page .ge-btn-save {
        background: linear-gradient(100deg, #1f6fa5, #124c72);
        box-shadow: 0 6px 16px rgba(0, 0, 0, 0.35);
    }
    html[data-bs-theme="dark"] .ge-offerings-page .ge-btn-save:hover:not(:disabled) {
        background: linear-gradient(100deg, #1a6090, #103e5d);
    }
    html[data-bs-theme="dark"] .ge-offerings-page .ge-offerings-table th {
        background: #1a2d3b;
        color: #c8deed;
        border-bottom-color: #355165;
    }
    html[data-bs-theme="dark"] .ge-offerings-page .ge-offerings-table td {
        color: #d4e7f4;
        border-bottom-color: #2a4050;
    }
    html[data-bs-theme="dark"] .ge-offerings-page .ge-college-badge {
        background: #274053;
        color: #b9ddf3;
    }
    html[data-bs-theme="dark"] .ge-offerings-page .ge-college-badge.ge-badge-multiple {
        background: #325166;
        color: #d1ecff;
    }
    html[data-bs-theme="dark"] .ge-offerings-page .ge-empty-message,
    html[data-bs-theme="dark"] .ge-offerings-page .ge-empty-inline { color: #8fa8ba; }
    html[data-bs-theme="dark"] .ge-offerings-page .ge-options-legend {
        background: #172938;
        border-color: #324a5a;
    }
    html[data-bs-theme="dark"] .ge-offerings-page .ge-legend-title { color: #b6d2e4; }
    html[data-bs-theme="dark"] .ge-offerings-page .ge-legend-badge {
        background: #1f3545;
        border-color: #365268;
        color: #cce3f1;
        box-shadow: none;
    }
    html[data-bs-theme="dark"] .ge-offerings-page .ge-flash-banner--warn {
        background: #3a321f;
        border-color: #6d5a32;
        color: #f2dcaa;
    }
    html[data-bs-theme="dark"] .ge-offerings-page .ge-flash-banner a { color: #8ec5ff; }
    @media (max-width: 800px) {
        .ge-offerings-page { padding: 1rem; margin-left: -0.5rem; margin-right: -0.5rem; }
        .ge-offerings-page .ge-grid { grid-template-columns: 1fr; gap: 20px; }
        .ge-offerings-page .ge-card-body { padding: 1.2rem; }
    }
</style>

<div class="ge-offerings-page">
<div class="ge-dashboard-container">
    <div class="ge-module-header">
        <h1><i class="fas fa-chalkboard-user" aria-hidden="true"></i> GE Course Offerings · by College</h1>
        <div class="ge-subhead">Assign general education courses to academic colleges &amp; manage cross-offerings</div>
    </div>

    <?php if (!$hasIsGened || !$hasAssignTable): ?>
        <div class="ge-flash-banner ge-flash-banner--warn">Run <a href="upgrade_roles.php">upgrade_roles.php</a> to enable GE assignments.</div>
    <?php endif; ?>

    <div class="ge-grid">
        <div class="ge-card">
            <div class="ge-card-header">
                <i class="fas fa-pen-ruler" aria-hidden="true"></i>
                <h2>Assign colleges to a GE course</h2>
            </div>
            <div class="ge-card-body">
                <form method="get" id="geCourseSelectForm" class="ge-selector">
                    <label for="geCourseSelect"><i class="fas fa-book-open" aria-hidden="true"></i> GE Course</label>
                    <select id="geCourseSelect" name="course_id" class="ge-custom-select" onchange="this.form.submit()" <?= $courses === [] ? 'disabled' : '' ?>>
                        <?php foreach ($courses as $c): ?>
                            <option value="<?= (int) $c['id'] ?>" <?= $selectedCourseId === (int) $c['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['course_code'] . ' - ' . $c['course_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <form method="post">
                    <input type="hidden" name="course_id" value="<?= (int) $selectedCourseId ?>">
                    <div class="ge-college-check-group">
                        <h3><i class="fas fa-building-columns" aria-hidden="true"></i> Colleges where this GE course is offered</h3>
                        <?php if ($courses === []): ?>
                            <p class="mb-0 small text-muted">No GE courses are marked in the catalog yet.</p>
                        <?php endif; ?>
                        <?php foreach ($colleges as $col): ?>
                            <div class="ge-checkbox-item">
                                <input type="checkbox" name="college_ids[]" value="<?= (int) $col['id'] ?>" id="col_<?= (int) $col['id'] ?>"
                                    <?= in_array((int) $col['id'], $assigned, true) ? 'checked' : '' ?>
                                    <?= (!$hasIsGened || !$hasAssignTable || $courses === []) ? 'disabled' : '' ?>>
                                <label for="col_<?= (int) $col['id'] ?>"><strong><?= htmlspecialchars($col['college_code']) ?></strong> – <?= htmlspecialchars($col['college_name']) ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" class="ge-btn-save"<?= (!$hasIsGened || !$hasAssignTable || $courses === []) ? ' disabled' : '' ?><?= app_tooltip_attr('Saves which colleges or cohorts receive this GE offering. Use after adjusting checkboxes or targets.') ?>>
                        <i class="fas fa-save" aria-hidden="true"></i> Save offerings
                    </button>
                </form>
            </div>
        </div>

        <div class="ge-card">
            <div class="ge-card-header">
                <i class="fas fa-table-list" aria-hidden="true"></i>
                <h2>Current GE offerings</h2>
            </div>
            <div class="ge-card-body">
                <div class="ge-offerings-table-wrapper">
                    <table class="ge-offerings-table" id="offeringsTable">
                        <thead>
                            <tr><th>GE Course</th><th>Assigned Colleges</th></tr>
                        </thead>
                        <tbody>
                        <?php if ($courses === []): ?>
                            <tr><td colspan="2" class="ge-empty-message">No GE courses in the catalog yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($geTableRows as $row): ?>
                                <tr>
                                    <td style="font-weight:500;"><?= htmlspecialchars($row['course']) ?></td>
                                    <td>
                                        <?php if ($row['colleges'] === []): ?>
                                            <span class="ge-empty-inline">— Not assigned —</span>
                                        <?php else: ?>
                                            <?php foreach ($row['colleges'] as $code): ?>
                                                <?php
                                                $codeStr = (string) $code;
                                                $tip = $collegeNameByCode[$codeStr] ?? $codeStr;
                                                ?>
                                                <span class="ge-college-badge<?= count($row['colleges']) > 1 ? ' ge-badge-multiple' : '' ?>" title="<?= htmlspecialchars($tip) ?>"><?= htmlspecialchars($codeStr) ?></span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="ge-options-legend">
        <span class="ge-legend-title"><i class="fas fa-graduation-cap" aria-hidden="true"></i> College codes &amp; names:</span>
        <?php foreach ($colleges as $col): ?>
            <span class="ge-legend-badge"><strong><?= htmlspecialchars($col['college_code']) ?></strong> – <?= htmlspecialchars($col['college_name']) ?></span>
        <?php endforeach; ?>
    </div>
</div>
<div id="geToastMessage" class="ge-toast-msg" role="status" aria-live="polite"></div>
</div>

<script>
(function () {
    var toast = document.getElementById('geToastMessage');
    var tmo = null;
    window.showGeToast = function (message, isError) {
        if (!toast) return;
        toast.textContent = message;
        toast.classList.remove('ge-toast--ok', 'ge-toast--err');
        toast.classList.add(isError ? 'ge-toast--err' : 'ge-toast--ok');
        toast.classList.add('ge-show');
        if (tmo) clearTimeout(tmo);
        tmo = setTimeout(function () { toast.classList.remove('ge-show'); }, 2600);
    };
    <?php if ($flash !== ''): ?>
    document.addEventListener('DOMContentLoaded', function () {
        showGeToast(<?= json_encode($flash, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= str_starts_with($flash, 'Error:') ? 'true' : 'false' ?>);
    });
    <?php endif; ?>
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

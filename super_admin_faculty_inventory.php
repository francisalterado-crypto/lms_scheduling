<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_role(['super_admin']);

$collegeId = (int) ($_GET['college_id'] ?? 0);
$scope = trim((string) ($_GET['scope'] ?? 'all'));
$search = trim((string) ($_GET['q'] ?? ''));
$allowedScopes = ['all', 'ge', 'non_ge'];
if (!in_array($scope, $allowedScopes, true)) {
    $scope = 'all';
}

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'delete' && isset($_POST['id'])) {
    $facultyRowId = (int) $_POST['id'];
    try {
        db()->beginTransaction();
        $stFaculty = db()->prepare('SELECT id, user_id FROM faculty WHERE id = ? LIMIT 1');
        $stFaculty->execute([$facultyRowId]);
        $facultyRow = $stFaculty->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$facultyRow) {
            throw new RuntimeException('Faculty record not found.');
        }

        db()->prepare('DELETE FROM faculty WHERE id = ?')->execute([$facultyRowId]);
        $linkedUserId = (int) ($facultyRow['user_id'] ?? 0);
        if ($linkedUserId > 0) {
            db()->prepare("DELETE FROM users WHERE id = ? AND role = 'faculty'")->execute([$linkedUserId]);
        }
        db()->commit();
        $_SESSION['flash'] = 'Faculty record deleted.';
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        $_SESSION['flash'] = 'Error: ' . $e->getMessage();
    }

    $query = [];
    if ($collegeId > 0) {
        $query['college_id'] = (string) $collegeId;
    }
    if ($scope !== 'all') {
        $query['scope'] = $scope;
    }
    if ($search !== '') {
        $query['q'] = $search;
    }
    $redirect = 'super_admin_faculty_inventory.php';
    if ($query !== []) {
        $redirect .= '?' . http_build_query($query);
    }
    header('Location: ' . $redirect);
    exit;
}

$hasEmploymentStatusColumn = db_column_exists('faculty', 'employment_status');
$employmentSelect = $hasEmploymentStatusColumn
    ? 'f.employment_status'
    : "'Permanent' AS employment_status";

$sql = "SELECT f.id, f.user_id, f.faculty_id, f.full_name, f.department, f.email, f.max_hours_per_day, f.status,
               COALESCE(f.is_gened,0) AS is_gened, f.college_id, {$employmentSelect},
               u.username, u.is_active,
               c.college_code, c.college_name
        FROM faculty f
        LEFT JOIN users u ON u.id = f.user_id
        LEFT JOIN colleges c ON c.id = f.college_id
        WHERE 1=1";
$params = [];
if ($collegeId > 0) {
    $sql .= ' AND f.college_id = ?';
    $params[] = $collegeId;
}
if ($scope === 'ge') {
    $sql .= ' AND COALESCE(f.is_gened,0) = 1';
} elseif ($scope === 'non_ge') {
    $sql .= ' AND COALESCE(f.is_gened,0) = 0';
}
if ($search !== '') {
    $sql .= ' AND (f.full_name LIKE ? OR f.faculty_id LIKE ? OR f.department LIKE ? OR u.username LIKE ?';
    if ($hasEmploymentStatusColumn) {
        $sql .= ' OR f.employment_status LIKE ?';
    }
    $sql .= ')';
    $needle = '%' . $search . '%';
    $params[] = $needle;
    $params[] = $needle;
    $params[] = $needle;
    $params[] = $needle;
    if ($hasEmploymentStatusColumn) {
        $params[] = $needle;
    }
}
$sql .= ' ORDER BY c.college_code ASC, f.full_name ASC';
$st = db()->prepare($sql);
$st->execute($params);
$list = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$collegeOptions = db()->query(
    'SELECT id, college_code, college_name FROM colleges ORDER BY college_code, college_name'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$export = trim((string) ($_GET['export'] ?? ''));
if ($export === 'excel') {
    $filename = 'faculty_inventory_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'wb');
    if ($out === false) {
        http_response_code(500);
        exit('Unable to generate export file.');
    }

    fwrite($out, "\xEF\xBB\xBF");

    $headers = ['Faculty ID', 'Name', 'College', 'Program', 'GE'];
    if ($hasEmploymentStatusColumn) {
        $headers[] = 'Employment';
    }
    $headers[] = 'Email';
    $headers[] = 'Status';
    fputcsv($out, $headers);

    foreach ($list as $r) {
        $collegeCode = trim((string) ($r['college_code'] ?? ''));
        $collegeName = trim((string) ($r['college_name'] ?? ''));
        $collegeLabel = $collegeCode !== '' ? $collegeCode : 'N/A';
        if ($collegeName !== '') {
            $collegeLabel .= ' - ' . $collegeName;
        }

        $row = [
            (string) ($r['faculty_id'] ?? ''),
            (string) ($r['full_name'] ?? ''),
            $collegeLabel,
            (string) ($r['department'] ?? ''),
            ((int) ($r['is_gened'] ?? 0) === 1) ? 'Yes' : 'No',
        ];
        if ($hasEmploymentStatusColumn) {
            $row[] = (string) ($r['employment_status'] ?? 'Permanent');
        }
        $row[] = (string) ($r['email'] ?? '');
        $row[] = (string) ($r['status'] ?? '');
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

$pageTitle = 'Faculty inventory';
$mainContainerClass = 'container py-4 py-md-5 app-main';
require_once __DIR__ . '/includes/header.php';
?>
<style>
    :root {
        --fi-surface: #ffffff;
        --fi-surface-soft: #fafcff;
        --fi-border: #e9edf2;
        --fi-border-soft: #f0f4f9;
        --fi-text: #1e293b;
        --fi-text-muted: #475569;
        --fi-chip-bg: #eef2ff;
        --fi-chip-text: #1f543b;
        --fi-chip-strong: #0f3b2a;
        --fi-college-bg: #eef2ff;
        --fi-college-text: #1e3a5f;
    }
    [data-bs-theme="dark"] {
        --fi-surface: #111827;
        --fi-surface-soft: #1f2937;
        --fi-border: #334155;
        --fi-border-soft: #293548;
        --fi-text: #e5e7eb;
        --fi-text-muted: #cbd5e1;
        --fi-chip-bg: #1f2937;
        --fi-chip-text: #86efac;
        --fi-chip-strong: #bbf7d0;
        --fi-college-bg: #1e293b;
        --fi-college-text: #bfdbfe;
    }
    @media (prefers-color-scheme: dark) {
        :root:not([data-bs-theme="light"]) {
            --fi-surface: #111827;
            --fi-surface-soft: #1f2937;
            --fi-border: #334155;
            --fi-border-soft: #293548;
            --fi-text: #e5e7eb;
            --fi-text-muted: #cbd5e1;
            --fi-chip-bg: #1f2937;
            --fi-chip-text: #86efac;
            --fi-chip-strong: #bbf7d0;
            --fi-college-bg: #1e293b;
            --fi-college-text: #bfdbfe;
        }
    }
    .faculty-dashboard {
        max-width: 1440px;
        margin: 0 auto;
        color: var(--fi-text);
    }
    .faculty-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        flex-wrap: wrap;
        gap: 1rem;
        margin-bottom: 1.75rem;
    }
    .faculty-title h1 {
        margin: 0;
        font-size: 1.8rem;
        font-weight: 700;
        letter-spacing: -0.3px;
        background: linear-gradient(135deg, #1e3c2c, #2b5e3b);
        -webkit-background-clip: text;
        background-clip: text;
        color: transparent;
    }
    .faculty-title p {
        margin: 0.4rem 0 0;
        font-size: 0.9rem;
        color: var(--fi-text-muted);
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .admin-chip {
        background: var(--fi-surface);
        border: 1px solid var(--fi-border);
        border-radius: 40px;
        padding: 0.4rem 1rem;
        font-size: 0.8rem;
        font-weight: 500;
        color: var(--fi-text);
        display: inline-flex;
        align-items: center;
        gap: 8px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    }
    .filter-card {
        background: var(--fi-surface);
        border: 1px solid var(--fi-border);
        border-radius: 24px;
        padding: 1.25rem 1.5rem;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03), 0 1px 2px rgba(0, 0, 0, 0.05);
        margin-bottom: 1.75rem;
    }
    .filter-label {
        display: block;
        margin-bottom: 0.4rem;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: var(--fi-text-muted);
    }
    .filter-card .form-select,
    .filter-card .form-control {
        border-radius: 16px;
        border: 1px solid var(--fi-border);
        padding: 0.7rem 0.9rem;
        background: var(--fi-surface);
        color: var(--fi-text);
    }
    .filter-card .form-select:focus,
    .filter-card .form-control:focus {
        border-color: #2c7a4b;
        box-shadow: 0 0 0 3px rgba(44, 122, 75, 0.2);
    }
    .search-wrapper {
        position: relative;
    }
    .search-wrapper .search-icon {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--fi-text-muted);
        font-size: 0.9rem;
    }
    .search-wrapper input {
        padding-left: 2.3rem;
    }
    .btn-apply {
        background: #1f543b;
        border: none;
        border-radius: 40px;
        padding: 0.7rem 1.4rem;
        color: #fff;
        font-weight: 600;
        font-size: 0.85rem;
    }
    .btn-apply:hover {
        background: #13472e;
        color: #fff;
    }
    .stats-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.75rem;
        margin-bottom: 1rem;
    }
    .result-count {
        background: var(--fi-chip-bg);
        color: var(--fi-chip-text);
        border-radius: 40px;
        padding: 0.3rem 1rem;
        font-size: 0.8rem;
        font-weight: 500;
    }
    .result-count span {
        font-weight: 800;
        color: var(--fi-chip-strong);
    }
    .table-shell {
        background: var(--fi-surface);
        border: 1px solid var(--fi-border);
        border-radius: 24px;
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.02);
        overflow: hidden;
    }
    .table-shell .table {
        margin-bottom: 0;
        min-width: 980px;
    }
    .table-shell thead th {
        background: var(--fi-surface-soft);
        border-bottom: 1px solid var(--fi-border);
        color: var(--fi-text-muted);
        font-size: 0.8rem;
        letter-spacing: 0.3px;
        font-weight: 600;
        white-space: nowrap;
        padding: 1rem;
    }
    .table-shell tbody td {
        border-color: var(--fi-border-soft);
        color: var(--fi-text);
        padding: 1rem;
    }
    .table-shell tbody tr:hover td {
        background: color-mix(in srgb, var(--fi-surface-soft) 75%, #1f543b 25%);
    }
    .college-badge {
        background: var(--fi-college-bg);
        color: var(--fi-college-text);
        border-radius: 20px;
        padding: 0.2rem 0.7rem;
        display: inline-block;
        font-size: 0.75rem;
        font-weight: 500;
    }
    .ge-badge {
        border-radius: 30px;
        padding: 0.2rem 0.6rem;
        font-size: 0.7rem;
        font-weight: 600;
        display: inline-block;
    }
    .ge-badge.yes {
        background: #dcfce7;
        color: #15803d;
    }
    .ge-badge.no {
        background: #fee2e2;
        color: #b91c1c;
    }
    .employment-tag {
        background: var(--fi-surface-soft);
        border-radius: 30px;
        padding: 0.2rem 0.7rem;
        font-size: 0.75rem;
        font-weight: 500;
        color: var(--fi-text-muted);
        display: inline-block;
    }
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #e6f7ec;
        color: #166534;
        border-radius: 40px;
        padding: 0.25rem 0.7rem;
        font-weight: 500;
        font-size: 0.75rem;
    }
    [data-bs-theme="dark"] .text-muted,
    [data-bs-theme="dark"] .small {
        color: var(--fi-text-muted) !important;
    }
    [data-bs-theme="dark"] .btn-outline-success {
        border-color: #34d399;
        color: #86efac;
    }
    [data-bs-theme="dark"] .btn-outline-success:hover {
        background: #14532d;
        border-color: #22c55e;
        color: #dcfce7;
    }
    @media (max-width: 767.98px) {
        .faculty-title h1 {
            font-size: 1.5rem;
        }
        .btn-apply {
            width: 100%;
        }
    }
</style>

<div class="faculty-dashboard">
    <div class="faculty-header">
        <div class="faculty-title">
            <h1><i class="fa-solid fa-chalkboard-user me-2" style="color:#2d6a4f;"></i>Faculty inventory</h1>
            <p>Western Philippines University - Super admin view <i class="fa-solid fa-university"></i></p>
        </div>
        <div class="admin-chip">
            <i class="fa-solid fa-crown"></i>
            <span>Administrator - Full access</span>
            <i class="fa-solid fa-chevron-down" style="font-size:0.7rem;"></i>
        </div>
    </div>
<?php if ($flash): ?>
    <div class="alert alert-info alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($flash) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="filter-card">
    <div>
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="filter-label"><i class="fa-solid fa-building me-1"></i>College</label>
                <select name="college_id" class="form-select">
                    <option value="0">All colleges</option>
                    <?php foreach ($collegeOptions as $c): ?>
                        <option value="<?= (int) $c['id'] ?>"<?= $collegeId === (int) $c['id'] ? ' selected' : '' ?>>
                            <?= htmlspecialchars(trim((string) ($c['college_code'] ?? '')) . ' - ' . trim((string) ($c['college_name'] ?? ''))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="filter-label"><i class="fa-solid fa-globe me-1"></i>Scope</label>
                <select name="scope" class="form-select">
                    <option value="all"<?= $scope === 'all' ? ' selected' : '' ?>>All faculty</option>
                    <option value="ge"<?= $scope === 'ge' ? ' selected' : '' ?>>GE only</option>
                    <option value="non_ge"<?= $scope === 'non_ge' ? ' selected' : '' ?>>Non-GE only</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="filter-label"><i class="fa-solid fa-magnifying-glass me-1"></i>Search</label>
                <div class="search-wrapper">
                    <i class="fa-solid fa-magnifying-glass search-icon"></i>
                    <input type="text" name="q" class="form-control" value="<?= htmlspecialchars($search) ?>" placeholder="Name, ID, program, username">
                </div>
            </div>
            <div class="col-md-2 d-grid">
                <button type="submit" class="btn btn-apply"><i class="fa-solid fa-sliders me-1"></i>Apply filters</button>
            </div>
            <div class="col-md-2 d-grid">
                <a
                    class="btn btn-outline-success"
                    href="super_admin_faculty_inventory.php?<?= htmlspecialchars(http_build_query([
                        'college_id' => $collegeId,
                        'scope' => $scope,
                        'q' => $search,
                        'export' => 'excel',
                    ])) ?>"
                >
                    <i class="fa-solid fa-file-excel me-1"></i>Download Excel
                </a>
            </div>
        </form>
    </div>
</div>

<div class="stats-row">
    <div class="result-count"><i class="fa-solid fa-users me-1"></i><span><?= count($list) ?></span> faculty records</div>
    <div class="text-muted small"><i class="fa-solid fa-database me-1"></i>Last sync: today</div>
</div>

<div class="table-shell">
    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
            <tr>
                <th>Faculty ID</th>
                <th>Name</th>
                <th>College</th>
                <th>Program</th>
                <th>GE</th>
                <?php if ($hasEmploymentStatusColumn): ?><th>Employment</th><?php endif; ?>
                <th>Email</th>
                <th>Status</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($list as $r): ?>
                <?php
                $collegeCode = trim((string) ($r['college_code'] ?? ''));
                $collegeName = trim((string) ($r['college_name'] ?? ''));
                $collegeLabel = $collegeCode !== '' ? $collegeCode : 'N/A';
                if ($collegeName !== '') {
                    $collegeLabel .= ' - ' . $collegeName;
                }
                $isGened = (int) ($r['is_gened'] ?? 0) === 1;
                $statusRaw = trim((string) ($r['status'] ?? ''));
                $statusLabel = $statusRaw !== '' ? ucfirst(strtolower($statusRaw)) : 'Active';
                $statusIconClass = strtolower($statusLabel) === 'active'
                    ? 'fa-solid fa-circle-check text-success'
                    : 'fa-solid fa-circle-exclamation';
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars((string) ($r['faculty_id'] ?? '')) ?></strong></td>
                    <td><?= htmlspecialchars((string) ($r['full_name'] ?? '')) ?></td>
                    <td><span class="college-badge"><?= htmlspecialchars($collegeLabel) ?></span></td>
                    <td><?= htmlspecialchars((string) ($r['department'] ?? '')) ?></td>
                    <td>
                        <span class="ge-badge <?= $isGened ? 'yes' : 'no' ?>">
                            <?= $isGened ? 'Yes' : 'No' ?>
                        </span>
                    </td>
                    <?php if ($hasEmploymentStatusColumn): ?>
                        <td><span class="employment-tag"><?= htmlspecialchars((string) ($r['employment_status'] ?? 'Permanent')) ?></span></td>
                    <?php endif; ?>
                    <td>
                        <?php $email = trim((string) ($r['email'] ?? '')); ?>
                        <?php if ($email !== ''): ?>
                            <a href="mailto:<?= htmlspecialchars($email) ?>" style="color:#2c7a4b; text-decoration:none;">
                                <?= htmlspecialchars($email) ?>
                            </a>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="status-badge"><i class="<?= $statusIconClass ?>"></i><?= htmlspecialchars($statusLabel) ?></span>
                    </td>
                    <td class="text-nowrap">
                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this faculty record?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) ($r['id'] ?? 0) ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($list === []): ?>
                <tr>
                    <td colspan="<?= $hasEmploymentStatusColumn ? '9' : '8' ?>" class="text-center text-muted py-5">
                        <i class="fa-solid fa-user-slash me-1"></i>No faculty records found for the selected filters.
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

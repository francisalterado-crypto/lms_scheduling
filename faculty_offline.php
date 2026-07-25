<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_role(['faculty', 'program_chair', 'dean', 'gened']);

$facultyId = isset($_SESSION['faculty_id']) ? (int) $_SESSION['faculty_id'] : 0;
$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($facultyId < 1) {
    $facultyId = resolve_faculty_id_for_user($userId) ?? 0;
    $_SESSION['faculty_id'] = $facultyId > 0 ? $facultyId : null;
}
if ($facultyId < 1 && in_array($_SESSION['role'] ?? '', ['program_chair', 'dean', 'gened'], true)) {
    $facultyId = ensure_faculty_profile_for_teaching_role($userId) ?? 0;
    if ($facultyId > 0) {
        $_SESSION['faculty_id'] = $facultyId;
    }
}
if ($facultyId < 1) {
    exit('Faculty profile not linked to this account. Ask your dean to create/link your faculty profile.');
}

$assignableClassrooms = faculty_owned_classrooms($facultyId);

$pageTitle = 'Offline uploads';
require_once __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4">
    <div class="min-w-0">
        <h1 class="h3 mb-1"><i class="fa-solid fa-cloud-arrow-up me-2 text-primary"></i>Offline uploads</h1>
        <p class="text-muted small mb-0">Queue course files and posts while offline; they upload automatically when you reconnect.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-primary btn-sm px-3" data-offline-sync-now<?= app_tooltip_attr('Uploads every pending offline post and file now (requires internet).') ?>>
            <i class="fa-solid fa-arrows-rotate me-1"></i>Sync now
        </button>
        <a href="faculty_classrooms.php" class="btn btn-outline-secondary btn-sm px-3"<?= app_tooltip_attr('Returns to your classroom list.') ?>>My classrooms</a>
    </div>
</div>

<p class="small mb-2" data-offline-status>Checking connection and queue…</p>
<p class="small mb-3 text-muted" data-offline-pending-label>Checking pending uploads…</p>

<div data-offline-queue></div>

<script type="application/json" id="offline-classroom-options"><?= json_encode(array_map(
    static function (array $row): array {
        $label = trim((string) ($row['course_code'] ?? ''));
        if ($label !== '' && trim((string) ($row['course_name'] ?? '')) !== '') {
            $label .= ' — ' . trim((string) $row['course_name']);
        } elseif (trim((string) ($row['course_name'] ?? '')) !== '') {
            $label = trim((string) $row['course_name']);
        } else {
            $label = trim((string) ($row['title'] ?? 'Classroom'));
        }
        $term = trim((string) ($row['semester'] ?? '') . ' ' . (string) ($row['school_year'] ?? ''));
        if ($term !== '') {
            $label .= ' (' . $term . ')';
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'label' => $label,
            'course_code' => trim((string) ($row['course_code'] ?? '')),
            'course_name' => trim((string) ($row['course_name'] ?? '')),
            'classroom_title' => trim((string) ($row['title'] ?? 'Classroom')),
        ];
    },
    $assignableClassrooms
), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<script src="assets/js/faculty_offline.js" defer></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

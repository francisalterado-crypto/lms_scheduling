<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_role(['admin', 'dean', 'program_chair', 'gened']);

$role = (string) ($_SESSION['role'] ?? '');
$classroomId = (int) ($_GET['id'] ?? 0);
$monitorCollegeId = (int) ($_GET['monitor_college'] ?? 0);
$monitorProgram = trim((string) ($_GET['monitor_program'] ?? ''));
$selectedWeek = trim((string) ($_GET['week'] ?? ''));

if ($classroomId < 1) {
    http_response_code(400);
    exit('Invalid classroom.');
}

$requiredTables = ['online_classrooms', 'classroom_content', 'schedules', 'courses', 'faculty'];
foreach ($requiredTables as $table) {
    if (!db_table_exists($table)) {
        http_response_code(503);
        exit('Classroom monitoring is not installed yet. Run upgrade_roles.php once.');
    }
}

$hasCourseIsGened = db_column_exists('courses', 'is_gened');
$courseIsGenedSelect = $hasCourseIsGened ? ', c.is_gened AS course_is_gened' : '';

$st = db()->prepare(
    'SELECT oc.id, oc.title, oc.faculty_id, oc.status, oc.created_at,
            s.college_id AS schedule_college_id, s.program AS schedule_program, s.semester, s.school_year,
            c.course_code, c.course_name, c.department AS course_department' . $courseIsGenedSelect . ',
            f.full_name AS faculty_name
     FROM online_classrooms oc
     INNER JOIN schedules s ON s.id = oc.schedule_id
     INNER JOIN courses c ON c.id = oc.course_id
     INNER JOIN faculty f ON f.id = oc.faculty_id
     WHERE oc.id = ?
     LIMIT 1'
);
$st->execute([$classroomId]);
$classroom = $st->fetch(PDO::FETCH_ASSOC) ?: null;
if (!$classroom) {
    http_response_code(404);
    exit('Classroom not found.');
}

$scheduleCollegeId = (int) ($classroom['schedule_college_id'] ?? 0);
$courseDepartment = trim((string) ($classroom['course_department'] ?? ''));
$scheduleProgram = trim((string) ($classroom['schedule_program'] ?? ''));
$isGeCourse = $hasCourseIsGened && (int) ($classroom['course_is_gened'] ?? 0) === 1;
$accessAllowed = false;

if ($role === 'admin') {
    $accessAllowed = true;
} elseif ($role === 'dean') {
    $collegeId = current_college_id();
    $accessAllowed = $collegeId !== null && $collegeId > 0 && $scheduleCollegeId === $collegeId;
} elseif ($role === 'program_chair') {
    $collegeId = current_college_id();
    $programScope = current_program_scope();
    $programMatches = $programScope !== null && (
        ($courseDepartment !== '' && strcasecmp($courseDepartment, $programScope) === 0)
        || ($scheduleProgram !== '' && strcasecmp($scheduleProgram, $programScope) === 0)
    );
    $accessAllowed = $collegeId !== null && $collegeId > 0 && $scheduleCollegeId === $collegeId && $programMatches;
} elseif ($role === 'gened') {
    if (!$isGeCourse) {
        $accessAllowed = false;
    } elseif ($monitorCollegeId > 0 && $monitorProgram !== '') {
        $programMatches = ($courseDepartment !== '' && strcasecmp($courseDepartment, $monitorProgram) === 0)
            || ($scheduleProgram !== '' && strcasecmp($scheduleProgram, $monitorProgram) === 0);
        $accessAllowed = $scheduleCollegeId === $monitorCollegeId && $programMatches;
    } else {
        $accessAllowed = true;
    }
}

if (!$accessAllowed) {
    http_response_code(403);
    $denyMsg = $role === 'gened'
        ? 'Access denied. GE accounts can monitor GE courses only.'
        : 'You do not have access to monitor this classroom.';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Access Denied</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{min-height:100vh;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.05);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
.notif{background:#fff;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.12);padding:28px 36px;text-align:center;max-width:340px;animation:pop .3s ease}
.notif .icon{width:48px;height:48px;margin:0 auto 14px;border-radius:50%;background:#fee2e2;display:flex;align-items:center;justify-content:center}
.notif .icon svg{width:24px;height:24px;color:#dc2626}
.notif h2{font-size:1rem;font-weight:600;color:#1f2937;margin-bottom:6px}
.notif p{font-size:.85rem;color:#6b7280;margin-bottom:18px}
.notif button{background:#3b82f6;color:#fff;border:none;border-radius:6px;padding:8px 20px;font-size:.8rem;cursor:pointer;transition:background .2s}
.notif button:hover{background:#2563eb}
@keyframes pop{from{opacity:0;transform:scale(.9)}to{opacity:1;transform:scale(1)}}
</style></head><body>
<div class="notif">
<div class="icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 0 0 5.636 5.636m12.728 12.728A9 9 0 0 1 5.636 5.636m12.728 12.728L5.636 5.636"/></svg></div>
<h2>Access Denied</h2>
<p>' . htmlspecialchars($denyMsg) . '</p>
<button onclick="window.close()">Close</button>
</div></body></html>';
    exit;
}

$hasContentWeeks = db_column_exists('classroom_content', 'weeks');
$hasContentDaysPerTopic = db_column_exists('classroom_content', 'days_per_topic');
$hasContentAttachments = db_table_exists('classroom_content_attachments');
$contentAttachmentMap = [];

$st = db()->prepare(
    'SELECT *
     FROM classroom_content
     WHERE classroom_id = ?
     ORDER BY created_at DESC'
);
$st->execute([$classroomId]);
$items = $st->fetchAll();
$contentWeeks = classroom_content_group_by_week($items);

if ($hasContentAttachments && $items !== []) {
    $contentAttachmentMap = classroom_content_attachment_map(array_column($items, 'id'));
}

$weekLookup = [];
foreach ($contentWeeks as $group) {
    $weekLookup[(string) $group['label']] = $group;
}

if ($selectedWeek === '' && $contentWeeks !== []) {
    $selectedWeek = (string) $contentWeeks[0]['label'];
} elseif ($selectedWeek !== '' && $contentWeeks !== [] && !isset($weekLookup[$selectedWeek])) {
    $selectedWeek = (string) $contentWeeks[0]['label'];
}

$filteredItems = [];
if ($selectedWeek !== '' && isset($weekLookup[$selectedWeek])) {
    $filteredItems = $weekLookup[$selectedWeek]['items'];
} elseif ($contentWeeks === []) {
    $filteredItems = $items;
}

$monitorBaseQuery = ['id' => $classroomId];
if ($monitorCollegeId > 0) {
    $monitorBaseQuery['monitor_college'] = $monitorCollegeId;
}
if ($monitorProgram !== '') {
    $monitorBaseQuery['monitor_program'] = $monitorProgram;
}

$monitorWeekHref = static function (string $weekLabel) use ($monitorBaseQuery): string {
    $query = $monitorBaseQuery;
    $query['week'] = $weekLabel;

    return 'classroom_materials_monitor.php?' . http_build_query($query);
};

$itemAttachmentSummary = static function (array $item) use ($contentAttachmentMap, $hasContentAttachments): string {
    $parts = [];
    $resource = trim((string) ($item['resource_url'] ?? ''));
    if ($resource !== '') {
        $parts[] = classroom_content_is_attachment($resource)
            ? classroom_content_attachment_name($resource)
            : 'External link';
    }
    if ($hasContentAttachments) {
        $attachments = $contentAttachmentMap[(int) ($item['id'] ?? 0)] ?? [];
        foreach ($attachments as $attachment) {
            $parts[] = classroom_content_attachment_download_name(
                (string) ($attachment['original_name'] ?? ''),
                (string) ($attachment['stored_name'] ?? '')
            );
        }
    }

    return implode(', ', $parts);
};

$pageTitle = 'Classroom materials monitor';
require_once __DIR__ . '/includes/header.php';
?>

<style>
.monitor-week-card {
    border: 1px solid rgba(0, 0, 0, 0.08);
    border-radius: 0.5rem;
    padding: 0.75rem 1rem;
    text-decoration: none;
    color: inherit;
    display: block;
    height: 100%;
    background: var(--bs-body-bg);
    transition: background 0.12s ease, border-color 0.12s ease, box-shadow 0.12s ease;
}
.monitor-week-card:hover {
    border-color: var(--bs-primary);
    background: rgba(var(--bs-primary-rgb), 0.06);
    color: inherit;
}
.monitor-week-card.is-active {
    border-color: var(--bs-primary);
    background: rgba(var(--bs-primary-rgb), 0.08);
    box-shadow: 0 0 0 1px var(--bs-primary);
}
.monitor-item-row {
    width: 100%;
    text-align: left;
    border: 1px solid rgba(0, 0, 0, 0.08);
    border-radius: 0.5rem;
    padding: 0.85rem 1rem;
    margin-bottom: 0.5rem;
    background: var(--bs-body-bg);
    cursor: pointer;
    transition: background 0.12s ease, border-color 0.12s ease;
}
.monitor-item-row:hover,
.monitor-item-row:focus-visible {
    border-color: var(--bs-primary);
    background: rgba(var(--bs-primary-rgb), 0.04);
    outline: none;
}
.monitor-item-row:last-child {
    margin-bottom: 0;
}
</style>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4">
    <div>
        <h1 class="h3 mb-1"><i class="fa-solid fa-folder-tree me-2 text-primary"></i>Classroom materials monitor</h1>
        <div class="text-muted">
            <?= htmlspecialchars((string) $classroom['course_code']) ?> - <?= htmlspecialchars((string) $classroom['course_name']) ?>
            | Faculty: <?= htmlspecialchars((string) $classroom['faculty_name']) ?>
            | <?= htmlspecialchars((string) $classroom['semester']) ?> / <?= htmlspecialchars((string) $classroom['school_year']) ?>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="view_schedule.php" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Back to Weekly Schedule</a>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white">
        <strong>Weekly topic coverage</strong>
    </div>
    <div class="card-body">
        <?php if ($contentWeeks === []): ?>
            <p class="text-muted mb-0">No posted materials/topics yet for this classroom.</p>
        <?php else: ?>
            <p class="small text-muted mb-3">Select a week to browse posted materials and topics for that period.</p>
            <div class="row g-2">
                <?php foreach ($contentWeeks as $group): ?>
                    <?php $weekLabel = (string) $group['label']; ?>
                    <?php $isActiveWeek = $selectedWeek === $weekLabel; ?>
                    <div class="col-md-4 col-lg-3">
                        <a
                            class="monitor-week-card<?= $isActiveWeek ? ' is-active' : '' ?>"
                            href="<?= htmlspecialchars($monitorWeekHref($weekLabel)) ?>"
                            <?= app_tooltip_attr('Show materials posted for ' . $weekLabel . '.') ?>
                        >
                            <div class="fw-semibold"><i class="fa-regular fa-calendar me-2 text-primary"></i><?= htmlspecialchars($weekLabel) ?></div>
                            <div class="small text-muted"><?= (int) $group['count'] ?> item<?= (int) $group['count'] === 1 ? '' : 's' ?></div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <strong>
            Posted materials and topics
            <?php if ($selectedWeek !== '' && $contentWeeks !== []): ?>
                <span class="text-muted fw-normal">— <?= htmlspecialchars($selectedWeek) ?></span>
            <?php endif; ?>
        </strong>
        <span class="badge text-bg-primary"><?= count($filteredItems) ?> in view<?= $contentWeeks !== [] ? ' · ' . count($items) . ' total' : '' ?></span>
    </div>
    <div class="card-body">
        <?php if ($items === []): ?>
            <p class="text-muted mb-0">No classroom content to monitor yet.</p>
        <?php elseif ($filteredItems === []): ?>
            <p class="text-muted mb-0">No materials were posted for this week.</p>
        <?php else: ?>
            <p class="small text-muted mb-3">Click an item to open its full details in a popup.</p>
            <?php foreach ($filteredItems as $item): ?>
                <?php
                $itemId = (int) ($item['id'] ?? 0);
                $attachmentSummary = $itemAttachmentSummary($item);
                $hasBody = trim((string) ($item['body'] ?? '')) !== '';
                ?>
                <button
                    type="button"
                    class="monitor-item-row"
                    data-bs-toggle="modal"
                    data-bs-target="#monitorContentModal"
                    data-monitor-detail="monitor-detail-<?= $itemId ?>"
                    data-monitor-title="<?= htmlspecialchars((string) ($item['title'] ?? 'Untitled'), ENT_QUOTES) ?>"
                    <?= app_tooltip_attr('Open full details for ' . (string) ($item['title'] ?? 'this item') . '.') ?>
                >
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                        <div class="min-w-0">
                            <div class="fw-semibold"><?= htmlspecialchars((string) ($item['title'] ?? 'Untitled')) ?></div>
                            <div class="small text-muted text-capitalize"><?= htmlspecialchars((string) ($item['content_type'] ?? 'material')) ?></div>
                        </div>
                        <div class="small text-muted text-nowrap"><?= htmlspecialchars((string) ($item['created_at'] ?? '')) ?></div>
                    </div>
                    <div class="small text-muted mt-2 d-flex flex-wrap gap-2">
                        <?php if ($hasBody): ?>
                            <span><i class="fa-regular fa-file-lines me-1"></i>Has description</span>
                        <?php endif; ?>
                        <?php if ($attachmentSummary !== ''): ?>
                            <span><i class="fa-solid fa-paperclip me-1"></i><?= htmlspecialchars($attachmentSummary) ?></span>
                        <?php endif; ?>
                        <?php if (!$hasBody && $attachmentSummary === ''): ?>
                            <span><i class="fa-regular fa-eye me-1"></i>View details</span>
                        <?php endif; ?>
                    </div>
                </button>

                <div id="monitor-detail-<?= $itemId ?>" class="d-none">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                        <div>
                            <div class="text-muted small text-capitalize mb-1"><?= htmlspecialchars((string) ($item['content_type'] ?? 'material')) ?></div>
                            <div class="small text-muted">Posted <?= htmlspecialchars((string) ($item['created_at'] ?? '')) ?></div>
                        </div>
                    </div>

                    <?php if ($hasContentWeeks): ?>
                        <div class="small text-muted mb-3">
                            <?php $weekLabel = trim((string) ($item['weeks'] ?? '')); ?>
                            <span class="me-3">
                                <i class="fa-regular fa-calendar me-1"></i>Week: <?= htmlspecialchars($weekLabel !== '' ? $weekLabel : 'General resources') ?>
                            </span>
                            <?php if ($hasContentDaysPerTopic): ?>
                                <?php $daysTopic = trim((string) ($item['days_per_topic'] ?? '')); ?>
                                <?php if ($daysTopic !== ''): ?>
                                    <span><i class="fa-regular fa-clock me-1"></i>Days/topic: <?= htmlspecialchars($daysTopic) ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($hasBody): ?>
                        <div class="classroom-content-body mb-3"><?= classroom_content_render_body((string) $item['body']) ?></div>
                    <?php endif; ?>

                    <?php $resource = trim((string) ($item['resource_url'] ?? '')); ?>
                    <?php if ($resource !== ''): ?>
                        <div class="mb-2">
                            <?php if (classroom_content_is_attachment($resource)): ?>
                                <span class="text-muted"><i class="fa-solid fa-paperclip me-1"></i><?= htmlspecialchars(classroom_content_attachment_name($resource)) ?></span>
                            <?php else: ?>
                                <a href="<?= htmlspecialchars($resource) ?>" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-link me-1"></i>External resource</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($hasContentAttachments): ?>
                        <?php $attachments = $contentAttachmentMap[$itemId] ?? []; ?>
                        <?php foreach ($attachments as $attachment): ?>
                            <div class="mb-2">
                                <span class="text-muted">
                                    <i class="fa-solid fa-paperclip me-1"></i><?= htmlspecialchars(classroom_content_attachment_download_name(
                                        (string) ($attachment['original_name'] ?? ''),
                                        (string) ($attachment['stored_name'] ?? '')
                                    )) ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php
                    $detailAttachments = $hasContentAttachments ? ($contentAttachmentMap[$itemId] ?? []) : [];
                    ?>
                    <?php if (!$hasBody && $resource === '' && $detailAttachments === []): ?>
                        <p class="text-muted mb-0">No additional details were posted for this item.</p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="monitorContentModal" tabindex="-1" aria-labelledby="monitorContentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-0 pb-0">
                <h2 class="modal-title h5 fw-semibold" id="monitorContentModalLabel">Content detail</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"<?= app_tooltip_attr('Close this detail view.') ?>></button>
            </div>
            <div class="modal-body pt-2" id="monitorContentModalBody"></div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal"<?= app_tooltip_attr('Close this detail view.') ?>>Close</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('monitorContentModal');
    if (!modal) {
        return;
    }

    modal.addEventListener('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        if (!trigger) {
            return;
        }

        var detailId = trigger.getAttribute('data-monitor-detail') || '';
        var title = trigger.getAttribute('data-monitor-title') || 'Content detail';
        var source = detailId ? document.getElementById(detailId) : null;
        var titleEl = document.getElementById('monitorContentModalLabel');
        var bodyEl = document.getElementById('monitorContentModalBody');

        if (titleEl) {
            titleEl.textContent = title;
        }
        if (bodyEl) {
            bodyEl.innerHTML = source ? source.innerHTML : '<p class="text-muted mb-0">Details are not available.</p>';
        }
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_role(['admin', 'dean', 'program_chair', 'gened']);

$role = (string) ($_SESSION['role'] ?? '');
$classroomId = (int) ($_GET['id'] ?? 0);
$monitorCollegeId = (int) ($_GET['monitor_college'] ?? 0);
$monitorProgram = trim((string) ($_GET['monitor_program'] ?? ''));

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
    // GE can monitor GE courses; when filter params are present, keep them enforced.
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
    if ($role === 'gened') {
        exit('Access denied. GE accounts can monitor GE courses only.');
    }
    exit('You do not have access to monitor this classroom.');
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

$pageTitle = 'Classroom materials monitor';
require_once __DIR__ . '/includes/header.php';
?>

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
            <div class="row g-2">
                <?php foreach ($contentWeeks as $group): ?>
                    <div class="col-md-4 col-lg-3">
                        <div class="border rounded p-2 h-100">
                            <div class="fw-semibold"><?= htmlspecialchars((string) $group['label']) ?></div>
                            <div class="small text-muted"><?= (int) $group['count'] ?> item<?= (int) $group['count'] === 1 ? '' : 's' ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <strong>Posted materials and topics</strong>
        <span class="badge text-bg-primary"><?= count($items) ?> total</span>
    </div>
    <div class="card-body">
        <?php if ($items === []): ?>
            <p class="text-muted mb-0">No classroom content to monitor yet.</p>
        <?php else: ?>
            <?php foreach ($items as $item): ?>
                <div class="border rounded p-3 mb-3">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                        <div>
                            <div class="fw-semibold"><?= htmlspecialchars((string) ($item['title'] ?? 'Untitled')) ?></div>
                            <div class="small text-muted text-capitalize"><?= htmlspecialchars((string) ($item['content_type'] ?? 'material')) ?></div>
                        </div>
                        <div class="small text-muted"><?= htmlspecialchars((string) ($item['created_at'] ?? '')) ?></div>
                    </div>

                    <?php if ($hasContentWeeks): ?>
                        <div class="small text-muted mt-2">
                            <?php $weekLabel = trim((string) ($item['weeks'] ?? '')); ?>
                            <?php if ($weekLabel !== ''): ?>
                                <span class="me-3"><i class="fa-regular fa-calendar me-1"></i>Week: <?= htmlspecialchars($weekLabel) ?></span>
                            <?php else: ?>
                                <span class="me-3"><i class="fa-regular fa-calendar me-1"></i>Week: General resources</span>
                            <?php endif; ?>
                            <?php if ($hasContentDaysPerTopic): ?>
                                <?php $daysTopic = trim((string) ($item['days_per_topic'] ?? '')); ?>
                                <?php if ($daysTopic !== ''): ?>
                                    <span><i class="fa-regular fa-clock me-1"></i>Days/topic: <?= htmlspecialchars($daysTopic) ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (trim((string) ($item['body'] ?? '')) !== ''): ?>
                        <div class="small mt-2 classroom-content-body"><?= classroom_content_render_body((string) $item['body']) ?></div>
                    <?php endif; ?>

                    <?php $resource = trim((string) ($item['resource_url'] ?? '')); ?>
                    <?php if ($resource !== ''): ?>
                        <div class="small mt-2">
                            <?php if (classroom_content_is_attachment($resource)): ?>
                                <span class="text-muted"><i class="fa-solid fa-paperclip me-1"></i><?= htmlspecialchars(classroom_content_attachment_name($resource)) ?></span>
                            <?php else: ?>
                                <a href="<?= htmlspecialchars($resource) ?>" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-link me-1"></i>External resource</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($hasContentAttachments): ?>
                        <?php $attachments = $contentAttachmentMap[(int) ($item['id'] ?? 0)] ?? []; ?>
                        <?php if ($attachments !== []): ?>
                            <div class="small mt-2">
                                <i class="fa-solid fa-paperclip me-1"></i><?= count($attachments) ?> attachment<?= count($attachments) === 1 ? '' : 's' ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

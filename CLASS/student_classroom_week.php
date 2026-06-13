<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_role(['student']);

$studentId = isset($_SESSION['student_id']) ? (int) $_SESSION['student_id'] : 0;
$userId = (int) ($_SESSION['user_id'] ?? 0);
$hasLiveAt = db_column_exists('schedules', 'online_live_at');
$hasContentAttachments = db_table_exists('classroom_content_attachments');
$hasContentWeeks = db_column_exists('classroom_content', 'weeks');
$hasContentDaysPerTopic = db_column_exists('classroom_content', 'days_per_topic');
$hasContentTopicSchedule = $hasContentWeeks && $hasContentDaysPerTopic;
$classIsLive = static function (?string $liveAt): bool {
    if ($liveAt === null || $liveAt === '') {
        return false;
    }

    $liveTs = strtotime($liveAt);
    if ($liveTs === false) {
        return false;
    }

    return (time() - $liveTs) <= 2 * 3600;
};

if ($studentId < 1) {
    $studentId = resolve_student_id_for_user($userId) ?? 0;
    $_SESSION['student_id'] = $studentId > 0 ? $studentId : null;
}
if ($studentId < 1) {
    exit('Student profile not linked to this account. Ask your instructor to create or link your student profile.');
}

$classroomId = (int) ($_GET['id'] ?? 0);
$requestedWeek = trim((string) ($_GET['week'] ?? ''));
$requiredTables = [
    'online_classrooms',
    'classroom_students',
    'classroom_enrollments',
    'classroom_content',
];
$missingTables = array_values(array_filter(
    $requiredTables,
    static fn (string $table): bool => !db_table_exists($table)
));

$classroom = null;
$weekItems = [];
$contentAttachmentMap = [];

if ($classroomId > 0 && $missingTables === []) {
    $st = db()->prepare(
        'SELECT oc.*, c.course_code, c.course_name, f.full_name AS faculty_name, s.semester, s.school_year, s.online_live_at
         FROM classroom_enrollments ce
         INNER JOIN online_classrooms oc ON oc.id = ce.classroom_id
         INNER JOIN courses c ON c.id = oc.course_id
         INNER JOIN faculty f ON f.id = oc.faculty_id
         INNER JOIN schedules s ON s.id = oc.schedule_id
         WHERE oc.id = ? AND ce.student_id = ?
         LIMIT 1'
    );
    $st->execute([$classroomId, $studentId]);
    $classroom = $st->fetch() ?: null;
}

if ($missingTables === [] && !$classroom) {
    http_response_code(404);
    exit('Classroom not found or you are not enrolled in it.');
}

if ($missingTables === []) {
    $st = db()->prepare(
        'SELECT *
         FROM classroom_content
         WHERE classroom_id = ? AND content_type <> "announcement"
         ORDER BY created_at DESC'
    );
    $st->execute([$classroomId]);
    $materials = $st->fetchAll();
    $materialWeeks = classroom_content_group_by_week($materials);
    $weekLookup = [];
    foreach ($materialWeeks as $group) {
        $weekLookup[(string) $group['label']] = $group;
    }

    if ($requestedWeek === '') {
        $requestedWeek = $materialWeeks !== [] ? (string) $materialWeeks[0]['label'] : '';
    }

    if ($requestedWeek !== '' && isset($weekLookup[$requestedWeek])) {
        $weekItems = $weekLookup[$requestedWeek]['items'];
    } elseif ($materials !== []) {
        http_response_code(404);
        exit('Week not found for this classroom.');
    }

    if ($hasContentAttachments) {
        $contentAttachmentMap = classroom_content_attachment_map(array_column($weekItems, 'id'));
    }
}

$pageTitle = $requestedWeek !== '' ? 'Materials - ' . $requestedWeek : 'Materials by Week';
require_once __DIR__ . '/includes/header.php';
?>
<?php
$classroomLiveAt = $hasLiveAt && $classroom ? (string) ($classroom['online_live_at'] ?? '') : '';
$classroomIsLive = $hasLiveAt && $classIsLive($classroomLiveAt);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="h3 mb-1">
            <i class="fa-solid fa-calendar-week me-2 text-primary"></i><?= htmlspecialchars($requestedWeek !== '' ? $requestedWeek : 'Course Materials') ?>
            <?php if ($classroomIsLive): ?>
                <span class="badge bg-danger live-pulse-badge align-middle ms-2">LIVE</span>
            <?php endif; ?>
        </h1>
        <?php if ($classroom): ?>
            <div class="text-muted">
                <?= htmlspecialchars((string) $classroom['course_code']) ?> - <?= htmlspecialchars((string) $classroom['course_name']) ?>
                | Instructor: <?= htmlspecialchars((string) $classroom['faculty_name']) ?>
            </div>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2">
        <a href="student_classroom.php?id=<?= (int) $classroomId ?>" class="btn btn-outline-secondary btn-sm"<?= student_tooltip_attr('Returns to the full classroom page with announcements, discussion, and assessments. Use this when you are done browsing this week’s materials.') ?>>Back to Classroom</a>
        <a href="student_classrooms.php" class="btn btn-outline-primary btn-sm"<?= student_tooltip_attr('Opens the list of all your enrolled classes. Use this to switch subjects or join another Meet.') ?>>My Classes</a>
    </div>
</div>

<?php if ($missingTables !== []): ?>
    <div class="alert alert-warning">
        Student classroom features are not installed yet. Run <a href="upgrade_roles.php">upgrade_roles.php</a> once, then reload this page.
    </div>
<?php else: ?>
    <div class="card shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <strong>Week Materials and Topics</strong>
            <?php if ($weekItems !== []): ?>
                <span class="badge text-bg-primary"><?= count($weekItems) ?> item<?= count($weekItems) === 1 ? '' : 's' ?></span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if ($weekItems === []): ?>
                <p class="text-muted mb-0">No course materials were posted for this week yet.</p>
            <?php else: ?>
                <?php foreach ($weekItems as $item): ?>
                    <div class="border rounded p-3 mb-3">
                        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                            <div>
                                <div class="fw-semibold"><?= htmlspecialchars((string) $item['title']) ?></div>
                                <div class="small text-muted mb-1"><?= htmlspecialchars((string) $item['content_type']) ?></div>
                            </div>
                            <div class="small text-muted"><?= htmlspecialchars((string) $item['created_at']) ?></div>
                        </div>

                        <?php if (trim((string) ($item['body'] ?? '')) !== ''): ?>
                            <div class="small mt-2 classroom-content-body"><?= classroom_content_render_body((string) $item['body']) ?></div>
                        <?php endif; ?>

                        <?php if ($hasContentTopicSchedule && (trim((string) ($item['weeks'] ?? '')) !== '' || trim((string) ($item['days_per_topic'] ?? '')) !== '')): ?>
                            <div class="small text-muted mt-2">
                                <?php if (trim((string) ($item['weeks'] ?? '')) !== ''): ?>
                                    <span class="me-2"><i class="fa-regular fa-calendar me-1"></i>Weeks: <?= htmlspecialchars((string) $item['weeks']) ?></span>
                                <?php endif; ?>
                                <?php if (trim((string) ($item['days_per_topic'] ?? '')) !== ''): ?>
                                    <span><i class="fa-regular fa-clock me-1"></i>Days/topic: <?= htmlspecialchars((string) $item['days_per_topic']) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (trim((string) ($item['resource_url'] ?? '')) !== ''): ?>
                            <?php $resourceUrl = trim((string) $item['resource_url']); ?>
                            <?php if (classroom_content_is_attachment($resourceUrl)): ?>
                                <div class="small mt-2">
                                    <a href="<?= htmlspecialchars(classroom_content_resource_href((int) $item['id'], $resourceUrl)) ?>"<?= student_tooltip_attr('Downloads or opens a file linked to this week’s topic. Use this for readings, slides, or worksheets your instructor posted.') ?>>
                                        <i class="fa-solid fa-paperclip me-1"></i><?= htmlspecialchars(classroom_content_attachment_name($resourceUrl)) ?>
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="small mt-2">
                                    <a href="<?= htmlspecialchars(classroom_content_resource_href((int) $item['id'], $resourceUrl)) ?>" target="_blank" rel="noopener noreferrer"<?= student_tooltip_attr('Opens the linked resource in a new tab. Use this for external sites or documents your instructor linked for this week.') ?>>Open resource</a>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php foreach ($contentAttachmentMap[(int) $item['id']] ?? [] as $attachment): ?>
                            <div class="small mt-2">
                                <a href="<?= htmlspecialchars(classroom_content_attachment_href((int) $attachment['id'])) ?>"<?= student_tooltip_attr('Downloads or opens an extra attachment for this week’s item. Use this when there are multiple files for the same topic.') ?>>
                                    <i class="fa-solid fa-paperclip me-1"></i><?= htmlspecialchars(classroom_content_attachment_download_name((string) $attachment['original_name'], (string) $attachment['stored_name'])) ?>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

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

$classroomId = (int) ($_GET['id'] ?? $_POST['classroom_id'] ?? 0);
$requestedWeek = trim((string) ($_GET['week'] ?? $_POST['week'] ?? ''));

$requiredTables = [
    'online_classrooms',
    'classroom_content',
];
$missingTables = array_values(array_filter(
    $requiredTables,
    static fn (string $table): bool => !db_table_exists($table)
));
$hasContentAttachments = db_table_exists('classroom_content_attachments');
$hasContentWeeks = db_column_exists('classroom_content', 'weeks');
$hasContentDaysPerTopic = db_column_exists('classroom_content', 'days_per_topic');
$hasContentTopicSchedule = $hasContentWeeks && $hasContentDaysPerTopic;

$classroom = null;
if ($classroomId > 0 && $missingTables === []) {
    $st = db()->prepare(
        'SELECT oc.*, s.semester, s.school_year, c.course_code, c.course_name
         FROM online_classrooms oc
         INNER JOIN schedules s ON s.id = oc.schedule_id
         INNER JOIN courses c ON c.id = oc.course_id
         WHERE oc.id = ? AND oc.faculty_id = ?
         LIMIT 1'
    );
    $st->execute([$classroomId, $facultyId]);
    $classroom = $st->fetch() ?: null;
}

if ($missingTables === [] && !$classroom) {
    http_response_code(404);
    exit('Classroom not found or you do not have access to it.');
}

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $missingTables === [] && $classroom) {
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'delete_content') {
            $contentId = (int) ($_POST['content_id'] ?? 0);
            $st = db()->prepare(
                'SELECT id, resource_url
                 FROM classroom_content
                 WHERE id = ? AND classroom_id = ? AND faculty_id = ?
                 LIMIT 1'
            );
            $st->execute([$contentId, $classroomId, $facultyId]);
            $existing = $st->fetch();
            if (!$existing) {
                throw new RuntimeException('Content item not found.');
            }
            $resourceUrl = (string) ($existing['resource_url'] ?? '');

            $attachmentRows = [];
            if ($hasContentAttachments) {
                $st = db()->prepare(
                    'SELECT stored_name
                     FROM classroom_content_attachments
                     WHERE content_id = ?'
                );
                $st->execute([$contentId]);
                $attachmentRows = $st->fetchAll();
            }

            db()->prepare(
                'DELETE FROM classroom_content
                 WHERE id = ? AND classroom_id = ? AND faculty_id = ?'
            )->execute([$contentId, $classroomId, $facultyId]);

            if (classroom_content_is_attachment($resourceUrl)) {
                $attachmentPath = classroom_content_attachment_path($resourceUrl);
                if (is_file($attachmentPath)) {
                    @unlink($attachmentPath);
                }
            }
            foreach ($attachmentRows as $attachmentRow) {
                $attachmentPath = classroom_content_attachment_storage_path((string) ($attachmentRow['stored_name'] ?? ''));
                if (is_file($attachmentPath)) {
                    @unlink($attachmentPath);
                }
            }

            $_SESSION['flash'] = 'Content removed.';
        } elseif ($action === 'upload_banner' || $action === 'delete_banner') {
            $bannerFlash = faculty_classroom_process_banner_post($classroomId, $facultyId, $classroom);
            if ($bannerFlash !== null) {
                $_SESSION['flash'] = $bannerFlash;
            }
        }
    } catch (Throwable $e) {
        $_SESSION['flash'] = 'Error: ' . $e->getMessage();
    }

    $redirectWeek = trim((string) ($_POST['current_week'] ?? $requestedWeek));
    $redirectQuery = 'id=' . $classroomId;
    if ($redirectWeek !== '') {
        $redirectQuery .= '&week=' . rawurlencode($redirectWeek);
    }
    header('Location: faculty_classroom_week.php?' . $redirectQuery);
    exit;
}

$allItems = [];
$weekItems = [];
$weekGroups = [];
$contentAttachmentMap = [];
if ($missingTables === [] && $classroom) {
    $st = db()->prepare(
        'SELECT *
         FROM classroom_content
         WHERE classroom_id = ? AND faculty_id = ?
         ORDER BY created_at DESC'
    );
    $st->execute([$classroomId, $facultyId]);
    $allItems = $st->fetchAll();
    $weekGroups = classroom_content_group_by_week($allItems);

    $weekLookup = [];
    foreach ($weekGroups as $group) {
        $weekLookup[(string) $group['label']] = $group;
    }

    if ($requestedWeek === '') {
        $requestedWeek = $weekGroups !== [] ? (string) $weekGroups[0]['label'] : '';
    }
    if ($requestedWeek !== '' && isset($weekLookup[$requestedWeek])) {
        $weekItems = $weekLookup[$requestedWeek]['items'];
    } elseif ($allItems !== []) {
        http_response_code(404);
        exit('Week not found for this classroom.');
    }

    if ($hasContentAttachments) {
        $contentAttachmentMap = classroom_content_attachment_map(array_column($weekItems, 'id'));
    }
}

$pageTitle = $requestedWeek !== '' ? 'Week Content - ' . $requestedWeek : 'Week Content';
require_once __DIR__ . '/includes/header.php';
?>

<div class="fc-manage container-fluid px-0">
<?php if ($classroom): ?>
    <?php faculty_classroom_render_banner($classroom, [
        'title' => $requestedWeek !== '' ? $requestedWeek : (string) ($classroom['title'] ?? 'Classroom'),
        'meta_extra' => $requestedWeek !== '' ? (string) ($classroom['title'] ?? '') : '',
        'form_id' => 'facultyWeekBanner',
    ]); ?>
<?php endif; ?>
    <div class="d-flex flex-wrap justify-content-end align-items-start gap-2 mb-4">
        <a href="faculty_classroom.php?id=<?= (int) $classroomId ?>" class="btn btn-outline-secondary btn-sm rounded-pill"<?= app_tooltip_attr('Returns to the main classroom page with all weeks and posting tools.') ?>><i class="fa-solid fa-arrow-left me-1"></i>Back to class</a>
    </div>

<?php if ($flash): ?><?php render_information_popup((string) $flash); ?><?php endif; ?>

<?php if ($missingTables !== []): ?>
    <div class="alert alert-warning border-0 shadow-sm">
        Classroom features are not installed yet. Run <a href="upgrade_roles.php">upgrade_roles.php</a> once, then reload this page.
    </div>
<?php else: ?>
    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card fc-section-card">
                <div class="card-header">
                    <h2 class="fc-section-title"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i>Weeks</h2>
                    <p class="small text-muted mb-0 mt-2">Jump to another week to edit its materials.</p>
                </div>
                <div class="card-body p-4">
                    <?php if ($weekGroups === []): ?>
                        <p class="fc-placeholder-hint mb-0"><i class="fa-regular fa-folder-open me-2"></i>No week-tagged content yet. Publish items from the main class page with a week label.</p>
                    <?php else: ?>
                        <?php foreach ($weekGroups as $group): ?>
                            <a class="fc-week-link <?= (string) $group['label'] === $requestedWeek ? 'is-active-week' : '' ?>" href="faculty_classroom_week.php?id=<?= (int) $classroomId ?>&week=<?= rawurlencode((string) $group['label']) ?>">
                                <span><strong><?= htmlspecialchars((string) $group['label']) ?></strong></span>
                                <span class="badge text-bg-primary rounded-pill"><?= (int) $group['count'] ?></span>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card fc-section-card">
                <div class="card-header">
                    <h2 class="fc-section-title"><i class="fa-solid fa-list-ul" aria-hidden="true"></i>This week’s topics &amp; materials</h2>
                    <p class="small text-muted mb-0 mt-2">What enrolled students see for <?= htmlspecialchars($requestedWeek !== '' ? $requestedWeek : 'this week') ?>.</p>
                </div>
                <div class="card-body p-4">
                    <?php if ($weekItems === []): ?>
                        <p class="fc-placeholder-hint mb-0"><i class="fa-regular fa-file-lines me-2"></i>No items for this week yet. Add content from the class page or switch weeks using the list.</p>
                    <?php else: ?>
                        <?php foreach ($weekItems as $item): ?>
                            <div class="border rounded-3 p-3 mb-3 fc-content-item-card">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <div>
                                        <div class="fw-semibold"><?= htmlspecialchars((string) $item['title']) ?></div>
                                        <div class="small text-muted text-capitalize"><i class="fa-solid fa-tag me-1 opacity-75"></i><?= htmlspecialchars((string) $item['content_type']) ?></div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 flex-shrink-0">
                                        <?php
                                        $editHref = 'faculty_classroom_week_edit.php?id=' . (int) $classroomId
                                            . '&content_id=' . (int) $item['id']
                                            . '&week=' . rawurlencode($requestedWeek);
                                        ?>
                                        <a href="<?= htmlspecialchars($editHref) ?>" class="btn btn-sm btn-outline-primary rounded-pill fc-edit-popup-link" data-content-id="<?= (int) $item['id'] ?>" title="Edit content"<?= app_tooltip_attr('Opens a separate window to change this item’s title, body, or links for this week.') ?>>
                                            <i class="fa-solid fa-pen-to-square me-1"></i>Edit
                                        </a>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this content item?');">
                                            <input type="hidden" name="action" value="delete_content">
                                            <input type="hidden" name="classroom_id" value="<?= (int) $classroomId ?>">
                                            <input type="hidden" name="content_id" value="<?= (int) $item['id'] ?>">
                                            <input type="hidden" name="current_week" value="<?= htmlspecialchars($requestedWeek) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill" title="Delete content"<?= app_tooltip_attr('Removes this post from the class after confirmation.') ?>>
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                        <div class="small text-muted text-end d-none d-md-block"><?= htmlspecialchars((string) $item['created_at']) ?></div>
                                    </div>
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
                                        <?php
                                        $legacyName = classroom_content_attachment_name($resourceUrl);
                                        $legacyHref = classroom_content_resource_href((int) $item['id'], $resourceUrl);
                                        $legacyIsImage = classroom_content_is_image_filename($legacyName);
                                        ?>
                                        <?php if ($legacyIsImage): ?>
                                            <div class="mt-2 classroom-content-attachment-image">
                                                <a href="<?= htmlspecialchars($legacyHref) ?>" target="_blank" rel="noopener noreferrer">
                                                    <img src="<?= htmlspecialchars($legacyHref . (str_contains($legacyHref, '?') ? '&' : '?') . 'inline=1') ?>" alt="<?= htmlspecialchars($legacyName) ?>" loading="lazy" decoding="async">
                                                </a>
                                                <div class="small mt-1">
                                                    <a href="<?= htmlspecialchars($legacyHref) ?>"><i class="fa-solid fa-paperclip me-1"></i><?= htmlspecialchars($legacyName) ?></a>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div class="small mt-2">
                                                <a href="<?= htmlspecialchars($legacyHref) ?>">
                                                    <i class="fa-solid fa-paperclip me-1"></i><?= htmlspecialchars($legacyName) ?>
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="small mt-2"><a href="<?= htmlspecialchars(classroom_content_resource_href((int) $item['id'], $resourceUrl)) ?>" target="_blank" rel="noopener noreferrer">Open resource</a></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php foreach ($contentAttachmentMap[(int) $item['id']] ?? [] as $attachment): ?>
                                    <?php
                                    $attachName = classroom_content_attachment_download_name((string) $attachment['original_name'], (string) $attachment['stored_name']);
                                    $attachHref = classroom_content_attachment_href((int) $attachment['id']);
                                    $attachInlineHref = classroom_content_attachment_href((int) $attachment['id'], true);
                                    ?>
                                    <?php if (classroom_content_attachment_is_image($attachment)): ?>
                                        <div class="mt-2 classroom-content-attachment-image">
                                            <a href="<?= htmlspecialchars($attachHref) ?>" target="_blank" rel="noopener noreferrer">
                                                <img src="<?= htmlspecialchars($attachInlineHref) ?>" alt="<?= htmlspecialchars($attachName) ?>" loading="lazy" decoding="async">
                                            </a>
                                            <div class="small mt-1">
                                                <a href="<?= htmlspecialchars($attachHref) ?>"><i class="fa-solid fa-paperclip me-1"></i><?= htmlspecialchars($attachName) ?></a>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="small mt-2">
                                            <a href="<?= htmlspecialchars($attachHref) ?>">
                                                <i class="fa-solid fa-paperclip me-1"></i><?= htmlspecialchars($attachName) ?>
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
</div>

<script>
(function () {
    document.querySelectorAll('.fc-edit-popup-link').forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            var w = 920;
            var h = 780;
            var l = Math.max(0, Math.round((window.screen.width - w) / 2));
            var t = Math.max(0, Math.round((window.screen.height - h) / 2));
            var contentId = link.getAttribute('data-content-id') || '0';
            var features = 'width=' + w + ',height=' + h + ',left=' + l + ',top=' + t + ',scrollbars=yes,resizable=yes';
            window.open(link.href, 'fcWeekEdit' + contentId, features);
        });
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_role(['faculty']);

$facultyId = isset($_SESSION['faculty_id']) ? (int) $_SESSION['faculty_id'] : 0;
$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($facultyId < 1) {
    $facultyId = resolve_faculty_id_for_user($userId) ?? 0;
    $_SESSION['faculty_id'] = $facultyId > 0 ? $facultyId : null;
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

/**
 * @throws RuntimeException
 */
function faculty_week_manage_url(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    $url = filter_var($raw, FILTER_VALIDATE_URL);
    if ($url === false) {
        throw new RuntimeException('Please enter a valid URL.');
    }

    $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new RuntimeException('Only http and https URLs are allowed.');
    }

    return $url;
}

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
        } elseif ($action === 'update_content') {
            $contentId = (int) ($_POST['content_id'] ?? 0);
            $contentType = (string) ($_POST['content_type'] ?? 'material');
            $title = trim((string) ($_POST['title'] ?? ''));
            $body = classroom_content_prepare_body((string) ($_POST['body'] ?? ''));
            $weeks = trim((string) ($_POST['weeks'] ?? ''));
            $daysPerTopic = trim((string) ($_POST['days_per_topic'] ?? ''));
            $resourceUrlRaw = trim((string) ($_POST['resource_url'] ?? ''));

            if (!in_array($contentType, ['material', 'link', 'announcement'], true)) {
                $contentType = 'material';
            }
            if ($title === '') {
                throw new RuntimeException('Content title is required.');
            }

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
            $existingResourceUrl = trim((string) ($existing['resource_url'] ?? ''));
            $resourceUrl = $resourceUrlRaw !== '' ? faculty_week_manage_url($resourceUrlRaw) : '';
            if ($resourceUrlRaw === '' && classroom_content_is_attachment($existingResourceUrl)) {
                // Keep legacy attachment token if URL input is left blank.
                $resourceUrl = $existingResourceUrl;
            }

            $hasAttachmentRows = false;
            if ($hasContentAttachments) {
                $st = db()->prepare('SELECT COUNT(*) FROM classroom_content_attachments WHERE content_id = ?');
                $st->execute([$contentId]);
                $hasAttachmentRows = (int) $st->fetchColumn() > 0;
            }

            if ($body === null && $resourceUrl === '' && !$hasAttachmentRows) {
                throw new RuntimeException('Add a short description, a resource URL, or at least one attachment.');
            }

            if ($hasContentTopicSchedule) {
                db()->prepare(
                    'UPDATE classroom_content
                     SET content_type = ?, title = ?, body = ?, weeks = ?, days_per_topic = ?, resource_url = ?
                     WHERE id = ? AND classroom_id = ? AND faculty_id = ?'
                )->execute([
                    $contentType,
                    $title,
                    $body !== '' ? $body : null,
                    $weeks,
                    $daysPerTopic,
                    $resourceUrl,
                    $contentId,
                    $classroomId,
                    $facultyId,
                ]);
            } else {
                db()->prepare(
                    'UPDATE classroom_content
                     SET content_type = ?, title = ?, body = ?, resource_url = ?
                     WHERE id = ? AND classroom_id = ? AND faculty_id = ?'
                )->execute([
                    $contentType,
                    $title,
                    $body !== '' ? $body : null,
                    $resourceUrl,
                    $contentId,
                    $classroomId,
                    $facultyId,
                ]);
            }

            $_SESSION['flash'] = 'Content updated.';
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
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h1 class="fc-page-title mb-2">
                <i class="fa-solid fa-calendar-week me-2" style="color: var(--fc-accent);"></i><?= htmlspecialchars($requestedWeek !== '' ? $requestedWeek : 'Course content by week') ?>
            </h1>
            <?php if ($classroom): ?>
                <p class="fc-meta-line mb-0">
                    <span class="fw-semibold text-body"><?= htmlspecialchars((string) $classroom['title']) ?></span>
                    <span class="mx-2 text-muted">·</span>
                    <i class="fa-solid fa-book me-1 opacity-75"></i><?= htmlspecialchars((string) $classroom['course_code']) ?> — <?= htmlspecialchars((string) $classroom['course_name']) ?>
                    <span class="mx-2 text-muted">·</span>
                    <?= htmlspecialchars((string) $classroom['semester']) ?> / <?= htmlspecialchars((string) $classroom['school_year']) ?>
                </p>
            <?php endif; ?>
        </div>
        <a href="faculty_classroom.php?id=<?= (int) $classroomId ?>" class="btn btn-outline-secondary btn-sm rounded-pill align-self-start"<?= app_tooltip_attr('Returns to the main classroom page with all weeks and posting tools.') ?>><i class="fa-solid fa-arrow-left me-1"></i>Back to class</a>
    </div>

<?php if ($flash): ?>
    <div class="alert alert-info alert-dismissible fade show border-0 shadow-sm">
        <?= htmlspecialchars($flash) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"<?= app_tooltip_attr('Dismisses this notice after you have read it.') ?>></button>
    </div>
<?php endif; ?>

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
                                        <button class="btn btn-sm btn-outline-primary rounded-pill" type="button" data-bs-toggle="collapse" data-bs-target="#editContent<?= (int) $item['id'] ?>" aria-expanded="false" aria-controls="editContent<?= (int) $item['id'] ?>" title="Edit content"<?= app_tooltip_attr('Expands the editor to change this item’s title, body, or links for this week.') ?>>
                                            <i class="fa-solid fa-pen-to-square me-1"></i>Edit
                                        </button>
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
                                        <div class="small mt-2">
                                            <a href="<?= htmlspecialchars(classroom_content_resource_href((int) $item['id'], $resourceUrl)) ?>">
                                                <i class="fa-solid fa-paperclip me-1"></i><?= htmlspecialchars(classroom_content_attachment_name($resourceUrl)) ?>
                                            </a>
                                        </div>
                                    <?php else: ?>
                                        <div class="small mt-2"><a href="<?= htmlspecialchars(classroom_content_resource_href((int) $item['id'], $resourceUrl)) ?>" target="_blank" rel="noopener noreferrer">Open resource</a></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php foreach ($contentAttachmentMap[(int) $item['id']] ?? [] as $attachment): ?>
                                    <div class="small mt-2">
                                        <a href="<?= htmlspecialchars(classroom_content_attachment_href((int) $attachment['id'])) ?>">
                                            <i class="fa-solid fa-paperclip me-1"></i><?= htmlspecialchars(classroom_content_attachment_download_name((string) $attachment['original_name'], (string) $attachment['stored_name'])) ?>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                                <div class="collapse mt-3" id="editContent<?= (int) $item['id'] ?>">
                                    <div class="fc-inner-panel">
                                        <div class="fc-subsection-label mb-3"><i class="fa-solid fa-pen" aria-hidden="true"></i>Edit this item</div>
                                        <form method="post" class="row g-3">
                                            <input type="hidden" name="action" value="update_content">
                                            <input type="hidden" name="classroom_id" value="<?= (int) $classroomId ?>">
                                            <input type="hidden" name="content_id" value="<?= (int) $item['id'] ?>">
                                            <input type="hidden" name="current_week" value="<?= htmlspecialchars($requestedWeek) ?>">
                                            <div class="col-md-4">
                                                <label class="form-label fw-semibold small">Content type</label>
                                                <select name="content_type" class="form-select form-select-sm">
                                                    <option value="material" <?= (string) ($item['content_type'] ?? '') === 'material' ? 'selected' : '' ?>>Material</option>
                                                    <option value="link" <?= (string) ($item['content_type'] ?? '') === 'link' ? 'selected' : '' ?>>Link</option>
                                                    <option value="announcement" <?= (string) ($item['content_type'] ?? '') === 'announcement' ? 'selected' : '' ?>>Announcement</option>
                                                </select>
                                            </div>
                                            <div class="col-md-8">
                                                <label class="form-label fw-semibold small">Title</label>
                                                <input type="text" name="title" class="form-control form-control-sm" maxlength="150" required value="<?= htmlspecialchars((string) ($item['title'] ?? '')) ?>" placeholder="Short title shown to students">
                                            </div>
                                            <?php if ($hasContentTopicSchedule): ?>
                                                <div class="col-md-6">
                                                    <label class="form-label fw-semibold small">Week label</label>
                                                    <input type="text" name="weeks" class="form-control form-control-sm" maxlength="100" value="<?= htmlspecialchars((string) ($item['weeks'] ?? '')) ?>" placeholder="e.g. Week 3">
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label fw-semibold small">Days per topic</label>
                                                    <input type="text" name="days_per_topic" class="form-control form-control-sm" maxlength="100" value="<?= htmlspecialchars((string) ($item['days_per_topic'] ?? '')) ?>" placeholder="e.g. 2 class days">
                                                </div>
                                            <?php endif; ?>
                                            <div class="col-12">
                                                <label class="form-label fw-semibold small">Description &amp; notes</label>
                                                <textarea name="body" class="form-control form-control-sm" rows="4" placeholder="Instructions, context, or notes (optional if you have a link or attachment)"><?= htmlspecialchars((string) ($item['body'] ?? '')) ?></textarea>
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label fw-semibold small">Resource URL</label>
                                                <?php $editResourceUrl = trim((string) ($item['resource_url'] ?? '')); ?>
                                                <input type="url" name="resource_url" class="form-control form-control-sm" placeholder="https://… (optional)" value="<?= classroom_content_is_attachment($editResourceUrl) ? '' : htmlspecialchars($editResourceUrl) ?>" autocomplete="url">
                                                <?php if (classroom_content_is_attachment($editResourceUrl)): ?>
                                                    <div class="form-text">This item uses a legacy uploaded attachment. Leave URL blank to keep that file link.</div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="col-12 pt-1">
                                                <button type="submit" class="btn btn-primary fc-btn-primary-lg"<?= app_tooltip_attr('Saves your edits to this content item for the selected week.') ?>>
                                                    <i class="fa-solid fa-floppy-disk me-2"></i>Save changes
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

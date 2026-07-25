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
            // Follow the item if its week label changed.
            if ($hasContentTopicSchedule) {
                $requestedWeek = classroom_content_week_label($weeks);
            }
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
    $flashMsg = (string) ($_SESSION['flash'] ?? '');
    if (
        $action === 'update_content'
        && $hasContentTopicSchedule
        && !str_starts_with($flashMsg, 'Error:')
    ) {
        $redirectWeek = classroom_content_week_label(trim((string) ($_POST['weeks'] ?? '')));
    }
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
                                        <button class="btn btn-sm btn-outline-primary rounded-pill" type="button" aria-expanded="false" aria-controls="editContent<?= (int) $item['id'] ?>" title="Edit content" data-fc-edit-toggle="#editContent<?= (int) $item['id'] ?>"<?= app_tooltip_attr('Expands the editor to change this item’s title, body, or links for this week.') ?>>
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
                                                <div class="wordpad-shell" data-wordpad data-wordpad-name="body">
                                                    <div class="wordpad-toolbar d-none" role="toolbar" aria-label="Formatting toolbar">
                                                        <select class="form-select form-select-sm wordpad-select" data-wordpad-block aria-label="Text style">
                                                            <option value="<p>">Normal text</option>
                                                            <option value="<h3>">Heading</option>
                                                            <option value="<blockquote>">Quote</option>
                                                        </select>
                                                        <select class="form-select form-select-sm wordpad-select wordpad-select--font" data-wordpad-font-family aria-label="Font">
                                                            <option value="">Font</option>
                                                            <option value="Arial, Helvetica, sans-serif">Arial</option>
                                                            <option value="'Times New Roman', Times, serif">Times New Roman</option>
                                                            <option value="Georgia, serif">Georgia</option>
                                                            <option value="'Courier New', Courier, monospace">Courier New</option>
                                                            <option value="Verdana, sans-serif">Verdana</option>
                                                            <option value="Tahoma, sans-serif">Tahoma</option>
                                                        </select>
                                                        <select class="form-select form-select-sm wordpad-select wordpad-select--size" data-wordpad-font-size aria-label="Font size">
                                                            <option value="">Size</option>
                                                            <option value="12px">Small (12)</option>
                                                            <option value="14px">Normal (14)</option>
                                                            <option value="16px">Medium (16)</option>
                                                            <option value="18px">Large (18)</option>
                                                            <option value="24px">Extra large (24)</option>
                                                            <option value="32px">Title (32)</option>
                                                        </select>
                                                        <button type="button" class="btn btn-outline-secondary btn-sm" data-command="bold" title="Bold"><i class="fa-solid fa-bold"></i></button>
                                                        <button type="button" class="btn btn-outline-secondary btn-sm" data-command="italic" title="Italic"><i class="fa-solid fa-italic"></i></button>
                                                        <button type="button" class="btn btn-outline-secondary btn-sm" data-command="underline" title="Underline"><i class="fa-solid fa-underline"></i></button>
                                                        <button type="button" class="btn btn-outline-secondary btn-sm" data-command="insertUnorderedList" title="Bulleted list"><i class="fa-solid fa-list-ul"></i></button>
                                                        <button type="button" class="btn btn-outline-secondary btn-sm" data-command="insertOrderedList" title="Numbered list"><i class="fa-solid fa-list-ol"></i></button>
                                                        <button type="button" class="btn btn-outline-secondary btn-sm" data-command="createLink" title="Insert link"><i class="fa-solid fa-link"></i></button>
                                                        <button type="button" class="btn btn-outline-secondary btn-sm" data-command="insertImage" title="Insert image"><i class="fa-solid fa-image"></i></button>
                                                        <button type="button" class="btn btn-outline-secondary btn-sm" data-command="removeFormat" title="Clear formatting"><i class="fa-solid fa-eraser"></i></button>
                                                    </div>
                                                    <div class="wordpad-editor form-control d-none" contenteditable="true" data-placeholder="Instructions, context, or notes (paste images OK)"></div>
                                                    <textarea name="body" class="form-control form-control-sm" rows="4" placeholder="Instructions, context, or notes (optional if you have a link or attachment)"><?= htmlspecialchars((string) ($item['body'] ?? '')) ?></textarea>
                                                </div>
                                                <div class="form-text">You can paste or insert images here; they are saved with the lesson.</div>
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

<script>
(function () {
    function initWordpad(shell) {
        if (!shell || shell.getAttribute('data-wordpad-ready') === '1') {
            return;
        }

        var fieldName = shell.getAttribute('data-wordpad-name') || 'body';
        var textarea = shell.querySelector('textarea[name="' + fieldName + '"]');
        var editor = shell.querySelector('.wordpad-editor');
        var toolbar = shell.querySelector('.wordpad-toolbar');
        var blockSelect = shell.querySelector('[data-wordpad-block]');
        var form = shell.closest('form');

        if (!textarea || !editor || !toolbar || !form) {
            return;
        }

        toolbar.classList.remove('d-none');
        editor.classList.remove('d-none');
        editor.innerHTML = textarea.value;
        textarea.classList.add('d-none');
        shell.setAttribute('data-wordpad-ready', '1');

        var syncEditor = function () {
            if (shell.getAttribute('data-wordpad-ready') !== '1') {
                return;
            }
            var html = editor.innerHTML
                .replace(/<div><br><\/div>/gi, '')
                .replace(/&nbsp;/gi, ' ')
                .trim();
            // Never wipe existing saved HTML if the editor failed to load it.
            if (html === '' && textarea.defaultValue.trim() !== '' && editor.childNodes.length === 0) {
                return;
            }
            textarea.value = html;
        };

        var runCommand = function (command, value) {
            editor.focus();
            document.execCommand('styleWithCSS', false, false);
            document.execCommand(command, false, value == null ? null : value);
            syncEditor();
        };

        var insertImageFromFile = function (file) {
            if (!file || !/^image\/(jpeg|png|gif|webp)$/i.test(file.type || '')) {
                window.alert('Please choose a JPEG, PNG, GIF, or WebP image.');
                return;
            }
            if (file.size > 5 * 1024 * 1024) {
                window.alert('Image is too large (max 5 MB).');
                return;
            }

            var fd = new FormData();
            fd.append('image', file, file.name || 'image.jpg');

            fetch('api/classroom_inline_image_upload.php', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            })
                .then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
                .then(function (result) {
                    if (!result.ok || !result.data || !result.data.src) {
                        throw new Error((result.data && result.data.error) || 'Image upload failed.');
                    }
                    editor.focus();
                    var safeSrc = String(result.data.src).replace(/"/g, '&quot;');
                    document.execCommand('insertHTML', false, '<p><img src="' + safeSrc + '" alt=""></p>');
                    syncEditor();
                })
                .catch(function (err) {
                    window.alert(err && err.message ? err.message : 'Image upload failed.');
                });
        };

        var imageInput = document.createElement('input');
        imageInput.type = 'file';
        imageInput.accept = 'image/jpeg,image/png,image/gif,image/webp,.jpg,.jpeg,.png,.gif,.webp';
        imageInput.className = 'd-none';
        shell.appendChild(imageInput);
        imageInput.addEventListener('change', function () {
            var file = imageInput.files && imageInput.files[0];
            if (file) {
                insertImageFromFile(file);
            }
            imageInput.value = '';
        });

        toolbar.addEventListener('click', function (event) {
            var button = event.target.closest('button[data-command]');
            if (!button) {
                return;
            }
            event.preventDefault();
            var command = button.getAttribute('data-command') || '';
            if (command === 'createLink') {
                var url = window.prompt('Enter link URL', 'https://');
                if (url) {
                    runCommand('createLink', url);
                }
                return;
            }
            if (command === 'insertImage') {
                imageInput.click();
                return;
            }
            runCommand(command);
        });

        if (blockSelect) {
            blockSelect.addEventListener('change', function (event) {
                runCommand('formatBlock', event.target.value || '<p>');
            });
        }

        var fontSizeSelect = shell.querySelector('[data-wordpad-font-size]');
        var fontFamilySelect = shell.querySelector('[data-wordpad-font-family]');

        var applyWordpadStyle = function (styleProp, styleValue) {
            editor.focus();
            var sel = window.getSelection();
            if (!sel || sel.rangeCount === 0) {
                window.alert('Select some text first.');
                return false;
            }
            var range = sel.getRangeAt(0);
            if (range.collapsed) {
                window.alert('Select some text first.');
                return false;
            }
            var span = document.createElement('span');
            span.style[styleProp] = styleValue;
            try {
                range.surroundContents(span);
            } catch (err) {
                var fragment = range.extractContents();
                span.appendChild(fragment);
                range.insertNode(span);
            }
            sel.removeAllRanges();
            syncEditor();
            return true;
        };

        if (fontSizeSelect) {
            fontSizeSelect.addEventListener('change', function (event) {
                var value = event.target.value || '';
                if (value !== '') {
                    applyWordpadStyle('fontSize', value);
                }
                event.target.value = '';
            });
        }

        if (fontFamilySelect) {
            fontFamilySelect.addEventListener('change', function (event) {
                var value = event.target.value || '';
                if (value !== '') {
                    applyWordpadStyle('fontFamily', value);
                }
                event.target.value = '';
            });
        }

        editor.addEventListener('paste', function (event) {
            var items = event.clipboardData && event.clipboardData.items;
            if (!items) {
                return;
            }
            for (var i = 0; i < items.length; i++) {
                var item = items[i];
                if (item && item.kind === 'file' && /^image\//i.test(item.type || '')) {
                    event.preventDefault();
                    var file = item.getAsFile();
                    if (file) {
                        insertImageFromFile(file);
                    }
                    return;
                }
            }
        });

        editor.addEventListener('input', syncEditor);
        editor.addEventListener('blur', syncEditor);
        form.addEventListener('submit', function () {
            syncEditor();
        });
    }

    function initWordpadsIn(root) {
        (root || document).querySelectorAll('[data-wordpad]').forEach(initWordpad);
    }

    function setEditExpanded(btn, expanded) {
        btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    }

    document.querySelectorAll('[data-fc-edit-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function (event) {
            event.preventDefault();
            var selector = btn.getAttribute('data-fc-edit-toggle') || '';
            var panel = selector ? document.querySelector(selector) : null;
            if (!panel) {
                return;
            }
            if (window.bootstrap && bootstrap.Collapse) {
                var instance = bootstrap.Collapse.getOrCreateInstance(panel, { toggle: false });
                panel.addEventListener('shown.bs.collapse', function onShown() {
                    setEditExpanded(btn, true);
                    initWordpadsIn(panel);
                    panel.removeEventListener('shown.bs.collapse', onShown);
                });
                panel.addEventListener('hidden.bs.collapse', function onHidden() {
                    setEditExpanded(btn, false);
                    panel.removeEventListener('hidden.bs.collapse', onHidden);
                });
                instance.toggle();
                return;
            }
            var willShow = !panel.classList.contains('show');
            panel.classList.toggle('show', willShow);
            setEditExpanded(btn, willShow);
            if (willShow) {
                initWordpadsIn(panel);
            }
        });
    });

    document.querySelectorAll('.collapse').forEach(function (panel) {
        panel.addEventListener('shown.bs.collapse', function () {
            initWordpadsIn(panel);
        });
        // If a panel is already open (e.g. browser restore), init immediately.
        if (panel.classList.contains('show')) {
            initWordpadsIn(panel);
        }
    });

    // Forms still submit correctly even if the rich editor never opened.
    document.querySelectorAll('form').forEach(function (form) {
        form.addEventListener('submit', function () {
            form.querySelectorAll('[data-wordpad][data-wordpad-ready="1"]').forEach(function (shell) {
                var fieldName = shell.getAttribute('data-wordpad-name') || 'body';
                var textarea = shell.querySelector('textarea[name="' + fieldName + '"]');
                var editor = shell.querySelector('.wordpad-editor');
                if (!textarea || !editor) {
                    return;
                }
                var html = editor.innerHTML
                    .replace(/<div><br><\/div>/gi, '')
                    .replace(/&nbsp;/gi, ' ')
                    .trim();
                if (!(html === '' && textarea.defaultValue.trim() !== '' && editor.childNodes.length === 0)) {
                    textarea.value = html;
                }
            });
        });
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

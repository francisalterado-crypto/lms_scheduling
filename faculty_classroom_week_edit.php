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
$contentId = (int) ($_GET['content_id'] ?? $_POST['content_id'] ?? 0);
$requestedWeek = trim((string) ($_GET['week'] ?? $_POST['current_week'] ?? ''));

$hasContentAttachments = db_table_exists('classroom_content_attachments');
$hasContentWeeks = db_column_exists('classroom_content', 'weeks');
$hasContentDaysPerTopic = db_column_exists('classroom_content', 'days_per_topic');
$hasContentTopicSchedule = $hasContentWeeks && $hasContentDaysPerTopic;

/**
 * @throws RuntimeException
 */
function faculty_week_edit_manage_url(string $raw): string
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
$item = null;
$error = '';

if ($classroomId > 0 && $contentId > 0 && db_table_exists('online_classrooms') && db_table_exists('classroom_content')) {
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

    if ($classroom) {
        $st = db()->prepare(
            'SELECT *
             FROM classroom_content
             WHERE id = ? AND classroom_id = ? AND faculty_id = ?
             LIMIT 1'
        );
        $st->execute([$contentId, $classroomId, $facultyId]);
        $item = $st->fetch() ?: null;
    }
}

if (!$classroom || !$item) {
    http_response_code(404);
    exit('Content item not found or you do not have access to it.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action !== 'update_content') {
            throw new RuntimeException('Invalid action.');
        }

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

        $existingResourceUrl = trim((string) ($item['resource_url'] ?? ''));
        $resourceUrl = $resourceUrlRaw !== '' ? faculty_week_edit_manage_url($resourceUrlRaw) : '';
        if ($resourceUrlRaw === '' && classroom_content_is_attachment($existingResourceUrl)) {
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

        $redirectWeek = $requestedWeek;
        if ($hasContentTopicSchedule) {
            $redirectWeek = classroom_content_week_label($weeks);
        }

        $openerUrl = 'faculty_classroom_week.php?id=' . $classroomId;
        if ($redirectWeek !== '') {
            $openerUrl .= '&week=' . rawurlencode($redirectWeek);
        }

        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Saved</title></head><body>';
        echo '<p>Saved. Closing editor…</p>';
        echo '<script>';
        echo 'if (window.opener && !window.opener.closed) { window.opener.location.href = ' . json_encode($openerUrl) . '; }';
        echo 'window.close();';
        echo 'setTimeout(function () { window.location.href = ' . json_encode($openerUrl) . '; }, 1500);';
        echo '</script></body></html>';
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$pageTitle = 'Edit content';
$bodyPageClass = 'page-fc-week-edit-popup';
$mainContainerClass = 'container-fluid px-3 py-3 app-main';
require_once __DIR__ . '/includes/header.php';
?>

<div class="fc-manage">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h5 mb-1">Edit content</h1>
            <p class="small text-muted mb-0"><?= htmlspecialchars((string) ($classroom['course_code'] ?? '')) ?> · <?= htmlspecialchars($requestedWeek !== '' ? $requestedWeek : 'Week content') ?></p>
        </div>
        <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill" onclick="window.close();"<?= app_tooltip_attr('Closes this editor without saving.') ?>>
            <i class="fa-solid fa-xmark me-1"></i>Close
        </button>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger border-0 shadow-sm"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="card fc-section-card">
        <div class="card-body p-4">
            <div class="fc-subsection-label mb-3"><i class="fa-solid fa-pen" aria-hidden="true"></i>Edit this item</div>
            <form method="post" class="row g-3" id="fcWeekEditForm">
                <input type="hidden" name="action" value="update_content">
                <input type="hidden" name="classroom_id" value="<?= (int) $classroomId ?>">
                <input type="hidden" name="content_id" value="<?= (int) $contentId ?>">
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
                        <textarea name="body" class="form-control form-control-sm" rows="8" placeholder="Instructions, context, or notes (optional if you have a link or attachment)"><?= htmlspecialchars((string) ($item['body'] ?? '')) ?></textarea>
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
                <div class="col-12 pt-1 d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary fc-btn-primary-lg"<?= app_tooltip_attr('Saves your edits to this content item.') ?>>
                        <i class="fa-solid fa-floppy-disk me-2"></i>Save changes
                    </button>
                    <button type="button" class="btn btn-outline-secondary fc-btn-primary-lg" onclick="window.close();">Cancel</button>
                </div>
            </form>
        </div>
    </div>
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
        form.addEventListener('submit', syncEditor);
    }

    document.querySelectorAll('[data-wordpad]').forEach(initWordpad);
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

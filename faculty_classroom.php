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

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$classroomId = (int) ($_GET['id'] ?? $_POST['classroom_id'] ?? 0);
$requiredTables = [
    'online_classrooms',
    'classroom_students',
    'classroom_enrollments',
    'classroom_content',
    'classroom_assessments',
    'classroom_scores',
    'classroom_submissions',
];
$missingTables = array_values(array_filter(
    $requiredTables,
    static fn (string $table): bool => !db_table_exists($table)
));
$hasOnlineUrl = db_column_exists('schedules', 'online_class_url');
$hasJoinCode = db_column_exists('online_classrooms', 'join_code');
$hasSyllabusCols = db_column_exists('online_classrooms', 'syllabus_stored_name');
$hasContentAttachments = db_table_exists('classroom_content_attachments');
$hasContentWeeks = db_column_exists('classroom_content', 'weeks');
$hasContentDaysPerTopic = db_column_exists('classroom_content', 'days_per_topic');
$hasContentTopicSchedule = $hasContentWeeks && $hasContentDaysPerTopic;

/**
 * @throws RuntimeException
 */
function faculty_classroom_manage_url(string $raw): string
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

function faculty_datetime_save(?string $value): ?string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }

    $raw = str_replace('T', ' ', $raw);
    if (strlen($raw) === 16) {
        $raw .= ':00';
    }

    return $raw;
}

$classroom = null;
if ($classroomId > 0 && $missingTables === []) {
    $st = db()->prepare(
        'SELECT oc.*, s.semester, s.school_year, s.day_of_week, s.start_time, s.end_time, s.id AS schedule_id, s.college_id,
                c.course_code, c.course_name
         FROM online_classrooms oc
         INNER JOIN schedules s ON s.id = oc.schedule_id
         INNER JOIN courses c ON c.id = oc.course_id
         WHERE oc.id = ? AND oc.faculty_id = ? AND s.faculty_id = ?
         LIMIT 1'
    );
    $st->execute([$classroomId, $facultyId, $facultyId]);
    $classroom = $st->fetch() ?: null;
}

if ($missingTables === [] && !$classroom) {
    http_response_code(404);
    exit('Classroom not found or you do not have access to it.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $missingTables === [] && $classroom) {
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'update_classroom') {
            $title = trim((string) ($_POST['title'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $meetLink = faculty_classroom_manage_url((string) ($_POST['meet_link'] ?? ''));
            $status = (string) ($_POST['status'] ?? 'active');
            if ($title === '') {
                throw new RuntimeException('Classroom title is required.');
            }
            if (!in_array($status, ['active', 'archived'], true)) {
                $status = 'active';
            }

            db()->prepare(
                'UPDATE online_classrooms
                 SET title = ?, description = ?, meet_link = ?, status = ?
                 WHERE id = ? AND faculty_id = ?'
            )->execute([
                $title,
                $description !== '' ? $description : null,
                $meetLink,
                $status,
                $classroomId,
                $facultyId,
            ]);

            if ($hasOnlineUrl) {
                db()->prepare('UPDATE schedules SET online_class_url = ? WHERE id = ? AND faculty_id = ?')
                    ->execute([$meetLink !== '' ? $meetLink : null, (int) $classroom['schedule_id'], $facultyId]);
            }

            $_SESSION['flash'] = 'Classroom details updated.';
        } elseif ($action === 'regenerate_join_code') {
            if (!$hasJoinCode) {
                throw new RuntimeException('Run upgrade_roles.php to enable class join codes.');
            }
            $newCode = classroom_alloc_unique_join_code();
            db()->prepare('UPDATE online_classrooms SET join_code = ? WHERE id = ? AND faculty_id = ?')
                ->execute([$newCode, $classroomId, $facultyId]);
            $_SESSION['flash'] = 'New join code: ' . $newCode . ' (the previous code no longer works).';
        } elseif ($action === 'upload_syllabus') {
            if (!$hasSyllabusCols) {
                throw new RuntimeException('Run upgrade_roles.php to enable syllabus uploads.');
            }
            if (!isset($_FILES['syllabus']) || !is_array($_FILES['syllabus'])) {
                throw new RuntimeException('Please choose a syllabus file to upload.');
            }
            $f = $_FILES['syllabus'];
            $file = [
                'name' => (string) ($f['name'] ?? ''),
                'type' => (string) ($f['type'] ?? ''),
                'tmp_name' => (string) ($f['tmp_name'] ?? ''),
                'error' => (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int) ($f['size'] ?? 0),
            ];
            $resourcePath = classroom_content_store_attachment($file);
            if ($resourcePath === null) {
                throw new RuntimeException('Please choose a syllabus file to upload.');
            }
            $basename = basename(str_replace('\\', '/', $resourcePath));
            $origName = classroom_content_attachment_name($resourcePath);
            $mime = trim((string) ($file['type'] ?? ''));
            if ($mime === '') {
                $mime = 'application/octet-stream';
            }

            $oldStored = trim((string) ($classroom['syllabus_stored_name'] ?? ''));
            if ($oldStored !== '') {
                $oldPath = classroom_content_attachment_storage_path($oldStored);
                if (is_file($oldPath)) {
                    @unlink($oldPath);
                }
            }

            db()->prepare(
                'UPDATE online_classrooms
                 SET syllabus_stored_name = ?, syllabus_original_name = ?, syllabus_mime = ?
                 WHERE id = ? AND faculty_id = ?'
            )->execute([$basename, $origName, $mime, $classroomId, $facultyId]);

            $classroom['syllabus_stored_name'] = $basename;
            $classroom['syllabus_original_name'] = $origName;
            $classroom['syllabus_mime'] = $mime;
            $_SESSION['flash'] = 'Syllabus uploaded. Students, your dean, program chair, and administrators can open it in a new tab from their views.';
        } elseif ($action === 'delete_syllabus') {
            if (!$hasSyllabusCols) {
                throw new RuntimeException('Syllabus columns are not installed.');
            }
            $oldStored = trim((string) ($classroom['syllabus_stored_name'] ?? ''));
            if ($oldStored !== '') {
                $oldPath = classroom_content_attachment_storage_path($oldStored);
                if (is_file($oldPath)) {
                    @unlink($oldPath);
                }
            }
            db()->prepare(
                'UPDATE online_classrooms
                 SET syllabus_stored_name = NULL, syllabus_original_name = NULL, syllabus_mime = NULL
                 WHERE id = ? AND faculty_id = ?'
            )->execute([$classroomId, $facultyId]);
            $classroom['syllabus_stored_name'] = null;
            $classroom['syllabus_original_name'] = null;
            $classroom['syllabus_mime'] = null;
            $_SESSION['flash'] = 'Syllabus removed. Upload a new syllabus before posting course content or announcements.';
        } elseif ($action === 'add_content') {
            $contentType = (string) ($_POST['content_type'] ?? 'material');
            $title = trim((string) ($_POST['title'] ?? ''));
            $body = classroom_content_prepare_body((string) ($_POST['body'] ?? ''));
            $weeks = trim((string) ($_POST['weeks'] ?? ''));
            $daysPerTopic = trim((string) ($_POST['days_per_topic'] ?? ''));
            $resourceUrl = faculty_classroom_manage_url((string) ($_POST['resource_url'] ?? ''));
            $uploadedFiles = isset($_FILES['attachments']) && is_array($_FILES['attachments'])
                ? classroom_content_normalize_uploads($_FILES['attachments'])
                : [];
            $actualUploadedFiles = array_values(array_filter(
                $uploadedFiles,
                static fn (array $file): bool => (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
            ));

            if (!in_array($contentType, ['material', 'link', 'announcement'], true)) {
                $contentType = 'material';
            }
            if ($hasSyllabusCols) {
                $sySt = db()->prepare(
                    'SELECT syllabus_stored_name FROM online_classrooms WHERE id = ? AND faculty_id = ? LIMIT 1'
                );
                $sySt->execute([$classroomId, $facultyId]);
                $syName = trim((string) ($sySt->fetchColumn() ?: ''));
                if ($syName === '') {
                    throw new RuntimeException('Upload the course syllabus before posting course content or announcements.');
                }
            }
            if ($title === '') {
                throw new RuntimeException('Content title is required.');
            }
            $legacyAttachmentResource = null;
            if (!$hasContentAttachments && count($actualUploadedFiles) > 1) {
                throw new RuntimeException('Multiple attachments require a database update. Run upgrade_roles.php once.');
            }
            if (!$hasContentAttachments && count($actualUploadedFiles) === 1) {
                if ($resourceUrl !== '') {
                    throw new RuntimeException('Use either a resource URL or one attachment. Run upgrade_roles.php to combine URLs with multiple attachments.');
                }
                $legacyAttachmentResource = classroom_content_store_attachment($actualUploadedFiles[0]);
            }
            $attachments = $hasContentAttachments && $actualUploadedFiles !== []
                ? classroom_content_store_attachments($_FILES['attachments'])
                : [];

            if ($body === null && $resourceUrl === '' && $legacyAttachmentResource === null && $attachments === []) {
                throw new RuntimeException('Add a short description, a resource URL, or at least one attachment.');
            }

            db()->beginTransaction();
            try {
                if ($hasContentTopicSchedule) {
                    db()->prepare(
                        'INSERT INTO classroom_content (classroom_id, faculty_id, content_type, title, body, weeks, days_per_topic, resource_url)
                         VALUES (?,?,?,?,?,?,?,?)'
                    )->execute([
                        $classroomId,
                        $facultyId,
                        $contentType,
                        $title,
                        $body !== '' ? $body : null,
                        $weeks,
                        $daysPerTopic,
                        $legacyAttachmentResource ?? $resourceUrl,
                    ]);
                } else {
                    db()->prepare(
                        'INSERT INTO classroom_content (classroom_id, faculty_id, content_type, title, body, resource_url)
                         VALUES (?,?,?,?,?,?)'
                    )->execute([
                        $classroomId,
                        $facultyId,
                        $contentType,
                        $title,
                        $body !== '' ? $body : null,
                        $legacyAttachmentResource ?? $resourceUrl,
                    ]);
                }
                $contentId = (int) db()->lastInsertId();

                if ($attachments !== []) {
                    $insertAttachment = db()->prepare(
                        'INSERT INTO classroom_content_attachments (content_id, original_name, stored_name, mime)
                         VALUES (?,?,?,?)'
                    );
                    foreach ($attachments as $attachment) {
                        $insertAttachment->execute([
                            $contentId,
                            $attachment['original_name'],
                            $attachment['stored_name'],
                            $attachment['mime'],
                        ]);
                    }
                }

                db()->commit();
            } catch (Throwable $e) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                if ($legacyAttachmentResource !== null) {
                    $attachmentPath = classroom_content_attachment_path($legacyAttachmentResource);
                    if (is_file($attachmentPath)) {
                        @unlink($attachmentPath);
                    }
                }
                foreach ($attachments as $attachment) {
                    $attachmentPath = classroom_content_attachment_storage_path((string) ($attachment['stored_name'] ?? ''));
                    if (is_file($attachmentPath)) {
                        @unlink($attachmentPath);
                    }
                }
                throw $e;
            }

            $_SESSION['flash'] = $contentType === 'announcement'
                ? 'Announcement published for enrolled students.'
                : 'Course content added.';
        } elseif ($action === 'delete_content') {
            $contentId = (int) ($_POST['content_id'] ?? 0);
            $st = db()->prepare(
                'SELECT resource_url
                 FROM classroom_content
                 WHERE id = ? AND classroom_id = ? AND faculty_id = ?
                 LIMIT 1'
            );
            $st->execute([$contentId, $classroomId, $facultyId]);
            $resourceUrl = (string) ($st->fetchColumn() ?: '');
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
                'DELETE FROM classroom_content WHERE id = ? AND classroom_id = ? AND faculty_id = ?'
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
        } elseif ($action === 'add_assessment') {
            $assessmentType = (string) ($_POST['assessment_type'] ?? 'assignment');
            $title = trim((string) ($_POST['title'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $totalPoints = (float) ($_POST['total_points'] ?? 0);
            $dueAt = faculty_datetime_save((string) ($_POST['due_at'] ?? ''));

            if (!in_array($assessmentType, ['assignment', 'quiz'], true)) {
                $assessmentType = 'assignment';
            }
            if ($title === '') {
                throw new RuntimeException('Assessment title is required.');
            }
            if ($totalPoints <= 0) {
                throw new RuntimeException('Total points must be greater than zero.');
            }

            db()->prepare(
                'INSERT INTO classroom_assessments (classroom_id, faculty_id, assessment_type, title, description, total_points, due_at)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute([
                $classroomId,
                $facultyId,
                $assessmentType,
                $title,
                $description !== '' ? $description : null,
                $totalPoints,
                $dueAt,
            ]);

            $_SESSION['flash'] = ucfirst($assessmentType) . ' added successfully.';
        } elseif ($action === 'delete_assessment') {
            $assessmentId = (int) ($_POST['assessment_id'] ?? 0);
            db()->prepare(
                'DELETE FROM classroom_assessments WHERE id = ? AND classroom_id = ? AND faculty_id = ?'
            )->execute([$assessmentId, $classroomId, $facultyId]);
            $_SESSION['flash'] = 'Assessment deleted.';
        } elseif ($action === 'save_scores') {
            $assessmentId = (int) ($_POST['assessment_id'] ?? 0);

            $st = db()->prepare(
                'SELECT id FROM classroom_assessments WHERE id = ? AND classroom_id = ? AND faculty_id = ? LIMIT 1'
            );
            $st->execute([$assessmentId, $classroomId, $facultyId]);
            if (!$st->fetchColumn()) {
                throw new RuntimeException('Assessment not found.');
            }

            $allowedStudentIds = db()->prepare(
                'SELECT ce.student_id
                 FROM classroom_enrollments ce
                 WHERE ce.classroom_id = ?'
            );
            $allowedStudentIds->execute([$classroomId]);
            $allowedMap = array_fill_keys(
                array_map('intval', $allowedStudentIds->fetchAll(PDO::FETCH_COLUMN) ?: []),
                true
            );

            $scores = $_POST['scores'] ?? [];
            $feedback = $_POST['feedback'] ?? [];
            foreach ($scores as $studentIdRaw => $scoreRaw) {
                $studentId = (int) $studentIdRaw;
                if (!isset($allowedMap[$studentId])) {
                    continue;
                }

                $scoreText = trim((string) $scoreRaw);
                $feedbackText = trim((string) ($feedback[$studentIdRaw] ?? ''));
                if ($scoreText === '' && $feedbackText === '') {
                    db()->prepare('DELETE FROM classroom_scores WHERE assessment_id = ? AND student_id = ?')
                        ->execute([$assessmentId, $studentId]);
                    continue;
                }

                $score = $scoreText === '' ? null : (float) $scoreText;
                db()->prepare(
                    'INSERT INTO classroom_scores (assessment_id, student_id, score, feedback, graded_at)
                     VALUES (?,?,?,?,NOW())
                     ON DUPLICATE KEY UPDATE score = VALUES(score), feedback = VALUES(feedback), graded_at = NOW()'
                )->execute([
                    $assessmentId,
                    $studentId,
                    $score,
                    $feedbackText !== '' ? $feedbackText : null,
                ]);
            }

            $_SESSION['flash'] = 'Grades saved successfully.';
        }
    } catch (Throwable $e) {
        $_SESSION['flash'] = 'Error: ' . $e->getMessage();
    }

    header('Location: faculty_classroom.php?id=' . $classroomId);
    exit;
}

$contentItems = [];
$contentAttachmentMap = [];
$assessments = [];
$scoreMap = [];
$submissionMap = [];
$overallPoints = 0.0;
$contentWeeks = [];

if ($missingTables === [] && $classroom) {
    $st = db()->prepare(
        'SELECT *
         FROM classroom_content
         WHERE classroom_id = ? AND faculty_id = ?
         ORDER BY created_at DESC'
    );
    $st->execute([$classroomId, $facultyId]);
    $contentItems = $st->fetchAll();
    $contentWeeks = classroom_content_group_by_week($contentItems);
    if ($hasContentAttachments) {
        $contentAttachmentMap = classroom_content_attachment_map(array_column($contentItems, 'id'));
    }

    $st = db()->prepare(
        'SELECT *
         FROM classroom_assessments
         WHERE classroom_id = ? AND faculty_id = ?
         ORDER BY created_at DESC'
    );
    $st->execute([$classroomId, $facultyId]);
    $assessments = $st->fetchAll();

    $overallPoints = array_reduce(
        $assessments,
        static fn (float $carry, array $item): float => $carry + (float) $item['total_points'],
        0.0
    );
}

$pageTitle = $classroom ? 'Manage Classroom' : 'Classroom';
$syllabusBlocksContent = $classroom && $hasSyllabusCols && trim((string) ($classroom['syllabus_stored_name'] ?? '')) === '';
$syllabusOriginalName = trim((string) ($classroom['syllabus_original_name'] ?? ''));
require_once __DIR__ . '/includes/header.php';

$assessmentQuizCount = count(array_filter(
    $assessments,
    static fn (array $a): bool => ($a['assessment_type'] ?? '') === 'quiz'
));
$assessmentAssignmentCount = count(array_filter(
    $assessments,
    static fn (array $a): bool => ($a['assessment_type'] ?? '') === 'assignment'
));
$meetHref = $classroom ? trim((string) $classroom['meet_link']) : '';
$statusIsActive = $classroom && (string) $classroom['status'] === 'active';
?>

<div class="fc-manage container-fluid px-0">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h1 class="fc-page-title mb-2">
                <i class="fa-solid fa-chalkboard-user me-2" style="color: var(--fc-accent);"></i><?= htmlspecialchars((string) ($classroom['title'] ?? 'Classroom')) ?>
            </h1>
            <?php if ($classroom): ?>
                <p class="fc-meta-line mb-0">
                    <i class="fa-solid fa-book me-1 opacity-75"></i><?= htmlspecialchars((string) $classroom['course_code']) ?> — <?= htmlspecialchars((string) $classroom['course_name']) ?>
                    <span class="mx-2 text-muted">·</span>
                    <i class="fa-regular fa-calendar me-1 opacity-75"></i><?= htmlspecialchars((string) $classroom['semester']) ?> / <?= htmlspecialchars((string) $classroom['school_year']) ?>
                </p>
            <?php endif; ?>
        </div>
        <div class="d-flex flex-wrap gap-2 justify-content-end">
            <a href="faculty_classrooms.php" class="btn btn-outline-secondary btn-sm rounded-pill"<?= app_tooltip_attr('Returns to the list of all your online classes.') ?>><i class="fa-solid fa-arrow-left me-1"></i>All classrooms</a>
            <a href="faculty_classroom_attendance.php?id=<?= (int) $classroomId ?>" class="btn btn-outline-secondary btn-sm rounded-pill"<?= app_tooltip_attr('Opens attendance records and auto-check for this class.') ?>><i class="fa-solid fa-user-check me-1"></i>Attendance</a>
            <a href="faculty_classroom_assessments.php?id=<?= (int) $classroomId ?>" class="btn btn-outline-primary btn-sm rounded-pill"<?= app_tooltip_attr('Manage quizzes, assignments, and student submissions for this class.') ?>><i class="fa-solid fa-clipboard-list me-1"></i>Grading &amp; submissions</a>
            <?php if ($meetHref !== ''): ?>
                <a href="<?= htmlspecialchars($meetHref) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-success btn-sm rounded-pill"<?= app_tooltip_attr('Opens your Meet link in a new tab for live class sessions.') ?>><i class="fa-solid fa-video me-1"></i>Join Meet</a>
            <?php endif; ?>
        </div>
    </div>

<?php if ($hasJoinCode && $classroom): ?>
    <div class="card fc-section-card border-0 shadow-sm mb-4">
        <div class="card-body p-4">
            <h2 class="h6 text-muted text-uppercase mb-3"><i class="fa-solid fa-key me-2"></i>Student join code</h2>
            <p class="small text-muted mb-3">Students in your college sign in, open <strong>My Classes</strong>, and enter this code to add this course to their list.</p>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                <?php $jc = trim((string) ($classroom['join_code'] ?? '')); ?>
                <?php if ($jc !== ''): ?>
                    <code class="fc-join-code-pill" id="fc-join-code"><?= htmlspecialchars($jc) ?></code>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="fc-copy-join" data-code="<?= htmlspecialchars($jc) ?>"<?= app_tooltip_attr('Copies the join code so you can paste it into chat or email for students.') ?>>Copy</button>
                <?php else: ?>
                    <span class="text-muted">No code yet — generate one for students to use.</span>
                <?php endif; ?>
            </div>
            <form method="post" class="d-inline" onsubmit="return confirm('<?= $jc !== '' ? 'Generate a new code? The old code will stop working.' : 'Generate a join code for this class?' ?>');">
                <input type="hidden" name="action" value="regenerate_join_code">
                <input type="hidden" name="classroom_id" value="<?= (int) $classroomId ?>">
                <button type="submit" class="btn btn-outline-warning btn-sm"<?= app_tooltip_attr($jc !== '' ? 'Creates a new join code and invalidates the old one. Use if the code was shared too widely.' : 'Creates a join code students can enter under My Classes to enroll.') ?>><?= $jc !== '' ? 'Regenerate code' : 'Generate code' ?></button>
            </form>
        </div>
    </div>
    <script>
    (function () {
        var btn = document.getElementById('fc-copy-join');
        if (!btn) return;
        btn.addEventListener('click', function () {
            var c = btn.getAttribute('data-code') || '';
            if (!c) return;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(c).then(function () {
                    btn.textContent = 'Copied';
                    setTimeout(function () { btn.textContent = 'Copy'; }, 2000);
                });
            }
        });
    })();
    </script>
<?php endif; ?>

<?php if ($flash): ?>
    <div class="alert alert-info alert-dismissible fade show border-0 shadow-sm">
        <?= htmlspecialchars($flash) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"<?= app_tooltip_attr('Dismisses this notice after you have read it.') ?>></button>
    </div>
<?php endif; ?>

<?php if ($missingTables !== []): ?>
    <div class="alert alert-warning border-0 shadow-sm">
        Classroom/student features are not installed yet. Run <a href="upgrade_roles.php">upgrade_roles.php</a> once, then reload this page.
    </div>
<?php else: ?>
    <form method="post" class="card fc-section-card mb-4">
        <input type="hidden" name="action" value="update_classroom">
        <input type="hidden" name="classroom_id" value="<?= (int) $classroomId ?>">
        <div class="card-header">
            <h2 class="fc-section-title"><i class="fa-solid fa-door-open" aria-hidden="true"></i>Classroom header</h2>
            <p class="small text-muted mb-0 mt-2">Title, visibility status, and live session link students see for this class.</p>
        </div>
        <div class="card-body p-4">
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label fw-semibold" for="fc-class-title">Class title</label>
                    <input id="fc-class-title" type="text" name="title" class="form-control form-control-lg" maxlength="150" required
                           placeholder="e.g. BIO 101 — Laboratory Section A"
                           value="<?= htmlspecialchars((string) $classroom['title']) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold" for="fc-class-status">Status</label>
                    <select id="fc-class-status" name="status" class="form-select form-select-lg" aria-describedby="fc-status-hint">
                        <option value="active" <?= (string) $classroom['status'] === 'active' ? 'selected' : '' ?>>Active — open to students</option>
                        <option value="archived" <?= (string) $classroom['status'] === 'archived' ? 'selected' : '' ?>>Archived — read-only</option>
                    </select>
                    <div id="fc-status-hint" class="form-text">Archived classes stay visible to you but are clearly marked for students.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold" for="fc-meet-link">Meet link</label>
                    <input id="fc-meet-link" type="url" name="meet_link" class="form-control form-control-lg" placeholder="https://meet.google.com/xxx-xxxx-xxx" value="<?= htmlspecialchars((string) $classroom['meet_link']) ?>" autocomplete="url">
                </div>
            </div>
            <?php if ($classroom): ?>
                <div class="d-flex flex-wrap align-items-center gap-2 mb-4 pb-3 border-bottom">
                    <span class="small text-muted text-uppercase fw-semibold me-1">Preview</span>
                    <?php if ($statusIsActive): ?>
                        <span class="badge rounded-pill bg-success-subtle text-success border border-success-subtle"><i class="fa-solid fa-circle-check me-1"></i>Active</span>
                    <?php else: ?>
                        <span class="badge rounded-pill bg-secondary-subtle text-secondary border"><i class="fa-solid fa-box-archive me-1"></i>Archived</span>
                    <?php endif; ?>
                    <?php if ($meetHref !== ''): ?>
                        <a class="btn btn-sm btn-outline-primary rounded-pill" href="<?= htmlspecialchars($meetHref) ?>" target="_blank" rel="noopener noreferrer"<?= app_tooltip_attr('Tests the Meet URL you saved for this class.') ?>><i class="fa-solid fa-video me-1"></i>Open Meet</a>
                    <?php else: ?>
                        <span class="fc-placeholder-hint"><i class="fa-regular fa-circle-question me-1"></i>No Meet link yet — add one above.</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="fc-subsection">
                <div class="fc-subsection-label" id="fc-desc-heading"><i class="fa-solid fa-align-left" aria-hidden="true"></i>Description</div>
                <label class="visually-hidden" for="fc-description">Class description</label>
                <textarea id="fc-description" name="description" class="form-control" rows="4" aria-labelledby="fc-desc-heading"
                          placeholder="Briefly describe goals, expectations, office hours, or how students should use this space. Students see this on the class overview."><?= htmlspecialchars((string) ($classroom['description'] ?? '')) ?></textarea>
                <?php if (trim((string) ($classroom['description'] ?? '')) === ''): ?>
                    <p class="fc-placeholder-hint mt-2 mb-0"><i class="fa-regular fa-lightbulb me-1"></i>Empty for now — add a short welcome or syllabus summary.</p>
                <?php endif; ?>
            </div>

            <div class="d-flex flex-wrap gap-2 pt-2 border-top">
                <button type="submit" class="btn btn-primary fc-btn-primary-lg"<?= app_tooltip_attr('Saves title, status, Meet link, and description shown to students.') ?>><i class="fa-solid fa-floppy-disk me-2"></i>Save class details</button>
            </div>
        </div>
    </form>

    <div class="card fc-section-card border-0 shadow-sm mb-4">
        <div class="card-header">
            <h2 class="fc-section-title"><i class="fa-solid fa-file-contract" aria-hidden="true"></i>Course syllabus</h2>
            <p class="small text-muted mb-0 mt-2">Required before you can publish <strong>course content</strong> or <strong>announcements</strong> (after the database step below). Students, dean, program chair, and admin can open it in a separate browser tab.</p>
        </div>
        <div class="card-body p-4">
            <?php if (!$hasSyllabusCols): ?>
                <div class="alert alert-warning border-0 shadow-sm mb-0">
                    <i class="fa-solid fa-screwdriver-wrench me-2"></i>Syllabus storage is not enabled on the database yet. Open <a href="upgrade_roles.php" class="alert-link">upgrade_roles.php</a> once (submit the upgrade form), then reload this page — the upload form and posting rules will appear here.
                </div>
            <?php else: ?>
                <?php if ($syllabusOriginalName !== ''): ?>
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                        <span class="small text-muted text-uppercase fw-semibold">Current file</span>
                        <span class="fw-semibold"><?= htmlspecialchars($syllabusOriginalName) ?></span>
                        <a href="<?= htmlspecialchars(classroom_syllabus_href((int) $classroomId)) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-primary rounded-pill"<?= app_tooltip_attr('Opens the syllabus in a new tab so you can confirm what students and leadership will see.') ?>><i class="fa-solid fa-up-right-from-square me-1"></i>Preview in new tab</a>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning border-0 shadow-sm mb-3">
                        <i class="fa-solid fa-triangle-exclamation me-2"></i>No syllabus yet — upload one to unlock posting for this class.
                    </div>
                <?php endif; ?>
                <form method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
                    <input type="hidden" name="action" value="upload_syllabus">
                    <input type="hidden" name="classroom_id" value="<?= (int) $classroomId ?>">
                    <div class="col-md-8">
                        <label class="form-label fw-semibold" for="fc-syllabus-file">Upload or replace syllabus</label>
                        <input id="fc-syllabus-file" type="file" name="syllabus" class="form-control" accept=".pdf,.doc,.docx,.ppt,.pptx,.txt" required>
                        <div class="form-text">PDF recommended for viewing in the browser (max 10 MB). Other types may download instead of preview.</div>
                    </div>
                    <div class="col-md-4 d-flex flex-wrap gap-2 align-items-end">
                        <button type="submit" class="btn btn-primary"<?= app_tooltip_attr('Saves this file as the official syllabus for oversight and students.') ?>><i class="fa-solid fa-upload me-1"></i>Save syllabus</button>
                    </div>
                </form>
                <?php if ($syllabusOriginalName !== ''): ?>
                    <form method="post" class="mt-2" onsubmit="return confirm('Remove the syllabus? You must upload again before posting new content or announcements.');">
                        <input type="hidden" name="action" value="delete_syllabus">
                        <input type="hidden" name="classroom_id" value="<?= (int) $classroomId ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger"<?= app_tooltip_attr('Deletes the syllabus file from this class.') ?>><i class="fa-solid fa-trash me-1"></i>Remove syllabus</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-12">
            <div class="card fc-section-card mb-4">
                <div class="card-header">
                    <h2 class="fc-section-title"><i class="fa-solid fa-folder-open" aria-hidden="true"></i>Course content &amp; announcements</h2>
                    <p class="small text-muted mb-0 mt-2">Post materials, links, or announcements. Choose a week so items group cleanly for students.</p>
                </div>
                <div class="card-body p-4">
                    <?php if ($syllabusBlocksContent): ?>
                        <div class="alert alert-warning border-0 shadow-sm mb-3">
                            <i class="fa-solid fa-lock me-2"></i>Posting is locked until you upload a <strong>course syllabus</strong> in the section above.
                        </div>
                    <?php endif; ?>
                    <form method="post" enctype="multipart/form-data" id="fc-add-content-form">
                        <input type="hidden" name="action" value="add_content">
                        <input type="hidden" name="classroom_id" value="<?= (int) $classroomId ?>">

                        <div class="fc-subsection">
                            <div class="fc-subsection-label"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>Post details</div>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold" for="fc-content-type">Content type</label>
                                    <select id="fc-content-type" name="content_type" class="form-select">
                                        <option value="material">Material</option>
                                        <option value="link">Link</option>
                                        <option value="announcement">Announcement</option>
                                    </select>
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label fw-semibold" for="fc-content-title">Title</label>
                                    <input id="fc-content-title" type="text" name="title" class="form-control" maxlength="150" required placeholder="e.g. Week 3 readings or Important exam date">
                                </div>
                                <?php if ($hasContentTopicSchedule): ?>
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold" for="fc-content-weeks">Week</label>
                                        <select id="fc-content-weeks" name="weeks" class="form-select">
                                            <option value="">General resources (no specific week)</option>
                                            <?php for ($weekNo = 1; $weekNo <= 18; $weekNo++): ?>
                                                <option value="Week <?= $weekNo ?>">Week <?= $weekNo ?></option>
                                            <?php endfor; ?>
                                        </select>
                                        <div class="form-text">Match week labels so the week view stays organized.</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold" for="fc-days-topic">Days per topic</label>
                                        <input id="fc-days-topic" type="text" name="days_per_topic" class="form-control" maxlength="100" placeholder="e.g. 2 class days">
                                    </div>
                                <?php else: ?>
                                    <div class="col-12">
                                        <div class="alert alert-light border small mb-0"><i class="fa-solid fa-screwdriver-wrench me-2 text-warning"></i>Run <code>upgrade_roles.php</code> once to enable <strong>Week</strong> and <strong>Days per topic</strong> fields.</div>
                                    </div>
                                <?php endif; ?>
                                <div class="col-12">
                                    <label class="form-label fw-semibold" for="fc-body-textarea">Description &amp; notes</label>
                                    <div class="wordpad-shell" data-wordpad data-wordpad-name="body">
                                        <div class="wordpad-toolbar d-none" role="toolbar" aria-label="Formatting toolbar">
                                            <select class="form-select form-select-sm wordpad-select" data-wordpad-block aria-label="Text style">
                                                <option value="<p>">Normal text</option>
                                                <option value="<h3>">Heading</option>
                                                <option value="<blockquote>">Quote</option>
                                            </select>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" data-command="bold" title="Bold"<?= app_tooltip_attr('Makes selected text bold in the rich description area.') ?>><i class="fa-solid fa-bold"></i></button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" data-command="italic" title="Italic"<?= app_tooltip_attr('Italicizes selected text for emphasis in the description.') ?>><i class="fa-solid fa-italic"></i></button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" data-command="underline" title="Underline"<?= app_tooltip_attr('Underlines selected text in the description.') ?>><i class="fa-solid fa-underline"></i></button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" data-command="insertUnorderedList" title="Bulleted list"<?= app_tooltip_attr('Starts or continues a bulleted list in the description.') ?>><i class="fa-solid fa-list-ul"></i></button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" data-command="insertOrderedList" title="Numbered list"<?= app_tooltip_attr('Starts or continues a numbered list in the description.') ?>><i class="fa-solid fa-list-ol"></i></button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" data-command="justifyLeft" title="Align left"<?= app_tooltip_attr('Aligns paragraph text to the left.') ?>><i class="fa-solid fa-align-left"></i></button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" data-command="justifyCenter" title="Align center"<?= app_tooltip_attr('Centers paragraph text.') ?>><i class="fa-solid fa-align-center"></i></button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" data-command="justifyRight" title="Align right"<?= app_tooltip_attr('Aligns paragraph text to the right.') ?>><i class="fa-solid fa-align-right"></i></button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" data-command="createLink" title="Insert link"<?= app_tooltip_attr('Turns the selection into a hyperlink. Use for readings or external resources.') ?>><i class="fa-solid fa-link"></i></button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" data-command="unlink" title="Remove link"<?= app_tooltip_attr('Removes the link from the selected text without deleting the text.') ?>><i class="fa-solid fa-link-slash"></i></button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" data-command="removeFormat" title="Clear formatting"<?= app_tooltip_attr('Strips bold, italics, and other formatting from the selection.') ?>><i class="fa-solid fa-eraser"></i></button>
                                        </div>
                                        <div class="wordpad-editor form-control d-none" contenteditable="true" data-placeholder="Add instructions, context, or a short message for students…"></div>
                                        <textarea id="fc-body-textarea" name="body" class="form-control" rows="5" placeholder="Plain text if the rich editor is unavailable"></textarea>
                                    </div>
                                    <div class="form-text">Optional formatting for longer notes (bold, lists, links).</div>
                                </div>
                            </div>
                        </div>

                        <div class="fc-subsection">
                            <div class="fc-subsection-label"><i class="fa-solid fa-paperclip" aria-hidden="true"></i>File upload &amp; links</div>
                            <div class="fc-inner-panel">
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label fw-semibold" for="fc-resource-url">Resource URL</label>
                                        <input id="fc-resource-url" type="url" name="resource_url" class="form-control" placeholder="https://… (slides, video, reading)" autocomplete="url">
                                        <div class="form-text">Link to an external page or file; optional if you attach files below.</div>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label fw-semibold" for="fc-attachments">Attachments</label>
                                        <input id="fc-attachments" type="file" name="attachments[]" class="form-control" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.jpg,.jpeg,.png" multiple>
                                        <div class="form-text">PDF, Office, images — up to 10 MB each. Leave empty if you only use a URL or description.</div>
                                        <?php if (!$hasContentAttachments): ?>
                                            <div class="form-text text-warning"><i class="fa-solid fa-triangle-exclamation me-1"></i>Run <code>upgrade_roles.php</code> to allow multiple attachments per post.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap align-items-center gap-2 pt-1">
                            <button type="submit" class="btn btn-primary fc-btn-primary-lg"<?= $syllabusBlocksContent ? ' disabled' : '' ?><?= app_tooltip_attr('Publishes this post, files, and links to the class stream for enrolled students.') ?>><i class="fa-solid fa-paper-plane me-2"></i>Publish to class</button>
                            <span class="small text-muted">Students see new posts on their class stream.</span>
                        </div>
                    </form>

                    <hr class="my-4 text-muted opacity-25">

                    <div class="fc-subsection mb-0">
                        <div class="fc-subsection-label"><i class="fa-solid fa-calendar-week" aria-hidden="true"></i>Browse by week</div>
                        <?php if ($contentWeeks === []): ?>
                            <p class="fc-placeholder-hint mb-0"><i class="fa-regular fa-folder-open me-2"></i>No published items yet. After you publish content with a week label, weeks appear here for quick editing.</p>
                        <?php else: ?>
                            <p class="small text-muted mb-3">Open a week to edit materials and topics for that period.</p>
                            <?php foreach ($contentWeeks as $group): ?>
                                <a class="fc-week-link" href="faculty_classroom_week.php?id=<?= (int) $classroomId ?>&week=<?= rawurlencode((string) $group['label']) ?>">
                                    <span><i class="fa-regular fa-calendar me-2 text-primary"></i><strong><?= htmlspecialchars((string) $group['label']) ?></strong></span>
                                    <span class="badge text-bg-primary rounded-pill"><?= (int) $group['count'] ?> <?= (int) $group['count'] === 1 ? 'item' : 'items' ?></span>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card fc-section-card mb-4">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-start gap-2">
                    <div>
                        <h2 class="fc-section-title mb-0"><i class="fa-solid fa-clipboard-check" aria-hidden="true"></i>Assessments</h2>
                        <p class="small text-muted mb-0 mt-2">Assignments, quizzes, total points, and grading live on the full assessments page.</p>
                    </div>
                    <a href="faculty_classroom_assessments.php?id=<?= (int) $classroomId ?>" class="btn btn-outline-primary btn-sm rounded-pill flex-shrink-0"><i class="fa-solid fa-arrow-up-right-from-square me-1"></i>Open assessments</a>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-6 col-md-3">
                            <div class="fc-stat-tile">
                                <div class="fc-stat-icon"><i class="fa-solid fa-list-check"></i></div>
                                <div class="fc-stat-label">Total</div>
                                <div class="fc-stat-value"><?= count($assessments) ?></div>
                                <div class="small text-muted">Assessments</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="fc-stat-tile">
                                <div class="fc-stat-icon"><i class="fa-solid fa-file-lines"></i></div>
                                <div class="fc-stat-label">Assignments</div>
                                <div class="fc-stat-value"><?= (int) $assessmentAssignmentCount ?></div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="fc-stat-tile">
                                <div class="fc-stat-icon"><i class="fa-solid fa-circle-question"></i></div>
                                <div class="fc-stat-label">Quizzes</div>
                                <div class="fc-stat-value"><?= (int) $assessmentQuizCount ?></div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="fc-stat-tile">
                                <div class="fc-stat-icon"><i class="fa-solid fa-star"></i></div>
                                <div class="fc-stat-label">Total points</div>
                                <div class="fc-stat-value"><?= number_format((float) $overallPoints, 0) ?></div>
                            </div>
                        </div>
                    </div>
                    <p class="small text-muted mt-4 mb-0"><i class="fa-solid fa-info-circle me-1"></i>Create assignments and quizzes, review submissions, and enter grades from the assessments workspace.</p>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
</div>

<script>
document.querySelectorAll('[data-wordpad]').forEach((shell) => {
    const fieldName = shell.getAttribute('data-wordpad-name') || 'body';
    const textarea = shell.querySelector('textarea[name="' + fieldName + '"]');
    const editor = shell.querySelector('.wordpad-editor');
    const toolbar = shell.querySelector('.wordpad-toolbar');
    const blockSelect = shell.querySelector('[data-wordpad-block]');
    const form = shell.closest('form');

    if (!textarea || !editor || !toolbar || !form) {
        return;
    }

    toolbar.classList.remove('d-none');
    editor.classList.remove('d-none');
    editor.innerHTML = textarea.value.trim() !== '' ? textarea.value : '';
    textarea.classList.add('d-none');

    const syncEditor = () => {
        const html = editor.innerHTML
            .replace(/<div><br><\/div>/gi, '')
            .replace(/&nbsp;/gi, ' ')
            .trim();
        textarea.value = html;
    };

    const runCommand = (command, value = null) => {
        editor.focus();
        document.execCommand('styleWithCSS', false, false);
        document.execCommand(command, false, value);
        syncEditor();
    };

    toolbar.addEventListener('click', (event) => {
        const button = event.target.closest('button[data-command]');
        if (!button) {
            return;
        }

        event.preventDefault();
        const command = button.getAttribute('data-command') || '';

        if (command === 'createLink') {
            const url = window.prompt('Enter link URL', 'https://');
            if (url) {
                runCommand(command, url);
            }
            return;
        }

        runCommand(command);
    });

    blockSelect?.addEventListener('change', (event) => {
        const value = event.target.value || '<p>';
        runCommand('formatBlock', value);
    });

    editor.addEventListener('input', syncEditor);
    editor.addEventListener('blur', syncEditor);
    form.addEventListener('submit', syncEditor);
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

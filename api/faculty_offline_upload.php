<?php
declare(strict_types=1);

/**
 * POST — sync a queued faculty offline upload (content post or syllabus).
 * Multipart fields mirror faculty_classroom.php actions.
 * Optional client_uuid for idempotent retries.
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

/**
 * @param array<string, mixed> $body
 * @never-return
 */
function fou_emit_json(int $httpCode, array $body): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    http_response_code($httpCode);

    echo json_encode(
        $body,
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
    );
    exit;
}

function fou_faculty_id(): int
{
    $role = (string) ($_SESSION['role'] ?? '');
    if (!in_array($role, ['faculty', 'program_chair', 'dean', 'gened'], true)) {
        return 0;
    }

    $facultyId = isset($_SESSION['faculty_id']) ? (int) $_SESSION['faculty_id'] : 0;
    if ($facultyId > 0) {
        return $facultyId;
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId < 1) {
        return 0;
    }

    $resolved = resolve_faculty_id_for_user($userId) ?? 0;
    if ($resolved < 1 && in_array($role, ['program_chair', 'dean', 'gened'], true)) {
        $resolved = ensure_faculty_profile_for_teaching_role($userId) ?? 0;
    }
    if ($resolved > 0) {
        $_SESSION['faculty_id'] = $resolved;

        return $resolved;
    }

    return 0;
}

/**
 * @throws RuntimeException
 */
function fou_manage_url(string $raw): string
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

function fou_ensure_sync_table(): void
{
    if (db_table_exists('faculty_offline_sync')) {
        return;
    }

    try {
        db()->exec(
            'CREATE TABLE IF NOT EXISTS faculty_offline_sync (
                client_uuid VARCHAR(64) NOT NULL,
                faculty_id INT NOT NULL,
                action VARCHAR(40) NOT NULL,
                classroom_id INT NOT NULL,
                content_id INT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (client_uuid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } catch (Throwable $e) {
        // Idempotency log is best-effort; sync still proceeds without it.
    }
}

/**
 * @return array{client_uuid:string,action:string,classroom_id:int,content_id:?int}|null
 */
function fou_find_sync(string $clientUuid): ?array
{
    if ($clientUuid === '' || !db_table_exists('faculty_offline_sync')) {
        return null;
    }

    $st = db()->prepare(
        'SELECT client_uuid, action, classroom_id, content_id
         FROM faculty_offline_sync
         WHERE client_uuid = ?
         LIMIT 1'
    );
    $st->execute([$clientUuid]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }

    return [
        'client_uuid' => (string) $row['client_uuid'],
        'action' => (string) $row['action'],
        'classroom_id' => (int) $row['classroom_id'],
        'content_id' => isset($row['content_id']) ? (int) $row['content_id'] : null,
    ];
}

function fou_remember_sync(string $clientUuid, int $facultyId, string $action, int $classroomId, ?int $contentId): void
{
    if ($clientUuid === '') {
        return;
    }

    try {
        fou_ensure_sync_table();
        if (!db_table_exists('faculty_offline_sync')) {
            return;
        }
        db()->prepare(
            'INSERT INTO faculty_offline_sync (client_uuid, faculty_id, action, classroom_id, content_id)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE content_id = VALUES(content_id)'
        )->execute([$clientUuid, $facultyId, $action, $classroomId, $contentId]);
    } catch (Throwable $e) {
        // Best-effort only.
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fou_emit_json(405, ['ok' => false, 'error' => 'Method not allowed. Use POST.']);
}

if (!isset($_SESSION['user_id'])) {
    fou_emit_json(401, ['ok' => false, 'error' => 'Please sign in again, then retry sync.']);
}

$facultyId = fou_faculty_id();
if ($facultyId < 1) {
    fou_emit_json(403, ['ok' => false, 'error' => 'Faculty profile is required to sync uploads.']);
}

$action = trim((string) ($_POST['action'] ?? ''));
$classroomId = (int) ($_POST['classroom_id'] ?? 0);
$clientUuid = trim((string) ($_POST['client_uuid'] ?? ''));
if (strlen($clientUuid) > 64) {
    $clientUuid = substr($clientUuid, 0, 64);
}
if ($clientUuid !== '' && !preg_match('/^[A-Za-z0-9._:-]+$/', $clientUuid)) {
    fou_emit_json(400, ['ok' => false, 'error' => 'Invalid client_uuid.']);
}

if ($classroomId < 1) {
    fou_emit_json(400, ['ok' => false, 'error' => 'classroom_id is required.']);
}

if (!in_array($action, ['add_content', 'upload_syllabus'], true)) {
    fou_emit_json(400, ['ok' => false, 'error' => 'Unsupported action. Use add_content or upload_syllabus.']);
}

$existing = fou_find_sync($clientUuid);
if ($existing !== null) {
    fou_emit_json(200, [
        'ok' => true,
        'duplicate' => true,
        'action' => $existing['action'],
        'classroom_id' => $existing['classroom_id'],
        'content_id' => $existing['content_id'],
        'message' => 'Already synced.',
    ]);
}

$st = db()->prepare(
    'SELECT oc.id, oc.faculty_id, oc.syllabus_stored_name, oc.syllabus_original_name, oc.syllabus_mime
     FROM online_classrooms oc
     INNER JOIN schedules s ON s.id = oc.schedule_id
     WHERE oc.id = ? AND oc.faculty_id = ? AND s.faculty_id = ?
     LIMIT 1'
);
$st->execute([$classroomId, $facultyId, $facultyId]);
$classroom = $st->fetch() ?: null;
if (!$classroom) {
    fou_emit_json(404, ['ok' => false, 'error' => 'Classroom not found or not owned by you.']);
}

$hasSyllabusCols = db_column_exists('online_classrooms', 'syllabus_stored_name');
$hasContentAttachments = db_table_exists('classroom_content_attachments');
$hasContentWeeks = db_column_exists('classroom_content', 'weeks');
$hasContentDaysPerTopic = db_column_exists('classroom_content', 'days_per_topic');
$hasContentTopicSchedule = $hasContentWeeks && $hasContentDaysPerTopic;

try {
    if ($action === 'upload_syllabus') {
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

        fou_remember_sync($clientUuid, $facultyId, $action, $classroomId, null);

        fou_emit_json(200, [
            'ok' => true,
            'duplicate' => false,
            'action' => $action,
            'classroom_id' => $classroomId,
            'content_id' => null,
            'message' => 'Syllabus uploaded.',
            'syllabus_original_name' => $origName,
        ]);
    }

    // add_content
    $contentType = (string) ($_POST['content_type'] ?? 'material');
    $title = trim((string) ($_POST['title'] ?? ''));
    $body = classroom_content_prepare_body((string) ($_POST['body'] ?? ''));
    $weeks = trim((string) ($_POST['weeks'] ?? ''));
    $daysPerTopic = trim((string) ($_POST['days_per_topic'] ?? ''));
    $resourceUrl = fou_manage_url((string) ($_POST['resource_url'] ?? ''));
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
        $syName = trim((string) ($classroom['syllabus_stored_name'] ?? ''));
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

    fou_remember_sync($clientUuid, $facultyId, $action, $classroomId, $contentId);

    fou_emit_json(200, [
        'ok' => true,
        'duplicate' => false,
        'action' => $action,
        'classroom_id' => $classroomId,
        'content_id' => $contentId,
        'message' => $contentType === 'announcement'
            ? 'Announcement published for enrolled students.'
            : 'Course content added.',
    ]);
} catch (Throwable $e) {
    fou_emit_json(400, [
        'ok' => false,
        'error' => $e->getMessage() !== '' ? $e->getMessage() : 'Sync failed.',
    ]);
}

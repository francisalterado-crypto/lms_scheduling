<?php
declare(strict_types=1);

/**
 * GET — pack enrolled classroom faculty items for offline reading (students only).
 * Optional: ?classroom_id=N to pack one class; omit to pack all enrolled classes.
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

/**
 * @param array<string, mixed> $body
 * @never-return
 */
function sop_emit_json(int $httpCode, array $body): void
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

function sop_student_id(): int
{
    $studentId = isset($_SESSION['student_id']) ? (int) $_SESSION['student_id'] : 0;
    if ($studentId > 0) {
        return $studentId;
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId < 1) {
        return 0;
    }

    $resolved = resolve_student_id_for_user($userId);
    if ($resolved !== null && $resolved > 0) {
        $_SESSION['student_id'] = $resolved;

        return $resolved;
    }

    return 0;
}

/**
 * @param array<int, array<string, mixed>> $items
 * @return array<int, array<string, mixed>>
 */
function sop_normalize_items(array $items, array $attachmentMap, bool $hasTopicSchedule): array
{
    $out = [];
    foreach ($items as $item) {
        $id = (int) ($item['id'] ?? 0);
        $resourceUrl = trim((string) ($item['resource_url'] ?? ''));
        $attachments = [];
        foreach ($attachmentMap[$id] ?? [] as $attachment) {
            $attachments[] = [
                'id' => (int) ($attachment['id'] ?? 0),
                'original_name' => (string) ($attachment['original_name'] ?? ''),
                'mime' => (string) ($attachment['mime'] ?? ''),
                'href' => classroom_content_attachment_href((int) ($attachment['id'] ?? 0)),
            ];
        }

        $resource = null;
        if ($resourceUrl !== '') {
            $isFile = classroom_content_is_attachment($resourceUrl);
            $resource = [
                'url' => $resourceUrl,
                'is_attachment' => $isFile,
                'label' => $isFile
                    ? classroom_content_attachment_name($resourceUrl)
                    : 'Open resource',
                'href' => classroom_content_resource_href($id, $resourceUrl),
            ];
        }

        $out[] = [
            'id' => $id,
            'content_type' => (string) ($item['content_type'] ?? 'material'),
            'title' => (string) ($item['title'] ?? ''),
            'body_html' => classroom_content_render_body((string) ($item['body'] ?? '')),
            'weeks' => $hasTopicSchedule ? (string) ($item['weeks'] ?? '') : '',
            'days_per_topic' => $hasTopicSchedule ? (string) ($item['days_per_topic'] ?? '') : '',
            'week_label' => classroom_content_week_label((string) ($item['weeks'] ?? '')),
            'resource' => $resource,
            'attachments' => $attachments,
            'created_at' => (string) ($item['created_at'] ?? ''),
        ];
    }

    return $out;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET') {
    sop_emit_json(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

if (empty($_SESSION['user_id']) || (string) ($_SESSION['role'] ?? '') !== 'student') {
    sop_emit_json(401, ['ok' => false, 'error' => 'Student login required.']);
}

$studentId = sop_student_id();
if ($studentId < 1) {
    sop_emit_json(403, ['ok' => false, 'error' => 'Student profile not linked.']);
}

if (!db_table_exists('online_classrooms') || !db_table_exists('classroom_enrollments') || !db_table_exists('classroom_content')) {
    sop_emit_json(503, ['ok' => false, 'error' => 'Classroom features are not installed yet.']);
}

$classroomIdFilter = (int) ($_GET['classroom_id'] ?? 0);
$hasContentAttachments = db_table_exists('classroom_content_attachments');
$hasTopicSchedule = db_column_exists('classroom_content', 'weeks')
    && db_column_exists('classroom_content', 'days_per_topic');

$sql = 'SELECT oc.id, oc.title, oc.description, oc.status,
               c.course_code, c.course_name, f.full_name AS faculty_name,
               s.semester, s.school_year, s.day_of_week, s.start_time, s.end_time
        FROM classroom_enrollments ce
        INNER JOIN online_classrooms oc ON oc.id = ce.classroom_id
        INNER JOIN courses c ON c.id = oc.course_id
        INNER JOIN faculty f ON f.id = oc.faculty_id
        INNER JOIN schedules s ON s.id = oc.schedule_id
        WHERE ce.student_id = ?';
$params = [$studentId];
if ($classroomIdFilter > 0) {
    $sql .= ' AND oc.id = ?';
    $params[] = $classroomIdFilter;
}
$sql .= ' ORDER BY c.course_code ASC, oc.title ASC';

$st = db()->prepare($sql);
$st->execute($params);
$classrooms = $st->fetchAll() ?: [];

if ($classroomIdFilter > 0 && $classrooms === []) {
    sop_emit_json(404, ['ok' => false, 'error' => 'Classroom not found or you are not enrolled.']);
}

$pack = [];
$totalItems = 0;

foreach ($classrooms as $classroom) {
    $cid = (int) $classroom['id'];
    $cst = db()->prepare(
        'SELECT *
         FROM classroom_content
         WHERE classroom_id = ?
         ORDER BY
           CASE WHEN content_type = "announcement" THEN 0 ELSE 1 END ASC,
           created_at DESC,
           id DESC'
    );
    $cst->execute([$cid]);
    $rawItems = $cst->fetchAll() ?: [];

    $attachmentMap = [];
    if ($hasContentAttachments && $rawItems !== []) {
        $attachmentMap = classroom_content_attachment_map(array_column($rawItems, 'id'));
    }

    $items = sop_normalize_items($rawItems, $attachmentMap, $hasTopicSchedule);
    $totalItems += count($items);

    $announcements = array_values(array_filter(
        $items,
        static fn (array $row): bool => ($row['content_type'] ?? '') === 'announcement'
    ));
    $materials = array_values(array_filter(
        $items,
        static fn (array $row): bool => ($row['content_type'] ?? '') !== 'announcement'
    ));

    $pack[] = [
        'id' => $cid,
        'title' => (string) ($classroom['title'] ?? ''),
        'description' => (string) ($classroom['description'] ?? ''),
        'status' => (string) ($classroom['status'] ?? ''),
        'course_code' => (string) ($classroom['course_code'] ?? ''),
        'course_name' => (string) ($classroom['course_name'] ?? ''),
        'faculty_name' => (string) ($classroom['faculty_name'] ?? ''),
        'semester' => (string) ($classroom['semester'] ?? ''),
        'school_year' => (string) ($classroom['school_year'] ?? ''),
        'day_of_week' => (string) ($classroom['day_of_week'] ?? ''),
        'start_time' => (string) ($classroom['start_time'] ?? ''),
        'end_time' => (string) ($classroom['end_time'] ?? ''),
        'announcements' => $announcements,
        'materials' => $materials,
        'item_count' => count($items),
    ];
}

sop_emit_json(200, [
    'ok' => true,
    'saved_at' => date('c'),
    'student_id' => $studentId,
    'classroom_count' => count($pack),
    'item_count' => $totalItems,
    'classrooms' => $pack,
    'note' => 'Text posts and links are available offline. Attachment file downloads still need internet.',
]);

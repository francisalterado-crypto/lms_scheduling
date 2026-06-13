<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_role(['faculty', 'student']);

$attachmentId = (int) ($_GET['attachment_id'] ?? 0);
$contentId = (int) ($_GET['id'] ?? 0);
if ($attachmentId < 1 && $contentId < 1) {
    http_response_code(400);
    exit('Invalid attachment request.');
}

$role = (string) ($_SESSION['role'] ?? '');
$hasContentAttachments = db_table_exists('classroom_content_attachments');
$resourceUrl = '';
$storedName = '';
$originalName = '';
$mime = '';

if ($role === 'faculty') {
    $facultyId = isset($_SESSION['faculty_id']) ? (int) $_SESSION['faculty_id'] : 0;
    if ($facultyId < 1) {
        $facultyId = resolve_faculty_id_for_user((int) ($_SESSION['user_id'] ?? 0)) ?? 0;
        $_SESSION['faculty_id'] = $facultyId > 0 ? $facultyId : null;
    }
    if ($facultyId < 1) {
        http_response_code(403);
        exit('Faculty profile not linked to this account.');
    }

    if ($attachmentId > 0 && $hasContentAttachments) {
        $st = db()->prepare(
            'SELECT cca.stored_name, cca.original_name, cca.mime
             FROM classroom_content_attachments cca
             INNER JOIN classroom_content cc ON cc.id = cca.content_id
             INNER JOIN online_classrooms oc ON oc.id = cc.classroom_id
             WHERE cca.id = ? AND cc.faculty_id = ? AND oc.faculty_id = ?
             LIMIT 1'
        );
        $st->execute([$attachmentId, $facultyId, $facultyId]);
        $attachment = $st->fetch() ?: null;
        if (is_array($attachment)) {
            $storedName = (string) ($attachment['stored_name'] ?? '');
            $originalName = (string) ($attachment['original_name'] ?? '');
            $mime = (string) ($attachment['mime'] ?? '');
        }
    } elseif ($contentId > 0) {
        $st = db()->prepare(
            'SELECT cc.resource_url
             FROM classroom_content cc
             INNER JOIN online_classrooms oc ON oc.id = cc.classroom_id
             WHERE cc.id = ? AND cc.faculty_id = ? AND oc.faculty_id = ?
             LIMIT 1'
        );
        $st->execute([$contentId, $facultyId, $facultyId]);
        $resourceUrl = (string) ($st->fetchColumn() ?: '');
    }
} elseif ($role === 'student') {
    $studentId = isset($_SESSION['student_id']) ? (int) $_SESSION['student_id'] : 0;
    if ($studentId < 1) {
        $studentId = resolve_student_id_for_user((int) ($_SESSION['user_id'] ?? 0)) ?? 0;
        $_SESSION['student_id'] = $studentId > 0 ? $studentId : null;
    }
    if ($studentId < 1) {
        http_response_code(403);
        exit('Student profile not linked to this account.');
    }

    if ($attachmentId > 0 && $hasContentAttachments) {
        $st = db()->prepare(
            'SELECT cca.stored_name, cca.original_name, cca.mime
             FROM classroom_content_attachments cca
             INNER JOIN classroom_content cc ON cc.id = cca.content_id
             INNER JOIN classroom_enrollments ce ON ce.classroom_id = cc.classroom_id
             WHERE cca.id = ? AND ce.student_id = ?
             LIMIT 1'
        );
        $st->execute([$attachmentId, $studentId]);
        $attachment = $st->fetch() ?: null;
        if (is_array($attachment)) {
            $storedName = (string) ($attachment['stored_name'] ?? '');
            $originalName = (string) ($attachment['original_name'] ?? '');
            $mime = (string) ($attachment['mime'] ?? '');
        }
    } elseif ($contentId > 0) {
        $st = db()->prepare(
            'SELECT cc.resource_url
             FROM classroom_content cc
             INNER JOIN classroom_enrollments ce ON ce.classroom_id = cc.classroom_id
             WHERE cc.id = ? AND ce.student_id = ?
             LIMIT 1'
        );
        $st->execute([$contentId, $studentId]);
        $resourceUrl = (string) ($st->fetchColumn() ?: '');
    }
} else {
    http_response_code(403);
    exit('You do not have access to this attachment.');
}

if ($storedName !== '') {
    $path = classroom_content_attachment_storage_path($storedName);
    if (!is_file($path)) {
        http_response_code(404);
        exit('Attachment file is missing.');
    }

    $downloadName = classroom_content_attachment_download_name($originalName, $storedName);
    $mime = trim($mime) !== '' ? $mime : (function_exists('mime_content_type') ? (string) mime_content_type($path) : 'application/octet-stream');
} else {
    if ($resourceUrl === '' || !classroom_content_is_attachment($resourceUrl)) {
        http_response_code(404);
        exit('Attachment not found.');
    }

    $path = classroom_content_attachment_path($resourceUrl);
    if (!is_file($path)) {
        http_response_code(404);
        exit('Attachment file is missing.');
    }

    $downloadName = classroom_content_attachment_name($resourceUrl);
    $mime = function_exists('mime_content_type') ? (string) mime_content_type($path) : 'application/octet-stream';
}
$mime = trim($mime) !== '' ? $mime : 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: attachment; filename="' . addcslashes($downloadName, '"\\') . '"');
readfile($path);
exit;

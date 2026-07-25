<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

/**
 * @param array<string,mixed> $body
 * @never-return
 */
function classroom_inline_image_json(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    classroom_inline_image_json(405, ['ok' => false, 'error' => 'POST required.']);
}

$role = (string) ($_SESSION['role'] ?? '');
if (!in_array($role, ['faculty', 'program_chair', 'dean', 'gened'], true)) {
    classroom_inline_image_json(403, ['ok' => false, 'error' => 'Not allowed.']);
}

$facultyId = isset($_SESSION['faculty_id']) ? (int) $_SESSION['faculty_id'] : 0;
if ($facultyId < 1) {
    $facultyId = resolve_faculty_id_for_user((int) ($_SESSION['user_id'] ?? 0)) ?? 0;
    $_SESSION['faculty_id'] = $facultyId > 0 ? $facultyId : null;
}
if ($facultyId < 1) {
    classroom_inline_image_json(403, ['ok' => false, 'error' => 'Faculty profile not linked.']);
}

if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
    classroom_inline_image_json(400, ['ok' => false, 'error' => 'Choose an image to upload.']);
}

$file = $_FILES['image'];
$error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
if ($error !== UPLOAD_ERR_OK) {
    classroom_inline_image_json(400, ['ok' => false, 'error' => 'Image upload failed.']);
}

$size = (int) ($file['size'] ?? 0);
if ($size < 1) {
    classroom_inline_image_json(400, ['ok' => false, 'error' => 'Image file is empty.']);
}
if ($size > 5 * 1024 * 1024) {
    classroom_inline_image_json(400, ['ok' => false, 'error' => 'Image is too large (max 5 MB).']);
}

$tmp = (string) ($file['tmp_name'] ?? '');
$imageInfo = @getimagesize($tmp);
if ($imageInfo === false) {
    classroom_inline_image_json(400, ['ok' => false, 'error' => 'File must be a JPEG, PNG, GIF, or WebP image.']);
}

$mime = strtolower((string) ($imageInfo['mime'] ?? ''));
$ext = match ($mime) {
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
    default => '',
};
if ($ext === '') {
    classroom_inline_image_json(400, ['ok' => false, 'error' => 'Only JPEG, PNG, GIF, and WebP images are allowed.']);
}

$dir = classroom_content_attachment_dir();
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    classroom_inline_image_json(500, ['ok' => false, 'error' => 'Unable to create upload directory.']);
}

$storedName = 'inline_' . bin2hex(random_bytes(16)) . '.' . $ext;
$destination = classroom_content_attachment_storage_path($storedName);
if (!move_uploaded_file($tmp, $destination)) {
    classroom_inline_image_json(500, ['ok' => false, 'error' => 'Failed to save image.']);
}

classroom_inline_image_json(200, [
    'ok' => true,
    'src' => classroom_content_inline_image_href($storedName),
]);

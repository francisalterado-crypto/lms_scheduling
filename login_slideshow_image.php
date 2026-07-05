<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/login_slideshow_helpers.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id < 1) {
    http_response_code(404);
    echo 'Image not found.';
    exit;
}

$row = login_slideshow_image_row($id);
if ($row === null) {
    http_response_code(404);
    echo 'Image not found.';
    exit;
}

$isActive = (int) ($row['is_active'] ?? 0) === 1;
$isAdminPreview = !empty($_SESSION['user_id']) && in_array((string) ($_SESSION['role'] ?? ''), ['admin', 'super_admin'], true);
if (!$isActive && !$isAdminPreview) {
    http_response_code(404);
    echo 'Image not found.';
    exit;
}

$stored = trim((string) ($row['stored_name'] ?? ''));
$path = login_slideshow_path($stored);
if ($stored === '' || !is_file($path)) {
    http_response_code(404);
    echo 'Image file is missing.';
    exit;
}

$mime = login_slideshow_mime_for_stored($stored);
if ($mime === 'application/octet-stream') {
    http_response_code(404);
    echo 'Image not found.';
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;

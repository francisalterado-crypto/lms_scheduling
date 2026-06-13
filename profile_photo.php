<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/profile_photo_helpers.php';

require_login();

$userId = isset($_GET['u']) ? (int) $_GET['u'] : (int) ($_SESSION['user_id'] ?? 0);
if ($userId < 1) {
    http_response_code(404);
    echo 'Photo not found.';
    exit;
}

$stored = profile_photo_stored_name($userId);
if ($stored === '') {
    http_response_code(404);
    echo 'Photo not found.';
    exit;
}

$path = profile_photo_path($stored);
if (!is_file($path)) {
    http_response_code(404);
    echo 'Photo file is missing.';
    exit;
}

$mime = profile_photo_mime_for_stored($stored);
if ($mime === 'application/octet-stream') {
    http_response_code(404);
    echo 'Photo not found.';
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;

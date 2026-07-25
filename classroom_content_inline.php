<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();

$storedName = basename(trim((string) ($_GET['n'] ?? '')));
if ($storedName === '' || !preg_match('/^inline_[A-Fa-f0-9]{32}\.(jpg|jpeg|png|gif|webp)$/', $storedName)) {
    http_response_code(400);
    exit('Invalid image request.');
}

$path = classroom_content_attachment_storage_path($storedName);
if (!is_file($path)) {
    http_response_code(404);
    exit('Image not found.');
}

$mime = match (strtolower(pathinfo($storedName, PATHINFO_EXTENSION))) {
    'jpg', 'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    default => 'application/octet-stream',
};

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline; filename="' . addcslashes($storedName, '"\\') . '"');
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;

<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/messaging_helpers.php';

require_login();

$messageId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$userId = (int) ($_SESSION['user_id'] ?? 0);
$message = $messageId > 0 ? messaging_message_for_user($messageId, $userId) : null;

if (!$message || trim((string) ($message['attachment_stored_name'] ?? '')) === '') {
    http_response_code(404);
    echo 'Attachment not found.';
    exit;
}

$path = messaging_attachment_path((string) $message['attachment_stored_name']);
if (!is_file($path)) {
    http_response_code(404);
    echo 'Attachment file is missing.';
    exit;
}

$downloadName = basename((string) ($message['attachment_original_name'] ?? 'attachment'));
$mime = trim((string) ($message['attachment_mime'] ?? 'application/octet-stream'));
if ($mime === '') {
    $mime = 'application/octet-stream';
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: attachment; filename="' . addcslashes($downloadName, '"\\') . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;

<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

function profile_photo_column_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        $ready = db_column_exists('users', 'profile_photo');
    }
    return $ready;
}

function profile_photo_dir(): string
{
    return BASE_PATH . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'profile_photos';
}

function profile_photo_path(string $storedName): string
{
    return profile_photo_dir() . DIRECTORY_SEPARATOR . basename($storedName);
}

function profile_photo_stored_name(int $userId): string
{
    if ($userId < 1 || !profile_photo_column_ready()) {
        return '';
    }

    static $cache = [];
    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }

    $st = db()->prepare('SELECT profile_photo FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
    $st->execute([$userId]);
    $stored = trim((string) ($st->fetchColumn() ?: ''));
    $cache[$userId] = $stored;

    return $stored;
}

function profile_photo_url(int $userId): ?string
{
    $stored = profile_photo_stored_name($userId);
    if ($stored === '') {
        return null;
    }
    $path = profile_photo_path($stored);
    if (!is_file($path)) {
        return null;
    }

    return 'profile_photo.php?u=' . $userId;
}

/**
 * @param array<string,mixed> $file
 */
function profile_photo_store(int $userId, array $file): string
{
    if ($userId < 1) {
        throw new RuntimeException('Invalid user.');
    }
    if (!profile_photo_column_ready()) {
        throw new RuntimeException('Profile photos are not installed. Run upgrade_roles.php once.');
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Please choose a photo to upload.');
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Photo upload failed.');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size < 1) {
        throw new RuntimeException('Photo file is empty.');
    }
    if ($size > 2 * 1024 * 1024) {
        throw new RuntimeException('Photo is too large (max 2 MB).');
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Invalid upload.');
    }

    $imageInfo = @getimagesize($tmp);
    if ($imageInfo === false) {
        throw new RuntimeException('File must be a JPEG, PNG, or WebP image.');
    }

    $mime = (string) ($imageInfo['mime'] ?? '');
    $ext = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        default => '',
    };
    if ($ext === '') {
        throw new RuntimeException('Only JPEG, PNG, and WebP images are allowed.');
    }

    $dir = profile_photo_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create photo storage directory.');
    }

    profile_photo_delete_file(profile_photo_stored_name($userId));

    $stored = 'user_' . $userId . '_' . bin2hex(random_bytes(12)) . '.' . $ext;
    $dest = profile_photo_path($stored);
    if (!move_uploaded_file($tmp, $dest)) {
        throw new RuntimeException('Failed to save photo.');
    }

    db()->prepare('UPDATE users SET profile_photo = ? WHERE id = ?')->execute([$stored, $userId]);

    return $stored;
}

function profile_photo_delete_file(string $storedName): void
{
    if ($storedName === '') {
        return;
    }
    $path = profile_photo_path($storedName);
    if (is_file($path)) {
        @unlink($path);
    }
}

function profile_photo_remove(int $userId): void
{
    if ($userId < 1) {
        throw new RuntimeException('Invalid user.');
    }
    if (!profile_photo_column_ready()) {
        throw new RuntimeException('Profile photos are not installed. Run upgrade_roles.php once.');
    }

    profile_photo_delete_file(profile_photo_stored_name($userId));
    db()->prepare('UPDATE users SET profile_photo = NULL WHERE id = ?')->execute([$userId]);
}

function profile_photo_mime_for_stored(string $storedName): string
{
    return match (strtolower(pathinfo($storedName, PATHINFO_EXTENSION))) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        default => 'application/octet-stream',
    };
}

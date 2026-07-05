<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

function login_slideshow_table_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        $ready = db_table_exists('login_slideshow_images');
    }
    return $ready;
}

function login_slideshow_dir(): string
{
    return BASE_PATH . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'login_slideshow';
}

function login_slideshow_path(string $storedName): string
{
    return login_slideshow_dir() . DIRECTORY_SEPARATOR . basename($storedName);
}

/**
 * @return list<array{id:int, caption:string, sort_order:int, url:string}>
 */
function login_slideshow_active_images(): array
{
    if (!login_slideshow_table_ready()) {
        return [];
    }

    $rows = db()->query(
        'SELECT id, caption, sort_order, stored_name
         FROM login_slideshow_images
         WHERE is_active = 1
         ORDER BY sort_order ASC, id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);

    $images = [];
    foreach ($rows as $row) {
        $stored = trim((string) ($row['stored_name'] ?? ''));
        if ($stored === '' || !is_file(login_slideshow_path($stored))) {
            continue;
        }
        $images[] = [
            'id' => (int) $row['id'],
            'caption' => trim((string) ($row['caption'] ?? '')),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'url' => 'login_slideshow_image.php?id=' . (int) $row['id'],
        ];
    }

    return $images;
}

/**
 * @return list<array<string,mixed>>
 */
function login_slideshow_all_images(): array
{
    if (!login_slideshow_table_ready()) {
        return [];
    }

    return db()->query(
        'SELECT id, stored_name, caption, sort_order, is_active, created_at
         FROM login_slideshow_images
         ORDER BY sort_order ASC, id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
}

function login_slideshow_mime_for_stored(string $storedName): string
{
    return match (strtolower(pathinfo($storedName, PATHINFO_EXTENSION))) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        default => 'application/octet-stream',
    };
}

function login_slideshow_delete_file(string $storedName): void
{
    if ($storedName === '') {
        return;
    }
    $path = login_slideshow_path($storedName);
    if (is_file($path)) {
        @unlink($path);
    }
}

/**
 * @param array<string,mixed> $file
 */
function login_slideshow_store_upload(array $file, string $caption, int $uploadedBy): int
{
    if (!login_slideshow_table_ready()) {
        throw new RuntimeException('Login slideshow is not installed. Run upgrade_roles.php once.');
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Please choose an image to upload.');
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed.');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size < 1) {
        throw new RuntimeException('Image file is empty.');
    }
    if ($size > 5 * 1024 * 1024) {
        throw new RuntimeException('Image is too large (max 5 MB).');
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

    $dir = login_slideshow_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create slideshow storage directory.');
    }

    $stored = 'slide_' . bin2hex(random_bytes(12)) . '.' . $ext;
    $dest = login_slideshow_path($stored);
    if (!move_uploaded_file($tmp, $dest)) {
        throw new RuntimeException('Failed to save image.');
    }

    $maxOrder = (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) FROM login_slideshow_images')->fetchColumn();
    $st = db()->prepare(
        'INSERT INTO login_slideshow_images (stored_name, caption, sort_order, is_active, uploaded_by)
         VALUES (?, ?, ?, 1, ?)'
    );
    $st->execute([
        $stored,
        trim($caption),
        $maxOrder + 1,
        $uploadedBy > 0 ? $uploadedBy : null,
    ]);

    return (int) db()->lastInsertId();
}

/**
 * Store multiple uploaded slideshow images.
 *
 * @param array<string,mixed> $files $_FILES entry (supports single or multi file input)
 * @return array{uploaded:int, ids:list<int>, errors:list<string>}
 */
function login_slideshow_store_uploads(array $files, string $caption, int $uploadedBy): array
{
    $fileList = [];
    if (isset($files['name']) && is_array($files['name'])) {
        $count = count($files['name']);
        for ($i = 0; $i < $count; $i++) {
            $fileList[] = [
                'name' => $files['name'][$i] ?? '',
                'type' => $files['type'][$i] ?? '',
                'tmp_name' => $files['tmp_name'][$i] ?? '',
                'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$i] ?? 0,
            ];
        }
    } elseif (isset($files['name']) && ($files['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $fileList[] = $files;
    }

    $fileList = array_values(array_filter($fileList, static function (array $file): bool {
        return (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    }));

    if ($fileList === []) {
        throw new RuntimeException('Please choose at least one image to upload.');
    }

    if (count($fileList) > 20) {
        throw new RuntimeException('You can upload up to 20 images at a time.');
    }

    $ids = [];
    $errors = [];
    foreach ($fileList as $i => $file) {
        $label = trim((string) ($file['name'] ?? ''));
        if ($label === '') {
            $label = 'Image ' . ($i + 1);
        }
        try {
            $ids[] = login_slideshow_store_upload($file, $caption, $uploadedBy);
        } catch (Throwable $e) {
            $errors[] = $label . ': ' . $e->getMessage();
        }
    }

    if ($ids === [] && $errors !== []) {
        throw new RuntimeException(implode(' ', $errors));
    }

    return [
        'uploaded' => count($ids),
        'ids' => $ids,
        'errors' => $errors,
    ];
}

function login_slideshow_delete(int $id): void
{
    if (!login_slideshow_table_ready()) {
        throw new RuntimeException('Login slideshow is not installed. Run upgrade_roles.php once.');
    }
    if ($id < 1) {
        throw new RuntimeException('Invalid image.');
    }

    $st = db()->prepare('SELECT stored_name FROM login_slideshow_images WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $stored = trim((string) ($st->fetchColumn() ?: ''));
    if ($stored === '') {
        throw new RuntimeException('Image not found.');
    }

    db()->prepare('DELETE FROM login_slideshow_images WHERE id = ?')->execute([$id]);
    login_slideshow_delete_file($stored);
}

function login_slideshow_set_active(int $id, bool $active): void
{
    if (!login_slideshow_table_ready()) {
        throw new RuntimeException('Login slideshow is not installed. Run upgrade_roles.php once.');
    }
    if ($id < 1) {
        throw new RuntimeException('Invalid image.');
    }

    db()->prepare('UPDATE login_slideshow_images SET is_active = ? WHERE id = ?')
        ->execute([$active ? 1 : 0, $id]);
}

function login_slideshow_update_caption(int $id, string $caption): void
{
    if (!login_slideshow_table_ready()) {
        throw new RuntimeException('Login slideshow is not installed. Run upgrade_roles.php once.');
    }
    if ($id < 1) {
        throw new RuntimeException('Invalid image.');
    }

    db()->prepare('UPDATE login_slideshow_images SET caption = ? WHERE id = ?')
        ->execute([trim($caption), $id]);
}

function login_slideshow_move(int $id, string $direction): void
{
    if (!login_slideshow_table_ready()) {
        throw new RuntimeException('Login slideshow is not installed. Run upgrade_roles.php once.');
    }
    if ($id < 1) {
        throw new RuntimeException('Invalid image.');
    }

    $rows = login_slideshow_all_images();
    $index = null;
    foreach ($rows as $i => $row) {
        if ((int) $row['id'] === $id) {
            $index = $i;
            break;
        }
    }
    if ($index === null) {
        throw new RuntimeException('Image not found.');
    }

    $swapIndex = $direction === 'up' ? $index - 1 : $index + 1;
    if ($swapIndex < 0 || $swapIndex >= count($rows)) {
        return;
    }

    $current = $rows[$index];
    $other = $rows[$swapIndex];
    $currentOrder = (int) $current['sort_order'];
    $otherOrder = (int) $other['sort_order'];

    $pdo = db();
    $pdo->prepare('UPDATE login_slideshow_images SET sort_order = ? WHERE id = ?')->execute([$otherOrder, (int) $current['id']]);
    $pdo->prepare('UPDATE login_slideshow_images SET sort_order = ? WHERE id = ?')->execute([$currentOrder, (int) $other['id']]);
}

function login_slideshow_image_row(int $id): ?array
{
    if (!login_slideshow_table_ready() || $id < 1) {
        return null;
    }

    $st = db()->prepare(
        'SELECT id, stored_name, caption, is_active
         FROM login_slideshow_images
         WHERE id = ? LIMIT 1'
    );
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

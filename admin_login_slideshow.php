<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/admin_activity_log.php';
require_once __DIR__ . '/includes/login_slideshow_helpers.php';

require_role(['admin']);

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$tableReady = login_slideshow_table_ready();
$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tableReady) {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'upload') {
            $caption = trim((string) ($_POST['caption'] ?? ''));
            $result = login_slideshow_store_uploads($_FILES['slide_images'] ?? [], $caption, $userId);
            foreach ($result['ids'] as $newId) {
                log_admin_activity('add', 'Login slideshow', 'Slide #' . $newId, null, ['caption' => $caption]);
            }
            $msg = $result['uploaded'] === 1
                ? '1 image added to the login slideshow.'
                : $result['uploaded'] . ' images added to the login slideshow.';
            if ($result['errors'] !== []) {
                $msg .= ' Some files were skipped: ' . implode(' ', $result['errors']);
            }
            $_SESSION['flash'] = $msg;
        } elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            $row = login_slideshow_image_row($id);
            login_slideshow_delete($id);
            log_admin_activity('delete', 'Login slideshow', 'Slide #' . $id, $row ? (array) $row : null, null);
            $_SESSION['flash'] = 'Image removed from the login slideshow.';
        } elseif ($action === 'toggle') {
            $id = (int) ($_POST['id'] ?? 0);
            $active = !empty($_POST['is_active']);
            $before = login_slideshow_image_row($id);
            login_slideshow_set_active($id, $active);
            $after = login_slideshow_image_row($id);
            log_admin_activity(
                'edit',
                'Login slideshow',
                'Slide #' . $id,
                $before ? (array) $before : null,
                $after ? (array) $after : null
            );
            $_SESSION['flash'] = $active ? 'Image is now visible on the login page.' : 'Image hidden from the login page.';
        } elseif ($action === 'caption') {
            $id = (int) ($_POST['id'] ?? 0);
            $caption = trim((string) ($_POST['caption'] ?? ''));
            $before = login_slideshow_image_row($id);
            login_slideshow_update_caption($id, $caption);
            $after = login_slideshow_image_row($id);
            log_admin_activity(
                'edit',
                'Login slideshow',
                'Slide #' . $id . ' caption',
                $before ? (array) $before : null,
                $after ? (array) $after : null
            );
            $_SESSION['flash'] = 'Caption updated.';
        } elseif ($action === 'move_up' || $action === 'move_down') {
            $id = (int) ($_POST['id'] ?? 0);
            login_slideshow_move($id, $action === 'move_up' ? 'up' : 'down');
            $_SESSION['flash'] = 'Slide order updated.';
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        $_SESSION['flash'] = 'Error: ' . $e->getMessage();
    }
    header('Location: admin_login_slideshow.php');
    exit;
}

$slides = $tableReady ? login_slideshow_all_images() : [];
$pageTitle = 'Login Slideshow';
require_once __DIR__ . '/includes/header.php';
?>
<h1 class="h3 mb-2"><i class="fa-solid fa-images me-2 text-primary"></i>Login Page Slideshow</h1>
<p class="small text-muted mb-4">
    Upload images that auto-scroll in the vacant area on the public login page (<code>login.php</code>).
    Only active images are shown, in the order listed below.
</p>

<?php if ($flash): ?>
    <div class="alert alert-info"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<?php if (!$tableReady): ?>
    <div class="alert alert-warning">
        The slideshow table is missing. Run <a href="upgrade_roles.php">upgrade_roles.php</a> once to enable this feature.
    </div>
<?php else: ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold">Add images</div>
        <div class="card-body">
            <form method="post" enctype="multipart/form-data" class="row g-3">
                <input type="hidden" name="action" value="upload">
                <div class="col-md-6">
                    <label class="form-label" for="slide_images">Images</label>
                    <input type="file" name="slide_images[]" id="slide_images" class="form-control" accept="image/jpeg,image/png,image/webp" multiple required>
                    <div class="form-text">Select one or more images (up to 20 at a time). JPEG, PNG, or WebP, max 5&nbsp;MB each. Images are scaled to fit the login panel.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="caption">Caption (optional)</label>
                    <input type="text" name="caption" id="caption" class="form-control" maxlength="255" placeholder="Applied to all images in this batch">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100"<?= app_tooltip_attr('Uploads selected images to the login page slideshow.') ?>>
                        <i class="fa-solid fa-upload me-1"></i>Upload
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($slides === []): ?>
        <div class="alert alert-secondary">No slideshow images yet. Upload one above to fill the login page hero area.</div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($slides as $i => $slide):
                $slideId = (int) $slide['id'];
                $stored = trim((string) ($slide['stored_name'] ?? ''));
                $previewUrl = is_file(login_slideshow_path($stored))
                    ? 'login_slideshow_image.php?id=' . $slideId . '&preview=1'
                    : '';
                $isActive = (int) ($slide['is_active'] ?? 0) === 1;
            ?>
                <div class="col-12">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <div class="row g-3 align-items-center">
                                <div class="col-md-3 col-lg-2">
                                    <?php if ($previewUrl !== '' && $isActive): ?>
                                        <img src="<?= htmlspecialchars($previewUrl) ?>" alt="" class="img-fluid rounded border bg-dark" style="max-height: 120px; width: 100%; object-fit: contain;">
                                    <?php elseif ($previewUrl !== ''): ?>
                                        <img src="<?= htmlspecialchars($previewUrl) ?>" alt="" class="img-fluid rounded border bg-dark opacity-50" style="max-height: 120px; width: 100%; object-fit: contain;">
                                    <?php else: ?>
                                        <div class="border rounded text-muted small p-3 text-center">File missing</div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-5 col-lg-6">
                                    <form method="post" class="row g-2">
                                        <input type="hidden" name="action" value="caption">
                                        <input type="hidden" name="id" value="<?= $slideId ?>">
                                        <div class="col-12">
                                            <label class="form-label small mb-1">Caption</label>
                                            <div class="input-group input-group-sm">
                                                <input type="text" name="caption" class="form-control" maxlength="255" value="<?= htmlspecialchars((string) ($slide['caption'] ?? '')) ?>">
                                                <button type="submit" class="btn btn-outline-secondary">Save</button>
                                            </div>
                                        </div>
                                    </form>
                                    <div class="small text-muted mt-2">
                                        Order #<?= $i + 1 ?>
                                        <?php if (!$isActive): ?>
                                            <span class="badge text-bg-secondary ms-1">Hidden</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-md-4 col-lg-4">
                                    <div class="d-flex flex-wrap gap-2 justify-content-md-end">
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="action" value="move_up">
                                            <input type="hidden" name="id" value="<?= $slideId ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary"<?= $i === 0 ? ' disabled' : '' ?> title="Move earlier">↑</button>
                                        </form>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="action" value="move_down">
                                            <input type="hidden" name="id" value="<?= $slideId ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary"<?= $i === count($slides) - 1 ? ' disabled' : '' ?> title="Move later">↓</button>
                                        </form>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?= $slideId ?>">
                                            <input type="hidden" name="is_active" value="<?= $isActive ? '0' : '1' ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-primary">
                                                <?= $isActive ? 'Hide' : 'Show' ?>
                                            </button>
                                        </form>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Remove this image from the login slideshow?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $slideId ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php
declare(strict_types=1);

/** @var array<string,mixed> $classroom */
/** @var int $classroomId */
/** @var string|null $bannerUrl */
/** @var bool $hasBannerCols */
/** @var bool $allowUpload */
/** @var string $title */
/** @var string $metaExtra */
/** @var string $formId */
/** @var string $fileInputId */
/** @var string $uploadFormId */
/** @var string $courseCode */
/** @var string $courseName */
/** @var string $semester */
/** @var string $schoolYear */
?>
<div class="classroom-banner mb-3<?= $bannerUrl ? ' has-photo' : '' ?>"<?= $bannerUrl ? ' style="--classroom-banner-image: url(\'' . htmlspecialchars($bannerUrl, ENT_QUOTES) . '\')"' : '' ?>>
    <div class="classroom-banner__overlay">
        <h1 class="classroom-banner__title"><?= htmlspecialchars($title) ?></h1>
        <div class="classroom-banner__meta">
            <?php if ($courseCode !== '' || $courseName !== ''): ?>
                <?= htmlspecialchars($courseCode) ?><?= ($courseCode !== '' && $courseName !== '') ? ' — ' : '' ?><?= htmlspecialchars($courseName) ?>
            <?php endif; ?>
            <?php if ($semester !== '' || $schoolYear !== ''): ?>
                <?php if ($courseCode !== '' || $courseName !== ''): ?>
                    <span class="classroom-banner__sep">·</span>
                <?php endif; ?>
                <?= htmlspecialchars(trim($semester . ($semester !== '' && $schoolYear !== '' ? ' / ' : '') . $schoolYear)) ?>
            <?php endif; ?>
            <?php if ($metaExtra !== ''): ?>
                <span class="classroom-banner__sep">·</span>
                <?= htmlspecialchars($metaExtra) ?>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($allowUpload && $hasBannerCols): ?>
        <div class="classroom-banner__actions">
            <form method="post" enctype="multipart/form-data" class="classroom-banner__upload-form" id="<?= htmlspecialchars($uploadFormId) ?>">
                <input type="hidden" name="action" value="upload_banner">
                <input type="hidden" name="classroom_id" value="<?= (int) $classroomId ?>">
                <label class="btn btn-light btn-sm classroom-banner__upload-btn" for="<?= htmlspecialchars($fileInputId) ?>"<?= function_exists('app_tooltip_attr') ? app_tooltip_attr('Choose a JPEG, PNG, or WebP image for this class header background. Students see the same banner.') : '' ?>>
                    <i class="fa-solid fa-image me-1"></i><?= $bannerUrl ? 'Change background' : 'Upload background' ?>
                </label>
                <input id="<?= htmlspecialchars($fileInputId) ?>" type="file" name="banner" class="visually-hidden" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp" required>
            </form>
            <?php if ($bannerUrl): ?>
                <form method="post" class="d-inline" onsubmit="return confirm('Remove the header background image?');">
                    <input type="hidden" name="action" value="delete_banner">
                    <input type="hidden" name="classroom_id" value="<?= (int) $classroomId ?>">
                    <button type="submit" class="btn btn-outline-light btn-sm"<?= function_exists('app_tooltip_attr') ? app_tooltip_attr('Removes the custom background; the default gradient banner will show instead.') : '' ?>><i class="fa-solid fa-trash me-1"></i>Remove</button>
                </form>
            <?php endif; ?>
        </div>
        <script>
        (function () {
            var input = document.getElementById(<?= json_encode($fileInputId) ?>);
            var form = document.getElementById(<?= json_encode($uploadFormId) ?>);
            if (!input || !form) return;
            input.addEventListener('change', function () {
                if (input.files && input.files.length) {
                    form.submit();
                }
            });
        })();
        </script>
    <?php elseif ($allowUpload && !$hasBannerCols): ?>
        <div class="classroom-banner__actions">
            <span class="badge text-bg-warning">Run upgrade_roles.php to enable background upload</span>
        </div>
    <?php endif; ?>
</div>

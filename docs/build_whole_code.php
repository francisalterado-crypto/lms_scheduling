<?php
declare(strict_types=1);

/**
 * Bundle all application source into one file (code only).
 * Run: C:\xampp\php\php.exe docs\build_whole_code.php
 */

$root = dirname(__DIR__);
$outPath = __DIR__ . DIRECTORY_SEPARATOR . 'WHOLE_SOURCE_CODE.txt';

$excludeDirs = [
    'docs',
    'uploads',
    'CLASS',
    '.git',
    'node_modules',
];

$extensions = ['php', 'sql', 'js', 'css', 'yaml', 'example.php'];

$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

    foreach ($excludeDirs as $dir) {
        if ($rel === $dir || str_starts_with($rel, $dir . '/')) {
            continue 2;
        }
    }

    if (str_contains($rel, 'config/config.php')) {
        continue;
    }

    $ext = strtolower($file->getExtension());
    $basename = $file->getFilename();
    if ($basename === 'config.example.php') {
        $ext = 'example.php';
    }

    if (!in_array($ext, $extensions, true)) {
        continue;
    }

    // Skip generated documentation bundles
    if (str_starts_with($basename, 'WPU_SABLAe_') || $basename === 'SOURCE_CODE_DOCUMENTATION.md') {
        continue;
    }

    $files[$rel] = $file->getPathname();
}

ksort($files, SORT_NATURAL | SORT_FLAG_CASE);

$buf = [];
$buf[] = 'WPU SABLAe Portal — Complete Source Code';
$buf[] = 'Generated: ' . date('Y-m-d H:i:s T');
$buf[] = 'Root: ' . $root;
$buf[] = 'Files: ' . count($files);
$buf[] = str_repeat('=', 80);
$buf[] = '';

foreach ($files as $rel => $path) {
    $content = file_get_contents($path);
    if ($content === false) {
        fwrite(STDERR, "Skip unreadable: {$rel}\n");
        continue;
    }
    // Normalize line endings
    $content = str_replace("\r\n", "\n", $content);
    $content = rtrim($content) . "\n";

    $buf[] = str_repeat('-', 80);
    $buf[] = "FILE: {$rel}";
    $buf[] = str_repeat('-', 80);
    $buf[] = $content;
    $buf[] = '';
}

$output = implode("\n", $buf);
if (file_put_contents($outPath, $output) === false) {
    fwrite(STDERR, "Failed to write {$outPath}\n");
    exit(1);
}

echo "Wrote {$outPath}\n";
echo 'Size: ' . number_format(strlen($output)) . " bytes\n";
echo 'Files: ' . count($files) . "\n";

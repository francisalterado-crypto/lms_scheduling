<?php
declare(strict_types=1);

/**
 * Combines module markdown files and exports PDF + DOCX via pandoc.
 * Run: C:\xampp\php\php.exe docs\build_exports.php
 */

$docsDir = __DIR__;
$rootDir = dirname($docsDir);

$moduleFiles = [
    'README.md',
    '01-core-infrastructure.md',
    '02-authentication.md',
    '03-installation.md',
    '04-super-admin.md',
    '05-admin.md',
    '06-dean.md',
    '07-program-chair.md',
    '08-faculty.md',
    '09-gened.md',
    '10-student.md',
    '11-scheduling.md',
    '12-classroom-lms.md',
    '13-messaging.md',
    '14-wellness.md',
    '15-reports.md',
    '16-api.md',
    '17-ui-assets.md',
];

$codeAppendix = <<<'MD'

\newpage

# Appendix A: Key Source Code Excerpts

Representative source files referenced throughout this documentation.

## `index.php` — Application entry point

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit;
```

## `includes/db.php` — Database connection

```php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            // ... XAMPP-friendly connection error ...
            throw $e;
        }
    }
    return $pdo;
}
```

## `includes/auth.php` — Role gate

```php
function require_role(array $roles): void
{
    require_login();
    if (!in_array((string) ($_SESSION['role'] ?? ''), $roles, true)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}
```

## `admin_gened.php` — Admin page pattern

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mail_helpers.php';
require_once __DIR__ . '/includes/admin_activity_log.php';

require_role(['admin']);
// POST handler → flash message → redirect
// GET → load data → header.php → HTML form → footer.php
```

## `api/programs_by_college.php` — JSON API pattern

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/student_registration_helpers.php';

header('Content-Type: application/json; charset=utf-8');

$collegeId = (int) ($_GET['college_id'] ?? 0);
$programs = active_programs_for_college($collegeId);
$yearLevelsByProgram = active_year_levels_by_program_for_college($collegeId);

echo json_encode([
    'programs' => $programs,
    'year_levels_by_program' => $yearLevelsByProgram,
], JSON_UNESCAPED_UNICODE);
```

## `install/schema.sql` — Core tables (excerpt)

```sql
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  full_name VARCHAR(100) NOT NULL,
  role ENUM('super_admin','admin','dean','program_chair','faculty','gened','student') NOT NULL,
  college_id INT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  ...
);

CREATE TABLE IF NOT EXISTS schedules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  faculty_id INT NOT NULL,
  course_id INT NOT NULL,
  room_id INT NOT NULL,
  day_of_week SET('Monday','Tuesday',...) NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  semester VARCHAR(20) NOT NULL,
  school_year VARCHAR(20) NOT NULL,
  ...
);
```

---

*Document generated: {{GENERATED_AT}}*

MD;

$parts = [];
$parts[] = "---\n";
$parts[] = "title: \"WPU SABLAe Portal — Source Code Documentation\"\n";
$parts[] = "author: \"CLASS / lms_scheduling\"\n";
$parts[] = "date: \"" . date('Y-m-d') . "\"\n";
$parts[] = "toc: true\n";
$parts[] = "toc-depth: 3\n";
$parts[] = "numbersections: true\n";
$parts[] = "---\n\n";

foreach ($moduleFiles as $file) {
    $path = $docsDir . DIRECTORY_SEPARATOR . $file;
    if (!is_readable($path)) {
        fwrite(STDERR, "Missing: {$path}\n");
        exit(1);
    }
    $content = file_get_contents($path);
    if ($content === false) {
        fwrite(STDERR, "Could not read: {$path}\n");
        exit(1);
    }
    // Strip per-file TOC links to sibling md files (keep content)
    $content = preg_replace('/\]\(0\d-[^)]+\.md\)/', '](#)', $content) ?? $content;
    $parts[] = "\n\n\\newpage\n\n";
    $parts[] = $content;
}

$parts[] = str_replace('{{GENERATED_AT}}', date('Y-m-d H:i:s T'), $codeAppendix);

$combinedPath = $docsDir . DIRECTORY_SEPARATOR . 'SOURCE_CODE_DOCUMENTATION.md';
$combined = implode('', $parts);
if (file_put_contents($combinedPath, $combined) === false) {
    fwrite(STDERR, "Failed to write {$combinedPath}\n");
    exit(1);
}
echo "Wrote {$combinedPath} (" . number_format(strlen($combined)) . " bytes)\n";

$pandoc = findPandoc();
if ($pandoc === null) {
    fwrite(STDERR, "pandoc not found in PATH. Install from https://pandoc.org\n");
    exit(1);
}

$baseName = 'WPU_SABLAe_Source_Code_Documentation';
$docxPath = $docsDir . DIRECTORY_SEPARATOR . $baseName . '.docx';
$pdfPath = $docsDir . DIRECTORY_SEPARATOR . $baseName . '.pdf';
$htmlPath = $docsDir . DIRECTORY_SEPARATOR . $baseName . '.html';

$commonArgs = [
    '-f', 'markdown',
    '-t', 'html',
    $combinedPath,
    '--standalone',
    '--toc',
    '--toc-depth=3',
];

// DOCX
$cmd = '"' . $pandoc . '" ' . escapeshellarg($combinedPath)
    . ' -f markdown -t docx --standalone --toc --toc-depth=3'
    . ' -o ' . escapeshellarg($docxPath);
runOrFail($cmd, 'DOCX export');
echo "Wrote {$docxPath}\n";

// HTML (print-friendly; open in browser → Print to PDF)
$htmlCss = <<<'CSS'
body { font-family: Georgia, "Times New Roman", serif; max-width: 900px; margin: 2em auto; padding: 0 1.5em; line-height: 1.5; color: #222; }
h1 { border-bottom: 2px solid #333; padding-bottom: 0.3em; page-break-before: always; }
h1:first-of-type { page-break-before: avoid; }
h2 { border-bottom: 1px solid #ccc; margin-top: 1.5em; }
table { border-collapse: collapse; width: 100%; margin: 1em 0; font-size: 0.9em; }
th, td { border: 1px solid #ccc; padding: 0.4em 0.6em; text-align: left; }
th { background: #f5f5f5; }
code { background: #f4f4f4; padding: 0.1em 0.3em; font-size: 0.9em; }
pre { background: #f8f8f8; border: 1px solid #ddd; padding: 1em; overflow-x: auto; font-size: 0.85em; }
pre code { background: none; padding: 0; }
#TOC { background: #fafafa; border: 1px solid #ddd; padding: 1em 1.5em; margin-bottom: 2em; }
@media print { body { max-width: none; } h1 { page-break-before: always; } }
CSS;
$htmlCssPath = $docsDir . DIRECTORY_SEPARATOR . '_export_style.css';
file_put_contents($htmlCssPath, $htmlCss);

$cmd = '"' . $pandoc . '" ' . escapeshellarg($combinedPath)
    . ' -f markdown -t html --standalone --toc --toc-depth=3'
    . ' --metadata title=' . escapeshellarg('WPU SABLAe Portal Source Code Documentation')
    . ' --css ' . escapeshellarg($htmlCssPath)
    . ' -o ' . escapeshellarg($htmlPath);
runOrFail($cmd, 'HTML export');
echo "Wrote {$htmlPath}\n";
@unlink($htmlCssPath);

// PDF — try available engines
$pdfEngines = ['wkhtmltopdf', 'weasyprint', 'pdflatex', 'xelatex', 'lualatex'];
$pdfOk = false;
foreach ($pdfEngines as $engine) {
    $cmd = '"' . $pandoc . '" ' . escapeshellarg($combinedPath)
        . ' -f markdown --standalone --toc --toc-depth=3'
        . ' --pdf-engine=' . escapeshellarg($engine)
        . ' -V geometry:margin=1in'
        . ' -o ' . escapeshellarg($pdfPath) . ' 2>&1';
    exec($cmd, $out, $code);
    if ($code === 0 && is_file($pdfPath) && filesize($pdfPath) > 0) {
        echo "Wrote {$pdfPath} (engine: {$engine})\n";
        $pdfOk = true;
        break;
    }
}

if (!$pdfOk) {
    $cmd = '"' . $pandoc . '" ' . escapeshellarg($combinedPath)
        . ' -f markdown --standalone --toc --toc-depth=3'
        . ' -o ' . escapeshellarg($pdfPath) . ' 2>&1';
    exec($cmd, $out, $code);
    if ($code === 0 && is_file($pdfPath) && filesize($pdfPath) > 0) {
        echo "Wrote {$pdfPath}\n";
        $pdfOk = true;
    }
}

if (!$pdfOk) {
    $pdfOk = exportPdfViaEdge($htmlPath, $pdfPath);
    if ($pdfOk) {
        echo "Wrote {$pdfPath} (engine: Microsoft Edge headless)\n";
    }
}

if (!$pdfOk) {
    echo "PDF: skipped (no PDF engine). Use {$htmlPath} → Print to PDF, or open {$docxPath} → Save as PDF.\n";
    if (!empty($out)) {
        echo implode("\n", array_slice($out, -5)) . "\n";
    }
}

echo "Done.\n";

function exportPdfViaEdge(string $htmlPath, string $pdfPath): bool
{
    if (PHP_OS_FAMILY !== 'Windows') {
        return false;
    }
    $candidates = [
        getenv('ProgramFiles(x86)') . '\\Microsoft\\Edge\\Application\\msedge.exe',
        getenv('ProgramFiles') . '\\Microsoft\\Edge\\Application\\msedge.exe',
        getenv('LOCALAPPDATA') . '\\Microsoft\\Edge\\Application\\msedge.exe',
    ];
    $edge = null;
    foreach ($candidates as $path) {
        if (is_string($path) && $path !== '' && is_file($path)) {
            $edge = $path;
            break;
        }
    }
    if ($edge === null) {
        return false;
    }
    $uri = 'file:///' . str_replace('\\', '/', $htmlPath);
    $cmd = '"' . $edge . '" --headless --disable-gpu --no-pdf-header-footer'
        . ' --print-to-pdf=' . escapeshellarg($pdfPath)
        . ' ' . escapeshellarg($uri) . ' 2>&1';
    exec($cmd, $out, $code);
    return is_file($pdfPath) && filesize($pdfPath) > 0;
}

function findPandoc(): ?string
{
    $paths = ['pandoc'];
    if (PHP_OS_FAMILY === 'Windows') {
        $paths[] = 'C:\\Program Files\\Pandoc\\pandoc.exe';
        $paths[] = getenv('LOCALAPPDATA') . '\\Pandoc\\pandoc.exe';
    }
    foreach ($paths as $p) {
        if ($p === '' || $p === false) {
            continue;
        }
        $cmd = PHP_OS_FAMILY === 'Windows' && str_contains($p, '\\')
            ? '"' . $p . '" --version'
            : escapeshellarg($p) . ' --version';
        exec($cmd . ' 2>&1', $out, $code);
        if ($code === 0) {
            return $p;
        }
    }
    return null;
}

function runOrFail(string $cmd, string $label): void
{
    exec($cmd . ' 2>&1', $out, $code);
    if ($code !== 0) {
        fwrite(STDERR, "{$label} failed (exit {$code}):\n" . implode("\n", $out) . "\n");
        exit(1);
    }
}

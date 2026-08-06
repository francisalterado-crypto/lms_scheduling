<?php
declare(strict_types=1);

/**
 * Bundle all application source and export as PDF via Microsoft Edge headless.
 * Run: C:\xampp\php\php.exe docs\build_whole_code_pdf.php
 */

$docsDir = __DIR__;
$root = dirname($docsDir);
$phpBin = PHP_BINARY;

$buildTxt = $docsDir . DIRECTORY_SEPARATOR . 'build_whole_code.php';
exec('"' . $phpBin . '" ' . escapeshellarg($buildTxt) . ' 2>&1', $buildOut, $buildCode);
echo implode("\n", $buildOut) . "\n";
if ($buildCode !== 0) {
    fwrite(STDERR, "build_whole_code.php failed (exit {$buildCode})\n");
    exit(1);
}

$outTxtPath = $docsDir . DIRECTORY_SEPARATOR . 'WHOLE_SOURCE_CODE.txt';
if (!is_readable($outTxtPath)) {
    fwrite(STDERR, "Missing {$outTxtPath}\n");
    exit(1);
}

$sourceText = file_get_contents($outTxtPath);
if ($sourceText === false) {
    fwrite(STDERR, "Could not read {$outTxtPath}\n");
    exit(1);
}

$htmlPath = $docsDir . DIRECTORY_SEPARATOR . 'WHOLE_SOURCE_CODE.html';
$pdfPath = $docsDir . DIRECTORY_SEPARATOR . 'WHOLE_SOURCE_CODE.pdf';

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>WPU SABLAe Portal — Complete Source Code</title>
<style>
@page { margin: 0.6in 0.5in; size: letter; }
body {
  font-family: Consolas, "Courier New", monospace;
  font-size: 7pt;
  line-height: 1.25;
  color: #111;
  margin: 0;
  padding: 0;
}
h1 {
  font-family: Georgia, "Times New Roman", serif;
  font-size: 16pt;
  margin: 0 0 0.5em;
  page-break-after: avoid;
}
.meta {
  font-family: Georgia, "Times New Roman", serif;
  font-size: 9pt;
  margin-bottom: 1em;
  color: #333;
}
pre {
  white-space: pre-wrap;
  word-wrap: break-word;
  margin: 0;
}
@media print {
  body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>
</head>
<body>
<h1>WPU SABLAe Portal — Complete Source Code</h1>
<div class="meta">Generated for CLASS / lms_scheduling. Monospace listing of all PHP, SQL, JS, CSS, and YAML source files.</div>
<pre>
HTML;

$html .= htmlspecialchars($sourceText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$html .= "\n</pre>\n</body>\n</html>";

if (file_put_contents($htmlPath, $html) === false) {
    fwrite(STDERR, "Failed to write {$htmlPath}\n");
    exit(1);
}
echo "Wrote {$htmlPath} (" . number_format(strlen($html)) . " bytes)\n";

if (!exportPdfViaEdge($htmlPath, $pdfPath)) {
    fwrite(STDERR, "PDF export failed. Open {$htmlPath} in a browser and use Print → Save as PDF.\n");
    exit(1);
}

echo "Wrote {$pdfPath} (" . number_format(filesize($pdfPath)) . " bytes)\n";
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
    if (is_file($pdfPath)) {
        @unlink($pdfPath);
    }
    $uri = 'file:///' . str_replace('\\', '/', $htmlPath);
    $cmd = '"' . $edge . '" --headless --disable-gpu --no-pdf-header-footer'
        . ' --print-to-pdf=' . escapeshellarg($pdfPath)
        . ' ' . escapeshellarg($uri) . ' 2>&1';
    exec($cmd, $out, $code);
    if (!is_file($pdfPath) || filesize($pdfPath) === 0) {
        if (!empty($out)) {
            fwrite(STDERR, implode("\n", $out) . "\n");
        }
        return false;
    }
    echo "PDF engine: Microsoft Edge headless\n";
    return true;
}

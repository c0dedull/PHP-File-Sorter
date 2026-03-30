<?php

/**
 * ============================================================
 *  FILE ORGANIZER — Auto-detects extensions & sorts files
 * ============================================================
 */

// ── CONFIG ──────────────────────────────────────────────────
// Automatically targets the folder this script is placed in.
// Move the script to any folder (Downloads, Desktop, etc.)
// and run it — it will organize that folder automatically.
define('TARGET_DIR', __DIR__);

// If TRUE, only previews what WOULD happen — no files are moved
define('DRY_RUN', false);

// Folder name for files that have no extension at all
define('NO_EXT_FOLDER', 'No Extension');

// ── HELPERS ─────────────────────────────────────────────────

function printLine(string $msg, string $type = 'info'): void
{
    $isCli = php_sapi_name() === 'cli';

    $cliColors = [
        'info'    => "\033[0m",
        'success' => "\033[32m",
        'warn'    => "\033[33m",
        'error'   => "\033[31m",
        'header'  => "\033[36m",
    ];

    if ($isCli) {
        $reset = "\033[0m";
        echo ($cliColors[$type] ?? '') . $msg . $reset . PHP_EOL;
    } else {
        echo "<div class=\"log {$type}\">" . htmlspecialchars($msg) . "</div>\n";
    }
}

function ensureDir(string $path): bool
{
    if (!is_dir($path)) {
        return mkdir($path, 0755, true);
    }
    return true;
}

function resolveDestPath(string $destDir, string $filename): string
{
    $dest = $destDir . DIRECTORY_SEPARATOR . $filename;
    if (!file_exists($dest)) {
        return $dest;
    }

    // Avoid overwrite — append incremental counter
    $info    = pathinfo($filename);
    $name    = $info['filename'];
    $ext     = isset($info['extension']) ? '.' . $info['extension'] : '';
    $counter = 1;

    do {
        $dest = $destDir . DIRECTORY_SEPARATOR . "{$name} ({$counter}){$ext}";
        $counter++;
    } while (file_exists($dest));

    return $dest;
}

// ── STEP 1: SCAN & GROUP FILES BY EXTENSION ─────────────────

function scanFiles(string $targetDir): array
{
    $groups = []; // [ 'extension' => [ 'fullPath', 'filename', ... ] ]

    if (!is_dir($targetDir)) {
        printLine("ERROR: Directory not found: {$targetDir}", 'error');
        return $groups;
    }

    $items = scandir($targetDir);

    foreach ($items as $item) {
        // Skip dots and hidden files
        if ($item === '.' || $item === '..' || str_starts_with($item, '.')) {
            continue;
        }

        $fullPath = $targetDir . DIRECTORY_SEPARATOR . $item;

        // Skip directories — only process files
        if (!is_file($fullPath)) {
            continue;
        }

        // Skip this script itself if it lives inside TARGET_DIR
        if (realpath($fullPath) === realpath(__FILE__)) {
            continue;
        }

        // Detect extension
        $ext    = strtolower(pathinfo($item, PATHINFO_EXTENSION));
        $folder = !empty($ext) ? strtoupper($ext) : NO_EXT_FOLDER;

        $groups[$folder][] = [
            'fullPath' => $fullPath,
            'filename' => $item,
        ];
    }

    // Sort groups alphabetically
    ksort($groups);

    return $groups;
}

// ── STEP 2: CREATE FOLDERS & MOVE FILES ─────────────────────

function organizeFiles(string $targetDir, array $groups): array
{
    $stats = ['moved' => 0, 'skipped' => 0, 'errors' => 0, 'folders' => []];

    if (empty($groups)) {
        printLine("No files found to organize.", 'warn');
        return $stats;
    }

    foreach ($groups as $folderName => $files) {
        $destDir = $targetDir . DIRECTORY_SEPARATOR . $folderName;

        printLine("", 'info');
        printLine("  [ {$folderName} ] — " . count($files) . " file(s)", 'header');

        foreach ($files as $file) {
            $srcPath  = $file['fullPath'];
            $filename = $file['filename'];

            if (DRY_RUN) {
                printLine("    [DRY RUN] {$filename}  →  {$folderName}/", 'warn');
                $stats['skipped']++;
                continue;
            }

            // Create folder if it doesn't exist yet
            if (!ensureDir($destDir)) {
                printLine("    ERROR: Cannot create folder '{$folderName}'", 'error');
                $stats['errors']++;
                continue;
            }

            $destPath = resolveDestPath($destDir, $filename);
            $destName = basename($destPath);

            if (rename($srcPath, $destPath)) {
                $label = $destName !== $filename
                    ? "    ✔  {$filename}  →  {$folderName}/{$destName}  (renamed)"
                    : "    ✔  {$filename}  →  {$folderName}/";
                printLine($label, 'success');
                $stats['moved']++;
                $stats['folders'][$folderName] = ($stats['folders'][$folderName] ?? 0) + 1;
            } else {
                printLine("    ✘  Failed to move: {$filename}", 'error');
                $stats['errors']++;
            }
        }
    }

    return $stats;
}

// ── BOOTSTRAP HTML (browser only) ───────────────────────────

$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>File Organizer</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body   { font-family: 'Courier New', monospace; background: #0d0d0d; color: #ccc; padding: 30px; }
    h2     { color: #fff; margin-bottom: 16px; font-size: 20px; letter-spacing: 1px; }
    .log   { padding: 2px 0; font-size: 13px; }
    .success { color: #66bb6a; }
    .warn    { color: #ffa726; }
    .error   { color: #ef5350; }
    .info    { color: #90caf9; }
    .header  { color: #ce93d8; font-weight: bold; margin-top: 6px; }
    .summary {
      margin-top: 24px; padding: 16px 20px;
      background: #1a1a1a; border-left: 4px solid #66bb6a;
      border-radius: 4px; font-size: 14px; color: #eee;
    }
    .summary span { color: #66bb6a; font-weight: bold; }
    .badge {
      display: inline-block; background: #2a2a2a; border: 1px solid #444;
      border-radius: 4px; padding: 2px 8px; margin: 3px 2px; font-size: 12px;
    }
  </style>
</head>
<body>
<h2>File Organizer</h2>
HTML;
}

// ── PRINT HEADER ─────────────────────────────────────────────

printLine("=============================================", 'info');
printLine("  FILE ORGANIZER — Auto Extension Detection ", 'info');
printLine("  Target : " . TARGET_DIR,                    'info');
printLine("  Mode   : " . (DRY_RUN ? 'DRY RUN (preview only)' : 'LIVE'), 'info');
printLine("=============================================", 'info');

// ── EXECUTE ──────────────────────────────────────────────────

$groups = scanFiles(TARGET_DIR);

if (!empty($groups)) {
    $extList = implode(', ', array_keys($groups));
    printLine("", 'info');
    printLine("  Detected extensions: {$extList}", 'info');
    printLine("  Total file groups  : " . count($groups), 'info');
}

$stats = organizeFiles(TARGET_DIR, $groups);

// ── SUMMARY ──────────────────────────────────────────────────

printLine("", 'info');
printLine("─── SUMMARY ──────────────────────────────────", 'info');
printLine("  Files moved  : {$stats['moved']}",   'success');
printLine("  Skipped      : {$stats['skipped']}", 'warn');
printLine("  Errors       : {$stats['errors']}",  $stats['errors'] > 0 ? 'error' : 'info');

if (!empty($stats['folders'])) {
    printLine("", 'info');
    printLine("  Folders created / used:", 'info');
    foreach ($stats['folders'] as $folder => $count) {
        printLine("    → {$folder}/  ({$count} file(s))", 'success');
    }
}

printLine("──────────────────────────────────────────────", 'info');

// ── CLOSE HTML ───────────────────────────────────────────────

if (!$isCli) {
    $folderBadges = '';
    foreach (($stats['folders'] ?: []) as $f => $c) {
        $folderBadges .= "<span class='badge'>{$f} ({$c})</span> ";
    }

    echo <<<HTML
<div class="summary">
  Done! &nbsp;
  Moved <span>{$stats['moved']}</span> file(s) &nbsp;|&nbsp;
  Skipped <span>{$stats['skipped']}</span> &nbsp;|&nbsp;
  Errors <span>{$stats['errors']}</span>
  <br><br>
  {$folderBadges}
</div>
</body>
</html>
HTML;
}
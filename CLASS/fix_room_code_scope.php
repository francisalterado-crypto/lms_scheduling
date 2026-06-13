<?php
declare(strict_types=1);

/**
 * One-time fix: remove global UNIQUE(room_code) and add scoped uniqueness (room_code_scope).
 * Run once via browser POST or: php fix_room_code_scope.php
 * Remove or protect this file after use (same security model as upgrade_roles.php).
 */
require_once __DIR__ . '/config/config.php';

/**
 * @return array{lines: string[], error: string}
 */
function migrate_room_code_scope(PDO $pdo): array
{
    $lines = [];
    $schema = DB_NAME;

    $run = static function (string $sql, bool $ignoreBenign = true) use ($pdo, &$lines): bool {
        try {
            $pdo->exec($sql);
            $lines[] = 'OK: ' . $sql;
            return true;
        } catch (Throwable $e) {
            $msg = strtolower($e->getMessage());
            if (
                $ignoreBenign
                && (
                    str_contains($msg, "can't drop")
                    || str_contains($msg, 'check that column/key exists')
                    || str_contains($msg, 'check that it exists')
                    || str_contains($msg, 'duplicate column')
                    || str_contains($msg, 'duplicate key name')
                    || str_contains($msg, 'already exists')
                )
            ) {
                $lines[] = 'Skip (expected): ' . $e->getMessage();
                return true;
            }
            $lines[] = 'FAIL: ' . $e->getMessage();
            return false;
        }
    };

    $run('ALTER TABLE rooms DROP INDEX room_code');
    $run('ALTER TABLE rooms DROP INDEX uq_rooms_scope');

    $st = $pdo->prepare(
        "SELECT DISTINCT INDEX_NAME
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = ?
           AND TABLE_NAME = 'rooms'
           AND COLUMN_NAME = 'room_code'
           AND NON_UNIQUE = 0
           AND INDEX_NAME <> 'PRIMARY'"
    );
    $st->execute([$schema]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $idxName) {
        $safe = str_replace('`', '``', (string) $idxName);
        if (!$run("ALTER TABLE rooms DROP INDEX `{$safe}`")) {
            return ['lines' => $lines, 'error' => 'Could not drop unique index on room_code.'];
        }
    }

    $run('ALTER TABLE rooms ADD INDEX idx_room_code (room_code)');

    $chk = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'rooms' AND COLUMN_NAME = 'room_code_scope'"
    );
    $chk->execute([$schema]);
    if ((int) $chk->fetchColumn() === 0) {
        $sql = "ALTER TABLE rooms ADD COLUMN room_code_scope VARCHAR(64) GENERATED ALWAYS AS (
            IF(COALESCE(is_gened,0) = 1,
               CONCAT('G|', room_code),
               CONCAT('C|', IFNULL(college_id, 0), '|', room_code))
        ) STORED";
        if (!$run($sql, false)) {
            return [
                'lines' => $lines,
                'error' => 'Could not add room_code_scope column. Check MySQL/MariaDB version supports GENERATED STORED columns.',
            ];
        }
    } else {
        $lines[] = 'OK: room_code_scope column already present.';
    }

    if (!$run('ALTER TABLE rooms ADD UNIQUE KEY uq_rooms_scope (room_code_scope)')) {
        return [
            'lines' => $lines,
            'error' => 'Could not add UNIQUE uq_rooms_scope. If two rows share the same scope (e.g. duplicate GE code), fix data first.',
        ];
    }

    return ['lines' => $lines, 'error' => ''];
}

// ----- CLI -----
if (PHP_SAPI === 'cli') {
    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET),
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $out = migrate_room_code_scope($pdo);
        foreach ($out['lines'] as $line) {
            fwrite(STDOUT, $line . PHP_EOL);
        }
        if ($out['error'] !== '') {
            fwrite(STDERR, $out['error'] . PHP_EOL);
            exit(1);
        }
        fwrite(STDOUT, 'Room code scope migration finished.' . PHP_EOL);
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

// ----- Web -----
$lines = [];
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET),
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $result = migrate_room_code_scope($pdo);
        $lines = $result['lines'];
        $error = $result['error'];
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fix room code uniqueness</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width: 800px;">
    <h1 class="h4 mb-3">Fix room code uniqueness</h1>
    <p class="text-muted">Removes the old global unique index on <code>room_code</code> and adds scoped uniqueness so different colleges (and Gen Ed) can share the same code (e.g. 101).</p>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php elseif ($lines !== []): ?>
        <div class="alert alert-success">Migration completed. You can add rooms again; delete or protect this script when done.</div>
    <?php endif; ?>
    <?php if ($lines !== []): ?>
        <pre class="bg-white border rounded p-3 small" style="max-height: 400px; overflow: auto;"><?= htmlspecialchars(implode("\n", $lines)) ?></pre>
    <?php endif; ?>
    <form method="post">
        <button class="btn btn-primary" type="submit">Run room code migration</button>
        <a class="btn btn-outline-secondary ms-2" href="upgrade_roles.php">Full role upgrade</a>
    </form>
</div>
</body>
</html>

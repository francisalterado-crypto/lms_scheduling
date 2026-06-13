<?php
/**
 * One-time database installer. Open in browser after configuring config/config.php
 */
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['run'])) {
    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=%s', DB_HOST, DB_PORT, DB_CHARSET),
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', DB_NAME) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET),
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $sql = file_get_contents(__DIR__ . '/install/schema.sql');
        if ($sql === false) {
            throw new RuntimeException('Could not read install/schema.sql');
        }
        $sql = preg_replace('/--.*$/m', '', $sql);
        $parts = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($parts as $stmt) {
            if ($stmt === '') {
                continue;
            }
            $pdo->exec($stmt);
        }

        $check = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
        if ($check === 0) {
            $hash = password_hash(DEFAULT_ADMIN_PASSWORD, PASSWORD_DEFAULT);
            $st = $pdo->prepare(
                'INSERT INTO users (username, password, full_name, role) VALUES (?,?,?,?)'
            );
            $st->execute([DEFAULT_ADMIN_USERNAME, $hash, DEFAULT_ADMIN_FULL_NAME, 'admin']);
        }
        $checkGened = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'gened'")->fetchColumn();
        if ($checkGened === 0) {
            $hash = password_hash(DEFAULT_GENED_PASSWORD, PASSWORD_DEFAULT);
            $st = $pdo->prepare(
                'INSERT INTO users (username, password, full_name, role) VALUES (?,?,?,?)'
            );
            $st->execute([DEFAULT_GENED_USERNAME, $hash, DEFAULT_GENED_FULL_NAME, 'gened']);
        }

        $message = 'Database installed successfully. Default login: ' . htmlspecialchars(DEFAULT_ADMIN_USERNAME)
            . ' / ' . htmlspecialchars(DEFAULT_ADMIN_PASSWORD)
            . ' and ' . htmlspecialchars(DEFAULT_GENED_USERNAME) . ' / ' . htmlspecialchars(DEFAULT_GENED_PASSWORD)
            . ' — change passwords after first login.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
} else {
    $message = 'Click the button below to create tables and the default admin user.';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install — WPU SABLAe Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:640px">
    <h1 class="h3 mb-4">WPU SABLAe Portal — Install</h1>
    <?php if ($message && !$error): ?>
        <div class="alert alert-success"><?= $message ?></div>
        <a href="login.php" class="btn btn-primary">Go to login</a>
    <?php elseif ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <a href="install.php" class="btn btn-secondary">Retry</a>
    <?php else: ?>
        <p><?= htmlspecialchars($message) ?></p>
        <form method="post">
            <button type="submit" class="btn btn-primary">Run installation</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>

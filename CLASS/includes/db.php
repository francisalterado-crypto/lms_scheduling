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
            $code = (string) $e->getCode();
            $msg = $e->getMessage();
            if (
                $code === '2002'
                || str_contains($msg, '2002')
                || str_contains(strtolower($msg), 'refused')
                || str_contains(strtolower($msg), 'could not find server')
            ) {
                throw new RuntimeException(
                    'Cannot connect to MySQL (nothing is listening on '
                    . DB_HOST . ':' . DB_PORT . '). '
                    . 'Open the XAMPP Control Panel and click Start for MySQL, then reload this page. '
                    . 'If you use a different port, set DB_PORT in config/config.php.',
                    0,
                    $e
                );
            }
            throw $e;
        }
    }
    return $pdo;
}

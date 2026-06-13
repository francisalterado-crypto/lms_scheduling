<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$logoutUsername = (string) ($_SESSION['username'] ?? '');
$logoutRole = (string) ($_SESSION['role'] ?? '');
if ($userId > 0) {
    try {
        $nowTs = date('Y-m-d H:i:s');
        if (db_column_exists('users', 'last_logout_at')) {
            db()->prepare('UPDATE users SET last_logout_at = ?, last_seen_at = ? WHERE id = ?')
                ->execute([$nowTs, $nowTs, $userId]);
        } elseif (db_column_exists('users', 'last_seen_at')) {
            db()->prepare('UPDATE users SET last_seen_at = ? WHERE id = ?')->execute([$nowTs, $userId]);
        }

        // If attendance has already been generated for today's classes, capture logout time on those records.
        if (
            db_table_exists('classroom_attendance_sessions')
            && db_table_exists('classroom_attendance_records')
            && db_table_exists('classroom_students')
            && db_column_exists('classroom_attendance_records', 'evidence_logout_at')
            && db_column_exists('classroom_students', 'user_id')
        ) {
            db()->prepare(
                'UPDATE classroom_attendance_records ar
                 INNER JOIN classroom_attendance_sessions s ON s.id = ar.session_id
                 INNER JOIN classroom_students cs ON cs.id = ar.student_id
                 SET ar.evidence_logout_at = ?, ar.checked_at = ?, ar.updated_at = CURRENT_TIMESTAMP,
                     ar.notes = CONCAT(TRIM(ar.notes), CASE WHEN TRIM(ar.notes) = "" THEN "" ELSE " " END, "Logout captured at sign-out.")
                 WHERE cs.user_id = ?
                   AND s.attendance_date = CURDATE()
                   AND ar.evidence_logout_at IS NULL'
            )->execute([$nowTs, $nowTs, $userId]);
        }
    } catch (Throwable $e) {
        // Best effort logout timestamp update.
    }
    require_once __DIR__ . '/includes/admin_activity_log.php';
    log_user_activity(
        'logout',
        'Authentication',
        'Signed out',
        null,
        null,
        $userId,
        $logoutUsername,
        $logoutRole
    );
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
header('Location: login.php');
exit;

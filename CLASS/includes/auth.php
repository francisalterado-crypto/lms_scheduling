<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

function auth_user_activity_columns_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        $st = db()->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME IN (?, ?)'
        );
        $st->execute([DB_NAME, 'users', 'last_login_at', 'last_seen_at']);
        $ready = (int) $st->fetchColumn() === 2;
    } catch (Throwable $e) {
        $ready = false;
    }

    return $ready;
}

function auth_touch_user_presence(int $userId): void
{
    if ($userId < 1 || !auth_user_activity_columns_ready()) {
        return;
    }

    try {
        db()->prepare('UPDATE users SET last_seen_at = NOW() WHERE id = ?')->execute([$userId]);
    } catch (Throwable $e) {
        // Presence updates are best effort.
    }
}

function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }

    auth_touch_user_presence((int) ($_SESSION['user_id'] ?? 0));
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    return [
        'id' => (int) $_SESSION['user_id'],
        'username' => $_SESSION['username'] ?? '',
        'full_name' => $_SESSION['full_name'] ?? '',
        'role' => $_SESSION['role'] ?? '',
        'assigned_program' => $_SESSION['assigned_program'] ?? '',
        'college_id' => isset($_SESSION['college_id']) ? (int) $_SESSION['college_id'] : null,
        'faculty_id' => isset($_SESSION['faculty_id']) ? (int) $_SESSION['faculty_id'] : null,
        'student_id' => isset($_SESSION['student_id']) ? (int) $_SESSION['student_id'] : null,
    ];
}

function is_admin(): bool
{
    return ($_SESSION['role'] ?? '') === 'admin';
}

function is_dean(): bool
{
    return ($_SESSION['role'] ?? '') === 'dean';
}

function is_program_chair(): bool
{
    return ($_SESSION['role'] ?? '') === 'program_chair';
}

function is_faculty(): bool
{
    return ($_SESSION['role'] ?? '') === 'faculty';
}

function is_gened(): bool
{
    return ($_SESSION['role'] ?? '') === 'gened';
}

function is_student(): bool
{
    return ($_SESSION['role'] ?? '') === 'student';
}

function require_role(array $roles): void
{
    require_login();
    if (!in_array((string) ($_SESSION['role'] ?? ''), $roles, true)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

function current_college_id(): ?int
{
    return isset($_SESSION['college_id']) ? (int) $_SESSION['college_id'] : null;
}

function current_program_scope(): ?string
{
    $program = trim((string) ($_SESSION['assigned_program'] ?? ''));
    return $program !== '' ? $program : null;
}

/** Home college for a faculty row; used to scope schedules and weekly views. */
function faculty_college_id(int $facultyId): ?int
{
    $st = db()->prepare('SELECT college_id FROM faculty WHERE id=?');
    $st->execute([$facultyId]);
    $v = $st->fetchColumn();
    return $v !== false && $v !== null ? (int) $v : null;
}

function resolve_faculty_id_for_user(int $userId): ?int
{
    $st = db()->prepare('SELECT id FROM faculty WHERE user_id=? LIMIT 1');
    $st->execute([$userId]);
    $fid = $st->fetchColumn();
    if ($fid !== false) {
        return (int) $fid;
    }

    $u = db()->prepare('SELECT id, role, full_name, college_id FROM users WHERE id=? LIMIT 1');
    $u->execute([$userId]);
    $user = $u->fetch();
    if (!$user || (string) $user['role'] !== 'faculty') {
        return null;
    }

    $sql = 'SELECT id, college_id FROM faculty WHERE user_id IS NULL AND full_name = ?';
    $params = [(string) $user['full_name']];
    if ($user['college_id'] !== null) {
        $sql .= ' AND college_id = ?';
        $params[] = (int) $user['college_id'];
    }
    $sql .= ' ORDER BY id';
    $f = db()->prepare($sql);
    $f->execute($params);
    $rows = $f->fetchAll();

    if (count($rows) === 1) {
        $newFid = (int) $rows[0]['id'];
        db()->prepare('UPDATE faculty SET user_id=? WHERE id=? AND user_id IS NULL')->execute([$userId, $newFid]);
        if ($user['college_id'] === null && $rows[0]['college_id'] !== null) {
            db()->prepare('UPDATE users SET college_id=? WHERE id=?')->execute([(int) $rows[0]['college_id'], $userId]);
            $_SESSION['college_id'] = (int) $rows[0]['college_id'];
        }
        return $newFid;
    }

    return null;
}

function resolve_student_id_for_user(int $userId): ?int
{
    $st = db()->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
    );
    $st->execute([DB_NAME, 'classroom_students']);
    if ((int) $st->fetchColumn() < 1) {
        return null;
    }

    $st = db()->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute([DB_NAME, 'classroom_students', 'user_id']);
    if ((int) $st->fetchColumn() < 1) {
        return null;
    }

    $st = db()->prepare('SELECT id FROM classroom_students WHERE user_id = ? LIMIT 1');
    $st->execute([$userId]);
    $sid = $st->fetchColumn();
    return $sid !== false ? (int) $sid : null;
}

function dean_college_id_or_fail(): int
{
    require_role(['dean']);
    $cid = current_college_id();
    if (!$cid) {
        http_response_code(403);
        echo 'Dean account is not assigned to a college.';
        exit;
    }
    return $cid;
}

function dean_or_program_chair_college_id_or_fail(): int
{
    require_role(['dean', 'program_chair']);
    $cid = current_college_id();
    if (!$cid) {
        http_response_code(403);
        echo 'This account is not assigned to a college.';
        exit;
    }
    return $cid;
}

function program_scope_or_fail(): string
{
    require_role(['program_chair']);
    $program = current_program_scope();
    if ($program === null) {
        http_response_code(403);
        echo 'Program Chair account is not assigned to a program.';
        exit;
    }
    return $program;
}


function college_name_by_id(?int $id): string
{
    if (!$id) {
        return 'Unassigned';
    }
    $stmt = db()->prepare('SELECT college_name FROM colleges WHERE id = ?');
    $stmt->execute([$id]);
    return (string) ($stmt->fetchColumn() ?: 'Unknown college');
}

function log_dean_activity(string $actionType, string $details): void
{
    if (!is_dean() && !is_program_chair()) {
        return;
    }
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    $cid = current_college_id();
    if ($uid < 1 || !$cid) {
        return;
    }
    $stmt = db()->prepare(
        'INSERT INTO dean_activity_logs (dean_user_id, college_id, action_type, action_details) VALUES (?,?,?,?)'
    );
    $stmt->execute([$uid, $cid, $actionType, $details]);
}

function verify_admin_password(string $password): bool
{
    $stmt = db()->query("SELECT password FROM users WHERE role = 'admin' AND is_active = 1");
    while ($row = $stmt->fetch()) {
        if (password_verify($password, $row['password'])) {
            return true;
        }
    }
    return false;
}

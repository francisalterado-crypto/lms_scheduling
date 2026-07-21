<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/account_registration_helpers.php';

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

    // Throttle DB writes: at most once per 2 minutes per session.
    $now = time();
    $last = (int) ($_SESSION['last_seen_touch_at'] ?? 0);
    if ($last > 0 && ($now - $last) < 120) {
        return;
    }
    $_SESSION['last_seen_touch_at'] = $now;

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
    if ((string) ($_SESSION['role'] ?? '') === 'program_chair') {
        program_chair_sync_session_programs((int) ($_SESSION['user_id'] ?? 0));
        program_chair_handle_active_program_post();
    }
    auth_enforce_password_change_if_required();
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
        'assigned_programs' => $_SESSION['assigned_programs'] ?? [],
        'admin_log_title' => $_SESSION['admin_log_title'] ?? '',
        'college_id' => isset($_SESSION['college_id']) ? (int) $_SESSION['college_id'] : null,
        'faculty_id' => isset($_SESSION['faculty_id']) ? (int) $_SESSION['faculty_id'] : null,
        'student_id' => isset($_SESSION['student_id']) ? (int) $_SESSION['student_id'] : null,
    ];
}

function is_super_admin(): bool
{
    return ($_SESSION['role'] ?? '') === 'super_admin';
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

function program_chair_programs_table_ready(bool $refresh = false): bool
{
    static $ready = null;
    if ($refresh) {
        $ready = null;
    }
    if ($ready !== null) {
        return $ready;
    }
    try {
        $ready = db_table_exists('program_chair_programs');
    } catch (Throwable $e) {
        $ready = false;
    }

    return $ready;
}

/** Create program_chair_programs if missing (idempotent). */
function ensure_program_chair_programs_table(): bool
{
    if (program_chair_programs_table_ready()) {
        return true;
    }
    try {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS program_chair_programs (
                user_id INT NOT NULL,
                program_name VARCHAR(120) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, program_name),
                CONSTRAINT fk_pcp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        return program_chair_programs_table_ready(true);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Programs assigned to a Program Chair (junction table + legacy assigned_program fallback).
 *
 * @return list<string>
 */
function program_chair_assigned_programs(int $userId): array
{
    if ($userId < 1) {
        return [];
    }

    $programs = [];
    if (program_chair_programs_table_ready()) {
        $st = db()->prepare(
            'SELECT program_name FROM program_chair_programs WHERE user_id = ? ORDER BY program_name'
        );
        $st->execute([$userId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $name) {
            $name = trim((string) $name);
            if ($name !== '') {
                $programs[] = $name;
            }
        }
    }

    if ($programs === [] && db_column_exists('users', 'assigned_program')) {
        $st = db()->prepare('SELECT assigned_program FROM users WHERE id = ? LIMIT 1');
        $st->execute([$userId]);
        $legacy = trim((string) ($st->fetchColumn() ?: ''));
        if ($legacy !== '') {
            $programs[] = $legacy;
        }
    }

    return array_values(array_unique($programs));
}

/**
 * Replace a Program Chair's assigned programs and sync users.assigned_program (primary/active).
 *
 * @param list<string> $programs
 */
function program_chair_set_assigned_programs(int $userId, array $programs, ?string $primaryProgram = null): void
{
    if ($userId < 1) {
        throw new RuntimeException('Invalid Program Chair user.');
    }

    $clean = [];
    foreach ($programs as $name) {
        $name = trim((string) $name);
        if ($name !== '' && !in_array($name, $clean, true)) {
            $clean[] = $name;
        }
    }
    if ($clean === []) {
        throw new RuntimeException('At least one program is required.');
    }

    $primary = trim((string) ($primaryProgram ?? ''));
    if ($primary === '' || !in_array($primary, $clean, true)) {
        $primary = $clean[0];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if (program_chair_programs_table_ready()) {
            $pdo->prepare('DELETE FROM program_chair_programs WHERE user_id = ?')->execute([$userId]);
            $ins = $pdo->prepare('INSERT INTO program_chair_programs (user_id, program_name) VALUES (?, ?)');
            foreach ($clean as $name) {
                $ins->execute([$userId, $name]);
            }
        }
        if (db_column_exists('users', 'assigned_program')) {
            $pdo->prepare('UPDATE users SET assigned_program = ? WHERE id = ?')->execute([$primary, $userId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Load assigned programs into the session and ensure assigned_program is one of them.
 *
 * @return list<string>
 */
function program_chair_sync_session_programs(?int $userId = null): array
{
    $uid = $userId ?? (int) ($_SESSION['user_id'] ?? 0);
    if ($uid < 1 || (string) ($_SESSION['role'] ?? '') !== 'program_chair') {
        return [];
    }

    $programs = program_chair_assigned_programs($uid);
    $_SESSION['assigned_programs'] = $programs;

    $active = trim((string) ($_SESSION['assigned_program'] ?? ''));
    if ($programs === []) {
        $_SESSION['assigned_program'] = '';
        return [];
    }
    if ($active === '' || !in_array($active, $programs, true)) {
        $_SESSION['assigned_program'] = $programs[0];
        if (db_column_exists('users', 'assigned_program')) {
            db()->prepare('UPDATE users SET assigned_program = ? WHERE id = ?')->execute([$programs[0], $uid]);
        }
    }

    return $programs;
}

function program_chair_switch_active_program(string $programName): void
{
    // Avoid require_role() here: it re-enters require_login() while the POST switch is handled.
    if (!is_program_chair()) {
        throw new RuntimeException('Only program chairs can switch active program.');
    }
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    $programName = trim($programName);
    $programs = program_chair_assigned_programs($uid);
    if ($programName === '' || !in_array($programName, $programs, true)) {
        throw new RuntimeException('Select one of your assigned programs.');
    }
    if (db_column_exists('users', 'assigned_program')) {
        db()->prepare('UPDATE users SET assigned_program = ? WHERE id = ?')->execute([$programName, $uid]);
    }
    $_SESSION['assigned_program'] = $programName;
    $_SESSION['assigned_programs'] = $programs;
}

/**
 * Whether a program chair user is assigned to a given program name.
 */
function program_chair_user_handles_program(int $userId, string $programName): bool
{
    $programName = trim($programName);
    if ($userId < 1 || $programName === '') {
        return false;
    }
    foreach (program_chair_assigned_programs($userId) as $name) {
        if (strcasecmp($name, $programName) === 0) {
            return true;
        }
    }

    return false;
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
    if (!$user || !in_array((string) $user['role'], ['faculty', 'program_chair', 'dean', 'gened'], true)) {
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

/**
 * Auto-provision a faculty record for a non-faculty role (dean, program_chair, gened)
 * so they can use classroom features under their own teaching load.
 * Returns the faculty table PK or null on failure.
 */
function ensure_faculty_profile_for_teaching_role(int $userId): ?int
{
    $role = (string) ($_SESSION['role'] ?? '');
    if (!in_array($role, ['dean', 'program_chair', 'gened'], true)) {
        return null;
    }

    $hasIsGened = db_column_exists('faculty', 'is_gened');

    $st = db()->prepare('SELECT id, department, college_id' . ($hasIsGened ? ', is_gened' : '') . ' FROM faculty WHERE user_id = ? LIMIT 1');
    $st->execute([$userId]);
    $existing = $st->fetch();
    if ($existing !== false) {
        $needsUpdate = false;
        $updates = [];
        $params = [];

        if ($role === 'program_chair' && trim((string) $existing['department']) === '') {
            $prog = trim((string) ($_SESSION['assigned_program'] ?? ''));
            if ($prog !== '') {
                $updates[] = 'department = ?';
                $params[] = $prog;
                $needsUpdate = true;
            }
        }

        if ($role === 'gened' && $hasIsGened && (int) ($existing['is_gened'] ?? 0) === 0) {
            $updates[] = 'is_gened = 1';
            $needsUpdate = true;
        }

        if ($needsUpdate && $updates !== []) {
            $params[] = (int) $existing['id'];
            db()->prepare('UPDATE faculty SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);
        }
        return (int) $existing['id'];
    }

    $u = db()->prepare('SELECT id, full_name, college_id, assigned_program FROM users WHERE id = ? LIMIT 1');
    $u->execute([$userId]);
    $user = $u->fetch();
    if (!$user) {
        return null;
    }

    $prefixMap = ['dean' => 'DN', 'program_chair' => 'PC', 'gened' => 'GE'];
    $prefix = $prefixMap[$role] ?? 'FC';
    $facCode = $prefix . '-' . str_pad((string) $userId, 6, '0', STR_PAD_LEFT);

    $chk = db()->prepare('SELECT COUNT(*) FROM faculty WHERE faculty_id = ?');
    $chk->execute([$facCode]);
    if ((int) $chk->fetchColumn() > 0) {
        $facCode = $prefix . '-' . $userId . '-' . time() % 10000;
    }

    $department = $role === 'program_chair' ? trim((string) ($user['assigned_program'] ?? '')) : '';
    $isGened = ($role === 'gened') ? 1 : 0;

    $cols = 'user_id, faculty_id, full_name, department, college_id, employment_status, status';
    $placeholders = '?, ?, ?, ?, ?, ?, ?';
    $values = [
        $userId,
        $facCode,
        (string) $user['full_name'],
        $department,
        $user['college_id'] !== null ? (int) $user['college_id'] : null,
        'Permanent',
        'active',
    ];

    if ($hasIsGened) {
        $cols .= ', is_gened';
        $placeholders .= ', ?';
        $values[] = $isGened;
    }

    db()->prepare("INSERT INTO faculty ({$cols}) VALUES ({$placeholders})")->execute($values);

    return (int) db()->lastInsertId();
}

/** @deprecated Use ensure_faculty_profile_for_teaching_role() instead */
function ensure_faculty_profile_for_program_chair(int $userId): ?int
{
    return ensure_faculty_profile_for_teaching_role($userId);
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
    program_chair_sync_session_programs((int) ($_SESSION['user_id'] ?? 0));
    $program = current_program_scope();
    if ($program === null) {
        http_response_code(403);
        echo 'Program Chair account is not assigned to a program.';
        exit;
    }
    return $program;
}

/**
 * Handle POST switch of the active program for multi-program chairs.
 * Call early on pages that include the header switcher.
 */
function program_chair_handle_active_program_post(): void
{
    if (
        $_SERVER['REQUEST_METHOD'] !== 'POST'
        || (string) ($_POST['action'] ?? '') !== 'switch_active_program'
        || !is_program_chair()
    ) {
        return;
    }
    try {
        program_chair_switch_active_program((string) ($_POST['active_program'] ?? ''));
        $_SESSION['flash'] = 'Active program switched to ' . (string) ($_SESSION['assigned_program'] ?? '') . '.';
    } catch (Throwable $e) {
        $_SESSION['flash'] = 'Error: ' . $e->getMessage();
    }
    $redirect = trim((string) ($_POST['redirect'] ?? ''));
    if ($redirect === '' || !preg_match('/^[a-zA-Z0-9_\-\/\.?=&%]+$/', $redirect) || str_contains($redirect, '..')) {
        $redirect = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php'));
    }
    header('Location: ' . $redirect);
    exit;
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
    $stmt = db()->query("SELECT password FROM users WHERE role IN ('admin','super_admin') AND is_active = 1");
    while ($row = $stmt->fetch()) {
        if (password_verify($password, $row['password'])) {
            return true;
        }
    }
    return false;
}

/** Username variants for login (exact, compact, common Super Admin aliases). */
function login_username_lookup_variants(string $username): array
{
    $trimmed = trim($username);
    if ($trimmed === '') {
        return [];
    }

    $variants = [$trimmed];
    $compact = strtolower((string) preg_replace('/[\s_.-]+/', '', $trimmed));
    if ($compact !== '' && $compact !== strtolower($trimmed)) {
        $variants[] = $compact;
    }
    if ($compact !== '' && defined('DEFAULT_SUPER_ADMIN_USERNAME')) {
        $defaultSuper = strtolower((string) DEFAULT_SUPER_ADMIN_USERNAME);
        if (in_array($compact, ['superadmin', 'superadministrator', 'super'], true) && $defaultSuper !== '') {
            $variants[] = (string) DEFAULT_SUPER_ADMIN_USERNAME;
        }
    }

    return array_values(array_unique($variants));
}

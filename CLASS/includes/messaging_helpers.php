<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function messaging_has_assigned_program_column(): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([DB_NAME, 'users', 'assigned_program']);
    $cache = (int) $stmt->fetchColumn() > 0;
    return $cache;
}

function messaging_table_exists(): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
    );
    $stmt->execute([DB_NAME, 'internal_messages']);
    $cache = (int) $stmt->fetchColumn() > 0;
    return $cache;
}

function messaging_thread_max_messages(): int
{
    if (defined('MESSAGING_THREAD_MAX_MESSAGES')) {
        return max(0, (int) MESSAGING_THREAD_MAX_MESSAGES);
    }
    return 10;
}

function messaging_enforce_thread_fifo(int $userA, int $userB): void
{
    if (!messaging_table_exists()) {
        return;
    }
    $max = messaging_thread_max_messages();
    if ($max < 1) {
        return;
    }

    $st = db()->prepare(
        'SELECT COUNT(*) FROM internal_messages
         WHERE (sender_user_id = ? AND recipient_user_id = ?)
            OR (sender_user_id = ? AND recipient_user_id = ?)'
    );
    $st->execute([$userA, $userB, $userB, $userA]);
    $count = (int) $st->fetchColumn();
    if ($count <= $max) {
        return;
    }

    $excess = $count - $max;
    $attachmentSelect = messaging_has_memo_columns() ? ', attachment_stored_name' : '';
    $st = db()->prepare(
        'SELECT id' . $attachmentSelect . ' FROM internal_messages
         WHERE (sender_user_id = ? AND recipient_user_id = ?)
            OR (sender_user_id = ? AND recipient_user_id = ?)
         ORDER BY created_at ASC, id ASC
         LIMIT ' . $excess
    );
    $st->execute([$userA, $userB, $userB, $userA]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $messageId = (int) ($row['id'] ?? 0);
        if ($messageId < 1) {
            continue;
        }
        $storedName = trim((string) ($row['attachment_stored_name'] ?? ''));
        db()->prepare('DELETE FROM internal_messages WHERE id = ?')->execute([$messageId]);
        if ($storedName !== '') {
            messaging_delete_attachment_if_unused($storedName);
        }
    }
}

function messaging_db_table_exists(string $tableName): bool
{
    $st = db()->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
    );
    $st->execute([DB_NAME, $tableName]);
    return (int) $st->fetchColumn() > 0;
}

function messaging_has_memo_columns(): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    if (!messaging_table_exists()) {
        $cache = false;
        return false;
    }
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ?
           AND TABLE_NAME = ?
           AND COLUMN_NAME IN (?,?,?,?,?)'
    );
    $stmt->execute([
        DB_NAME,
        'internal_messages',
        'subject',
        'is_memo',
        'attachment_original_name',
        'attachment_stored_name',
        'attachment_mime',
    ]);
    $cache = (int) $stmt->fetchColumn() === 5;
    return $cache;
}

function messaging_select_columns(): string
{
    if (messaging_has_memo_columns()) {
        return 'id, sender_user_id, recipient_user_id, subject, body, is_memo, attachment_original_name, attachment_stored_name, attachment_mime, created_at';
    }
    return 'id, sender_user_id, recipient_user_id, "" AS subject, body, 0 AS is_memo, "" AS attachment_original_name, "" AS attachment_stored_name, "" AS attachment_mime, created_at';
}

function messaging_user_department_by_user_id(int $userId): string
{
    $st = db()->prepare('SELECT department FROM faculty WHERE user_id = ? LIMIT 1');
    $st->execute([$userId]);
    return trim((string) $st->fetchColumn());
}

function messaging_faculty_id_by_user_id(int $userId): ?int
{
    $st = db()->prepare('SELECT id FROM faculty WHERE user_id = ? LIMIT 1');
    $st->execute([$userId]);
    $id = $st->fetchColumn();
    return $id !== false ? (int) $id : null;
}

function messaging_student_id_by_user_id(int $userId): ?int
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
    $id = $st->fetchColumn();
    return $id !== false ? (int) $id : null;
}

function messaging_is_gened_faculty_user(int $userId): bool
{
    $st = db()->prepare('SELECT COALESCE(is_gened,0) FROM faculty WHERE user_id = ? LIMIT 1');
    $st->execute([$userId]);
    return (int) $st->fetchColumn() === 1;
}

function messaging_ge_dean_name_matches_hint(string $fullName, string $hint): bool
{
    $normalize = static function (string $value): string {
        $value = strtolower($value);
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $value));
    };
    $name = $normalize($fullName);
    $hintNorm = $normalize($hint);
    if ($hintNorm === '') {
        return false;
    }
    foreach (array_filter(explode(' ', $hintNorm)) as $token) {
        if (!str_contains($name, $token)) {
            return false;
        }
    }
    return true;
}

function messaging_ge_dean_user_id(): ?int
{
    static $resolved = null;
    static $done = false;
    if ($done) {
        return $resolved;
    }
    $done = true;

    if (defined('GE_DEAN_USER_ID') && (int) GE_DEAN_USER_ID > 0) {
        $id = (int) GE_DEAN_USER_ID;
        $u = messaging_user_row($id);
        if ($u && $u['role'] === 'dean' && $u['is_active']) {
            $resolved = $id;
            return $resolved;
        }
    }

    $hint = defined('GE_DEAN_NAME_HINT') ? trim((string) GE_DEAN_NAME_HINT) : 'Francis Alterado';
    if ($hint === '') {
        return null;
    }

    $st = db()->query("SELECT id, full_name FROM users WHERE is_active = 1 AND role = 'dean'");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if (messaging_ge_dean_name_matches_hint((string) ($row['full_name'] ?? ''), $hint)) {
            $resolved = (int) ($row['id'] ?? 0);
            return $resolved > 0 ? $resolved : null;
        }
    }

    return null;
}

function messaging_is_ge_dean_user(int $userId): bool
{
    $geDeanId = messaging_ge_dean_user_id();
    return $geDeanId !== null && $userId === $geDeanId;
}

function messaging_faculty_can_message_student(int $facultyUserId, int $studentUserId): bool
{
    if (!messaging_db_table_exists('online_classrooms') || !messaging_db_table_exists('classroom_enrollments')) {
        return false;
    }

    $facultyId = messaging_faculty_id_by_user_id($facultyUserId);
    $studentId = messaging_student_id_by_user_id($studentUserId);
    if ($facultyId === null || $studentId === null) {
        return false;
    }

    $st = db()->prepare(
        'SELECT COUNT(*)
         FROM classroom_enrollments ce
         INNER JOIN online_classrooms oc ON oc.id = ce.classroom_id
         WHERE oc.faculty_id = ? AND ce.student_id = ?'
    );
    $st->execute([$facultyId, $studentId]);
    return (int) $st->fetchColumn() > 0;
}

function messaging_student_can_message_faculty(int $studentUserId, int $facultyUserId): bool
{
    return messaging_faculty_can_message_student($facultyUserId, $studentUserId);
}

/** @return array{id:int,role:string,college_id:?int,is_active:int,assigned_program:string,full_name:string}|null */
function messaging_user_row(int $id): ?array
{
    $st = db()->prepare(
        'SELECT u.id, u.full_name, u.role, COALESCE(u.college_id, f.college_id) AS college_id, u.is_active, '
        . (messaging_has_assigned_program_column() ? 'assigned_program' : '"" AS assigned_program')
        . ' FROM users u
            LEFT JOIN faculty f ON f.user_id = u.id
           WHERE u.id = ? LIMIT 1'
    );
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? [
        'id' => (int) $r['id'],
        'full_name' => (string) $r['full_name'],
        'role' => (string) $r['role'],
        'college_id' => $r['college_id'] !== null ? (int) $r['college_id'] : null,
        'is_active' => (int) $r['is_active'],
        'assigned_program' => (string) ($r['assigned_program'] ?? ''),
    ] : null;
}

function messaging_can_open_thread(int $viewerId, int $otherId): bool
{
    if ($viewerId === $otherId) {
        return false;
    }
    if (messaging_can_send($viewerId, $otherId) || messaging_can_send($otherId, $viewerId)) {
        return true;
    }
    if (!messaging_table_exists()) {
        return false;
    }
    $st = db()->prepare(
        'SELECT COUNT(*) FROM internal_messages
         WHERE (sender_user_id = ? AND recipient_user_id = ?)
            OR (sender_user_id = ? AND recipient_user_id = ?)'
    );
    $st->execute([$viewerId, $otherId, $otherId, $viewerId]);
    return (int) $st->fetchColumn() > 0;
}

function messaging_can_send(int $senderId, int $recipientId): bool
{
    if ($senderId === $recipientId) {
        return false;
    }
    $s = messaging_user_row($senderId);
    $r = messaging_user_row($recipientId);
    if (!$s || !$r || !$s['is_active'] || !$r['is_active']) {
        return false;
    }
    $sr = $s['role'];
    $rr = $r['role'];
    $sc = $s['college_id'];
    $rc = $r['college_id'];
    $sp = trim((string) ($s['assigned_program'] ?? ''));
    $rp = trim((string) ($r['assigned_program'] ?? ''));

    if ($sr === 'admin') {
        if (in_array($rr, ['dean', 'program_chair', 'gened'], true)) {
            return true;
        }
        return $rr === 'faculty';
    }
    if ($sr === 'dean') {
        if ($rr === 'admin' || $rr === 'gened') {
            return true;
        }
        if ($rr === 'program_chair' && $sc !== null && $rc !== null && $sc === $rc) {
            return true;
        }
        if ($rr === 'faculty' && $sc !== null && $rc !== null && $sc === $rc) {
            if (messaging_is_gened_faculty_user($recipientId)) {
                return messaging_is_ge_dean_user($senderId);
            }
            return true;
        }
        return false;
    }
    if ($sr === 'program_chair') {
        if ($rr === 'admin') {
            return true;
        }
        if ($rr === 'dean' && $sc !== null && $rc !== null && $sc === $rc) {
            return true;
        }
        if ($rr === 'faculty' && $sc !== null && $rc !== null && $sc === $rc && $sp !== '') {
            return messaging_user_department_by_user_id($recipientId) === $sp;
        }
        return false;
    }
    if ($sr === 'faculty') {
        if ($rr === 'student') {
            return messaging_faculty_can_message_student($senderId, $recipientId);
        }
        if ($rr === 'dean') {
            if (messaging_is_gened_faculty_user($senderId)) {
                $geDeanId = messaging_ge_dean_user_id();
                return $geDeanId !== null && $recipientId === $geDeanId;
            }
            return $sc !== null && $rc !== null && $sc === $rc;
        }
        if ($rr === 'program_chair' && $sc !== null && $rc !== null && $sc === $rc && $rp !== '') {
            return messaging_user_department_by_user_id($senderId) === $rp;
        }
        if ($rr === 'gened') {
            return messaging_is_gened_faculty_user($senderId);
        }
        return false;
    }
    if ($sr === 'student') {
        if ($rr === 'faculty') {
            return messaging_student_can_message_faculty($senderId, $recipientId);
        }
        return false;
    }
    if ($sr === 'gened') {
        if (in_array($rr, ['dean', 'admin'], true)) {
            return true;
        }
        return $rr === 'faculty' && messaging_is_gened_faculty_user($recipientId);
    }
    return false;
}

/**
 * Users the current user may start (or continue) a conversation with.
 * @return list<array{id:int,full_name:string,role:string,college_id:?int}>
 */
function messaging_allowed_recipients(int $forUserId): array
{
    $me = messaging_user_row($forUserId);
    if (!$me || !$me['is_active']) {
        return [];
    }
    $role = $me['role'];
    $cid = $me['college_id'];

    if ($role === 'admin') {
        $st = db()->query(
            "SELECT id, full_name, role, college_id FROM users
             WHERE is_active = 1 AND role IN ('dean','program_chair','gened')
             ORDER BY role, full_name"
        );
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    if ($role === 'dean' && $cid !== null) {
        $includeGenedFaculty = messaging_is_ge_dean_user($forUserId);
        $st = db()->prepare(
            "SELECT u.id, u.full_name, u.role, u.college_id
             FROM users u
             LEFT JOIN faculty f ON f.user_id = u.id
             WHERE u.is_active = 1 AND (
               u.role = 'admin'
               OR u.role = 'gened'
               OR (u.role = 'program_chair' AND u.college_id = ?)
               OR (u.role = 'faculty' AND u.college_id = ? AND (? = 1 OR COALESCE(f.is_gened,0) = 0))
             )
             ORDER BY u.role, u.full_name"
        );
        $st->execute([$cid, $cid, $includeGenedFaculty ? 1 : 0]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    if ($role === 'dean' && $cid === null) {
        $st = db()->query(
            "SELECT id, full_name, role, college_id FROM users
             WHERE is_active = 1 AND role IN ('admin','gened')
             ORDER BY role, full_name"
        );
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    if ($role === 'program_chair' && $cid !== null) {
        $rows = [];
        $st = db()->prepare(
            "SELECT id, full_name, role, college_id FROM users
             WHERE is_active = 1 AND (
               role = 'admin'
               OR (role = 'dean' AND college_id = ?)
             )
             ORDER BY role, full_name"
        );
        $st->execute([$cid]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $st = db()->prepare(
            "SELECT u.id, u.full_name, u.role, u.college_id
             FROM users u
             INNER JOIN faculty f ON f.user_id = u.id
             WHERE u.is_active = 1 AND u.role = 'faculty' AND u.college_id = ? AND f.department = ?
             ORDER BY u.full_name"
        );
        $st->execute([$cid, trim((string) $me['assigned_program'])]);
        return array_merge($rows, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }
    if ($role === 'faculty' && $cid !== null) {
        $facultyId = messaging_faculty_id_by_user_id($forUserId);
        if (
            $facultyId !== null
            && messaging_db_table_exists('classroom_students')
            && messaging_db_table_exists('classroom_enrollments')
            && messaging_db_table_exists('online_classrooms')
        ) {
            $st = db()->prepare(
                "SELECT DISTINCT u.id, u.full_name, u.role, u.college_id
                 FROM users u
                 INNER JOIN classroom_students cs ON cs.user_id = u.id
                 INNER JOIN classroom_enrollments ce ON ce.student_id = cs.id
                 INNER JOIN online_classrooms oc ON oc.id = ce.classroom_id
                 WHERE u.is_active = 1 AND u.role = 'student' AND oc.faculty_id = ?
                 ORDER BY u.full_name"
            );
            $st->execute([$facultyId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        return [];
    }
    if ($role === 'gened') {
        $st = db()->query(
            "SELECT id, full_name, role, college_id FROM users
             WHERE is_active = 1 AND role IN ('admin','dean')
             ORDER BY role, full_name"
        );
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $st = db()->query(
            "SELECT u.id, u.full_name, u.role, u.college_id
             FROM users u
             INNER JOIN faculty f ON f.user_id = u.id
             WHERE u.is_active = 1 AND u.role = 'faculty' AND COALESCE(f.is_gened,0) = 1
             ORDER BY u.full_name"
        );
        return array_merge($rows, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }
    if (
        $role === 'student'
        && messaging_db_table_exists('classroom_students')
        && messaging_db_table_exists('classroom_enrollments')
        && messaging_db_table_exists('online_classrooms')
    ) {
        $studentId = messaging_student_id_by_user_id($forUserId);
        if ($studentId === null) {
            return [];
        }

        $st = db()->prepare(
            "SELECT DISTINCT u.id, u.full_name, u.role, u.college_id
             FROM users u
             INNER JOIN faculty f ON f.user_id = u.id
             INNER JOIN online_classrooms oc ON oc.faculty_id = f.id
             INNER JOIN classroom_enrollments ce ON ce.classroom_id = oc.id
             WHERE u.is_active = 1 AND u.role = 'faculty' AND ce.student_id = ?
             ORDER BY u.full_name"
        );
        $st->execute([$studentId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    return [];
}

/**
 * Dean and program chair contacts a faculty member may message in Inbox (same college / program).
 *
 * @return list<array{id:int,full_name:string,role:string,college_id:?int}>
 */
function messaging_faculty_inbox_recipients(int $forUserId): array
{
    $me = messaging_user_row($forUserId);
    if (!$me || $me['role'] !== 'faculty' || !$me['is_active']) {
        return [];
    }

    $cid = $me['college_id'];
    $department = messaging_user_department_by_user_id($forUserId);
    $isGenedFaculty = messaging_is_gened_faculty_user($forUserId);
    $rows = [];

    if ($isGenedFaculty) {
        $geDeanId = messaging_ge_dean_user_id();
        if ($geDeanId !== null) {
            $geDean = messaging_user_row($geDeanId);
            if ($geDean) {
                $rows[] = [
                    'id' => $geDean['id'],
                    'full_name' => $geDean['full_name'],
                    'role' => $geDean['role'],
                    'college_id' => $geDean['college_id'],
                ];
            }
        }
        $st = db()->query(
            "SELECT id, full_name, role, college_id FROM users
             WHERE is_active = 1 AND role = 'gened'
             ORDER BY full_name"
        );
        $rows = array_merge($rows, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    } elseif ($cid !== null) {
        $st = db()->prepare(
            "SELECT id, full_name, role, college_id FROM users
             WHERE is_active = 1 AND role = 'dean' AND college_id = ?
             ORDER BY full_name"
        );
        $st->execute([$cid]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($department !== '' && messaging_has_assigned_program_column()) {
            $st = db()->prepare(
                "SELECT id, full_name, role, college_id FROM users
                 WHERE is_active = 1 AND role = 'program_chair' AND college_id = ? AND assigned_program = ?
                 ORDER BY full_name"
            );
            $st->execute([$cid, $department]);
            $rows = array_merge($rows, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
        }
    }

    $seen = [];
    $out = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1 || isset($seen[$id]) || !messaging_can_send($forUserId, $id)) {
            continue;
        }
        $seen[$id] = true;
        $out[] = [
            'id' => $id,
            'full_name' => (string) ($row['full_name'] ?? ''),
            'role' => (string) ($row['role'] ?? ''),
            'college_id' => isset($row['college_id']) && $row['college_id'] !== null ? (int) $row['college_id'] : null,
        ];
    }

    return $out;
}

/**
 * @return list<array{id:int,full_name:string,role:string,college_id:?int,department:string}>
 */
function messaging_memo_recipients(int $forUserId): array
{
    $me = messaging_user_row($forUserId);
    if (!$me || !$me['is_active']) {
        return [];
    }
    $role = $me['role'];
    $cid = $me['college_id'];

    if ($role === 'admin') {
        $st = db()->query(
            "SELECT u.id, u.full_name, u.role, u.college_id, COALESCE(f.department,'') AS department
             FROM users u
             INNER JOIN faculty f ON f.user_id = u.id
             WHERE u.is_active = 1 AND u.role = 'faculty'
             ORDER BY u.full_name"
        );
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    if ($role === 'dean' && $cid !== null) {
        $st = db()->prepare(
            "SELECT u.id, u.full_name, u.role, u.college_id, COALESCE(f.department,'') AS department
             FROM users u
             INNER JOIN faculty f ON f.user_id = u.id
             WHERE u.is_active = 1 AND u.role = 'faculty' AND u.college_id = ? AND COALESCE(f.is_gened,0) = 0
             ORDER BY u.full_name"
        );
        $st->execute([$cid]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    if ($role === 'program_chair' && $cid !== null) {
        $program = trim((string) ($me['assigned_program'] ?? ''));
        if ($program === '') {
            return [];
        }
        $st = db()->prepare(
            "SELECT u.id, u.full_name, u.role, u.college_id, COALESCE(f.department,'') AS department
             FROM users u
             INNER JOIN faculty f ON f.user_id = u.id
             WHERE u.is_active = 1 AND u.role = 'faculty' AND u.college_id = ? AND f.department = ?
             ORDER BY u.full_name"
        );
        $st->execute([$cid, $program]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    if ($role === 'gened') {
        $st = db()->query(
            "SELECT u.id, u.full_name, u.role, u.college_id, 'General Education' AS department
             FROM users u
             INNER JOIN faculty f ON f.user_id = u.id
             WHERE u.is_active = 1 AND u.role = 'faculty' AND COALESCE(f.is_gened,0) = 1
             ORDER BY u.full_name"
        );
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    return [];
}

function messaging_unread_count(int $userId): int
{
    if (!messaging_table_exists()) {
        return 0;
    }
    $st = db()->prepare('SELECT COUNT(*) FROM internal_messages WHERE recipient_user_id = ? AND read_at IS NULL');
    $st->execute([$userId]);
    return (int) $st->fetchColumn();
}

/**
 * @return list<array{partner_id:int,full_name:string,role:string,last_at:string,unread:int,preview:string}>
 */
function messaging_conversation_list(int $userId): array
{
    $st = db()->prepare(
        'SELECT DISTINCT CASE WHEN sender_user_id = ? THEN recipient_user_id ELSE sender_user_id END AS partner_id
         FROM internal_messages
         WHERE sender_user_id = ? OR recipient_user_id = ?'
    );
    $st->execute([$userId, $userId, $userId]);
    $partnerIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if ($partnerIds === []) {
        return [];
    }

    $list = [];
    foreach ($partnerIds as $pid) {
        $u = messaging_user_row($pid);
        if (!$u) {
            continue;
        }
        $st2 = db()->prepare(
            'SELECT ' . messaging_select_columns() . '
             FROM internal_messages
             WHERE (sender_user_id = ? AND recipient_user_id = ?) OR (sender_user_id = ? AND recipient_user_id = ?)
             ORDER BY created_at DESC LIMIT 1'
        );
        $st2->execute([$userId, $pid, $pid, $userId]);
        $last = $st2->fetch(PDO::FETCH_ASSOC);
        $st3 = db()->prepare(
            'SELECT COUNT(*) FROM internal_messages WHERE sender_user_id = ? AND recipient_user_id = ? AND read_at IS NULL'
        );
        $st3->execute([$pid, $userId]);
        $unread = (int) $st3->fetchColumn();
        if ($last) {
            $preview = (int) ($last['is_memo'] ?? 0) === 1
                ? ('MEMO: ' . trim((string) ($last['subject'] ?? '')))
                : (string) ($last['body'] ?? '');
        } else {
            $preview = '';
        }
        if (function_exists('mb_substr')) {
            $preview = mb_strlen($preview) > 80 ? mb_substr($preview, 0, 80) . '...' : $preview;
        } else {
            $preview = strlen($preview) > 80 ? substr($preview, 0, 80) . '...' : $preview;
        }
        $list[] = [
            'partner_id' => $pid,
            'full_name' => $u['full_name'],
            'role' => $u['role'],
            'last_at' => $last ? (string) $last['created_at'] : '',
            'unread' => $unread,
            'preview' => $preview,
        ];
    }

    usort($list, static function (array $a, array $b): int {
        return strcmp($b['last_at'], $a['last_at']);
    });

    return $list;
}

/**
 * @return list<array{id:int,sender_user_id:int,recipient_user_id:int,subject:string,body:string,is_memo:int,attachment_original_name:string,attachment_stored_name:string,attachment_mime:string,created_at:string,mine:bool}>
 */
function messaging_thread(int $userId, int $otherId, ?int $limit = null): array
{
    $max = messaging_thread_max_messages();
    if ($limit === null) {
        $limit = $max > 0 ? $max : 500;
    }
    $st = db()->prepare(
        'SELECT ' . messaging_select_columns() . '
         FROM internal_messages
         WHERE (sender_user_id = ? AND recipient_user_id = ?) OR (sender_user_id = ? AND recipient_user_id = ?)
         ORDER BY created_at ASC
         LIMIT ' . max(1, min(500, $limit))
    );
    $st->execute([$userId, $otherId, $otherId, $userId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $sid = (int) $r['sender_user_id'];
        $out[] = [
            'id' => (int) $r['id'],
            'sender_user_id' => $sid,
            'recipient_user_id' => (int) $r['recipient_user_id'],
            'subject' => (string) ($r['subject'] ?? ''),
            'body' => (string) $r['body'],
            'is_memo' => (int) ($r['is_memo'] ?? 0),
            'attachment_original_name' => (string) ($r['attachment_original_name'] ?? ''),
            'attachment_stored_name' => (string) ($r['attachment_stored_name'] ?? ''),
            'attachment_mime' => (string) ($r['attachment_mime'] ?? ''),
            'created_at' => (string) $r['created_at'],
            'mine' => $sid === $userId,
        ];
    }
    return $out;
}

function messaging_mark_read(int $viewerId, int $otherId): void
{
    db()->prepare(
        'UPDATE internal_messages SET read_at = CURRENT_TIMESTAMP
         WHERE recipient_user_id = ? AND sender_user_id = ? AND read_at IS NULL'
    )->execute([$viewerId, $otherId]);
}

function messaging_attachment_dir(): string
{
    return BASE_PATH . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'message_attachments';
}

function messaging_attachment_path(string $storedName): string
{
    return messaging_attachment_dir() . DIRECTORY_SEPARATOR . basename($storedName);
}

/**
 * @param array<string,mixed> $file
 * @return array{original_name:string,stored_name:string,mime:string}|null
 */
function messaging_store_attachment(array $file): ?array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Attachment upload failed.');
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size < 1) {
        throw new RuntimeException('Attachment is empty.');
    }
    if ($size > 10 * 1024 * 1024) {
        throw new RuntimeException('Attachment is too large (max 10 MB).');
    }

    $original = trim((string) ($file['name'] ?? ''));
    if ($original === '') {
        throw new RuntimeException('Invalid attachment name.');
    }
    $original = str_replace(["\r", "\n"], '', basename($original));
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'jpg', 'jpeg', 'png'];
    if (!in_array($ext, $allowed, true)) {
        throw new RuntimeException('Unsupported attachment type.');
    }

    $dir = messaging_attachment_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create attachment directory.');
    }

    $stored = bin2hex(random_bytes(16)) . ($ext !== '' ? '.' . $ext : '');
    $dest = messaging_attachment_path($stored);
    if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $dest)) {
        throw new RuntimeException('Failed to save attachment.');
    }

    $mime = trim((string) ($file['type'] ?? 'application/octet-stream'));
    return [
        'original_name' => $original,
        'stored_name' => $stored,
        'mime' => $mime !== '' ? $mime : 'application/octet-stream',
    ];
}

/**
 * @param array<string,mixed> $options
 */
function messaging_send(int $fromId, int $toId, string $body, array $options = []): void
{
    $body = trim($body);
    $subject = trim((string) ($options['subject'] ?? ''));
    $isMemo = !empty($options['is_memo']);
    $attachment = $options['attachment'] ?? null;

    if ($body === '' && (!$isMemo || $subject === '')) {
        throw new RuntimeException($isMemo ? 'Memo subject is required.' : 'Message cannot be empty.');
    }
    if (strlen($body) > 8000) {
        throw new RuntimeException('Message is too long (max 8000 characters).');
    }
    if ($subject !== '' && strlen($subject) > 255) {
        throw new RuntimeException('Memo subject is too long.');
    }
    if (!messaging_can_send($fromId, $toId)) {
        throw new RuntimeException('You cannot message this user.');
    }

    if ($isMemo && !messaging_has_memo_columns()) {
        throw new RuntimeException('Memo fields are not installed yet. Run upgrade_roles.php first.');
    }

    if (messaging_has_memo_columns()) {
        $attachment = is_array($attachment) ? $attachment : [
            'original_name' => '',
            'stored_name' => '',
            'mime' => '',
        ];
        db()->prepare(
            'INSERT INTO internal_messages (sender_user_id, recipient_user_id, subject, body, is_memo, attachment_original_name, attachment_stored_name, attachment_mime)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([
            $fromId,
            $toId,
            $subject,
            $body,
            $isMemo ? 1 : 0,
            (string) ($attachment['original_name'] ?? ''),
            (string) ($attachment['stored_name'] ?? ''),
            (string) ($attachment['mime'] ?? ''),
        ]);
        messaging_enforce_thread_fifo($fromId, $toId);
        return;
    }

    db()->prepare(
        'INSERT INTO internal_messages (sender_user_id, recipient_user_id, body) VALUES (?,?,?)'
    )->execute([$fromId, $toId, $body]);
    messaging_enforce_thread_fifo($fromId, $toId);
}

/**
 * @param int[] $recipientIds
 * @param array<string,mixed>|null $file
 */
function messaging_send_memo(int $fromId, array $recipientIds, string $subject, string $body, ?array $file): int
{
    $sender = messaging_user_row($fromId);
    if (!$sender || !in_array((string) $sender['role'], ['admin', 'dean', 'gened', 'program_chair'], true)) {
        throw new RuntimeException('You cannot send memos.');
    }
    if (!messaging_has_memo_columns()) {
        throw new RuntimeException('Memo fields are not installed yet. Run upgrade_roles.php first.');
    }

    $subject = trim($subject);
    $body = trim($body);
    if ($subject === '') {
        throw new RuntimeException('Memo subject is required.');
    }
    $recipientIds = array_values(array_unique(array_filter(array_map('intval', $recipientIds), static fn (int $v): bool => $v > 0)));
    if ($recipientIds === []) {
        throw new RuntimeException('Select at least one faculty recipient.');
    }

    $allowed = array_map(static fn (array $r): int => (int) $r['id'], messaging_memo_recipients($fromId));
    $allowedMap = array_fill_keys($allowed, true);
    foreach ($recipientIds as $rid) {
        if (!isset($allowedMap[$rid]) || !messaging_can_send($fromId, $rid)) {
            throw new RuntimeException('One or more memo recipients are not allowed.');
        }
    }

    $attachment = $file !== null ? messaging_store_attachment($file) : null;

    db()->beginTransaction();
    try {
        foreach ($recipientIds as $rid) {
            messaging_send($fromId, $rid, $body, [
                'subject' => $subject,
                'is_memo' => true,
                'attachment' => $attachment,
            ]);
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }
    return count($recipientIds);
}

function messaging_delete_attachment_if_unused(string $storedName): void
{
    $storedName = trim($storedName);
    if ($storedName === '') {
        return;
    }

    $st = db()->prepare(
        'SELECT COUNT(*) FROM internal_messages WHERE attachment_stored_name = ?'
    );
    $st->execute([$storedName]);
    if ((int) $st->fetchColumn() > 0) {
        return;
    }

    $path = messaging_attachment_path($storedName);
    if (is_file($path)) {
        @unlink($path);
    }
}

function messaging_delete_message(int $messageId, int $userId): bool
{
    if (!messaging_table_exists()) {
        return false;
    }

    $st = db()->prepare(
        'SELECT id, attachment_stored_name
         FROM internal_messages
         WHERE id = ? AND sender_user_id = ?
         LIMIT 1'
    );
    $st->execute([$messageId, $userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }

    $storedName = trim((string) ($row['attachment_stored_name'] ?? ''));
    db()->prepare(
        'DELETE FROM internal_messages WHERE id = ? AND sender_user_id = ?'
    )->execute([$messageId, $userId]);

    if ($storedName !== '') {
        messaging_delete_attachment_if_unused($storedName);
    }

    return true;
}

/** @return array<string,mixed>|null */
function messaging_message_for_user(int $messageId, int $userId): ?array
{
    if (!messaging_table_exists()) {
        return null;
    }
    $st = db()->prepare(
        'SELECT ' . messaging_select_columns() . '
         FROM internal_messages
         WHERE id = ? AND (sender_user_id = ? OR recipient_user_id = ?)
         LIMIT 1'
    );
    $st->execute([$messageId, $userId, $userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function messaging_role_badge_class(string $role): string
{
    return match ($role) {
        'admin' => 'danger',
        'dean' => 'primary',
        'program_chair' => 'warning',
        'faculty' => 'success',
        'student' => 'secondary',
        'gened' => 'info',
        default => 'secondary',
    };
}

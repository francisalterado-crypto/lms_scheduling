<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/messaging_helpers.php';

function classroom_discussions_table_exists(): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $st = db()->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
    );
    $st->execute([DB_NAME, 'classroom_messages']);
    $cache = (int) $st->fetchColumn() > 0;
    return $cache;
}

function classroom_discussion_can_access(int $classroomId, int $userId): bool
{
    $role = (string) ($_SESSION['role'] ?? '');

    if ($role === 'faculty') {
        $facultyId = isset($_SESSION['faculty_id']) ? (int) $_SESSION['faculty_id'] : 0;
        if ($facultyId < 1) {
            $facultyId = resolve_faculty_id_for_user($userId) ?? 0;
            $_SESSION['faculty_id'] = $facultyId > 0 ? $facultyId : null;
        }
        if ($facultyId < 1) {
            return false;
        }

        $st = db()->prepare('SELECT COUNT(*) FROM online_classrooms WHERE id = ? AND faculty_id = ?');
        $st->execute([$classroomId, $facultyId]);
        return (int) $st->fetchColumn() > 0;
    }

    if ($role === 'student') {
        $studentId = isset($_SESSION['student_id']) ? (int) $_SESSION['student_id'] : 0;
        if ($studentId < 1) {
            $studentId = resolve_student_id_for_user($userId) ?? 0;
            $_SESSION['student_id'] = $studentId > 0 ? $studentId : null;
        }
        if ($studentId < 1) {
            return false;
        }

        $st = db()->prepare(
            'SELECT COUNT(*)
             FROM classroom_enrollments
             WHERE classroom_id = ? AND student_id = ?'
        );
        $st->execute([$classroomId, $studentId]);
        return (int) $st->fetchColumn() > 0;
    }

    return false;
}

function classroom_discussion_enforce_fifo(int $classroomId): void
{
    if (!classroom_discussions_table_exists()) {
        return;
    }
    $max = messaging_thread_max_messages();
    if ($max < 1) {
        return;
    }

    $st = db()->prepare('SELECT COUNT(*) FROM classroom_messages WHERE classroom_id = ?');
    $st->execute([$classroomId]);
    $count = (int) $st->fetchColumn();
    if ($count <= $max) {
        return;
    }

    $excess = $count - $max;
    $st = db()->prepare(
        'SELECT id FROM classroom_messages
         WHERE classroom_id = ?
         ORDER BY created_at ASC, id ASC
         LIMIT ' . $excess
    );
    $st->execute([$classroomId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $messageId = (int) ($row['id'] ?? 0);
        if ($messageId < 1) {
            continue;
        }
        db()->prepare('DELETE FROM classroom_messages WHERE id = ?')->execute([$messageId]);
    }
}

/**
 * @return list<array{id:int,sender_user_id:int,body:string,created_at:string,full_name:string,role:string,mine:bool}>
 */
function classroom_discussion_messages(int $classroomId, int $viewerUserId, ?int $limit = null): array
{
    if (!classroom_discussions_table_exists()) {
        return [];
    }

    $max = messaging_thread_max_messages();
    if ($limit === null) {
        $limit = $max > 0 ? $max : 500;
    }

    $st = db()->prepare(
        'SELECT cm.id, cm.sender_user_id, cm.body, cm.created_at, u.full_name, u.role
         FROM classroom_messages cm
         INNER JOIN users u ON u.id = cm.sender_user_id
         WHERE cm.classroom_id = ?
         ORDER BY cm.created_at ASC, cm.id ASC
         LIMIT ' . max(1, min(500, $limit))
    );
    $st->execute([$classroomId]);

    $messages = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $senderUserId = (int) ($row['sender_user_id'] ?? 0);
        $messages[] = [
            'id' => (int) ($row['id'] ?? 0),
            'sender_user_id' => $senderUserId,
            'body' => (string) ($row['body'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'full_name' => (string) ($row['full_name'] ?? 'Unknown user'),
            'role' => (string) ($row['role'] ?? ''),
            'mine' => $senderUserId === $viewerUserId,
        ];
    }

    return $messages;
}

function classroom_discussion_post(int $classroomId, int $senderUserId, string $body): void
{
    if (!classroom_discussions_table_exists()) {
        throw new RuntimeException('Run upgrade_roles.php once to enable classroom discussion.');
    }
    if (!classroom_discussion_can_access($classroomId, $senderUserId)) {
        throw new RuntimeException('You cannot post in this classroom discussion.');
    }

    $body = trim($body);
    if ($body === '') {
        throw new RuntimeException('Message cannot be empty.');
    }
    if (strlen($body) > 8000) {
        throw new RuntimeException('Message is too long (max 8000 characters).');
    }

    db()->prepare(
        'INSERT INTO classroom_messages (classroom_id, sender_user_id, body) VALUES (?,?,?)'
    )->execute([$classroomId, $senderUserId, $body]);
    classroom_discussion_enforce_fifo($classroomId);
}

function classroom_discussion_role_badge_class(string $role): string
{
    return match ($role) {
        'faculty' => 'success',
        'student' => 'secondary',
        default => 'light text-dark border',
    };
}

/**
 * @return list<array{classroom_id:int,title:string,course_code:string,course_name:string,last_at:string,preview:string}>
 */
function classroom_discussion_threads_for_user(int $userId): array
{
    if (!classroom_discussions_table_exists()) {
        return [];
    }

    $role = (string) ($_SESSION['role'] ?? '');
    if ($role === 'faculty') {
        $facultyId = isset($_SESSION['faculty_id']) ? (int) $_SESSION['faculty_id'] : 0;
        if ($facultyId < 1) {
            $facultyId = resolve_faculty_id_for_user($userId) ?? 0;
            $_SESSION['faculty_id'] = $facultyId > 0 ? $facultyId : null;
        }
        if ($facultyId < 1) {
            return [];
        }

        $st = db()->prepare(
            'SELECT oc.id AS classroom_id, oc.title, c.course_code, c.course_name,
                    COALESCE((
                        SELECT cm.created_at
                        FROM classroom_messages cm
                        WHERE cm.classroom_id = oc.id
                        ORDER BY cm.created_at DESC, cm.id DESC
                        LIMIT 1
                    ), "") AS last_at,
                    COALESCE((
                        SELECT cm.body
                        FROM classroom_messages cm
                        WHERE cm.classroom_id = oc.id
                        ORDER BY cm.created_at DESC, cm.id DESC
                        LIMIT 1
                    ), "") AS preview
             FROM online_classrooms oc
             INNER JOIN courses c ON c.id = oc.course_id
             WHERE oc.faculty_id = ?
             ORDER BY (last_at = "") ASC, last_at DESC, c.course_code ASC'
        );
        $st->execute([$facultyId]);
        return classroom_discussion_normalize_threads($st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    if ($role === 'student') {
        $studentId = isset($_SESSION['student_id']) ? (int) $_SESSION['student_id'] : 0;
        if ($studentId < 1) {
            $studentId = resolve_student_id_for_user($userId) ?? 0;
            $_SESSION['student_id'] = $studentId > 0 ? $studentId : null;
        }
        if ($studentId < 1) {
            return [];
        }

        $st = db()->prepare(
            'SELECT oc.id AS classroom_id, oc.title, c.course_code, c.course_name,
                    COALESCE((
                        SELECT cm.created_at
                        FROM classroom_messages cm
                        WHERE cm.classroom_id = oc.id
                        ORDER BY cm.created_at DESC, cm.id DESC
                        LIMIT 1
                    ), "") AS last_at,
                    COALESCE((
                        SELECT cm.body
                        FROM classroom_messages cm
                        WHERE cm.classroom_id = oc.id
                        ORDER BY cm.created_at DESC, cm.id DESC
                        LIMIT 1
                    ), "") AS preview
             FROM classroom_enrollments ce
             INNER JOIN online_classrooms oc ON oc.id = ce.classroom_id
             INNER JOIN courses c ON c.id = oc.course_id
             WHERE ce.student_id = ?
             ORDER BY (last_at = "") ASC, last_at DESC, c.course_code ASC'
        );
        $st->execute([$studentId]);
        return classroom_discussion_normalize_threads($st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    return [];
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array{classroom_id:int,title:string,course_code:string,course_name:string,last_at:string,preview:string}>
 */
function classroom_discussion_normalize_threads(array $rows): array
{
    $threads = [];
    foreach ($rows as $row) {
        $preview = trim((string) ($row['preview'] ?? ''));
        if (function_exists('mb_substr')) {
            $preview = mb_strlen($preview) > 80 ? mb_substr($preview, 0, 80) . '...' : $preview;
        } else {
            $preview = strlen($preview) > 80 ? substr($preview, 0, 80) . '...' : $preview;
        }

        $threads[] = [
            'classroom_id' => (int) ($row['classroom_id'] ?? 0),
            'title' => (string) ($row['title'] ?? ''),
            'course_code' => (string) ($row['course_code'] ?? ''),
            'course_name' => (string) ($row['course_name'] ?? ''),
            'last_at' => (string) ($row['last_at'] ?? ''),
            'preview' => $preview,
        ];
    }

    return $threads;
}

/** @return array{classroom_id:int,title:string,course_code:string,course_name:string}|null */
function classroom_discussion_thread_row(int $classroomId, int $userId): ?array
{
    if (!classroom_discussion_can_access($classroomId, $userId)) {
        return null;
    }

    $st = db()->prepare(
        'SELECT oc.id AS classroom_id, oc.title, c.course_code, c.course_name
         FROM online_classrooms oc
         INNER JOIN courses c ON c.id = oc.course_id
         WHERE oc.id = ?
         LIMIT 1'
    );
    $st->execute([$classroomId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return [
        'classroom_id' => (int) ($row['classroom_id'] ?? 0),
        'title' => (string) ($row['title'] ?? ''),
        'course_code' => (string) ($row['course_code'] ?? ''),
        'course_name' => (string) ($row['course_name'] ?? ''),
    ];
}

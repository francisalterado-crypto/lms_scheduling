<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();

$classroomId = (int) ($_GET['id'] ?? 0);
if ($classroomId < 1) {
    http_response_code(400);
    exit('Invalid classroom.');
}

if (!db_table_exists('online_classrooms') || !classroom_banner_column_ready()) {
    http_response_code(503);
    exit('Classroom banners are not installed. Run upgrade_roles.php once.');
}

$st = db()->prepare(
    'SELECT oc.id, oc.faculty_id, oc.banner_stored_name, oc.banner_mime,
            s.college_id AS schedule_college_id, s.program AS schedule_program,
            c.department AS course_department, c.is_gened AS course_is_gened
     FROM online_classrooms oc
     INNER JOIN schedules s ON s.id = oc.schedule_id
     INNER JOIN courses c ON c.id = oc.course_id
     WHERE oc.id = ?
     LIMIT 1'
);
$st->execute([$classroomId]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    exit('Classroom not found.');
}

$storedName = trim((string) ($row['banner_stored_name'] ?? ''));
if ($storedName === '') {
    http_response_code(404);
    exit('No banner uploaded for this class.');
}

$role = (string) ($_SESSION['role'] ?? '');
$userId = (int) ($_SESSION['user_id'] ?? 0);
$allowed = false;

if ($role === 'admin' || $role === 'super_admin') {
    $allowed = true;
} elseif ($role === 'faculty' || $role === 'program_chair' || $role === 'dean' || $role === 'gened') {
    $facultyId = isset($_SESSION['faculty_id']) ? (int) $_SESSION['faculty_id'] : 0;
    if ($facultyId < 1) {
        $facultyId = resolve_faculty_id_for_user($userId) ?? 0;
        $_SESSION['faculty_id'] = $facultyId > 0 ? $facultyId : null;
    }
    if ($facultyId > 0 && (int) $row['faculty_id'] === $facultyId) {
        $allowed = true;
    } elseif ($role === 'dean') {
        $cid = current_college_id();
        $schCid = $row['schedule_college_id'] !== null ? (int) $row['schedule_college_id'] : 0;
        $allowed = $cid !== null && $cid > 0 && $schCid === $cid;
    } elseif ($role === 'program_chair') {
        $cid = current_college_id();
        $programScope = current_program_scope();
        $schCid = $row['schedule_college_id'] !== null ? (int) $row['schedule_college_id'] : 0;
        if ($cid !== null && $cid > 0 && $schCid === $cid && $programScope !== null) {
            $courseDept = trim((string) ($row['course_department'] ?? ''));
            $schedProg = trim((string) ($row['schedule_program'] ?? ''));
            $allowed = ($courseDept !== '' && strcasecmp($courseDept, $programScope) === 0)
                || ($schedProg !== '' && strcasecmp($schedProg, $programScope) === 0);
        }
    } elseif ($role === 'gened') {
        $allowed = (int) ($row['course_is_gened'] ?? 0) === 1;
    }
} elseif ($role === 'student') {
    $studentId = isset($_SESSION['student_id']) ? (int) $_SESSION['student_id'] : 0;
    if ($studentId < 1) {
        $studentId = resolve_student_id_for_user($userId) ?? 0;
        $_SESSION['student_id'] = $studentId > 0 ? $studentId : null;
    }
    if ($studentId > 0 && db_table_exists('classroom_enrollments')) {
        $chk = db()->prepare('SELECT COUNT(*) FROM classroom_enrollments WHERE classroom_id = ? AND student_id = ?');
        $chk->execute([$classroomId, $studentId]);
        $allowed = (int) $chk->fetchColumn() > 0;
    }
}

if (!$allowed) {
    http_response_code(403);
    exit('Access denied.');
}

$path = classroom_banner_path($storedName);
if (!is_file($path)) {
    http_response_code(404);
    exit('Banner file missing.');
}

$mime = trim((string) ($row['banner_mime'] ?? ''));
if ($mime === '' || !str_starts_with($mime, 'image/')) {
    $mime = classroom_banner_mime_for_stored($storedName);
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;

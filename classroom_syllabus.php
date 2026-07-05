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

if (!db_table_exists('online_classrooms') || !db_column_exists('online_classrooms', 'syllabus_stored_name')) {
    http_response_code(503);
    exit('Syllabus storage is not installed. Run upgrade_roles.php once.');
}

$st = db()->prepare(
    'SELECT oc.id, oc.faculty_id, oc.syllabus_stored_name, oc.syllabus_original_name, oc.syllabus_mime,
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

$storedName = trim((string) ($row['syllabus_stored_name'] ?? ''));
if ($storedName === '') {
    http_response_code(404);
    exit('No syllabus uploaded for this class.');
}

$role = (string) ($_SESSION['role'] ?? '');
$userId = (int) ($_SESSION['user_id'] ?? 0);
$allowed = false;

if ($role === 'admin') {
    $allowed = true;
} elseif ($role === 'faculty') {
    $facultyId = isset($_SESSION['faculty_id']) ? (int) $_SESSION['faculty_id'] : 0;
    if ($facultyId < 1) {
        $facultyId = resolve_faculty_id_for_user($userId) ?? 0;
        $_SESSION['faculty_id'] = $facultyId > 0 ? $facultyId : null;
    }
    $allowed = $facultyId > 0 && (int) $row['faculty_id'] === $facultyId;
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

if (!$allowed) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Access Denied</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{min-height:100vh;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.05);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
.notif{background:#fff;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.12);padding:28px 36px;text-align:center;max-width:340px;animation:pop .3s ease}
.notif .icon{width:48px;height:48px;margin:0 auto 14px;border-radius:50%;background:#fee2e2;display:flex;align-items:center;justify-content:center}
.notif .icon svg{width:24px;height:24px;color:#dc2626}
.notif h2{font-size:1rem;font-weight:600;color:#1f2937;margin-bottom:6px}
.notif p{font-size:.85rem;color:#6b7280;margin-bottom:18px}
.notif button{background:#3b82f6;color:#fff;border:none;border-radius:6px;padding:8px 20px;font-size:.8rem;cursor:pointer;transition:background .2s}
.notif button:hover{background:#2563eb}
@keyframes pop{from{opacity:0;transform:scale(.9)}to{opacity:1;transform:scale(1)}}
</style></head><body>
<div class="notif">
<div class="icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 0 0 5.636 5.636m12.728 12.728A9 9 0 0 1 5.636 5.636m12.728 12.728L5.636 5.636"/></svg></div>
<h2>Access Denied</h2>
<p>You do not have access to this syllabus.</p>
<button onclick="window.close()">Close</button>
</div></body></html>';
    exit;
}

$path = classroom_content_attachment_storage_path($storedName);
if (!is_file($path)) {
    http_response_code(404);
    exit('Syllabus file is missing.');
}

$downloadName = classroom_content_attachment_download_name(
    (string) ($row['syllabus_original_name'] ?? ''),
    $storedName
);
$mime = trim((string) ($row['syllabus_mime'] ?? ''));
if ($mime === '' && function_exists('mime_content_type')) {
    $mime = (string) mime_content_type($path);
}
$mime = $mime !== '' ? $mime : 'application/octet-stream';

$inline = str_starts_with(strtolower($mime), 'application/pdf')
    || str_starts_with(strtolower($mime), 'image/');

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header(
    'Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . addcslashes($downloadName, '"\\') . '"'
);
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;

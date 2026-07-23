<?php
declare(strict_types=1);

/**
 * POST schedule_id — end the LIVE indicator for a faculty schedule block.
 * Used by faculty_meet_live.php when Google Meet closes.
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

$role = (string) ($_SESSION['role'] ?? '');
if (!in_array($role, ['faculty', 'program_chair', 'dean', 'gened'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_THROW_ON_ERROR);
    exit;
}

$facultyId = isset($_SESSION['faculty_id']) ? (int) $_SESSION['faculty_id'] : 0;
$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($facultyId < 1 && $userId > 0) {
    $facultyId = resolve_faculty_id_for_user($userId) ?? 0;
}
if ($facultyId < 1 && in_array($role, ['program_chair', 'dean', 'gened'], true) && $userId > 0) {
    $facultyId = ensure_faculty_profile_for_teaching_role($userId) ?? 0;
}
if ($facultyId < 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Faculty profile required'], JSON_THROW_ON_ERROR);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_THROW_ON_ERROR);
    exit;
}

$scheduleId = (int) ($_POST['schedule_id'] ?? 0);
if ($scheduleId < 1) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'schedule_id is required'], JSON_THROW_ON_ERROR);
    exit;
}

$ended = faculty_end_live_for_schedule($scheduleId, $facultyId);
echo json_encode(['ok' => true, 'ended' => $ended], JSON_THROW_ON_ERROR);

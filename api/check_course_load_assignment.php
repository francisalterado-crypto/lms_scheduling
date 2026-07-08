<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!in_array($_SESSION['role'] ?? '', ['dean', 'program_chair', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$courseId = (int) ($_GET['course_id'] ?? 0);
$facultyId = (int) ($_GET['faculty_id'] ?? 0);
$semester = trim((string) ($_GET['semester'] ?? ''));
$schoolYear = trim((string) ($_GET['school_year'] ?? ''));

if ($courseId < 1 || $facultyId < 1 || $semester === '' || $schoolYear === '') {
    echo json_encode(['assigned' => false], JSON_UNESCAPED_UNICODE);
    exit;
}

$geTarget = null;
$targetCollegeId = (int) ($_GET['target_college_id'] ?? 0);
$targetProgram = trim((string) ($_GET['target_program'] ?? ''));
$targetYearLevel = trim((string) ($_GET['target_year_level'] ?? ''));
$targetSection = trim((string) ($_GET['target_section'] ?? ''));
if ($targetCollegeId > 0 && $targetProgram !== '' && $targetYearLevel !== '' && $targetSection !== '') {
    $geTarget = [
        'college_id' => $targetCollegeId,
        'program_name' => $targetProgram,
        'year_level' => $targetYearLevel,
        'section' => $targetSection,
    ];
}

$assigned = find_course_load_assigned_faculty($courseId, $semester, $schoolYear, $facultyId, $geTarget);
if ($assigned === null) {
    echo json_encode(['assigned' => false], JSON_UNESCAPED_UNICODE);
    exit;
}

$message = course_load_assignment_conflict_message($assigned, $courseId, $geTarget);
echo json_encode([
    'assigned' => true,
    'faculty_name' => $assigned['full_name'],
    'faculty_code' => $assigned['faculty_code'],
    'message' => $message,
], JSON_UNESCAPED_UNICODE);

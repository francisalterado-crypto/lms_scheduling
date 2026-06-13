<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/student_registration_helpers.php';

header('Content-Type: application/json; charset=utf-8');

$collegeId = (int) ($_GET['college_id'] ?? 0);
$programs = active_programs_for_college($collegeId);
$yearLevelsByProgram = active_year_levels_by_program_for_college($collegeId);

echo json_encode([
    'programs' => $programs,
    'year_levels_by_program' => $yearLevelsByProgram,
], JSON_UNESCAPED_UNICODE);

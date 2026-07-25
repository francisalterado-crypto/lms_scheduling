<?php
declare(strict_types=1);

/**
 * POST — auto-generate assessment questions with answer keys (faculty only).
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/faculty_assessment_question_generator.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

/**
 * @param array<string, mixed> $body
 * @never-return
 */
function fagq_emit_json(int $httpCode, array $body): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    http_response_code($httpCode);

    echo json_encode(
        $body,
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
    );
    exit;
}

function fagq_read_json_body(): ?array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        return null;
    }

    return is_array($decoded) ? $decoded : [];
}

function fagq_faculty_id(): int
{
    $role = (string) ($_SESSION['role'] ?? '');
    if (!in_array($role, ['faculty', 'program_chair', 'dean', 'gened'], true)) {
        return 0;
    }

    $facultyId = isset($_SESSION['faculty_id']) ? (int) $_SESSION['faculty_id'] : 0;
    if ($facultyId > 0) {
        return $facultyId;
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId < 1) {
        return 0;
    }

    $resolved = resolve_faculty_id_for_user($userId) ?? 0;
    if ($resolved < 1 && in_array($role, ['program_chair', 'dean', 'gened'], true)) {
        $resolved = ensure_faculty_profile_for_teaching_role($userId) ?? 0;
    }
    if ($resolved > 0) {
        $_SESSION['faculty_id'] = $resolved;

        return $resolved;
    }

    return 0;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    fagq_emit_json(200, [
        'ok' => true,
        'service' => 'faculty-assessment-generate-questions',
        'version' => '1.0.0',
        'ai_available' => wellness_ai_is_enabled(),
        'endpoints' => [
            [
                'method' => 'POST',
                'auth' => ['session_cookie_faculty'],
                'request_body' => [
                    'classroom_id' => 1,
                    'assessment_type' => 'multiple_choice',
                    'title' => 'Quiz 1',
                    'description' => '',
                    'count' => 5,
                    'topic' => '',
                    'credited_week' => 'Week 1',
                    'use_materials' => true,
                    'exclude' => [],
                ],
            ],
        ],
    ]);
}

if ($method !== 'POST') {
    fagq_emit_json(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

if (empty($_SESSION['user_id'])) {
    fagq_emit_json(401, ['ok' => false, 'error' => 'Login required.']);
}

$facultyId = fagq_faculty_id();
if ($facultyId < 1) {
    fagq_emit_json(403, ['ok' => false, 'error' => 'Faculty profile not linked to this account.']);
}

$payload = fagq_read_json_body();
if ($payload === null) {
    fagq_emit_json(400, ['ok' => false, 'error' => 'Invalid JSON body.']);
}

$classroomId = (int) ($payload['classroom_id'] ?? 0);
$assessmentType = (string) ($payload['assessment_type'] ?? 'essay');
$title = trim((string) ($payload['title'] ?? ''));
$description = (string) ($payload['description'] ?? '');
$count = max(1, min(20, (int) ($payload['count'] ?? 5)));
$topic = trim((string) ($payload['topic'] ?? ''));
$weekLabel = trim((string) ($payload['credited_week'] ?? $payload['week'] ?? ''));
$useMaterials = !empty($payload['use_materials']);
$exclude = [];
if (isset($payload['exclude']) && is_array($payload['exclude'])) {
    foreach ($payload['exclude'] as $questionText) {
        $text = trim((string) $questionText);
        if ($text !== '') {
            $exclude[] = $text;
        }
    }
}

if ($classroomId < 1) {
    fagq_emit_json(400, ['ok' => false, 'error' => 'classroom_id is required.']);
}

try {
    $result = faculty_assessment_generator_generate(
        $facultyId,
        $classroomId,
        $assessmentType,
        $title,
        $description,
        $count,
        $topic,
        $weekLabel,
        $useMaterials,
        $exclude
    );
    fagq_emit_json(200, $result);
} catch (Throwable $e) {
    fagq_emit_json(400, ['ok' => false, 'error' => $e->getMessage()]);
}

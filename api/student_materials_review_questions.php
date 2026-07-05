<?php
declare(strict_types=1);

/**
 * POST — generate weekly practice questions from enrolled classroom materials (students only).
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/student_materials_reviewer.php';
require_once dirname(__DIR__) . '/includes/wellness_ai.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

/**
 * @param array<string, mixed> $body
 * @never-return
 */
function smrq_emit_json(int $httpCode, array $body): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    http_response_code($httpCode);

    echo json_encode(
        $body,
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
    );
    exit;
}

function smrq_read_json_body(): ?array
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

function smrq_student_id(): int
{
    $studentId = isset($_SESSION['student_id']) ? (int) $_SESSION['student_id'] : 0;
    if ($studentId > 0) {
        return $studentId;
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId < 1) {
        return 0;
    }

    $resolved = resolve_student_id_for_user($userId);
    if ($resolved !== null && $resolved > 0) {
        $_SESSION['student_id'] = $resolved;

        return $resolved;
    }

    return 0;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    smrq_emit_json(200, [
        'ok' => true,
        'service' => 'student-materials-review-questions',
        'version' => '1.0.0',
        'ai_available' => wellness_ai_is_enabled(),
        'endpoints' => [
            [
                'method' => 'POST',
                'auth' => ['session_cookie_student'],
                'request_body' => [
                    'classroom_id' => 1,
                    'week' => 'Week 1',
                    'count' => 0,
                    'exclude' => [],
                ],
            ],
        ],
    ]);
}

if ($method !== 'POST') {
    smrq_emit_json(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'student') {
    smrq_emit_json(401, ['ok' => false, 'error' => 'Student login required.']);
}

$payload = smrq_read_json_body();
if ($payload === null) {
    smrq_emit_json(400, ['ok' => false, 'error' => 'Invalid JSON body.']);
}

$studentId = smrq_student_id();
if ($studentId < 1) {
    smrq_emit_json(403, ['ok' => false, 'error' => 'Student profile not linked to this account.']);
}

$classroomId = (int) ($payload['classroom_id'] ?? 0);
$week = trim((string) ($payload['week'] ?? ''));
$count = (int) ($payload['count'] ?? 0);
$exclude = [];
if (isset($payload['exclude']) && is_array($payload['exclude'])) {
    foreach ($payload['exclude'] as $questionText) {
        $text = trim((string) $questionText);
        if ($text !== '') {
            $exclude[] = $text;
        }
    }
}

try {
    $result = student_materials_reviewer_generate($studentId, $classroomId, $week, $count, $exclude);
    smrq_emit_json(200, $result);
} catch (Throwable $e) {
    smrq_emit_json(400, ['ok' => false, 'error' => $e->getMessage()]);
}

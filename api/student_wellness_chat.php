<?php
declare(strict_types=1);

/**
 * REST endpoint: student wellness companion (non-diagnostic; crisis escalation for PH).
 *
 * GET  — service descriptor (public).
 * POST — chat completion (authenticated students only).
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/wellness_chatbot.php';
require_once dirname(__DIR__) . '/includes/wellness_ai.php';
require_once dirname(__DIR__) . '/includes/wellness_engine.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

/**
 * @param array<string, mixed> $body
 * @never-return
 */
function wellness_api_emit_json(int $httpCode, array $body): void
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

function wellness_api_read_json_body(): ?array
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

function wellness_api_is_student_logged_in(): bool
{
    return !empty($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'student';
}

/**
 * @param array<string, mixed> $payload
 *
 * @return list<array{role: string, content: string}>
 */
function wellness_api_parse_history(array $payload): array
{
    if (!isset($payload['history']) || !is_array($payload['history'])) {
        return [];
    }

    return wellness_ai_sanitize_history($payload['history']);
}

/** @never-return */
function wellness_api_handle_get(): void
{
    $base = rtrim((string) (defined('APP_BASE_URL') ? APP_BASE_URL : ''), '/');
    $openapi = $base . '/api/wellness_chatbot.openapi.yaml';
    wellness_api_emit_json(200, [
        'ok' => true,
        'service' => 'student-wellness-chat',
        'version' => '1.3.0',
        'ai_available' => wellness_ai_is_enabled(),
        'ollama_reachable' => wellness_ollama_reachable(),
        'ai_provider' => wellness_ai_provider(),
        'groq' => wellness_groq_status(),
        'setup_hint' => wellness_ai_has_cloud_key()
            ? null
            : (wellness_ai_provider() === 'groq'
                ? 'Groq: open config/config.php and paste your API key from https://console.groq.com/keys (starts with gsk_).'
                : 'Set WELLNESS_AI_API_KEY in config/config.php or install Ollama for local AI.'),
        'constraints' => [
            'Question-aware replies matched to scenario (exams, sleep, loneliness, family, etc.).',
            'Provides general coping support—not therapy, diagnosis, or emergency intervention.',
            'Responds in English or Filipino/Tagalog heuristically, or honors client `language_hint`.',
            'May return `crisis: true` plus Philippines crisis hotlines when ideation cues are matched.',
        ],
        'openapi_url' => $openapi,
        'endpoints' => [
            [
                'name' => 'chat',
                'method' => 'POST',
                'path' => $base !== '' ? ($base . '/api/student_wellness_chat.php') : '/api/student_wellness_chat.php',
                'auth' => ['session_cookie_student'],
                'request_body_example' => [
                    'message' => 'School has been draining and I barely sleep lately.',
                    'language_hint' => 'auto',
                ],
            ],
        ],
        'disclaimer_banner_en' => wellness_disclaimer_banner('en'),
        'philippines_crisis_sample' => wellness_ph_crises_resources_public(),
    ]);
}

/** @never-return */
function wellness_api_handle_post(): void
{
    if (!wellness_api_is_student_logged_in()) {
        wellness_api_emit_json(401, [
            'ok' => false,
            'error' => 'Login required.',
            'code' => 'UNAUTHORIZED',
        ]);
    }

    $payload = wellness_api_read_json_body();
    if ($payload === null) {
        wellness_api_emit_json(400, [
            'ok' => false,
            'error' => 'Invalid JSON.',
            'code' => 'BAD_JSON',
        ]);
    }

    $messageRaw = isset($payload['message']) ? (string) $payload['message'] : '';
    if (mb_strlen($messageRaw, 'UTF-8') > 4000) {
        wellness_api_emit_json(400, [
            'ok' => false,
            'error' => 'Message exceeds 4000 characters.',
            'code' => 'MESSAGE_TOO_LONG',
        ]);
    }

    $hintLc = null;
    if (isset($payload['language_hint'])) {
        $h = mb_strtolower(trim((string) $payload['language_hint']), 'UTF-8');
        $hintLc = $h !== '' ? $h : null;
    } elseif (isset($payload['locale'])) {
        $h = mb_strtolower(trim((string) $payload['locale']), 'UTF-8');
        $hintLc = $h !== '' ? $h : null;
    }
    if ($hintLc === 'auto') {
        $hintLc = null;
    }

    $history = wellness_api_parse_history($payload);
    if ($history === [] && !empty($_SESSION['wellness_chat_history']) && is_array($_SESSION['wellness_chat_history'])) {
        $history = wellness_ai_sanitize_history($_SESSION['wellness_chat_history']);
    }

    $out = wellness_chat_orchestrate($messageRaw, $hintLc, $history);

    if ($messageRaw !== '' && !$out['crisis']) {
        $_SESSION['wellness_chat_history'] = wellness_ai_sanitize_history(array_merge(
            $history,
            [
                ['role' => 'user', 'content' => $messageRaw],
                ['role' => 'assistant', 'content' => $out['reply_body']],
            ]
        ));
    }

    $reply = [
        'ok' => true,
        'reply' => $out['reply'],
        'reply_body' => $out['reply_body'],
        'reply_format' => 'markdown',
        'crisis' => $out['crisis'],
        'intent' => $out['intent'],
        'scenarios' => $out['scenarios'],
        'is_question' => $out['is_question'],
        'topics' => $out['topics'],
        'detected_language' => $out['language'],
        'disclaimer_banner' => $out['disclaimer_banner'],
        'footer_disclaimer' => $out['footer_disclaimer'],
        'philippines_crisis_resources' => $out['resources'] ?? null,
        'reply_source' => $out['reply_source'] ?? 'builtin',
    ];

    wellness_api_emit_json(200, $reply);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    wellness_api_handle_get();
}

if ($method === 'POST') {
    wellness_api_handle_post();
}

wellness_api_emit_json(405, [
    'ok' => false,
    'error' => 'Method not allowed.',
    'code' => 'METHOD_NOT_ALLOWED',
]);

<?php
declare(strict_types=1);

/**
 * Wellness AI: Groq, OpenAI, Gemini, or local Ollama (OpenAI-compatible).
 * Crisis checks stay in wellness_chatbot.php.
 */

function wellness_ai_provider(): string
{
    if (!defined('WELLNESS_AI_PROVIDER')) {
        return 'auto';
    }
    return strtolower(trim((string) WELLNESS_AI_PROVIDER));
}

function wellness_ai_has_cloud_key(): bool
{
    if (!defined('WELLNESS_AI_API_KEY')) {
        return false;
    }
    $key = trim((string) WELLNESS_AI_API_KEY);

    return $key !== '' && str_starts_with($key, 'gsk_');
}

function wellness_ai_is_enabled(): bool
{
    if (defined('WELLNESS_AI_ENABLED') && !WELLNESS_AI_ENABLED) {
        return false;
    }
    $p = wellness_ai_provider();
    if ($p === 'off' || $p === 'builtin') {
        return false;
    }
    if ($p === 'ollama') {
        return true;
    }
    if ($p === 'auto') {
        return wellness_ai_has_cloud_key() || wellness_ollama_reachable();
    }

    return wellness_ai_has_cloud_key();
}

function wellness_ollama_url(): string
{
    return rtrim((string) (defined('WELLNESS_OLLAMA_URL') ? WELLNESS_OLLAMA_URL : 'http://127.0.0.1:11434'), '/');
}

function wellness_ollama_model(): string
{
    return (string) (defined('WELLNESS_OLLAMA_MODEL') ? WELLNESS_OLLAMA_MODEL : 'llama3.2');
}

function wellness_ollama_reachable(): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $ch = curl_init(wellness_ollama_url() . '/api/tags');
    if ($ch === false) {
        $cache = false;
        return false;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 2,
        CURLOPT_CONNECTTIMEOUT => 1,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $cache = is_string($raw) && $code >= 200 && $code < 300;
    return $cache;
}

/**
 * @param list<array{role: string, content: string}> $history
 * @param list<string>                             $scenarios
 */
function wellness_ai_system_prompt(string $lang, array $scenarios, string $userMessage): string
{
    $focus = $scenarios !== [] ? implode(', ', array_slice($scenarios, 0, 3)) : 'general support';
    $langRule = $lang === 'tl'
        ? 'Sumagot sa natural na Filipino o Taglish—tulad ng kausap na ate/kuya sa campus, hindi textbook.'
        : 'Reply in warm, natural English—like a supportive peer mentor, not a textbook.';

    return <<<PROMPT
You are "Wellness Companion" for university students in the Philippines (WPU-style campus).

{$langRule}

The student's latest message (respond to THIS specifically):
---
{$userMessage}
---

Detected themes: {$focus}.

Your job:
1. First sentence: reflect what they feel or face, in fresh words (never copy their sentence in quotes).
2. Then answer their question directly with 2–4 short, practical steps they can do today or tomorrow.
3. End with one gentle line: you are not a therapist; suggest campus guidance if this lasts weeks or feels overwhelming.

Hard rules:
- Max ~150 words. Short paragraphs or bullets.
- Never diagnose (no "you have depression/anxiety disorder").
- Never prescribe medicine.
- No headers like "Direct answer:" or "Sagot:".
- If they mix English and Tagalog, match their style.
- Be kind, specific, and human—not generic wellness pamphlet text.
PROMPT;
}

/**
 * @param list<array{role: string, content: string}> $history
 * @return list<array{role: string, content: string}>
 */
function wellness_ai_sanitize_history(array $history): array
{
    $out = [];
    foreach ($history as $turn) {
        if (!is_array($turn)) {
            continue;
        }
        $role = (string) ($turn['role'] ?? '');
        $content = trim((string) ($turn['content'] ?? ''));
        if ($content === '' || mb_strlen($content, 'UTF-8') > 2000) {
            continue;
        }
        if ($role !== 'user' && $role !== 'assistant') {
            continue;
        }
        $out[] = ['role' => $role, 'content' => $content];
    }

    return array_slice($out, -8);
}

/**
 * @param list<array{role: string, content: string}> $history
 * @param list<string>                             $scenarios
 */
function wellness_ai_chat(string $userMessage, string $lang, array $scenarios, array $history = []): ?string
{
    if (!wellness_ai_is_enabled()) {
        return null;
    }

    $provider = wellness_ai_provider();
    if ($provider === 'auto') {
        if (wellness_ollama_reachable()) {
            $text = wellness_ai_call_ollama($userMessage, $lang, $scenarios, $history);
            if ($text !== null) {
                return $text;
            }
        }
        if (wellness_ai_has_cloud_key()) {
            return wellness_ai_call_openai_compatible($userMessage, $lang, $scenarios, $history);
        }

        return null;
    }

    if ($provider === 'ollama') {
        return wellness_ai_call_ollama($userMessage, $lang, $scenarios, $history);
    }

    if ($provider === 'gemini') {
        return wellness_ai_call_gemini($userMessage, $lang, $scenarios, $history);
    }

    if ($provider === 'groq' || $provider === 'openai') {
        return wellness_ai_call_openai_compatible($userMessage, $lang, $scenarios, $history);
    }

    return wellness_ai_call_openai_compatible($userMessage, $lang, $scenarios, $history);
}

/** Quick connectivity check for admin GET / status (does not log the key). */
function wellness_groq_status(): array
{
    $ready = wellness_ai_provider() === 'groq' && wellness_ai_has_cloud_key() && wellness_ai_is_enabled();

    return [
        'provider' => wellness_ai_provider(),
        'configured' => wellness_ai_has_cloud_key(),
        'ready' => $ready,
        'model' => defined('WELLNESS_AI_MODEL') ? (string) WELLNESS_AI_MODEL : '',
        'message' => $ready
            ? 'Groq is configured. Wellness chat will use cloud AI.'
            : 'Add your Groq API key (gsk_…) in config/config.php or set GROQ_API_KEY environment variable.',
    ];
}

/**
 * @param list<array{role: string, content: string}> $history
 * @param list<string>                             $scenarios
 */
function wellness_ai_build_messages(string $userMessage, string $lang, array $scenarios, array $history): array
{
    $messages = [
        ['role' => 'system', 'content' => wellness_ai_system_prompt($lang, $scenarios, $userMessage)],
    ];
    foreach (wellness_ai_sanitize_history($history) as $turn) {
        $messages[] = $turn;
    }
    $messages[] = ['role' => 'user', 'content' => $userMessage];

    return $messages;
}

/**
 * @param list<array{role: string, content: string}> $history
 * @param list<string>                             $scenarios
 */
function wellness_ai_call_openai_compatible(string $userMessage, string $lang, array $scenarios, array $history): ?string
{
    if (!wellness_ai_has_cloud_key()) {
        return null;
    }

    $baseUrl = rtrim((string) (defined('WELLNESS_AI_BASE_URL') ? WELLNESS_AI_BASE_URL : 'https://api.groq.com/openai/v1'), '/');
    $model = (string) (defined('WELLNESS_AI_MODEL') ? WELLNESS_AI_MODEL : 'llama-3.3-70b-versatile');

    $payload = [
        'model' => $model,
        'temperature' => 0.72,
        'max_tokens' => 520,
        'messages' => wellness_ai_build_messages($userMessage, $lang, $scenarios, $history),
    ];

    return wellness_ai_http_json_post($baseUrl . '/chat/completions', [
        'Authorization: Bearer ' . trim((string) WELLNESS_AI_API_KEY),
    ], $payload, static function (array $data): ?string {
        return trim((string) ($data['choices'][0]['message']['content'] ?? ''));
    });
}

/**
 * @param list<array{role: string, content: string}> $history
 * @param list<string>                             $scenarios
 */
function wellness_ai_call_ollama(string $userMessage, string $lang, array $scenarios, array $history): ?string
{
    $payload = [
        'model' => wellness_ollama_model(),
        'stream' => false,
        'options' => ['temperature' => 0.72, 'num_predict' => 520],
        'messages' => wellness_ai_build_messages($userMessage, $lang, $scenarios, $history),
    ];

    return wellness_ai_http_json_post(wellness_ollama_url() . '/api/chat', [], $payload, static function (array $data): ?string {
        return trim((string) ($data['message']['content'] ?? ''));
    });
}

/**
 * @param list<array{role: string, content: string}> $history
 * @param list<string>                             $scenarios
 */
function wellness_ai_call_gemini(string $userMessage, string $lang, array $scenarios, array $history): ?string
{
    if (!wellness_ai_has_cloud_key()) {
        return null;
    }

    $model = (string) (defined('WELLNESS_AI_MODEL') ? WELLNESS_AI_MODEL : 'gemini-2.0-flash');
    $key = trim((string) WELLNESS_AI_API_KEY);
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
        . rawurlencode($model) . ':generateContent?key=' . rawurlencode($key);

    $parts = [];
    foreach (wellness_ai_sanitize_history($history) as $turn) {
        $role = $turn['role'] === 'assistant' ? 'model' : 'user';
        $parts[] = ['role' => $role, 'parts' => [['text' => $turn['content']]]];
    }
    $parts[] = ['role' => 'user', 'parts' => [['text' => $userMessage]]];

    $payload = [
        'systemInstruction' => ['parts' => [['text' => wellness_ai_system_prompt($lang, $scenarios, $userMessage)]]],
        'contents' => $parts,
        'generationConfig' => ['temperature' => 0.72, 'maxOutputTokens' => 520],
    ];

    return wellness_ai_http_json_post($url, [], $payload, static function (array $data): ?string {
        $candidates = $data['candidates'] ?? [];
        if (!is_array($candidates) || $candidates === []) {
            return null;
        }
        $partsOut = $candidates[0]['content']['parts'] ?? [];
        $text = '';
        if (is_array($partsOut)) {
            foreach ($partsOut as $p) {
                $text .= (string) ($p['text'] ?? '');
            }
        }

        return trim($text);
    });
}

/**
 * @param array<string, mixed> $payload
 */
function wellness_ai_http_json_post(string $url, array $extraHeaders, array $payload, callable $extract): ?string
{
    try {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        return null;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }

    $headers = array_merge(['Content-Type: application/json'], $extraHeaders);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);

    $raw = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($raw) || $raw === '' || $httpCode < 200 || $httpCode >= 300) {
        return null;
    }

    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        return null;
    }

    if (!is_array($data)) {
        return null;
    }

    $text = $extract($data);
    if ($text === null || $text === '') {
        return null;
    }

    if (mb_strlen($text, 'UTF-8') > 2400) {
        $text = mb_substr($text, 0, 2397, 'UTF-8') . '…';
    }

    return $text;
}

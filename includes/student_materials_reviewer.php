<?php
declare(strict_types=1);

/**
 * Weekly course-materials reviewer: build context and generate practice questions.
 */

function student_materials_reviewer_plain_text(string $html): string
{
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

    return trim($text);
}

/**
 * @return array<string, mixed>|null
 */
function student_materials_reviewer_fetch_classroom(int $studentId, int $classroomId): ?array
{
    if ($studentId < 1 || $classroomId < 1 || !db_table_exists('classroom_enrollments')) {
        return null;
    }

    $st = db()->prepare(
        'SELECT oc.id, c.course_code, c.course_name, f.full_name AS faculty_name
         FROM classroom_enrollments ce
         INNER JOIN online_classrooms oc ON oc.id = ce.classroom_id
         INNER JOIN courses c ON c.id = oc.course_id
         INNER JOIN faculty f ON f.id = oc.faculty_id
         WHERE oc.id = ? AND ce.student_id = ?
         LIMIT 1'
    );
    $st->execute([$classroomId, $studentId]);
    $row = $st->fetch();

    return is_array($row) ? $row : null;
}

/**
 * @return list<array{label:string,count:int,items:array<int,array<string,mixed>>}>
 */
function student_materials_reviewer_week_groups(int $classroomId): array
{
    if ($classroomId < 1 || !db_table_exists('classroom_content')) {
        return [];
    }

    $st = db()->prepare(
        'SELECT *
         FROM classroom_content
         WHERE classroom_id = ? AND content_type <> "announcement"
         ORDER BY created_at DESC'
    );
    $st->execute([$classroomId]);
    $materials = $st->fetchAll();

    return classroom_content_group_by_week($materials);
}

/**
 * @return list<array<string, mixed>>
 */
function student_materials_reviewer_week_items(int $classroomId, string $weekLabel): array
{
    foreach (student_materials_reviewer_week_groups($classroomId) as $group) {
        if ((string) $group['label'] === $weekLabel) {
            return $group['items'];
        }
    }

    return [];
}

/**
 * @param list<array<string, mixed>> $items
 */
function student_materials_reviewer_build_source_text(array $items, array $classroom, string $weekLabel): string
{
    $lines = [
        'Course: ' . trim((string) ($classroom['course_code'] ?? '')) . ' - ' . trim((string) ($classroom['course_name'] ?? '')),
        'Week: ' . $weekLabel,
        'Instructor: ' . trim((string) ($classroom['faculty_name'] ?? '')),
        '',
    ];

    foreach ($items as $idx => $item) {
        $n = (int) $idx + 1;
        $title = trim((string) ($item['title'] ?? 'Untitled'));
        $type = trim((string) ($item['content_type'] ?? 'material'));
        $body = student_materials_reviewer_plain_text((string) ($item['body'] ?? ''));
        if (mb_strlen($body, 'UTF-8') > 1200) {
            $body = mb_substr($body, 0, 1197, 'UTF-8') . '…';
        }

        $lines[] = "Material {$n}: {$title} ({$type})";
        if ($body !== '') {
            $lines[] = $body;
        }
        $url = trim((string) ($item['resource_url'] ?? ''));
        if ($url !== '' && !classroom_content_is_attachment($url)) {
            $lines[] = 'Resource link: ' . $url;
        }
        $lines[] = '';
    }

    $text = trim(implode("\n", $lines));
    if (mb_strlen($text, 'UTF-8') > 8000) {
        $text = mb_substr($text, 0, 7997, 'UTF-8') . '…';
    }

    return $text;
}

function student_materials_reviewer_unlimited_count(int $count): bool
{
    return $count <= 0;
}

/**
 * @param list<string> $excludeNormalized
 */
function student_materials_reviewer_question_excluded(string $question, array $excludeNormalized): bool
{
    if ($excludeNormalized === []) {
        return false;
    }

    $norm = mb_strtolower(trim($question), 'UTF-8');

    return in_array($norm, $excludeNormalized, true);
}

/**
 * @param list<string> $exclude
 * @return list<string>
 */
function student_materials_reviewer_normalize_exclude_list(array $exclude): array
{
    $out = [];
    foreach ($exclude as $text) {
        $norm = mb_strtolower(trim((string) $text), 'UTF-8');
        if ($norm !== '') {
            $out[] = $norm;
        }
    }

    return array_values(array_unique($out));
}

/**
 * @param list<array{id:int,question:string,type:string,choices:list<string>,answer:string,source:string}> $questions
 * @return list<array{id:int,question:string,type:string,choices:list<string>,answer:string,source:string}>
 */
function student_materials_reviewer_renumber_questions(array $questions, int $startId = 1): array
{
    $out = [];
    $id = $startId;
    foreach ($questions as $row) {
        $row['id'] = $id++;
        $out[] = $row;
    }

    return $out;
}

/**
 * @param list<array<string, mixed>> $items
 * @param list<string>              $excludeNormalized
 * @return list<array{id:int,question:string,type:string,choices:list<string>,answer:string,source:string}>
 */
function student_materials_reviewer_builtin_questions(
    string $weekLabel,
    array $items,
    int $count = 0,
    array $excludeNormalized = []
): array {
    $unlimited = student_materials_reviewer_unlimited_count($count);
    $questions = [];
    $id = 1;

    $add = static function (array $row) use (
        &$questions,
        &$id,
        $unlimited,
        $count,
        $excludeNormalized
    ): bool {
        if (student_materials_reviewer_question_excluded((string) $row['question'], $excludeNormalized)) {
            return false;
        }
        if (!$unlimited && count($questions) >= $count) {
            return false;
        }

        $row['id'] = $id++;
        $row['source'] = 'builtin';
        $questions[] = $row;

        return true;
    };

    if ($items === []) {
        return [[
            'id' => 1,
            'question' => 'No materials were posted for ' . $weekLabel . ' yet. Check back after your instructor uploads topics for this week.',
            'type' => 'short_answer',
            'choices' => [],
            'answer' => 'Review will be available once course materials are posted.',
            'source' => 'builtin',
        ]];
    }

    foreach ($items as $item) {
        if (!$unlimited && count($questions) >= $count) {
            break;
        }

        $title = trim((string) ($item['title'] ?? 'this topic'));
        if ($title === '') {
            $title = 'this topic';
        }
        $type = trim((string) ($item['content_type'] ?? 'material'));
        $body = student_materials_reviewer_plain_text((string) ($item['body'] ?? ''));

        $add([
            'question' => 'For ' . $weekLabel . ', what are the main ideas you should remember from "' . $title . '"?',
            'type' => 'short_answer',
            'choices' => [],
            'answer' => $body !== ''
                ? 'Focus on the posted notes for "' . $title . '". Key excerpt: ' . mb_substr($body, 0, 280, 'UTF-8')
                : 'Review the posted "' . $title . '" material and list the concepts your instructor highlighted.',
        ]);

        $typeChoices = array_values(array_unique(array_filter([
            $type,
            'reading',
            'video',
            'link',
            'worksheet',
            'slides',
        ])));
        if (count($typeChoices) < 4) {
            $typeChoices = array_values(array_unique(array_merge($typeChoices, ['reading', 'video', 'link', 'worksheet', 'slides'])));
        }
        $typeChoices = array_slice($typeChoices, 0, 4);
        if (!in_array($type, $typeChoices, true)) {
            $typeChoices[0] = $type;
        }

        $add([
            'question' => 'What kind of resource did your instructor post for "' . $title . '" in ' . $weekLabel . '?',
            'type' => 'multiple_choice',
            'choices' => $typeChoices,
            'answer' => $type,
        ]);

        $add([
            'question' => 'Explain "' . $title . '" from ' . $weekLabel . ' in your own words, as if teaching a classmate.',
            'type' => 'short_answer',
            'choices' => [],
            'answer' => $body !== ''
                ? 'Your explanation should cover the core ideas from the posted material on "' . $title . '".'
                : 'Summarize the main lesson your instructor intended for "' . $title . '".',
        ]);

        $add([
            'question' => 'Why is "' . $title . '" important for the lessons in ' . $weekLabel . '?',
            'type' => 'short_answer',
            'choices' => [],
            'answer' => 'Connect this topic to the weekly learning goals and how it supports later lessons.',
        ]);

        if ($body !== '') {
            $add([
                'question' => 'From the notes on "' . $title . '", what is one detail you should not forget for the exam or recitation?',
                'type' => 'short_answer',
                'choices' => [],
                'answer' => 'Pick a specific fact or concept from the material: ' . mb_substr($body, 0, 220, 'UTF-8'),
            ]);
        }
    }

    $itemCount = count($items);
    for ($i = 0; $i < $itemCount; ++$i) {
        if (!$unlimited && count($questions) >= $count) {
            break;
        }
        for ($j = $i + 1; $j < $itemCount; ++$j) {
            if (!$unlimited && count($questions) >= $count) {
                break 2;
            }
            $a = $items[$i];
            $b = $items[$j];
            $add([
                'question' => 'How do "' . trim((string) ($a['title'] ?? 'Topic A')) . '" and "' . trim((string) ($b['title'] ?? 'Topic B')) . '" connect within ' . $weekLabel . '?',
                'type' => 'short_answer',
                'choices' => [],
                'answer' => 'Explain how both topics support the learning goals for this week.',
            ]);
        }
    }

    $titles = array_values(array_filter(array_map(
        static fn (array $item): string => trim((string) ($item['title'] ?? '')),
        $items
    )));
    $titleList = $titles !== [] ? implode(', ', array_slice($titles, 0, 8)) : 'each posted material';

    $weekQuestions = [
        [
            'question' => 'Summarize everything you studied in ' . $weekLabel . ' in five sentences or fewer.',
            'type' => 'short_answer',
            'choices' => [],
            'answer' => 'Your summary should mention each posted material and how it fits the week\'s lessons.',
        ],
        [
            'question' => 'What is the overall learning goal for ' . $weekLabel . ' in this subject?',
            'type' => 'short_answer',
            'choices' => [],
            'answer' => 'Combine the themes from: ' . $titleList . '.',
        ],
        [
            'question' => 'Which material from ' . $weekLabel . ' should you review first before the others, and why?',
            'type' => 'short_answer',
            'choices' => [],
            'answer' => 'Choose the foundational topic first, then build toward more advanced materials in the week.',
        ],
    ];

    foreach ($weekQuestions as $row) {
        if (!$unlimited && count($questions) >= $count) {
            break;
        }
        $add($row);
    }

    if ($excludeNormalized !== []) {
        $extraTemplates = [
            static fn (string $title): array => [
                'question' => 'What exam-style question might your instructor ask about "' . $title . '" from ' . $weekLabel . '?',
                'type' => 'short_answer',
                'choices' => [],
                'answer' => 'Write a question that tests understanding of the core ideas in "' . $title . '", then answer it.',
            ],
            static fn (string $title): array => [
                'question' => 'List three important terms from "' . $title . '" and define each in one sentence.',
                'type' => 'short_answer',
                'choices' => [],
                'answer' => 'Use vocabulary and concepts directly from the posted material on "' . $title . '".',
            ],
            static fn (string $title): array => [
                'question' => 'How would you apply what you learned in "' . $title . '" to a real-world example?',
                'type' => 'short_answer',
                'choices' => [],
                'answer' => 'Give a concrete example that shows you understood the lesson in "' . $title . '".',
            ],
            static fn (string $title): array => [
                'question' => 'What is still unclear to you about "' . $title . '", and what would you ask your instructor?',
                'type' => 'short_answer',
                'choices' => [],
                'answer' => 'Name a specific concept from "' . $title . '" you would clarify in class or office hours.',
            ],
        ];

        foreach ($items as $item) {
            if (!$unlimited && count($questions) >= $count) {
                break;
            }
            $title = trim((string) ($item['title'] ?? 'this topic'));
            if ($title === '') {
                $title = 'this topic';
            }
            foreach ($extraTemplates as $template) {
                if (!$unlimited && count($questions) >= $count) {
                    break 2;
                }
                $add($template($title));
            }
        }
    }

    if (!$unlimited) {
        return array_slice($questions, 0, max(1, $count));
    }

    return $questions;
}

/**
 * @return list<array{id:int,question:string,type:string,choices:list<string>,answer:string,source:string}>
 */
function student_materials_reviewer_parse_ai_questions(string $raw, int $maxCount = 0, array $excludeNormalized = []): array
{
    if (preg_match('/\{[\s\S]*\}/', $raw, $m) === 1) {
        $raw = $m[0];
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        return [];
    }

    if (!is_array($decoded) || !isset($decoded['questions']) || !is_array($decoded['questions'])) {
        return [];
    }

    $unlimited = student_materials_reviewer_unlimited_count($maxCount);
    $out = [];
    foreach ($decoded['questions'] as $row) {
        if (!$unlimited && count($out) >= $maxCount) {
            break;
        }
        if (!is_array($row)) {
            continue;
        }
        $question = trim((string) ($row['question'] ?? ''));
        if ($question === '' || student_materials_reviewer_question_excluded($question, $excludeNormalized)) {
            continue;
        }
        $type = strtolower(trim((string) ($row['type'] ?? 'short_answer')));
        if ($type !== 'multiple_choice') {
            $type = 'short_answer';
        }
        $choices = [];
        if (isset($row['choices']) && is_array($row['choices'])) {
            foreach ($row['choices'] as $choice) {
                $c = trim((string) $choice);
                if ($c !== '') {
                    $choices[] = $c;
                }
            }
        }
        $answer = trim((string) ($row['answer'] ?? ''));
        if ($answer === '') {
            continue;
        }

        $out[] = [
            'id' => 0,
            'question' => $question,
            'type' => $type,
            'choices' => array_slice($choices, 0, 6),
            'answer' => $answer,
            'source' => 'ai',
        ];
        $excludeNormalized[] = mb_strtolower($question, 'UTF-8');
    }

    return $out;
}

/**
 * @param list<string> $exclude
 * @return list<array{id:int,question:string,type:string,choices:list<string>,answer:string,source:string}>
 */
function student_materials_reviewer_ai_questions_batch(
    string $sourceText,
    string $weekLabel,
    string $courseName,
    int $batchSize,
    array $exclude = []
): array {
    $excludeNormalized = student_materials_reviewer_normalize_exclude_list($exclude);
    $batchSize = max(1, $batchSize);

    $excludeBlock = '';
    if ($exclude !== []) {
        $sample = array_slice($exclude, -40);
        $excludeBlock = "\nDo NOT repeat or closely rephrase these existing questions:\n- "
            . implode("\n- ", array_map(static fn (string $q): string => mb_substr($q, 0, 200, 'UTF-8'), $sample));
    }

    $system = <<<PROMPT
You create short practice review questions for university students based ONLY on the course materials provided.

Week: {$weekLabel}
Course: {$courseName}

Rules:
- Generate exactly {$batchSize} new questions grounded in the materials (no outside facts).
- Mix multiple_choice and short_answer types.
- For multiple_choice, provide exactly 4 choices and one correct answer string matching one choice.
- Keep questions clear, varied, and study-focused.
- Return ONLY valid JSON with this shape (no markdown fences):
{"questions":[{"question":"...","type":"multiple_choice"|"short_answer","choices":["A","B","C","D"],"answer":"..."}]}
PROMPT;

    $user = "Course materials for review:\n\n" . $sourceText . $excludeBlock;
    $raw = student_materials_reviewer_ai_call($system, $user, $batchSize > 12);
    if ($raw === null || $raw === '') {
        return [];
    }

    return student_materials_reviewer_parse_ai_questions($raw, $batchSize, $excludeNormalized);
}

/**
 * @param list<string> $exclude
 * @return list<array{id:int,question:string,type:string,choices:list<string>,answer:string,source:string}>|null
 */
function student_materials_reviewer_ai_questions(
    string $sourceText,
    string $weekLabel,
    string $courseName,
    int $count = 0,
    array $exclude = []
): ?array {
    if (!wellness_ai_is_enabled() || trim($sourceText) === '') {
        return null;
    }

    $unlimited = student_materials_reviewer_unlimited_count($count);
    $batchSize = $unlimited ? 15 : max(1, $count);
    $excludeTexts = $exclude;
    $all = [];

    $maxBatches = $unlimited ? 40 : 1;
    for ($batch = 0; $batch < $maxBatches; ++$batch) {
        $remaining = $unlimited ? $batchSize : max(0, $count - count($all));
        if (!$unlimited && $remaining < 1) {
            break;
        }

        $requestSize = $unlimited ? $batchSize : $remaining;
        $batchQuestions = student_materials_reviewer_ai_questions_batch(
            $sourceText,
            $weekLabel,
            $courseName,
            $requestSize,
            $excludeTexts
        );
        if ($batchQuestions === []) {
            break;
        }

        foreach ($batchQuestions as $row) {
            $all[] = $row;
            $excludeTexts[] = (string) $row['question'];
        }

        if (!$unlimited) {
            break;
        }
        if (count($batchQuestions) < $requestSize) {
            break;
        }
    }

    return $all !== [] ? student_materials_reviewer_renumber_questions($all) : null;
}

function student_materials_reviewer_ai_call(string $system, string $user, bool $largeBatch = false): ?string
{
    require_once dirname(__DIR__) . '/includes/wellness_ai.php';

    $messages = [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $user],
    ];

    $provider = wellness_ai_provider();
    if ($provider === 'auto') {
        if (wellness_ollama_reachable()) {
            $text = student_materials_reviewer_ai_ollama($messages, $largeBatch);
            if ($text !== null) {
                return $text;
            }
        }
        if (wellness_ai_has_cloud_key()) {
            return student_materials_reviewer_ai_openai_compatible($messages, $largeBatch);
        }

        return null;
    }

    if ($provider === 'ollama') {
        return student_materials_reviewer_ai_ollama($messages, $largeBatch);
    }

    if ($provider === 'gemini') {
        return student_materials_reviewer_ai_gemini($system, $user, $largeBatch);
    }

    return student_materials_reviewer_ai_openai_compatible($messages, $largeBatch);
}

/**
 * @param list<array{role:string,content:string}> $messages
 */
function student_materials_reviewer_ai_openai_compatible(array $messages, bool $largeBatch = false): ?string
{
    if (!wellness_ai_has_cloud_key()) {
        return null;
    }

    $baseUrl = rtrim((string) (defined('WELLNESS_AI_BASE_URL') ? WELLNESS_AI_BASE_URL : 'https://api.groq.com/openai/v1'), '/');
    $model = (string) (defined('WELLNESS_AI_MODEL') ? WELLNESS_AI_MODEL : 'llama-3.3-70b-versatile');
    $payload = [
        'model' => $model,
        'temperature' => 0.35,
        'max_tokens' => $largeBatch ? 2400 : 900,
        'messages' => $messages,
    ];

    return wellness_ai_http_json_post($baseUrl . '/chat/completions', [
        'Authorization: Bearer ' . trim((string) WELLNESS_AI_API_KEY),
    ], $payload, static function (array $data): ?string {
        return trim((string) ($data['choices'][0]['message']['content'] ?? ''));
    });
}

/**
 * @param list<array{role:string,content:string}> $messages
 */
function student_materials_reviewer_ai_ollama(array $messages, bool $largeBatch = false): ?string
{
    $payload = [
        'model' => wellness_ollama_model(),
        'stream' => false,
        'options' => ['temperature' => 0.35, 'num_predict' => $largeBatch ? 2400 : 900],
        'messages' => $messages,
    ];

    return wellness_ai_http_json_post(wellness_ollama_url() . '/api/chat', [], $payload, static function (array $data): ?string {
        return trim((string) ($data['message']['content'] ?? ''));
    });
}

function student_materials_reviewer_ai_gemini(string $system, string $user, bool $largeBatch = false): ?string
{
    if (!wellness_ai_has_cloud_key()) {
        return null;
    }

    $model = (string) (defined('WELLNESS_AI_MODEL') ? WELLNESS_AI_MODEL : 'gemini-2.0-flash');
    $key = trim((string) WELLNESS_AI_API_KEY);
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
        . rawurlencode($model) . ':generateContent?key=' . rawurlencode($key);

    $payload = [
        'systemInstruction' => ['parts' => [['text' => $system]]],
        'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
        'generationConfig' => ['temperature' => 0.35, 'maxOutputTokens' => $largeBatch ? 2400 : 900],
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
 * @return array{
 *     ok: bool,
 *     week: string,
 *     classroom_id: int,
 *     course_code: string,
 *     course_name: string,
 *     material_count: int,
 *     source: string,
 *     ai_available: bool,
 *     questions: list<array{id:int,question:string,type:string,choices:list<string>,answer:string,source:string}>
 * }
 */
function student_materials_reviewer_generate(
    int $studentId,
    int $classroomId,
    string $weekLabel,
    int $count = 0,
    array $exclude = []
): array {
    $classroom = student_materials_reviewer_fetch_classroom($studentId, $classroomId);
    if ($classroom === null) {
        throw new RuntimeException('Classroom not found or you are not enrolled in it.');
    }

    $weekLabel = trim($weekLabel);
    if ($weekLabel === '') {
        throw new RuntimeException('Select a week to review.');
    }

    $items = student_materials_reviewer_week_items($classroomId, $weekLabel);
    $sourceText = student_materials_reviewer_build_source_text($items, $classroom, $weekLabel);
    $courseName = trim((string) ($classroom['course_code'] ?? '')) . ' - ' . trim((string) ($classroom['course_name'] ?? ''));
    $excludeNormalized = student_materials_reviewer_normalize_exclude_list($exclude);

    $aiAvailable = wellness_ai_is_enabled();
    $questions = null;
    $source = 'builtin';
    if ($aiAvailable && $items !== []) {
        $questions = student_materials_reviewer_ai_questions($sourceText, $weekLabel, $courseName, $count, $exclude);
        if ($questions !== null) {
            $source = 'ai';
        }
    }

    if ($questions === null) {
        $questions = student_materials_reviewer_builtin_questions($weekLabel, $items, $count, $excludeNormalized);
    }

    $questions = student_materials_reviewer_renumber_questions($questions);

    return [
        'ok' => true,
        'week' => $weekLabel,
        'classroom_id' => $classroomId,
        'course_code' => (string) ($classroom['course_code'] ?? ''),
        'course_name' => (string) ($classroom['course_name'] ?? ''),
        'material_count' => count($items),
        'unlimited' => student_materials_reviewer_unlimited_count($count),
        'source' => $source,
        'ai_available' => $aiAvailable,
        'questions' => $questions,
    ];
}

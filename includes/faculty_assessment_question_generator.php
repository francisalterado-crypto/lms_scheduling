<?php
declare(strict_types=1);

/**
 * Auto-generate assessment questions with answer keys for faculty.
 */

require_once __DIR__ . '/student_materials_reviewer.php';
require_once __DIR__ . '/wellness_ai.php';

/**
 * @return array<string, mixed>|null
 */
function faculty_assessment_generator_fetch_classroom(int $facultyId, int $classroomId): ?array
{
    if ($facultyId < 1 || $classroomId < 1) {
        return null;
    }

    $st = db()->prepare(
        'SELECT oc.id, c.course_code, c.course_name, f.full_name AS faculty_name
         FROM online_classrooms oc
         INNER JOIN schedules s ON s.id = oc.schedule_id
         INNER JOIN courses c ON c.id = oc.course_id
         INNER JOIN faculty f ON f.id = oc.faculty_id
         WHERE oc.id = ? AND oc.faculty_id = ? AND s.faculty_id = ?
         LIMIT 1'
    );
    $st->execute([$classroomId, $facultyId, $facultyId]);
    $row = $st->fetch();

    return is_array($row) ? $row : null;
}

function faculty_assessment_generator_topic(string $title, string $description, string $topicOverride = ''): string
{
    $topic = trim($topicOverride);
    if ($topic !== '') {
        return $topic;
    }

    $title = trim($title);
    if ($title !== '') {
        return $title;
    }

    $plain = student_materials_reviewer_plain_text($description);
    if ($plain !== '') {
        return mb_substr($plain, 0, 120, 'UTF-8');
    }

    return 'this subject';
}

/**
 * @return list<array<string, mixed>>
 */
function faculty_assessment_generator_material_items(int $classroomId, string $weekLabel): array
{
    $weekLabel = trim($weekLabel);
    if ($classroomId < 1 || $weekLabel === '') {
        return [];
    }

    return student_materials_reviewer_week_items($classroomId, $weekLabel);
}

function faculty_assessment_generator_material_snippet(array $item, int $maxChars = 320): string
{
    $attachmentText = trim((string) ($item['attachment_text'] ?? ''));
    if ($attachmentText !== '') {
        return mb_strlen($attachmentText, 'UTF-8') > $maxChars
            ? mb_substr($attachmentText, 0, max(0, $maxChars - 1), 'UTF-8') . '…'
            : $attachmentText;
    }

    $body = student_materials_reviewer_plain_text((string) ($item['body'] ?? ''));
    if ($body === '') {
        return '';
    }

    return mb_strlen($body, 'UTF-8') > $maxChars
        ? mb_substr($body, 0, max(0, $maxChars - 1), 'UTF-8') . '…'
        : $body;
}

function faculty_assessment_generator_count_attachments(array $items): int
{
    $count = 0;
    foreach ($items as $item) {
        $count += max(0, (int) ($item['attachment_count'] ?? 0));
    }

    return $count;
}

function faculty_assessment_generator_build_context(
    array $classroom,
    string $title,
    string $description,
    string $topic,
    string $weekLabel,
    bool $useMaterials
): string {
    $lines = [
        'Course: ' . trim((string) ($classroom['course_code'] ?? '')) . ' - ' . trim((string) ($classroom['course_name'] ?? '')),
        'Assessment title: ' . trim($title),
        'Topic focus: ' . trim($topic),
    ];

    $plainDesc = student_materials_reviewer_plain_text($description);
    if ($plainDesc !== '') {
        $lines[] = 'Instructions: ' . mb_substr($plainDesc, 0, 1500, 'UTF-8');
    }

    if ($useMaterials && trim($weekLabel) !== '') {
        $items = faculty_assessment_generator_material_items((int) ($classroom['id'] ?? 0), $weekLabel);
        if ($items !== []) {
            $lines[] = '';
            $lines[] = '=== Week materials (including attachment content) ===';
            $lines[] = student_materials_reviewer_build_source_text($items, $classroom, $weekLabel);
        }
    }

    $text = trim(implode("\n", $lines));
    if (mb_strlen($text, 'UTF-8') > 8000) {
        $text = mb_substr($text, 0, 7997, 'UTF-8') . '…';
    }

    return $text;
}

/**
 * @param list<array<string, mixed>> $items
 * @param list<string> $exclude
 * @return list<array<string, mixed>>
 */
function faculty_assessment_generator_materials_builtin(
    string $assessmentType,
    string $weekLabel,
    array $items,
    int $count,
    array $exclude = []
): array {
    $type = classroom_assessment_normalize_type($assessmentType);
    $count = max(1, min(20, $count));
    $excludeNorm = student_materials_reviewer_normalize_exclude_list($exclude);
    $pool = [];

    foreach ($items as $item) {
        $title = trim((string) ($item['title'] ?? 'Untitled'));
        if ($title === '') {
            $title = 'Untitled';
        }
        $snippet = faculty_assessment_generator_material_snippet($item);
        $hasAttachment = ((int) ($item['attachment_count'] ?? 0)) > 0;
        $sourceLabel = $hasAttachment ? 'the attachment for "' . $title . '"' : '"' . $title . '"';

        if ($type === 'true_false') {
            if ($snippet !== '') {
                $pool[] = [
                    'question_type' => 'true_false',
                    'question_text' => 'According to ' . $sourceLabel . ' in ' . $weekLabel . ', the posted material covers concepts students must understand for this course.',
                    'points' => 1.0,
                    'options' => [],
                    'answer_key' => 'true',
                    'word_limit' => null,
                    'char_limit' => null,
                    'allow_steps' => 0,
                ];
                $pool[] = [
                    'question_type' => 'true_false',
                    'question_text' => 'Based on ' . $sourceLabel . ', students can skip reviewing the posted content and still fully understand ' . $weekLabel . '.',
                    'points' => 1.0,
                    'options' => [],
                    'answer_key' => 'false',
                    'word_limit' => null,
                    'char_limit' => null,
                    'allow_steps' => 0,
                ];
            } else {
                $pool[] = [
                    'question_type' => 'true_false',
                    'question_text' => 'The instructor posted "' . $title . '" as part of ' . $weekLabel . ' materials.',
                    'points' => 1.0,
                    'options' => [],
                    'answer_key' => 'true',
                    'word_limit' => null,
                    'char_limit' => null,
                    'allow_steps' => 0,
                ];
            }
        } elseif ($type === 'multiple_choice') {
            if ($snippet !== '') {
                $shortSnippet = mb_strlen($snippet, 'UTF-8') > 140
                    ? mb_substr($snippet, 0, 137, 'UTF-8') . '…'
                    : $snippet;
                $pool[] = faculty_assessment_generator_mc_row(
                    'Which statement best reflects the content of ' . $sourceLabel . '?',
                    [
                        $shortSnippet,
                        'The material is unrelated to ' . $weekLabel,
                        'Students are not expected to read ' . $sourceLabel,
                        'The attachment contains no course concepts',
                    ],
                    0
                );
            }
            $pool[] = faculty_assessment_generator_mc_row(
                'Which resource should students review for "' . $title . '" in ' . $weekLabel . '?',
                [
                    'An unrelated website',
                    $hasAttachment ? 'The posted attachment: ' . $title : 'The posted material: ' . $title,
                    'Only the syllabus cover page',
                    'Materials from a different week',
                ],
                1
            );
        } elseif ($type === 'problem_solving') {
            if (preg_match('/\d+(?:\.\d+)?/', $snippet, $m) === 1) {
                $num = (float) $m[0];
                $pool[] = [
                    'question_type' => 'problem_solving',
                    'question_text' => 'Using a value from ' . $sourceLabel . ', compute: ' . $num . ' × 2.',
                    'points' => 1.0,
                    'options' => [],
                    'answer_key' => (string) ($num * 2),
                    'word_limit' => null,
                    'char_limit' => null,
                    'allow_steps' => 1,
                ];
            }
            $pool[] = [
                'question_type' => 'problem_solving',
                'question_text' => 'If a student reviews ' . count($items) . ' posted material(s) for ' . $weekLabel . ', how many materials should they study in 2 review sessions covering the full week?',
                'points' => 1.0,
                'options' => [],
                'answer_key' => (string) count($items),
                'word_limit' => null,
                'char_limit' => null,
                'allow_steps' => 1,
            ];
        } else {
            $pool[] = [
                'question_type' => 'essay',
                'question_text' => $snippet !== ''
                    ? 'Explain the main ideas from ' . $sourceLabel . ' and how they relate to ' . $weekLabel . '.'
                    : 'Summarize the learning goals of "' . $title . '" for ' . $weekLabel . '.',
                'points' => 1.0,
                'options' => [],
                'answer_key' => null,
                'word_limit' => 220,
                'char_limit' => null,
                'allow_steps' => 0,
            ];
            if ($snippet !== '') {
                $pool[] = [
                    'question_type' => 'essay',
                    'question_text' => 'Using ' . $sourceLabel . ', describe one concept students must understand and give an example.',
                    'points' => 1.0,
                    'options' => [],
                    'answer_key' => null,
                    'word_limit' => 200,
                    'char_limit' => null,
                    'allow_steps' => 0,
                ];
            }
        }
    }

    if ($pool === []) {
        return faculty_assessment_generator_builtin($type, $weekLabel, $count, ['id' => 0, 'course_name' => 'the course'], $weekLabel, true);
    }

    $filtered = [];
    foreach ($pool as $row) {
        if (student_materials_reviewer_question_excluded((string) ($row['question_text'] ?? ''), $excludeNorm)) {
            continue;
        }
        $filtered[] = $row;
        $excludeNorm[] = mb_strtolower((string) ($row['question_text'] ?? ''), 'UTF-8');
    }

    if ($filtered === []) {
        return faculty_assessment_generator_builtin($type, $weekLabel, $count, ['id' => 0, 'course_name' => 'the course'], $weekLabel, true);
    }

    $questions = [];
    for ($i = 0; $i < $count; ++$i) {
        $questions[] = $filtered[$i % count($filtered)];
    }

    return $questions;
}

/**
 * @param list<string> $options
 */
function faculty_assessment_generator_mc_key(int $correctIndex): string
{
    return chr(65 + max(0, min(5, $correctIndex)));
}

/**
 * @return list<array{
 *   question_type:string,
 *   question_text:string,
 *   points:float,
 *   options:array<int,string>,
 *   answer_key:?string,
 *   word_limit:?int,
 *   char_limit:?int,
 *   allow_steps:int
 * }>
 */
function faculty_assessment_generator_builtin(
    string $assessmentType,
    string $topic,
    int $count,
    array $classroom,
    string $weekLabel,
    bool $useMaterials
): array {
    $type = classroom_assessment_normalize_type($assessmentType);
    $count = max(1, min(20, $count));
    $courseName = trim((string) ($classroom['course_name'] ?? 'the course'));
    $topicLabel = $topic !== '' ? $topic : $courseName;
    $materialTitles = [];

    if ($useMaterials && trim($weekLabel) !== '') {
        foreach (faculty_assessment_generator_material_items((int) ($classroom['id'] ?? 0), $weekLabel) as $item) {
            $title = trim((string) ($item['title'] ?? ''));
            if ($title !== '') {
                $materialTitles[] = $title;
            }
        }
    }

    $questions = [];
    $templates = faculty_assessment_generator_builtin_templates($type, $topicLabel, $courseName, $materialTitles, $weekLabel);

    for ($i = 0; $i < $count; ++$i) {
        $template = $templates[$i % count($templates)];
        $questions[] = $template;
    }

    return $questions;
}

/**
 * @param list<string> $materialTitles
 * @return list<array<string, mixed>>
 */
function faculty_assessment_generator_builtin_templates(
    string $type,
    string $topic,
    string $courseName,
    array $materialTitles,
    string $weekLabel
): array {
    $material = $materialTitles[0] ?? $topic;
    $secondMaterial = $materialTitles[1] ?? $material;

    if ($type === 'true_false') {
        return [
            [
                'question_type' => 'true_false',
                'question_text' => 'The topic "' . $topic . '" is part of the learning goals in ' . $courseName . '.',
                'points' => 1.0,
                'options' => [],
                'answer_key' => 'true',
                'word_limit' => null,
                'char_limit' => null,
                'allow_steps' => 0,
            ],
            [
                'question_type' => 'true_false',
                'question_text' => 'Students can ignore the core concepts of "' . $topic . '" and still master ' . $courseName . '.',
                'points' => 1.0,
                'options' => [],
                'answer_key' => 'false',
                'word_limit' => null,
                'char_limit' => null,
                'allow_steps' => 0,
            ],
            [
                'question_type' => 'true_false',
                'question_text' => 'Understanding "' . $material . '" helps explain ideas covered in "' . $topic . '".',
                'points' => 1.0,
                'options' => [],
                'answer_key' => 'true',
                'word_limit' => null,
                'char_limit' => null,
                'allow_steps' => 0,
            ],
            [
                'question_type' => 'true_false',
                'question_text' => 'The assessment on "' . $topic . '" requires no preparation from course materials.',
                'points' => 1.0,
                'options' => [],
                'answer_key' => 'false',
                'word_limit' => null,
                'char_limit' => null,
                'allow_steps' => 0,
            ],
        ];
    }

    if ($type === 'multiple_choice') {
        return [
            faculty_assessment_generator_mc_row(
                'Which option best describes the main focus of "' . $topic . '"?',
                [
                    'A broad overview unrelated to ' . $courseName,
                    'The core concepts and skills for "' . $topic . '"',
                    'Only administrative policies',
                    'Topics from a different course entirely',
                ],
                1
            ),
            faculty_assessment_generator_mc_row(
                'What should students review first when preparing for "' . $topic . '"?',
                [
                    'Random internet sources only',
                    'Posted materials and notes on "' . $material . '"',
                    'Skip all readings',
                    'Wait until after the due date',
                ],
                1
            ),
            faculty_assessment_generator_mc_row(
                'In ' . $courseName . ', "' . $topic . '" is best understood by connecting it to:',
                [
                    'Unrelated hobbies',
                    'Prior lessons and "' . $secondMaterial . '"',
                    'Only the syllabus header',
                    'Nothing else in the course',
                ],
                1
            ),
            faculty_assessment_generator_mc_row(
                'Which study strategy fits an assessment on "' . $topic . '"?',
                [
                    'Memorize labels without understanding',
                    'Practice explaining key ideas in your own words',
                    'Avoid all practice questions',
                    'Copy answers without reading the prompt',
                ],
                1
            ),
        ];
    }

    if ($type === 'problem_solving') {
        $weekNum = 1;
        if (preg_match('/(\d+)/', $weekLabel, $m) === 1) {
            $weekNum = max(1, (int) $m[1]);
        }

        return [
            [
                'question_type' => 'problem_solving',
                'question_text' => 'If a class covers ' . $weekNum . ' topics per week on "' . $topic . '", how many topics are covered in 4 weeks?',
                'points' => 1.0,
                'options' => [],
                'answer_key' => (string) ($weekNum * 4),
                'word_limit' => null,
                'char_limit' => null,
                'allow_steps' => 1,
            ],
            [
                'question_type' => 'problem_solving',
                'question_text' => 'A review session for "' . $material . '" lasts 45 minutes. How many minutes are used in 3 sessions?',
                'points' => 1.0,
                'options' => [],
                'answer_key' => '135',
                'word_limit' => null,
                'char_limit' => null,
                'allow_steps' => 1,
            ],
            [
                'question_type' => 'problem_solving',
                'question_text' => 'Solve for x: 2x + 6 = 18. This models a two-step process in "' . $topic . '".',
                'points' => 1.0,
                'options' => [],
                'answer_key' => '6',
                'word_limit' => null,
                'char_limit' => null,
                'allow_steps' => 1,
            ],
            [
                'question_type' => 'problem_solving',
                'question_text' => 'A dataset has values 4, 8, and 12. What is the mean?',
                'points' => 1.0,
                'options' => [],
                'answer_key' => '8',
                'word_limit' => null,
                'char_limit' => null,
                'allow_steps' => 1,
            ],
        ];
    }

    return [
        [
            'question_type' => 'essay',
            'question_text' => 'Explain the main ideas of "' . $topic . '" and why they matter in ' . $courseName . '.',
            'points' => 1.0,
            'options' => [],
            'answer_key' => null,
            'word_limit' => 250,
            'char_limit' => null,
            'allow_steps' => 0,
        ],
        [
            'question_type' => 'essay',
            'question_text' => 'How does "' . $material . '" connect to the learning goals for "' . $topic . '"?',
            'points' => 1.0,
            'options' => [],
            'answer_key' => null,
            'word_limit' => 200,
            'char_limit' => null,
            'allow_steps' => 0,
        ],
        [
            'question_type' => 'essay',
            'question_text' => 'Give a real-world example that illustrates a key concept from "' . $topic . '".',
            'points' => 1.0,
            'options' => [],
            'answer_key' => null,
            'word_limit' => 180,
            'char_limit' => null,
            'allow_steps' => 0,
        ],
        [
            'question_type' => 'essay',
            'question_text' => 'What is still unclear to you about "' . $topic . '", and how would you clarify it using course materials?',
            'points' => 1.0,
            'options' => [],
            'answer_key' => null,
            'word_limit' => 150,
            'char_limit' => null,
            'allow_steps' => 0,
        ],
    ];
}

/**
 * @param list<string> $options
 * @return array<string, mixed>
 */
function faculty_assessment_generator_mc_row(string $questionText, array $options, int $correctIndex): array
{
    $options = array_values(array_slice($options, 0, 6));
    while (count($options) < 4) {
        $options[] = 'Option ' . chr(65 + count($options));
    }

    return [
        'question_type' => 'multiple_choice',
        'question_text' => $questionText,
        'points' => 1.0,
        'options' => $options,
        'answer_key' => faculty_assessment_generator_mc_key($correctIndex),
        'word_limit' => null,
        'char_limit' => null,
        'allow_steps' => 0,
    ];
}

/**
 * @param list<string> $exclude
 * @return list<array<string, mixed>>
 */
function faculty_assessment_generator_parse_ai(string $raw, string $assessmentType, int $maxCount, array $exclude = []): array
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

    $expectedType = classroom_assessment_normalize_type($assessmentType);
    $excludeNorm = student_materials_reviewer_normalize_exclude_list($exclude);
    $out = [];

    foreach ($decoded['questions'] as $row) {
        if (count($out) >= $maxCount || !is_array($row)) {
            continue;
        }

        $questionText = trim((string) ($row['question_text'] ?? $row['question'] ?? ''));
        if ($questionText === '' || student_materials_reviewer_question_excluded($questionText, $excludeNorm)) {
            continue;
        }

        $type = classroom_question_normalize_type((string) ($row['question_type'] ?? $expectedType));
        if ($type !== $expectedType) {
            $type = $expectedType;
        }

        $points = (float) ($row['points'] ?? 1);
        if ($points <= 0) {
            $points = 1.0;
        }

        $options = [];
        $rawOptions = $row['options'] ?? $row['choices'] ?? [];
        if (is_array($rawOptions)) {
            foreach ($rawOptions as $opt) {
                $text = trim((string) $opt);
                if ($text !== '') {
                    $options[] = $text;
                }
            }
        }

        $answerKey = trim((string) ($row['answer_key'] ?? $row['answer'] ?? ''));
        $wordLimit = isset($row['word_limit']) && (string) $row['word_limit'] !== '' ? max(0, (int) $row['word_limit']) : null;
        $charLimit = isset($row['char_limit']) && (string) $row['char_limit'] !== '' ? max(0, (int) $row['char_limit']) : null;
        $allowSteps = !empty($row['allow_steps']) ? 1 : 0;

        if ($type === 'multiple_choice') {
            if (count($options) < 2) {
                continue;
            }
            $options = array_slice($options, 0, 6);
            $answerKey = strtoupper($answerKey);
            if (!preg_match('/^[A-F]$/', $answerKey)) {
                $answerKey = 'A';
            }
            $maxLetterIndex = count($options) - 1;
            $letterIndex = ord($answerKey) - 65;
            if ($letterIndex < 0 || $letterIndex > $maxLetterIndex) {
                $answerKey = faculty_assessment_generator_mc_key(0);
            }
        } elseif ($type === 'true_false') {
            $keyNorm = strtolower($answerKey);
            if (!in_array($keyNorm, ['true', 'false'], true)) {
                continue;
            }
            $answerKey = $keyNorm;
            $options = [];
        } elseif ($type === 'problem_solving') {
            if ($answerKey === '') {
                continue;
            }
            $options = [];
        } else {
            $answerKey = null;
            $options = [];
            if ($wordLimit === null) {
                $wordLimit = 200;
            }
        }

        $out[] = [
            'question_type' => $type,
            'question_text' => $questionText,
            'points' => $points,
            'options' => $options,
            'answer_key' => $answerKey,
            'word_limit' => $wordLimit,
            'char_limit' => $charLimit,
            'allow_steps' => $type === 'problem_solving' ? ($allowSteps ?: 1) : 0,
        ];
        $excludeNorm[] = mb_strtolower($questionText, 'UTF-8');
    }

    return $out;
}

/**
 * @param list<string> $exclude
 * @return list<array<string, mixed>>|null
 */
function faculty_assessment_generator_ai(
    string $sourceText,
    string $assessmentType,
    string $topic,
    int $count,
    array $exclude = [],
    bool $fromWeekMaterials = false,
    string $weekLabel = ''
): ?array {
    if (!wellness_ai_is_enabled() || trim($sourceText) === '') {
        return null;
    }

    $type = classroom_assessment_normalize_type($assessmentType);
    $count = max(1, min(20, $count));
    $typeLabel = classroom_assessment_type_label($type);

    $shapeHint = match ($type) {
        'multiple_choice' => 'For multiple_choice: provide exactly 4 options (plain text, no A/B prefix) and answer_key as a single letter A-D matching the correct option index.',
        'true_false' => 'For true_false: answer_key must be "true" or "false". No options array.',
        'problem_solving' => 'For problem_solving: provide a concise numeric or expression answer_key (e.g. 12, 3.14, x=5). Set allow_steps to 1.',
        default => 'For essay: no answer_key. Set word_limit around 150-300.',
    };

    $excludeBlock = '';
    if ($exclude !== []) {
        $sample = array_slice($exclude, -30);
        $excludeBlock = "\nDo NOT repeat or closely rephrase these existing questions:\n- "
            . implode("\n- ", array_map(static fn (string $q): string => mb_substr($q, 0, 200, 'UTF-8'), $sample));
    }

    $materialsRules = '';
    if ($fromWeekMaterials) {
        $weekFocus = $weekLabel !== '' ? $weekLabel : 'the selected week';
        $materialsRules = <<<RULES

IMPORTANT — Week materials mode:
- Base every question ONLY on the posted week materials and extracted attachment text in the context below.
- Prefer facts, definitions, examples, and concepts found in attachments (PDF, DOCX, slides, text files) over generic course wording.
- Reference specific material titles when helpful.
- Do not invent content that is not supported by {$weekFocus} materials.
RULES;
    }

    $system = <<<PROMPT
You create assessment questions for a university instructor. All questions must be type "{$type}" ({$typeLabel}).

Topic: {$topic}

Rules:
- Generate exactly {$count} questions grounded in the provided context.
- Every question must include question_type "{$type}" and points (use 1 unless the prompt suggests otherwise).
- {$shapeHint}{$materialsRules}
- Return ONLY valid JSON (no markdown fences) with this shape:
{"questions":[{"question_type":"{$type}","question_text":"...","points":1,"options":[],"answer_key":"...","word_limit":null,"char_limit":null,"allow_steps":0}]}
PROMPT;

    $user = ($fromWeekMaterials ? "Week materials (body + attachments):\n\n" : "Assessment context:\n\n") . $sourceText . $excludeBlock;
    $raw = student_materials_reviewer_ai_call($system, $user, $count > 8);
    if ($raw === null || trim($raw) === '') {
        return null;
    }

    $parsed = faculty_assessment_generator_parse_ai($raw, $type, $count, $exclude);

    return $parsed !== [] ? $parsed : null;
}

/**
 * @param list<string> $exclude
 * @return array{
 *   ok: bool,
 *   source: string,
 *   ai_available: bool,
 *   material_count: int,
 *   attachment_count: int,
 *   credited_week: string,
 *   from_week_materials: bool,
 *   questions: list<array<string, mixed>>
 * }
 */
function faculty_assessment_generator_generate(
    int $facultyId,
    int $classroomId,
    string $assessmentType,
    string $title,
    string $description,
    int $count,
    string $topicOverride = '',
    string $weekLabel = '',
    bool $useMaterials = false,
    array $exclude = []
): array {
    $classroom = faculty_assessment_generator_fetch_classroom($facultyId, $classroomId);
    if ($classroom === null) {
        throw new RuntimeException('Classroom not found or you do not have access to it.');
    }

    $type = classroom_assessment_normalize_type($assessmentType);
    $count = max(1, min(20, $count));
    $weekLabel = trim($weekLabel);
    $fromWeekMaterials = $useMaterials && $weekLabel !== '';
    $materialItems = [];
    $materialCount = 0;
    $attachmentCount = 0;

    if ($fromWeekMaterials) {
        $materialItems = faculty_assessment_generator_material_items($classroomId, $weekLabel);
        $materialCount = count($materialItems);
        $attachmentCount = faculty_assessment_generator_count_attachments($materialItems);

        if ($materialCount === 0) {
            throw new RuntimeException(
                'No materials are posted for ' . $weekLabel . '. Upload week materials first, or generate from the assessment title and description only.'
            );
        }
    }

    $topic = $fromWeekMaterials && trim($topicOverride) === ''
        ? $weekLabel
        : faculty_assessment_generator_topic($title, $description, $topicOverride);

    $sourceText = faculty_assessment_generator_build_context(
        $classroom,
        $title,
        $description,
        $topic,
        $weekLabel,
        $fromWeekMaterials
    );

    $aiAvailable = wellness_ai_is_enabled();
    $questions = null;
    $source = 'builtin';

    if ($aiAvailable) {
        $questions = faculty_assessment_generator_ai(
            $sourceText,
            $type,
            $topic,
            $count,
            $exclude,
            $fromWeekMaterials,
            $weekLabel
        );
        if ($questions !== null) {
            $source = 'ai';
        }
    }

    if ($questions === null) {
        if ($fromWeekMaterials) {
            $questions = faculty_assessment_generator_materials_builtin($type, $weekLabel, $materialItems, $count, $exclude);
            $source = 'materials';
        } else {
            $questions = faculty_assessment_generator_builtin($type, $topic, $count, $classroom, $weekLabel, $useMaterials);
        }
    }

    return [
        'ok' => true,
        'source' => $source,
        'ai_available' => $aiAvailable,
        'material_count' => $materialCount,
        'attachment_count' => $attachmentCount,
        'credited_week' => $weekLabel,
        'from_week_materials' => $fromWeekMaterials,
        'questions' => $questions,
    ];
}

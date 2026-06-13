<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** @return string[] */
function schedule_days_list(): array
{
    return ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
}

function sql_scope_for_college(string $alias, ?int $collegeId, bool $adminSeesAll = true): array
{
    if ($adminSeesAll && function_exists('is_admin') && is_admin()) {
        return ['sql' => '', 'params' => []];
    }
    if ($collegeId) {
        return ['sql' => " AND {$alias}.college_id = ? ", 'params' => [$collegeId]];
    }
    return ['sql' => ' AND 1 = 0 ', 'params' => []];
}

/**
 * Suggest available usernames based on a preferred value.
 * @return string[]
 */
function suggest_available_usernames(string $preferred, int $limit = 3): array
{
    $base = strtolower(trim($preferred));
    $base = preg_replace('/[^a-z0-9._-]+/', '', $base ?? '');
    if ($base === '') {
        $base = 'user';
    }

    $st = db()->prepare('SELECT username FROM users WHERE username LIKE ?');
    $st->execute([$base . '%']);
    $existing = array_fill_keys(array_map('strtolower', $st->fetchAll(PDO::FETCH_COLUMN)), true);

    $suggestions = [];
    if (!isset($existing[$base])) {
        $suggestions[] = $base;
    }

    $n = 1;
    while (count($suggestions) < $limit && $n < 1000) {
        $candidate = $base . $n;
        if (!isset($existing[$candidate])) {
            $suggestions[] = $candidate;
        }
        $n++;
    }
    return $suggestions;
}

/**
 * Suggest available course codes within a scope.
 * Scope is college-specific for dean flows, or GE-specific for gened flows.
 *
 * @return string[]
 */
function suggest_available_course_codes(
    string $preferred,
    ?int $collegeId = null,
    bool $isGened = false,
    int $limit = 3
): array {
    $base = strtoupper(trim($preferred));
    $base = preg_replace('/[^A-Z0-9._-]+/', '', $base ?? '');
    if ($base === '') {
        $base = 'COURSE';
    }

    if ($isGened) {
        $st = db()->prepare('SELECT course_code FROM courses WHERE COALESCE(is_gened,0)=1 AND course_code LIKE ?');
        $st->execute([$base . '%']);
    } else {
        $st = db()->prepare('SELECT course_code FROM courses WHERE college_id = ? AND course_code LIKE ?');
        $st->execute([$collegeId, $base . '%']);
    }
    $existing = array_fill_keys(array_map('strtoupper', $st->fetchAll(PDO::FETCH_COLUMN)), true);

    $suggestions = [];
    if (!isset($existing[$base])) {
        $suggestions[] = $base;
    }

    $n = 1;
    while (count($suggestions) < $limit && $n < 1000) {
        $candidate = $base . sprintf('%02d', $n);
        if (!isset($existing[$candidate])) {
            $suggestions[] = $candidate;
        }
        $n++;
    }
    return $suggestions;
}

function db_column_exists(string $table, string $column): bool
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([DB_NAME, $table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Return the first existing column name from a preferred list, else fallback.
 */
function db_first_existing_column(string $table, array $preferredColumns, string $fallback): string
{
    foreach ($preferredColumns as $column) {
        if (is_string($column) && $column !== '' && db_column_exists($table, $column)) {
            return $column;
        }
    }
    return $fallback;
}

/** Normalize a classroom join code for lookup (alphanumeric only, uppercase). */
function classroom_normalize_join_code(string $raw): string
{
    $clean = preg_replace('/[^A-Za-z0-9]+/', '', $raw);

    return strtoupper($clean === null ? '' : $clean);
}

/**
 * Allocate a short unique join code for online_classrooms.join_code.
 * @throws RuntimeException
 */
function classroom_alloc_unique_join_code(): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $st = db()->prepare('SELECT COUNT(*) FROM online_classrooms WHERE join_code = ?');
        $st->execute([$code]);
        if ((int) $st->fetchColumn() === 0) {
            return $code;
        }
    }
    throw new RuntimeException('Could not allocate a unique class code. Try again.');
}

/**
 * Unique code for courses.classroom_code (Program Chair subject code).
 * Avoids collision with online_classrooms.join_code so student join-by-code stays unambiguous.
 *
 * @throws RuntimeException
 */
function course_alloc_unique_classroom_code(): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $st = db()->prepare('SELECT COUNT(*) FROM courses WHERE classroom_code = ?');
        $st->execute([$code]);
        if ((int) $st->fetchColumn() > 0) {
            continue;
        }
        if (db_table_exists('online_classrooms') && db_column_exists('online_classrooms', 'join_code')) {
            $stOc = db()->prepare('SELECT COUNT(*) FROM online_classrooms WHERE join_code = ?');
            $stOc->execute([$code]);
            if ((int) $stOc->fetchColumn() > 0) {
                continue;
            }
        }
        return $code;
    }
    throw new RuntimeException('Could not allocate a unique classroom code. Try again.');
}

function db_table_exists(string $table): bool
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
    );
    $stmt->execute([DB_NAME, $table]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Ensure a program name exists for a college (e.g. "General Education" for GE program chairs).
 */
function ensure_college_program_name(int $collegeId, string $programName): void
{
    if (!db_table_exists('programs') || $programName === '') {
        return;
    }
    $st = db()->prepare('SELECT id FROM programs WHERE college_id=? AND program_name=? LIMIT 1');
    $st->execute([$collegeId, $programName]);
    if ($st->fetch()) {
        return;
    }
    db()->prepare('INSERT INTO programs (college_id, program_name, status) VALUES (?,?,?)')
        ->execute([$collegeId, $programName, 'active']);
}

/**
 * @return array<string, list<string>>
 */
function dean_program_year_levels_map(?int $collegeId): array
{
    if ($collegeId === null || $collegeId < 1 || !db_table_exists('programs_year_levels')) {
        return [];
    }
    $st = db()->prepare(
        'SELECT p.program_name, pyl.year_level
         FROM programs_year_levels pyl
         INNER JOIN programs p ON p.id = pyl.program_id
         WHERE p.college_id = ?
         ORDER BY p.program_name, pyl.year_level'
    );
    $st->execute([$collegeId]);
    $out = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $pn = (string) ($row['program_name'] ?? '');
        $yl = trim((string) ($row['year_level'] ?? ''));
        if ($pn === '' || $yl === '') {
            continue;
        }
        if (!isset($out[$pn])) {
            $out[$pn] = [];
        }
        if (!in_array($yl, $out[$pn], true)) {
            $out[$pn][] = $yl;
        }
    }
    return $out;
}

/**
 * Normalize year-level labels for predictable ordering (1, 2… then others alphabetically).
 * @param list<string> $levels
 * @return list<string>
 */
function sort_schedule_year_levels(array $levels): array
{
    $uniq = [];
    foreach ($levels as $l) {
        $s = trim((string) $l);
        if ($s !== '' && !in_array($s, $uniq, true)) {
            $uniq[] = $s;
        }
    }
    usort(
        $uniq,
        static function (string $a, string $b): int {
            $ad = ctype_digit($a);
            $bd = ctype_digit($b);
            if ($ad && $bd) {
                return (int) $a <=> (int) $b;
            }
            if ($ad !== $bd) {
                return $ad ? -1 : 1;
            }
            return strcmp($a, $b);
        }
    );
    /** @var list<string> */
    return $uniq;
}

/** @return list<string> */
function program_defined_year_levels(int $programId): array
{
    if ($programId < 1 || !db_table_exists('programs_year_levels')) {
        return [];
    }
    $st = db()->prepare('SELECT year_level FROM programs_year_levels WHERE program_id=?');
    $st->execute([$programId]);
    $raw = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) {
        $raw[] = (string) $v;
    }
    return sort_schedule_year_levels($raw);
}

/**
 * Replace dean-configured year levels for programs.id (no-op when table absent).
 *
 * @param list<string>|array<int|string,string> $levels
 */
function program_year_levels_replace(int $programId, array $levels): void
{
    if (!db_table_exists('programs_year_levels') || $programId < 1) {
        return;
    }
    $pdo = db();
    $pdo->prepare('DELETE FROM programs_year_levels WHERE program_id=?')->execute([$programId]);
    $ins = $pdo->prepare('INSERT INTO programs_year_levels (program_id, year_level) VALUES (?,?)');
    $seen = [];
    foreach ($levels as $l) {
        $s = trim((string) $l);
        if ($s === '' || strlen($s) > 20 || isset($seen[$s])) {
            continue;
        }
        $seen[$s] = true;
        $ins->execute([$programId, $s]);
    }
}

/**
 * Parsed year levels from Programs form (checkboxes + optional comma list).
 *
 * @return list<string>
 */
function parse_dean_program_year_levels_post(array $post): array
{
    $levels = [];
    $raw = $post['program_year_levels'] ?? null;
    if (is_array($raw)) {
        foreach ($raw as $v) {
            $s = trim((string) $v);
            if ($s !== '' && strlen($s) <= 20) {
                $levels[] = $s;
            }
        }
    }
    $extra = trim((string) ($post['program_year_level_extra'] ?? ''));
    if ($extra !== '') {
        foreach (preg_split('/[,;]\s*/', $extra, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            $p = trim($part);
            if ($p !== '' && strlen($p) <= 20) {
                $levels[] = $p;
            }
        }
    }
    return sort_schedule_year_levels($levels);
}

function classroom_assessment_normalize_type(string $type): string
{
    return match (trim(strtolower($type))) {
        'multiple_choice' => 'multiple_choice',
        'true_false' => 'true_false',
        'problem_solving', 'performance_task' => 'problem_solving',
        'essay', 'assignment', 'written_work' => 'essay',
        'quiz' => 'multiple_choice',
        default => 'essay',
    };
}

function classroom_assessment_type_label(string $type): string
{
    return match (classroom_assessment_normalize_type($type)) {
        'multiple_choice' => 'Multiple Choice',
        'true_false' => 'True or False',
        'problem_solving' => 'Problem Solving',
        default => 'Essay',
    };
}

function classroom_assessment_type_badge_class(string $type): string
{
    return match (classroom_assessment_normalize_type($type)) {
        'multiple_choice' => 'text-bg-primary',
        'true_false' => 'text-bg-info',
        'problem_solving' => 'text-bg-success',
        default => 'text-bg-secondary',
    };
}

/** @return string[] */
function classroom_question_type_list(): array
{
    return ['multiple_choice', 'true_false', 'essay', 'problem_solving'];
}

function classroom_question_type_label(string $type): string
{
    return match (trim(strtolower($type))) {
        'multiple_choice' => 'Multiple Choice',
        'true_false' => 'True or False',
        'essay' => 'Essay',
        'problem_solving' => 'Problem Solving',
        default => 'Question',
    };
}

function classroom_question_normalize_type(string $type): string
{
    $type = trim(strtolower($type));
    return in_array($type, classroom_question_type_list(), true) ? $type : 'essay';
}

function classroom_problem_answer_normalize(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $value = preg_replace('/\s+/u', '', $value) ?? '';
    $value = strtolower($value);
    if (preg_match('/^-?\d+(?:\.\d+)?$/', $value) === 1) {
        $value = rtrim(rtrim($value, '0'), '.');
    }
    return $value;
}

function classroom_problem_answer_matches(string $answer, string $key): bool
{
    return classroom_problem_answer_normalize($answer) === classroom_problem_answer_normalize($key);
}

/**
 * @param array<string,mixed> $question
 * @param array<string,mixed> $payload
 * @return array{answer_text:string,answer_steps:?string,is_correct:?int,auto_score:?float,requires_manual:bool}
 */
function classroom_grade_question_submission(array $question, array $payload): array
{
    $type = classroom_question_normalize_type((string) ($question['question_type'] ?? 'essay'));
    $points = (float) ($question['points'] ?? 0);
    $answerRaw = trim((string) ($payload['answer_text'] ?? ''));
    $stepsRaw = trim((string) ($payload['answer_steps'] ?? ''));
    $keyRaw = trim((string) ($question['answer_key'] ?? ''));

    if ($type === 'multiple_choice') {
        $ok = $answerRaw !== '' && strcasecmp($answerRaw, $keyRaw) === 0;
        return [
            'answer_text' => $answerRaw,
            'answer_steps' => $stepsRaw !== '' ? $stepsRaw : null,
            'is_correct' => $ok ? 1 : 0,
            'auto_score' => $ok ? $points : 0.0,
            'requires_manual' => false,
        ];
    }

    if ($type === 'true_false') {
        $answerNorm = strtolower($answerRaw) === 'true' ? 'true' : (strtolower($answerRaw) === 'false' ? 'false' : '');
        $keyNorm = strtolower($keyRaw) === 'true' ? 'true' : (strtolower($keyRaw) === 'false' ? 'false' : '');
        $ok = $answerNorm !== '' && $keyNorm !== '' && $answerNorm === $keyNorm;
        return [
            'answer_text' => $answerNorm,
            'answer_steps' => $stepsRaw !== '' ? $stepsRaw : null,
            'is_correct' => $ok ? 1 : 0,
            'auto_score' => $ok ? $points : 0.0,
            'requires_manual' => false,
        ];
    }

    if ($type === 'problem_solving') {
        $ok = $answerRaw !== '' && $keyRaw !== '' && classroom_problem_answer_matches($answerRaw, $keyRaw);
        return [
            'answer_text' => $answerRaw,
            'answer_steps' => $stepsRaw !== '' ? $stepsRaw : null,
            'is_correct' => $ok ? 1 : 0,
            'auto_score' => $ok ? $points : 0.0,
            'requires_manual' => false,
        ];
    }

    return [
        'answer_text' => $answerRaw,
        'answer_steps' => $stepsRaw !== '' ? $stepsRaw : null,
        'is_correct' => null,
        'auto_score' => null,
        'requires_manual' => true,
    ];
}

function classroom_content_attachment_dir(): string
{
    return BASE_PATH . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'classroom_content';
}

function classroom_content_attachment_relative_dir(): string
{
    return 'uploads/classroom_content';
}

function classroom_content_is_attachment(string $resourceUrl): bool
{
    $resourceUrl = trim($resourceUrl);
    if ($resourceUrl === '') {
        return false;
    }

    $normalized = str_replace('\\', '/', $resourceUrl);
    $prefix = classroom_content_attachment_relative_dir() . '/';
    return str_starts_with($normalized, $prefix);
}

function classroom_content_attachment_path(string $resourceUrl): string
{
    return classroom_content_attachment_dir() . DIRECTORY_SEPARATOR . basename(str_replace('\\', '/', trim($resourceUrl)));
}

function classroom_content_attachment_storage_path(string $storedName): string
{
    return classroom_content_attachment_dir() . DIRECTORY_SEPARATOR . basename($storedName);
}

function classroom_content_attachment_name(string $resourceUrl): string
{
    $name = basename(str_replace('\\', '/', trim($resourceUrl)));
    $parts = explode('__', $name, 2);
    $downloadName = $parts[1] ?? $name;
    $downloadName = trim($downloadName);
    return $downloadName !== '' ? $downloadName : 'attachment';
}

function classroom_content_resource_href(int $contentId, string $resourceUrl): string
{
    if (classroom_content_is_attachment($resourceUrl)) {
        return 'classroom_content_attachment.php?id=' . $contentId;
    }

    return $resourceUrl;
}

function classroom_content_attachment_href(int $attachmentId): string
{
    return 'classroom_content_attachment.php?attachment_id=' . $attachmentId;
}

/** Open syllabus for a classroom (auth enforced in classroom_syllabus.php). */
function classroom_syllabus_href(int $classroomId): string
{
    return 'classroom_syllabus.php?id=' . $classroomId;
}

function classroom_content_attachment_download_name(string $originalName, string $storedName = ''): string
{
    $name = trim($originalName);
    if ($name !== '') {
        return $name;
    }

    $storedName = basename(trim($storedName));
    if ($storedName === '') {
        return 'attachment';
    }

    $parts = explode('__', $storedName, 2);
    $fallback = trim($parts[1] ?? $storedName);
    return $fallback !== '' ? $fallback : 'attachment';
}

/**
 * @param array<string,mixed> $files
 * @return list<array{name:string,type:string,tmp_name:string,error:int,size:int}>
 */
function classroom_content_normalize_uploads(array $files): array
{
    $normalized = [];
    $names = $files['name'] ?? null;

    if (is_array($names)) {
        $count = count($names);
        for ($i = 0; $i < $count; $i++) {
            $normalized[] = [
                'name' => (string) ($files['name'][$i] ?? ''),
                'type' => (string) ($files['type'][$i] ?? ''),
                'tmp_name' => (string) ($files['tmp_name'][$i] ?? ''),
                'error' => (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int) ($files['size'][$i] ?? 0),
            ];
        }

        return $normalized;
    }

    if ($names === null) {
        return [];
    }

    return [[
        'name' => (string) ($files['name'] ?? ''),
        'type' => (string) ($files['type'] ?? ''),
        'tmp_name' => (string) ($files['tmp_name'] ?? ''),
        'error' => (int) ($files['error'] ?? UPLOAD_ERR_NO_FILE),
        'size' => (int) ($files['size'] ?? 0),
    ]];
}

/**
 * @param array<string,mixed> $file
 * @return string|null
 */
function classroom_content_store_attachment(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Attachment upload failed.');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size < 1) {
        throw new RuntimeException('Attachment is empty.');
    }
    if ($size > 10 * 1024 * 1024) {
        throw new RuntimeException('Attachment is too large (max 10 MB).');
    }

    $original = trim((string) ($file['name'] ?? ''));
    if ($original === '') {
        throw new RuntimeException('Invalid attachment name.');
    }

    $original = str_replace(["\r", "\n"], '', basename($original));
    $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'jpg', 'jpeg', 'png'];
    if (!in_array($extension, $allowed, true)) {
        throw new RuntimeException('Unsupported attachment type.');
    }

    $baseName = pathinfo($original, PATHINFO_FILENAME);
    $safeBaseName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $baseName) ?? '';
    $safeBaseName = trim($safeBaseName, '._-');
    if ($safeBaseName === '') {
        $safeBaseName = 'attachment';
    }
    $safeBaseName = substr($safeBaseName, 0, 80);

    $downloadName = $safeBaseName . ($extension !== '' ? '.' . $extension : '');
    $storedName = bin2hex(random_bytes(16)) . '__' . $downloadName;

    $dir = classroom_content_attachment_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create attachment directory.');
    }

    $destination = $dir . DIRECTORY_SEPARATOR . $storedName;
    if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $destination)) {
        throw new RuntimeException('Failed to save attachment.');
    }

    return classroom_content_attachment_relative_dir() . '/' . $storedName;
}

/**
 * @param array<string,mixed> $files
 * @return list<array{original_name:string,stored_name:string,mime:string}>
 */
function classroom_content_store_attachments(array $files): array
{
    $attachments = [];
    foreach (classroom_content_normalize_uploads($files) as $file) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $resourceUrl = classroom_content_store_attachment($file);
        if ($resourceUrl === null) {
            continue;
        }

        $attachments[] = [
            'original_name' => classroom_content_attachment_name($resourceUrl),
            'stored_name' => basename(str_replace('\\', '/', $resourceUrl)),
            'mime' => trim((string) ($file['type'] ?? 'application/octet-stream')) ?: 'application/octet-stream',
        ];
    }

    return $attachments;
}

/**
 * @param array<int,mixed> $contentIds
 * @return array<int,list<array{id:int,content_id:int,original_name:string,stored_name:string,mime:string}>>
 */
function classroom_content_attachment_map(array $contentIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $contentIds), static fn (int $id): bool => $id > 0)));
    if ($ids === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare(
        "SELECT id, content_id, original_name, stored_name, mime
         FROM classroom_content_attachments
         WHERE content_id IN ($placeholders)
         ORDER BY created_at ASC, id ASC"
    );
    $st->execute($ids);

    $map = [];
    foreach ($st->fetchAll() as $row) {
        $contentId = (int) ($row['content_id'] ?? 0);
        if ($contentId < 1) {
            continue;
        }

        $map[$contentId][] = [
            'id' => (int) ($row['id'] ?? 0),
            'content_id' => $contentId,
            'original_name' => (string) ($row['original_name'] ?? ''),
            'stored_name' => (string) ($row['stored_name'] ?? ''),
            'mime' => (string) ($row['mime'] ?? ''),
        ];
    }

    return $map;
}

function classroom_content_extract_alignment(string $style): string
{
    if (preg_match('/text-align\s*:\s*(left|center|right|justify)/i', $style, $matches) !== 1) {
        return '';
    }

    return strtolower((string) ($matches[1] ?? ''));
}

function classroom_content_sanitize_html_node(DOMNode $node, DOMDocument $doc): ?DOMNode
{
    if ($node instanceof DOMText) {
        return $doc->createTextNode($node->nodeValue);
    }

    if (!($node instanceof DOMElement)) {
        return null;
    }

    $tag = strtolower($node->tagName);
    $allowedTags = [
        'p' => true,
        'div' => true,
        'br' => true,
        'strong' => true,
        'b' => true,
        'em' => true,
        'i' => true,
        'u' => true,
        's' => true,
        'ul' => true,
        'ol' => true,
        'li' => true,
        'blockquote' => true,
        'a' => true,
        'h2' => true,
        'h3' => true,
    ];

    if (!isset($allowedTags[$tag])) {
        $fragment = $doc->createDocumentFragment();
        foreach ($node->childNodes as $child) {
            $cleanChild = classroom_content_sanitize_html_node($child, $doc);
            if ($cleanChild !== null) {
                $fragment->appendChild($cleanChild);
            }
        }

        return $fragment;
    }

    $clean = $doc->createElement($tag);

    if ($tag === 'a') {
        $href = trim((string) $node->getAttribute('href'));
        $validatedHref = filter_var($href, FILTER_VALIDATE_URL);
        $scheme = strtolower((string) (parse_url((string) $validatedHref, PHP_URL_SCHEME) ?? ''));
        if ($validatedHref !== false && in_array($scheme, ['http', 'https'], true)) {
            $clean->setAttribute('href', $validatedHref);
            $clean->setAttribute('target', '_blank');
            $clean->setAttribute('rel', 'noopener noreferrer');
        }
    }

    if (in_array($tag, ['p', 'div', 'li', 'blockquote', 'h2', 'h3'], true)) {
        $alignment = classroom_content_extract_alignment((string) $node->getAttribute('style'));
        if ($alignment === '') {
            $alignment = classroom_content_extract_alignment('text-align:' . (string) $node->getAttribute('align'));
        }
        if ($alignment !== '') {
            $clean->setAttribute('style', 'text-align:' . $alignment . ';');
        }
    }

    foreach ($node->childNodes as $child) {
        $cleanChild = classroom_content_sanitize_html_node($child, $doc);
        if ($cleanChild !== null) {
            $clean->appendChild($cleanChild);
        }
    }

    return $clean;
}

function classroom_content_sanitize_html(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    if (!class_exists('DOMDocument')) {
        return nl2br(htmlspecialchars(strip_tags($html)));
    }

    $previousErrors = libxml_use_internal_errors(true);

    $source = new DOMDocument('1.0', 'UTF-8');
    $wrapper = '<div data-classroom-content-root="1">' . $html . '</div>';
    $source->loadHTML(
        '<?xml encoding="utf-8" ?>' . $wrapper,
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );

    $xpath = new DOMXPath($source);
    $root = $xpath->query('//*[@data-classroom-content-root="1"]')->item(0);

    $cleanDoc = new DOMDocument('1.0', 'UTF-8');
    $cleanRoot = $cleanDoc->createElement('div');
    $cleanDoc->appendChild($cleanRoot);

    if ($root instanceof DOMElement) {
        foreach ($root->childNodes as $child) {
            $cleanChild = classroom_content_sanitize_html_node($child, $cleanDoc);
            if ($cleanChild !== null) {
                $cleanRoot->appendChild($cleanChild);
            }
        }
    }

    $output = '';
    foreach ($cleanRoot->childNodes as $child) {
        $output .= $cleanDoc->saveHTML($child);
    }

    libxml_clear_errors();
    libxml_use_internal_errors($previousErrors);

    return trim($output);
}

function classroom_content_prepare_body(?string $body): ?string
{
    $raw = trim((string) $body);
    if ($raw === '') {
        return null;
    }

    $sanitized = classroom_content_sanitize_html($raw);
    $plain = html_entity_decode(
        strip_tags(str_replace('&nbsp;', ' ', $sanitized)),
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
    );
    $plain = preg_replace('/\s+/u', ' ', $plain ?? '') ?? '';

    return trim($plain) === '' ? null : $sanitized;
}

function classroom_content_render_body(?string $body): string
{
    $body = trim((string) $body);
    if ($body === '') {
        return '';
    }

    if (preg_match('/<\s*\/?\s*[a-z][^>]*>/i', $body) === 1) {
        return classroom_content_sanitize_html($body);
    }

    return nl2br(htmlspecialchars($body));
}

function classroom_content_week_label(?string $weeks): string
{
    $label = trim((string) $weeks);
    return $label !== '' ? $label : 'General resources';
}

/**
 * @param array<int,array<string,mixed>> $items
 * @return array<int,array{label:string,count:int,items:array<int,array<string,mixed>>}>
 */
function classroom_content_group_by_week(array $items): array
{
    $groups = [];
    foreach ($items as $item) {
        $label = classroom_content_week_label((string) ($item['weeks'] ?? ''));
        if (!isset($groups[$label])) {
            $groups[$label] = [
                'label' => $label,
                'count' => 0,
                'items' => [],
            ];
        }

        $groups[$label]['items'][] = $item;
        $groups[$label]['count']++;
    }

    return array_values($groups);
}

/**
 * Remove any UNIQUE indexes that include courses.course_code.
 * Returns number of dropped indexes.
 */
function ensure_course_code_duplicates_allowed(): int
{
    if (!db_table_exists('courses') || !db_column_exists('courses', 'course_code')) {
        return 0;
    }

    $st = db()->prepare(
        'SELECT DISTINCT INDEX_NAME
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = ?
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
           AND NON_UNIQUE = 0
           AND INDEX_NAME <> "PRIMARY"'
    );
    $st->execute([DB_NAME, 'courses', 'course_code']);
    $indexes = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $dropped = 0;
    foreach ($indexes as $idx) {
        $indexName = str_replace('`', '``', (string) $idx);
        db()->exec("ALTER TABLE courses DROP INDEX `{$indexName}`");
        $dropped++;
    }

    // Keep a normal non-unique index for lookups/sorts.
    if ($dropped > 0) {
        db()->exec('ALTER TABLE courses ADD INDEX idx_course_code (course_code)');
    }

    return $dropped;
}

/**
 * Parse MySQL SET or comma string into array of day names.
 * @return string[]
 */
function parse_day_set(?string $value): array
{
    if ($value === null || $value === '') {
        return [];
    }
    $parts = preg_split('/\s*,\s*/', $value) ?: [];
    return array_values(array_filter(array_map('trim', $parts)));
}

/**
 * Convert array of days to MySQL SET string.
 */
function days_to_set(array $days): string
{
    $allowed = array_flip(schedule_days_list());
    $clean = [];
    foreach ($days as $d) {
        $d = trim((string) $d);
        if (isset($allowed[$d])) {
            $clean[$d] = true;
        }
    }
    return implode(',', array_keys($clean));
}

function time_to_minutes(string $time): int
{
    $parts = explode(':', $time);
    $h = (int) ($parts[0] ?? 0);
    $m = (int) ($parts[1] ?? 0);
    $s = (int) ($parts[2] ?? 0);
    return $h * 60 + $m + (int) round($s / 60);
}

function minutes_to_time(int $minutes): string
{
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    return sprintf('%02d:%02d:00', $h, $m);
}

/**
 * Check overlap of two [start,end) intervals in minutes.
 */
function intervals_overlap(int $s1, int $e1, int $s2, int $e2): bool
{
    return $s1 < $e2 && $s2 < $e1;
}

/**
 * @param string[] $days
 * @return array{ok:bool,errors:string[]}
 */
function validate_schedule_rules(
    string $scheduleType,
    array $days,
    string $startTime,
    string $endTime
): array {
    $errors = [];
    $daySet = array_flip($days);
    $boundsMin = time_to_minutes(TIME_MIN);
    $boundsMax = time_to_minutes(TIME_MAX);

    $st = time_to_minutes(substr($startTime, 0, 5) . ':00');
    $en = time_to_minutes(substr($endTime, 0, 5) . ':00');

    if ($st >= $en) {
        $errors[] = 'Start time must be before end time.';
    }
    if ($st < $boundsMin || $en > $boundsMax) {
        $errors[] = 'Schedule must fall between 6:00 AM and 10:00 PM.';
    }
    $dur = $en - $st;
    if ($dur < MIN_CLASS_MINUTES) {
        $errors[] = 'Each class must be at least ' . MIN_CLASS_MINUTES . ' minutes long.';
    }
    if ($dur > MAX_CLASS_BLOCK_HOURS * 60) {
        $errors[] = 'A single class block cannot exceed ' . MAX_CLASS_BLOCK_HOURS . ' hours.';
    }

    switch ($scheduleType) {
        case 'MW':
            if (!isset($daySet['Monday']) || !isset($daySet['Wednesday'])) {
                $errors[] = 'MW schedule requires Monday and Wednesday.';
            }
            break;
        case 'TTH':
            if (!isset($daySet['Tuesday']) || !isset($daySet['Thursday'])) {
                $errors[] = 'TTH schedule requires Tuesday and Thursday.';
            }
            break;
        case 'Saturday':
            if (count($days) !== 1 || !isset($daySet['Saturday'])) {
                $errors[] = 'Saturday schedule must select Saturday only.';
            }
            break;
        case 'Sunday':
            if (count($days) !== 1 || !isset($daySet['Sunday'])) {
                $errors[] = 'Sunday schedule must select Sunday only.';
            }
            break;
        case 'MWF':
            foreach (['Monday', 'Wednesday', 'Friday'] as $req) {
                if (!isset($daySet[$req])) {
                    $errors[] = 'MWF schedule requires Monday, Wednesday, and Friday.';
                    break;
                }
            }
            break;
        case 'TTHS':
            foreach (['Tuesday', 'Thursday', 'Saturday'] as $req) {
                if (!isset($daySet[$req])) {
                    $errors[] = 'TTHS schedule requires Tuesday, Thursday, and Saturday.';
                    break;
                }
            }
            break;
        case 'MW_TTH':
            $mw = isset($daySet['Monday'], $daySet['Wednesday']);
            $tth = isset($daySet['Tuesday'], $daySet['Thursday']);
            if (!$mw || !$tth) {
                $errors[] = 'MW_TTH requires Monday & Wednesday and Tuesday & Thursday.';
            }
            break;
        case 'Custom':
        default:
            break;
    }

    return ['ok' => $errors === [], 'errors' => $errors];
}

/**
 * Fetch schedules for faculty on a given day (same semester/school year), optional exclude id.
 * @return array<int,array<string,mixed>>
 */
function fetch_faculty_day_schedules(
    int $facultyId,
    string $day,
    string $semester,
    string $schoolYear,
    ?int $excludeId = null
): array {
    $sql = "SELECT id, start_time, end_time FROM schedules
            WHERE faculty_id = ? AND semester = ? AND school_year = ?
            AND FIND_IN_SET(?, day_of_week) > 0";
    $params = [$facultyId, $semester, $schoolYear, $day];
    if ($excludeId !== null) {
        $sql .= ' AND id != ?';
        $params[] = $excludeId;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Fetch schedules for room on a given day.
 * @return array<int,array<string,mixed>>
 */
function fetch_room_day_schedules(
    int $roomId,
    string $day,
    string $semester,
    string $schoolYear,
    ?int $excludeId = null
): array {
    $sql = "SELECT id, start_time, end_time FROM schedules
            WHERE room_id = ? AND semester = ? AND school_year = ?
            AND FIND_IN_SET(?, day_of_week) > 0";
    $params = [$roomId, $semester, $schoolYear, $day];
    if ($excludeId !== null) {
        $sql .= ' AND id != ?';
        $params[] = $excludeId;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function room_status_allows_overlap(int $roomId): bool
{
    $stmt = db()->prepare('SELECT status, type FROM rooms WHERE id = ? LIMIT 1');
    $stmt->execute([$roomId]);
    $row = $stmt->fetch();
    if (!$row) {
        return false;
    }
    $status = strtolower((string) ($row['status'] ?? ''));
    $type = strtolower((string) ($row['type'] ?? ''));
    return $status === 'tba' || $type === 'tba';
}

/**
 * Whether room_code is already used for another room in the same college (dean-managed rooms).
 */
function room_code_taken_for_college(string $roomCode, int $collegeId, ?int $exceptRoomId = null): bool
{
    $code = trim($roomCode);
    if ($code === '' || $collegeId < 1) {
        return false;
    }
    if ($exceptRoomId !== null && $exceptRoomId > 0) {
        $stmt = db()->prepare(
            'SELECT id FROM rooms WHERE COALESCE(is_gened,0) = 0 AND college_id = ? AND room_code = ? AND id <> ? LIMIT 1'
        );
        $stmt->execute([$collegeId, $code, $exceptRoomId]);
    } else {
        $stmt = db()->prepare(
            'SELECT id FROM rooms WHERE COALESCE(is_gened,0) = 0 AND college_id = ? AND room_code = ? LIMIT 1'
        );
        $stmt->execute([$collegeId, $code]);
    }
    return $stmt->fetchColumn() !== false;
}

/**
 * Whether room_code is already used for another Gen Ed room.
 */
function room_code_taken_for_gened(string $roomCode, ?int $exceptRoomId = null): bool
{
    $code = trim($roomCode);
    if ($code === '') {
        return false;
    }
    if ($exceptRoomId !== null && $exceptRoomId > 0) {
        $stmt = db()->prepare(
            'SELECT id FROM rooms WHERE COALESCE(is_gened,0) = 1 AND room_code = ? AND id <> ? LIMIT 1'
        );
        $stmt->execute([$code, $exceptRoomId]);
    } else {
        $stmt = db()->prepare(
            'SELECT id FROM rooms WHERE COALESCE(is_gened,0) = 1 AND room_code = ? LIMIT 1'
        );
        $stmt->execute([$code]);
    }
    return $stmt->fetchColumn() !== false;
}

/**
 * Short uppercase prefix for auto room codes from a college name (fallback C{id}).
 */
function room_auto_code_prefix_from_name(string $collegeName, int $fallbackId, int $maxLen = 6): string
{
    $s = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', $collegeName));
    if ($s === '') {
        $s = 'C' . max(1, $fallbackId);
    }
    if (strlen($s) > $maxLen) {
        $s = substr($s, 0, $maxLen);
    }
    return $s;
}

/**
 * Next auto room code for a college: PREFIX-001, PREFIX-002, ... (unique within that college).
 */
function next_auto_room_code_for_college(int $collegeId, string $collegeName): string
{
    if ($collegeId < 1) {
        throw new RuntimeException('Invalid college for room code.');
    }
    $prefix = room_auto_code_prefix_from_name($collegeName, $collegeId, 6);
    while (strlen($prefix) + 8 > 20) {
        $prefix = substr($prefix, 0, -1);
    }
    if ($prefix === '') {
        $prefix = 'C' . $collegeId;
    }
    $prefix = substr($prefix, 0, 10);

    $st = db()->prepare(
        "SELECT room_code FROM rooms WHERE college_id = ? AND COALESCE(is_gened,0) = 0 AND room_code LIKE ?"
    );
    $st->execute([$collegeId, $prefix . '-%']);
    $max = 0;
    $pattern = '/^' . preg_quote($prefix, '/') . '-(\d+)$/';
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $code) {
        if (preg_match($pattern, (string) $code, $m)) {
            $max = max($max, (int) $m[1]);
        }
    }
    for ($n = $max + 1; $n < $max + 10000; $n++) {
        $suffix = (string) $n;
        if (strlen($suffix) < 3) {
            $suffix = str_pad($suffix, 3, '0', STR_PAD_LEFT);
        }
        $candidate = $prefix . '-' . $suffix;
        if (strlen($candidate) > 20) {
            $candidate = substr($prefix, 0, 12) . '-' . $n;
            $candidate = substr($candidate, 0, 20);
        }
        if (!room_code_taken_for_college($candidate, $collegeId)) {
            return $candidate;
        }
    }
    throw new RuntimeException('Could not allocate a unique room code. Use a custom code.');
}

/**
 * Next auto room code for Gen Ed: GE-001, GE-002, ...
 */
function next_auto_room_code_gened(): string
{
    $prefix = 'GE';
    $st = db()->prepare(
        "SELECT room_code FROM rooms WHERE COALESCE(is_gened,0) = 1 AND room_code LIKE ?"
    );
    $st->execute([$prefix . '-%']);
    $max = 0;
    $pattern = '/^' . preg_quote($prefix, '/') . '-(\d+)$/';
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $code) {
        if (preg_match($pattern, (string) $code, $m)) {
            $max = max($max, (int) $m[1]);
        }
    }
    for ($n = $max + 1; $n < $max + 10000; $n++) {
        $suffix = str_pad((string) $n, 3, '0', STR_PAD_LEFT);
        $candidate = $prefix . '-' . $suffix;
        if (strlen($candidate) > 20) {
            $candidate = $prefix . '-' . $n;
            $candidate = substr($candidate, 0, 20);
        }
        if (!room_code_taken_for_gened($candidate)) {
            return $candidate;
        }
    }
    throw new RuntimeException('Could not allocate a unique GE room code. Use a custom code.');
}

function detect_cross_college_room_conflicts(
    int $roomId,
    int $collegeId,
    string $day,
    string $semester,
    string $schoolYear,
    int $st,
    int $en,
    ?int $excludeId = null
): array {
    if (room_status_allows_overlap($roomId)) {
        return [];
    }

    $sql = "SELECT s.id, s.start_time, s.end_time, c.college_name
            FROM schedules s
            LEFT JOIN colleges c ON c.id = s.college_id
            WHERE s.room_id = ? AND s.college_id IS NOT NULL AND s.college_id <> ?
              AND s.semester = ? AND s.school_year = ?
              AND FIND_IN_SET(?, s.day_of_week) > 0";
    $params = [$roomId, $collegeId, $semester, $schoolYear, $day];
    if ($excludeId !== null) {
        $sql .= ' AND s.id != ?';
        $params[] = $excludeId;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $cross = [];
    foreach ($rows as $row) {
        $rs = time_to_minutes(substr((string) $row['start_time'], 0, 8));
        $re = time_to_minutes(substr((string) $row['end_time'], 0, 8));
        if (intervals_overlap($st, $en, $rs, $re)) {
            $collegeName = (string) ($row['college_name'] ?: 'Other college');
            $cross[] = "Cross-college room conflict on {$day}: room is used by {$collegeName}.";
        }
    }
    return array_values(array_unique($cross));
}

/**
 * Check gap and consecutive-hour rules for faculty on a day when adding [st,en).
 * @param array<int,array{start_time:string,end_time:string}> $existing
 * @return string[]
 */
function check_faculty_gaps_and_consecutive(array $existing, int $st, int $en): array
{
    $errors = [];
    $intervals = [];
    foreach ($existing as $row) {
        $intervals[] = [
            time_to_minutes(substr($row['start_time'], 0, 8)),
            time_to_minutes(substr($row['end_time'], 0, 8)),
        ];
    }
    $intervals[] = [$st, $en];
    usort($intervals, static fn ($x, $y) => $x[0] <=> $y[0]);

    for ($i = 0; $i < count($intervals) - 1; $i++) {
        $end1 = $intervals[$i][1];
        $start2 = $intervals[$i + 1][0];
        $gap = $start2 - $end1;
        if ($gap < 0) {
            continue;
        }
        if (MIN_GAP_MINUTES > 0 && $gap > 0 && $gap < MIN_GAP_MINUTES) {
            $errors[] = 'There must be at least ' . MIN_GAP_MINUTES . ' minutes between classes on the same day.';
        }
    }

    $idx = 0;
    while ($idx < count($intervals)) {
        $chainStart = $intervals[$idx][0];
        $chainEnd = $intervals[$idx][1];
        $chainCount = 1;
        $k = $idx;
        while ($k < count($intervals) - 1) {
            $g = $intervals[$k + 1][0] - $intervals[$k][1];
            if ($g === 0) {
                $chainEnd = $intervals[$k + 1][1];
                $chainCount++;
                $k++;
            } else {
                break;
            }
        }
        if ($chainCount > 1 && ($chainEnd - $chainStart > MAX_CONSECUTIVE_HOURS * 60)) {
            $breakPhrase = MIN_GAP_MINUTES > 0
                ? MIN_GAP_MINUTES . '-minute break'
                : 'break between class periods';
            $errors[] = 'Faculty cannot teach more than ' . MAX_CONSECUTIVE_HOURS . ' consecutive hours without a '
                . $breakPhrase . '.';
        }
        $idx = $k + 1;
    }

    return array_values(array_unique($errors));
}

/**
 * Main conflict checker.
 *
 * @param string[] $days
 * @return array<int,array{type:string,description:string,scope:string}>
 */
function checkConflicts(
    int $faculty_id,
    int $room_id,
    array $days,
    string $start_time,
    string $end_time,
    string $semester,
    string $school_year,
    ?int $exclude_id = null,
    ?int $college_id = null,
    bool $allow_long_block = false
): array {
    $conflicts = [];
    $st = time_to_minutes(substr($start_time, 0, 5) . ':00');
    $en = time_to_minutes(substr($end_time, 0, 5) . ':00');
    $ignoreRoomOverlap = room_status_allows_overlap($room_id);

    foreach ($days as $day) {
        // Faculty overlap (always internal because faculty belongs to one college)
        $frows = fetch_faculty_day_schedules($faculty_id, $day, $semester, $school_year, $exclude_id);
        foreach ($frows as $row) {
            $rs = time_to_minutes(substr($row['start_time'], 0, 8));
            $re = time_to_minutes(substr($row['end_time'], 0, 8));
            if (intervals_overlap($st, $en, $rs, $re)) {
                $conflicts[] = [
                    'type' => 'faculty',
                    'description' => "Faculty already has a class on {$day} overlapping {$start_time}-{$end_time}.",
                    'scope' => 'internal',
                ];
            }
        }

        // Room overlap (same-college, term scoped)
        if (!$ignoreRoomOverlap) {
            $rrows = fetch_room_day_schedules($room_id, $day, $semester, $school_year, $exclude_id);
            foreach ($rrows as $row) {
                $rs = time_to_minutes(substr($row['start_time'], 0, 8));
                $re = time_to_minutes(substr($row['end_time'], 0, 8));
                if (intervals_overlap($st, $en, $rs, $re)) {
                    $conflicts[] = [
                        'type' => 'room',
                        'description' => "Room is already booked on {$day} during this time.",
                        'scope' => 'internal',
                    ];
                }
            }
        }

        // Daily hour limit
        $stmt = db()->prepare('SELECT max_hours_per_day FROM faculty WHERE id = ?');
        $stmt->execute([$faculty_id]);
        $maxH = (int) ($stmt->fetchColumn() ?: 8);
        $maxMin = $maxH * 60;
        $existingMin = 0;
        foreach ($frows as $row) {
            $a = time_to_minutes(substr($row['start_time'], 0, 8));
            $b = time_to_minutes(substr($row['end_time'], 0, 8));
            $existingMin += ($b - $a);
        }
        $newMin = $en - $st;
        /* Only count new slot once per day */
        if ($existingMin + $newMin > $maxMin) {
            $conflicts[] = [
                'type' => 'time',
                'description' => "Faculty would exceed {$maxH} hours on {$day}.",
                'scope' => 'internal',
            ];
        }

        // Gap / consecutive rules
        $merged = fetch_faculty_day_schedules($faculty_id, $day, $semester, $school_year, $exclude_id);
        $gapErrors = check_faculty_gaps_and_consecutive($merged, $st, $en);
        foreach ($gapErrors as $msg) {
            if ($allow_long_block && str_contains($msg, 'consecutive hours')) {
                continue;
            }
            $conflicts[] = ['type' => 'time', 'description' => $msg . " ({$day})", 'scope' => 'internal'];
        }

        // Cross-college room overlap (request required for dean workflow)
        if ($college_id !== null && !$ignoreRoomOverlap) {
            $cross = detect_cross_college_room_conflicts(
                $room_id,
                $college_id,
                $day,
                $semester,
                $school_year,
                $st,
                $en,
                $exclude_id
            );
            foreach ($cross as $msg) {
                $conflicts[] = ['type' => 'room', 'description' => $msg, 'scope' => 'cross_college'];
            }
        }

    }

    // Deduplicate by type+description+scope
    $unique = [];
    foreach ($conflicts as $c) {
        $k = $c['type'] . '|' . $c['scope'] . '|' . $c['description'];
        $unique[$k] = $c;
    }
    return array_values($unique);
}

/**
 * Log conflicts to conflict_logs (schedule_id nullable until saved).
 */
function log_conflicts(?int $scheduleId, array $conflicts, bool $resolved = false): void
{
    $stmt = db()->prepare(
        'INSERT INTO conflict_logs (schedule_id, conflict_type, conflict_description, resolved) VALUES (?,?,?,?)'
    );
    foreach ($conflicts as $c) {
        $stmt->execute([$scheduleId, $c['type'], $c['description'], $resolved ? 1 : 0]);
    }
}

function create_conflict_request(array $payload): int
{
    $stmt = db()->prepare(
        'INSERT INTO conflict_requests
        (requested_by, college_id, faculty_id, course_id, room_id, schedule_type, day_of_week, start_time, end_time, semester, school_year, academic_year, reason)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        (int) $payload['requested_by'],
        (int) $payload['college_id'],
        (int) $payload['faculty_id'],
        (int) $payload['course_id'],
        (int) $payload['room_id'],
        (string) $payload['schedule_type'],
        (string) $payload['day_of_week'],
        (string) $payload['start_time'],
        (string) $payload['end_time'],
        (string) $payload['semester'],
        (string) $payload['school_year'],
        (string) ($payload['academic_year'] ?? ''),
        (string) $payload['reason'],
    ]);
    return (int) db()->lastInsertId();
}

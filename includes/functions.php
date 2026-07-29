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

/** Ensure faculty_course_colors exists (idempotent; safe for local installs). */
function ensure_faculty_course_colors_table(): bool
{
    if (db_table_exists('faculty_course_colors')) {
        return true;
    }
    try {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS faculty_course_colors (
                id INT AUTO_INCREMENT PRIMARY KEY,
                faculty_id INT NOT NULL,
                course_id INT NOT NULL,
                color_index TINYINT UNSIGNED NOT NULL DEFAULT 0,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_fcc_faculty_course (faculty_id, course_id),
                CONSTRAINT fk_fcc_faculty FOREIGN KEY (faculty_id) REFERENCES faculty(id) ON DELETE CASCADE,
                CONSTRAINT fk_fcc_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        return false;
    }
    return db_table_exists('faculty_course_colors');
}

/**
 * Preset schedule block colors (index 0–5 → CSS classes c0–c5).
 *
 * @return list<array{index:int,label:string,bg:string,border:string}>
 */
function schedule_color_palette(): array
{
    return [
        ['index' => 0, 'label' => 'Blue', 'bg' => '#e3f2fd', 'border' => '#1976d2'],
        ['index' => 1, 'label' => 'Green', 'bg' => '#e8f5e9', 'border' => '#388e3c'],
        ['index' => 2, 'label' => 'Orange', 'bg' => '#fff3e0', 'border' => '#f57c00'],
        ['index' => 3, 'label' => 'Pink', 'bg' => '#fce4ec', 'border' => '#c2185b'],
        ['index' => 4, 'label' => 'Purple', 'bg' => '#f3e5f5', 'border' => '#7b1fa2'],
        ['index' => 5, 'label' => 'Teal', 'bg' => '#e0f7fa', 'border' => '#00838f'],
    ];
}

/**
 * Map of course_id => color_index for one faculty member.
 *
 * @return array<int,int>
 */
function faculty_course_color_map(int $facultyId): array
{
    if ($facultyId < 1 || !ensure_faculty_course_colors_table()) {
        return [];
    }
    $st = db()->prepare('SELECT course_id, color_index FROM faculty_course_colors WHERE faculty_id = ?');
    $st->execute([$facultyId]);
    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(int) $row['course_id']] = max(0, min(5, (int) $row['color_index']));
    }
    return $map;
}

/**
 * CSS class for a schedule block (c0–c5). Uses faculty preference when available.
 *
 * @param array<int,int>|null $colorMap course_id => color_index
 */
function schedule_block_color_class(int $courseId, ?array $colorMap = null): string
{
    if ($colorMap !== null && isset($colorMap[$courseId])) {
        return 'c' . max(0, min(5, (int) $colorMap[$courseId]));
    }
    return 'c' . ($courseId % 6);
}

/**
 * Save a faculty member's preferred color for a course they teach.
 *
 * @throws RuntimeException
 */
function save_faculty_course_color(int $facultyId, int $courseId, int $colorIndex, ?int $collegeId = null): void
{
    if ($facultyId < 1 || $courseId < 1) {
        throw new RuntimeException('Invalid course selection.');
    }
    $colorIndex = max(0, min(5, $colorIndex));
    if (!ensure_faculty_course_colors_table()) {
        throw new RuntimeException('Course colors are not available. Ask an administrator to run upgrade_roles.php.');
    }
    $sql = 'SELECT COUNT(*) FROM schedules WHERE faculty_id = ? AND course_id = ?';
    $params = [$facultyId, $courseId];
    if ($collegeId !== null && $collegeId > 0) {
        $sql .= ' AND college_id = ?';
        $params[] = $collegeId;
    }
    $chk = db()->prepare($sql);
    $chk->execute($params);
    if ((int) $chk->fetchColumn() < 1) {
        throw new RuntimeException('You can only color-code courses on your schedule.');
    }
    db()->prepare(
        'INSERT INTO faculty_course_colors (faculty_id, course_id, color_index)
         VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE color_index = VALUES(color_index)'
    )->execute([$facultyId, $courseId, $colorIndex]);
}

/**
 * Makeup schedule helpers.
 */
require_once __DIR__ . '/makeup_helpers.php';

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

function classroom_content_attachment_href(int $attachmentId, bool $inline = false): string
{
    $href = 'classroom_content_attachment.php?attachment_id=' . $attachmentId;
    if ($inline) {
        $href .= '&inline=1';
    }

    return $href;
}

function classroom_content_is_image_filename(string $name): bool
{
    $ext = strtolower(pathinfo(trim($name), PATHINFO_EXTENSION));

    return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
}

function classroom_content_is_image_mime(string $mime): bool
{
    $mime = strtolower(trim($mime));

    return $mime !== '' && str_starts_with($mime, 'image/');
}

/**
 * Whether a content attachment row should be previewed as an inline image.
 *
 * @param array<string,mixed> $attachment
 */
function classroom_content_attachment_is_image(array $attachment): bool
{
    if (classroom_content_is_image_mime((string) ($attachment['mime'] ?? ''))) {
        return true;
    }

    return classroom_content_is_image_filename((string) ($attachment['original_name'] ?? ''))
        || classroom_content_is_image_filename((string) ($attachment['stored_name'] ?? ''));
}

function classroom_content_inline_image_href(string $storedName): string
{
    return 'classroom_content_inline.php?n=' . rawurlencode(basename($storedName));
}

/**
 * Persist a pasted/editor data-URI image and return a safe relative img src.
 *
 * @throws RuntimeException
 */
function classroom_content_store_data_uri_image(string $dataUri): string
{
    if (preg_match('#^data:image/(jpeg|jpg|png|gif|webp);base64,([A-Za-z0-9+/=\s]+)$#i', trim($dataUri), $matches) !== 1) {
        throw new RuntimeException('Unsupported inline image format.');
    }

    $kind = strtolower((string) $matches[1]);
    $ext = match ($kind) {
        'jpeg', 'jpg' => 'jpg',
        'png' => 'png',
        'gif' => 'gif',
        'webp' => 'webp',
        default => '',
    };
    if ($ext === '') {
        throw new RuntimeException('Unsupported inline image format.');
    }

    $binary = base64_decode(preg_replace('/\s+/', '', (string) $matches[2]) ?? '', true);
    if ($binary === false || $binary === '') {
        throw new RuntimeException('Could not decode the pasted image.');
    }
    if (strlen($binary) > 5 * 1024 * 1024) {
        throw new RuntimeException('Inline image is too large (max 5 MB).');
    }

    $imageInfo = @getimagesizefromstring($binary);
    if ($imageInfo === false) {
        throw new RuntimeException('Pasted file is not a valid image.');
    }
    $detectedMime = strtolower((string) ($imageInfo['mime'] ?? ''));
    $allowedMime = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    if (!isset($allowedMime[$detectedMime])) {
        throw new RuntimeException('Only JPEG, PNG, GIF, and WebP images can be pasted.');
    }
    $ext = $allowedMime[$detectedMime];

    $dir = classroom_content_attachment_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create attachment directory.');
    }

    $storedName = 'inline_' . bin2hex(random_bytes(16)) . '.' . $ext;
    $destination = classroom_content_attachment_storage_path($storedName);
    if (file_put_contents($destination, $binary) === false) {
        throw new RuntimeException('Failed to save pasted image.');
    }

    return classroom_content_inline_image_href($storedName);
}

/**
 * Normalize and validate an <img src> value for classroom HTML bodies.
 */
function classroom_content_sanitize_img_src(string $src): ?string
{
    $src = trim(html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($src === '') {
        return null;
    }

    if (str_starts_with(strtolower($src), 'data:image/')) {
        try {
            return classroom_content_store_data_uri_image($src);
        } catch (Throwable) {
            return null;
        }
    }

    if (preg_match('#^(?:(?:\./)?classroom_content_inline\.php\?n=)([A-Za-z0-9._-]+)$#', $src, $matches) === 1) {
        $stored = basename((string) $matches[1]);
        if ($stored === '' || !is_file(classroom_content_attachment_storage_path($stored))) {
            return null;
        }

        return classroom_content_inline_image_href($stored);
    }

    if (preg_match('#^(?:(?:\./)?classroom_content_attachment\.php\?(?:attachment_id|id)=)(\d+)(?:&inline=1)?$#', $src, $matches) === 1) {
        $id = (int) $matches[1];

        return $id > 0 ? 'classroom_content_attachment.php?attachment_id=' . $id . '&inline=1' : null;
    }

    $validated = filter_var($src, FILTER_VALIDATE_URL);
    if ($validated === false) {
        return null;
    }
    $scheme = strtolower((string) (parse_url($validated, PHP_URL_SCHEME) ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return null;
    }

    return $validated;
}

/** Open syllabus for a classroom (auth enforced in classroom_syllabus.php). */
function classroom_syllabus_href(int $classroomId): string
{
    return 'classroom_syllabus.php?id=' . $classroomId;
}

function classroom_banner_column_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        $ready = db_column_exists('online_classrooms', 'banner_stored_name');
    }
    return $ready;
}

function classroom_banner_dir(): string
{
    return BASE_PATH . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'classroom_banners';
}

function classroom_banner_path(string $storedName): string
{
    return classroom_banner_dir() . DIRECTORY_SEPARATOR . basename($storedName);
}

function classroom_banner_href(int $classroomId): string
{
    return 'classroom_banner.php?id=' . $classroomId;
}

function classroom_banner_url_for(array $classroom): ?string
{
    if (!classroom_banner_column_ready()) {
        return null;
    }
    $stored = trim((string) ($classroom['banner_stored_name'] ?? ''));
    if ($stored === '') {
        return null;
    }
    $path = classroom_banner_path($stored);
    if (!is_file($path)) {
        return null;
    }
    $id = (int) ($classroom['id'] ?? 0);
    return $id > 0 ? classroom_banner_href($id) : null;
}

/**
 * @param array<string,mixed> $file
 */
function classroom_banner_store(int $classroomId, int $facultyId, array $file): string
{
    if ($classroomId < 1) {
        throw new RuntimeException('Invalid classroom.');
    }
    // facultyId kept for call-site compatibility; ownership is enforced by the caller.
    unset($facultyId);
    return classroom_banner_store_for_classroom($classroomId, $file);
}

/**
 * Store a classroom header background. Caller must verify the user may edit this class.
 *
 * @param array<string,mixed> $file
 */
function classroom_banner_store_for_classroom(int $classroomId, array $file): string
{
    if ($classroomId < 1) {
        throw new RuntimeException('Invalid classroom.');
    }
    if (!classroom_banner_column_ready()) {
        throw new RuntimeException('Classroom banners are not installed. Run upgrade_roles.php once.');
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Please choose a background image to upload.');
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Background image upload failed.');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size < 1) {
        throw new RuntimeException('Background image file is empty.');
    }
    if ($size > 5 * 1024 * 1024) {
        throw new RuntimeException('Background image is too large (max 5 MB).');
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Invalid upload.');
    }

    $imageInfo = @getimagesize($tmp);
    if ($imageInfo === false) {
        throw new RuntimeException('File must be a JPEG, PNG, or WebP image.');
    }

    $mime = (string) ($imageInfo['mime'] ?? '');
    $ext = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        default => '',
    };
    if ($ext === '') {
        throw new RuntimeException('Only JPEG, PNG, and WebP images are allowed.');
    }

    $dir = classroom_banner_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create banner storage directory.');
    }

    $st = db()->prepare(
        'SELECT banner_stored_name FROM online_classrooms WHERE id = ? LIMIT 1'
    );
    $st->execute([$classroomId]);
    $oldStored = trim((string) ($st->fetchColumn() ?: ''));
    if ($oldStored !== '') {
        $oldPath = classroom_banner_path($oldStored);
        if (is_file($oldPath)) {
            @unlink($oldPath);
        }
    }

    $original = trim((string) ($file['name'] ?? ''));
    $original = str_replace(["\r", "\n"], '', basename($original));
    if ($original === '') {
        $original = 'banner.' . $ext;
    }

    $stored = 'class_' . $classroomId . '_' . bin2hex(random_bytes(12)) . '.' . $ext;
    $dest = classroom_banner_path($stored);
    if (!move_uploaded_file($tmp, $dest)) {
        throw new RuntimeException('Failed to save background image.');
    }

    $upd = db()->prepare(
        'UPDATE online_classrooms
         SET banner_stored_name = ?, banner_original_name = ?, banner_mime = ?
         WHERE id = ?'
    );
    $upd->execute([$stored, $original, $mime, $classroomId]);
    if ($upd->rowCount() < 1) {
        @unlink($dest);
        throw new RuntimeException('Classroom not found.');
    }

    return $stored;
}

function classroom_banner_remove(int $classroomId, int $facultyId): void
{
    unset($facultyId);
    classroom_banner_remove_for_classroom($classroomId);
}

function classroom_banner_remove_for_classroom(int $classroomId): void
{
    if ($classroomId < 1) {
        throw new RuntimeException('Invalid classroom.');
    }
    if (!classroom_banner_column_ready()) {
        throw new RuntimeException('Classroom banners are not installed.');
    }

    $st = db()->prepare(
        'SELECT banner_stored_name FROM online_classrooms WHERE id = ? LIMIT 1'
    );
    $st->execute([$classroomId]);
    $oldStored = trim((string) ($st->fetchColumn() ?: ''));
    if ($oldStored !== '') {
        $oldPath = classroom_banner_path($oldStored);
        if (is_file($oldPath)) {
            @unlink($oldPath);
        }
    }

    db()->prepare(
        'UPDATE online_classrooms
         SET banner_stored_name = NULL, banner_original_name = NULL, banner_mime = NULL
         WHERE id = ?'
    )->execute([$classroomId]);
}

function classroom_banner_mime_for_stored(string $storedName): string
{
    return match (strtolower(pathinfo($storedName, PATHINFO_EXTENSION))) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        default => 'application/octet-stream',
    };
}

/**
 * Handle upload_banner / delete_banner POST for faculty manage-classroom pages.
 * Returns a flash message when handled, or null when the action is not a banner action.
 *
 * @param array<string,mixed>|null $classroom Updated in place on success
 */
function faculty_classroom_process_banner_post(int $classroomId, int $facultyId, ?array &$classroom): ?string
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || $classroomId < 1 || $facultyId < 1 || !$classroom) {
        return null;
    }

    $action = (string) ($_POST['action'] ?? '');
    if ($action !== 'upload_banner' && $action !== 'delete_banner') {
        return null;
    }

    if (!classroom_banner_column_ready()) {
        throw new RuntimeException('Run upgrade_roles.php to enable classroom header backgrounds.');
    }

    if ($action === 'upload_banner') {
        if (!isset($_FILES['banner']) || !is_array($_FILES['banner'])) {
            throw new RuntimeException('Please choose a background image to upload.');
        }
        $f = $_FILES['banner'];
        $file = [
            'name' => (string) ($f['name'] ?? ''),
            'type' => (string) ($f['type'] ?? ''),
            'tmp_name' => (string) ($f['tmp_name'] ?? ''),
            'error' => (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) ($f['size'] ?? 0),
        ];
        $stored = classroom_banner_store($classroomId, $facultyId, $file);
        $classroom['banner_stored_name'] = $stored;
        $classroom['banner_original_name'] = (string) ($file['name'] ?? 'banner');
        return 'Classroom header background updated. Students will see it on their class page.';
    }

    classroom_banner_remove($classroomId, $facultyId);
    $classroom['banner_stored_name'] = null;
    $classroom['banner_original_name'] = null;
    $classroom['banner_mime'] = null;
    return 'Classroom header background removed.';
}

/**
 * Render the Google Classroom–style header used on all Manage Classroom pages.
 *
 * Options:
 * - title: override banner title (default: classroom title)
 * - meta_extra: optional plain-text suffix after course/semester meta
 * - allow_upload: show upload/remove controls (default true)
 * - form_id: unique form/input id prefix (default facultyBanner)
 *
 * @param array<string,mixed> $classroom
 * @param array<string,mixed> $options
 */
function faculty_classroom_render_banner(array $classroom, array $options = []): void
{
    $classroomId = (int) ($classroom['id'] ?? 0);
    $bannerUrl = classroom_banner_url_for($classroom);
    $hasBannerCols = classroom_banner_column_ready();
    $allowUpload = (bool) ($options['allow_upload'] ?? true);
    $title = trim((string) ($options['title'] ?? ''));
    if ($title === '') {
        $title = (string) ($classroom['title'] ?? 'Classroom');
    }
    $metaExtra = trim((string) ($options['meta_extra'] ?? ''));
    $formId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($options['form_id'] ?? 'facultyBanner')) ?: 'facultyBanner';
    $fileInputId = $formId . 'File';
    $uploadFormId = $formId . 'UploadForm';

    $courseCode = trim((string) ($classroom['course_code'] ?? ''));
    $courseName = trim((string) ($classroom['course_name'] ?? ''));
    $semester = trim((string) ($classroom['semester'] ?? ''));
    $schoolYear = trim((string) ($classroom['school_year'] ?? ''));

    require __DIR__ . '/faculty_classroom_banner.php';
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

function classroom_content_attachment_extension(string $originalName, string $storedName = '', string $mime = ''): string
{
    foreach ([$originalName, $storedName] as $name) {
        $name = trim($name);
        if ($name === '') {
            continue;
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext !== '') {
            return $ext;
        }
    }

    $mime = strtolower(trim($mime));
    $mimeMap = [
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/pdf' => 'pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-excel' => 'xls',
    ];

    return $mimeMap[$mime] ?? '';
}

/**
 * @param array<string,mixed> $item
 * @param list<array{id:int,content_id:int,original_name:string,stored_name:string,mime:string}> $extraAttachments
 * @return list<array{original_name:string,stored_name:string,mime:string,extension:string}>
 */
function classroom_content_item_attachment_files(array $item, array $extraAttachments = []): array
{
    $files = [];

    $resourceUrl = trim((string) ($item['resource_url'] ?? ''));
    if ($resourceUrl !== '' && classroom_content_is_attachment($resourceUrl)) {
        $storedName = basename(str_replace('\\', '/', $resourceUrl));
        $originalName = classroom_content_attachment_name($resourceUrl);
        $files[] = [
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'mime' => '',
            'extension' => classroom_content_attachment_extension($originalName, $storedName),
        ];
    }

    foreach ($extraAttachments as $attachment) {
        if (!is_array($attachment)) {
            continue;
        }
        $storedName = trim((string) ($attachment['stored_name'] ?? ''));
        if ($storedName === '') {
            continue;
        }
        $originalName = classroom_content_attachment_download_name(
            (string) ($attachment['original_name'] ?? ''),
            $storedName
        );
        $files[] = [
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'mime' => trim((string) ($attachment['mime'] ?? '')),
            'extension' => classroom_content_attachment_extension(
                $originalName,
                $storedName,
                (string) ($attachment['mime'] ?? '')
            ),
        ];
    }

    $seen = [];
    $unique = [];
    foreach ($files as $file) {
        $key = strtolower((string) ($file['stored_name'] ?? ''));
        if ($key === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $unique[] = $file;
    }

    return $unique;
}

function classroom_content_attachment_extract_plain_text(string $storedName, string $originalName = '', string $mime = '', int $maxChars = 4000): string
{
    $storedName = basename(str_replace('\\', '/', trim($storedName)));
    if ($storedName === '') {
        return '';
    }

    $path = classroom_content_attachment_storage_path($storedName);
    if (!is_file($path) || !is_readable($path)) {
        return '';
    }

    $ext = classroom_content_attachment_extension($originalName, $storedName, $mime);
    $text = match ($ext) {
        'txt', 'csv', 'md' => classroom_content_attachment_read_text_file($path, $maxChars),
        'docx' => classroom_content_attachment_extract_docx_text($path, $maxChars),
        'pptx' => classroom_content_attachment_extract_pptx_text($path, $maxChars),
        'xlsx' => classroom_content_attachment_extract_xlsx_text($path, $maxChars),
        'pdf' => classroom_content_attachment_extract_pdf_text($path, $maxChars),
        default => '',
    };

    return classroom_content_attachment_normalize_extracted_text($text, $maxChars);
}

function classroom_content_attachment_read_text_file(string $path, int $maxChars): string
{
    $size = filesize($path);
    if ($size === false || $size < 1 || $size > 5_000_000) {
        return '';
    }

    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return '';
    }

    if (!mb_check_encoding($raw, 'UTF-8')) {
        $converted = mb_convert_encoding($raw, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
        $raw = is_string($converted) ? $converted : $raw;
    }

    return mb_substr(trim($raw), 0, $maxChars, 'UTF-8');
}

function classroom_content_attachment_extract_docx_text(string $path, int $maxChars): string
{
    if (!class_exists('ZipArchive')) {
        return '';
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return '';
    }

    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false || $xml === '') {
        return '';
    }

    $text = classroom_content_attachment_xml_to_text($xml);

    return mb_substr($text, 0, $maxChars, 'UTF-8');
}

function classroom_content_attachment_extract_pptx_text(string $path, int $maxChars): string
{
    if (!class_exists('ZipArchive')) {
        return '';
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return '';
    }

    $chunks = [];
    for ($i = 0; $i < $zip->numFiles; ++$i) {
        $name = (string) $zip->getNameIndex($i);
        if (!preg_match('#^ppt/slides/slide\d+\.xml$#', $name)) {
            continue;
        }
        $xml = $zip->getFromIndex($i);
        if ($xml !== false && $xml !== '') {
            $chunks[] = classroom_content_attachment_xml_to_text($xml);
        }
    }
    $zip->close();

    $text = trim(implode("\n", array_filter($chunks)));

    return mb_substr($text, 0, $maxChars, 'UTF-8');
}

function classroom_content_attachment_extract_xlsx_text(string $path, int $maxChars): string
{
    if (!class_exists('ZipArchive')) {
        return '';
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return '';
    }

    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    $zip->close();
    if ($sharedXml === false || $sharedXml === '') {
        return '';
    }

    $text = classroom_content_attachment_xml_to_text($sharedXml);

    return mb_substr($text, 0, $maxChars, 'UTF-8');
}

function classroom_content_attachment_extract_pdf_text(string $path, int $maxChars): string
{
    $pdftotext = classroom_content_find_pdftotext_binary();
    if ($pdftotext !== null) {
        $cmd = escapeshellarg($pdftotext) . ' -layout -nopgbrk ' . escapeshellarg($path) . ' - 2>NUL';
        $output = shell_exec($cmd);
        if (is_string($output) && trim($output) !== '') {
            return classroom_content_attachment_normalize_extracted_text($output, $maxChars);
        }
    }

    $size = filesize($path);
    if ($size === false || $size < 1 || $size > 8_000_000) {
        return '';
    }

    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return '';
    }

    $parts = [];
    if (preg_match_all('/\(([^()\\\\\r\n]{3,200})\)/s', $raw, $matches) === 1 && isset($matches[1])) {
        foreach ($matches[1] as $part) {
            $part = str_replace(['\\(', '\\)', '\\n', '\\r', '\\t'], ['(', ')', "\n", '', ' '], (string) $part);
            $part = trim($part);
            if ($part !== '' && preg_match('/[\p{L}\p{N}]/u', $part) === 1) {
                $parts[] = $part;
            }
        }
    }

    return classroom_content_attachment_normalize_extracted_text(implode(' ', $parts), $maxChars);
}

function classroom_content_find_pdftotext_binary(): ?string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached !== '' ? $cached : null;
    }

    $candidates = ['pdftotext'];
    if (DIRECTORY_SEPARATOR === '\\') {
        $candidates[] = 'C:\\Program Files\\poppler\\Library\\bin\\pdftotext.exe';
        $candidates[] = 'C:\\poppler\\Library\\bin\\pdftotext.exe';
    }

    foreach ($candidates as $candidate) {
        $cmd = escapeshellarg($candidate) . ' -v 2>NUL';
        $result = shell_exec($cmd);
        if (is_string($result) && stripos($result, 'pdftotext') !== false) {
            $cached = $candidate;

            return $candidate;
        }
    }

    $cached = '';

    return null;
}

function classroom_content_attachment_xml_to_text(string $xml): string
{
    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$loaded) {
        $text = strip_tags($xml);

        return classroom_content_attachment_normalize_extracted_text($text, 8000);
    }

    $chunks = [];
    $nodes = $dom->getElementsByTagName('*');
    foreach ($nodes as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }
        if (!in_array($node->localName, ['t', 'p', 'text'], true)) {
            continue;
        }
        $value = trim($node->textContent ?? '');
        if ($value !== '') {
            $chunks[] = $value;
        }
    }

    return classroom_content_attachment_normalize_extracted_text(implode("\n", $chunks), 8000);
}

function classroom_content_attachment_normalize_extracted_text(string $text, int $maxChars): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text) ?? $text;
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    return mb_strlen($text, 'UTF-8') > $maxChars
        ? mb_substr($text, 0, max(0, $maxChars - 1), 'UTF-8') . '…'
        : $text;
}

/**
 * @param list<array{original_name:string,stored_name:string,mime:string,extension:string}> $attachments
 */
function classroom_content_attachment_files_extract_text(array $attachments, int $maxCharsPerFile = 2500): string
{
    $lines = [];
    foreach ($attachments as $attachment) {
        $name = trim((string) ($attachment['original_name'] ?? 'Attachment'));
        $text = classroom_content_attachment_extract_plain_text(
            (string) ($attachment['stored_name'] ?? ''),
            $name,
            (string) ($attachment['mime'] ?? ''),
            $maxCharsPerFile
        );
        if ($text === '') {
            continue;
        }
        $lines[] = 'Attachment "' . $name . '": ' . $text;
    }

    return trim(implode("\n", $lines));
}

/**
 * @param list<array<string,mixed>> $items
 * @return list<array<string,mixed>>
 */
function classroom_content_enrich_week_items(array $items): array
{
    if ($items === []) {
        return [];
    }

    $contentIds = [];
    foreach ($items as $item) {
        $id = (int) ($item['id'] ?? 0);
        if ($id > 0) {
            $contentIds[] = $id;
        }
    }

    $attachmentMap = classroom_content_attachment_map($contentIds);
    $enriched = [];

    foreach ($items as $item) {
        $contentId = (int) ($item['id'] ?? 0);
        $attachments = classroom_content_item_attachment_files($item, $attachmentMap[$contentId] ?? []);
        $item['attachments'] = $attachments;
        $item['attachment_count'] = count($attachments);
        $item['attachment_text'] = classroom_content_attachment_files_extract_text($attachments);
        $enriched[] = $item;
    }

    return $enriched;
}

function classroom_content_extract_alignment(string $style): string
{
    if (preg_match('/text-align\s*:\s*(left|center|right|justify)/i', $style, $matches) !== 1) {
        return '';
    }

    return strtolower((string) ($matches[1] ?? ''));
}

function classroom_content_extract_font_size(string $style): string
{
    if (preg_match('/font-size\s*:\s*(\d+(?:\.\d+)?(?:px|pt|rem|em))/i', $style, $matches) !== 1) {
        return '';
    }

    $size = strtolower((string) ($matches[1] ?? ''));
    if (preg_match('/^(\d+(?:\.\d+)?)(px|pt|rem|em)$/', $size, $parts) !== 1) {
        return '';
    }

    $num = (float) $parts[1];
    $unit = (string) $parts[2];
    if ($unit === 'px' && ($num < 8 || $num > 72)) {
        return '';
    }
    if ($unit === 'pt' && ($num < 6 || $num > 54)) {
        return '';
    }
    if ($unit === 'rem' && ($num < 0.5 || $num > 4)) {
        return '';
    }
    if ($unit === 'em' && ($num < 0.5 || $num > 4)) {
        return '';
    }

    return $num . $unit;
}

function classroom_content_extract_font_family(string $style): string
{
    if (preg_match('/font-family\s*:\s*([^;]+)/i', $style, $matches) !== 1) {
        return '';
    }

    $family = trim((string) ($matches[1] ?? ''));
    if ($family === '') {
        return '';
    }

    $allowed = [
        'arial, helvetica, sans-serif',
        "'times new roman', times, serif",
        'georgia, serif',
        "'courier new', courier, monospace",
        'verdana, sans-serif',
        'tahoma, sans-serif',
    ];
    $normalized = strtolower(str_replace('"', "'", $family));
    foreach ($allowed as $allowedFamily) {
        if ($normalized === $allowedFamily) {
            return $family;
        }
    }

    return '';
}

function classroom_content_build_text_style(string $alignment, string $fontSize, string $fontFamily): string
{
    $parts = [];
    if ($alignment !== '') {
        $parts[] = 'text-align:' . $alignment;
    }
    if ($fontSize !== '') {
        $parts[] = 'font-size:' . $fontSize;
    }
    if ($fontFamily !== '') {
        $parts[] = 'font-family:' . $fontFamily;
    }

    return $parts !== [] ? implode(';', $parts) . ';' : '';
}

function classroom_content_font_size_from_legacy(int $size): string
{
    return match ($size) {
        1 => '10px',
        2 => '12px',
        3 => '14px',
        4 => '16px',
        5 => '18px',
        6 => '24px',
        7 => '32px',
        default => '',
    };
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
        'img' => true,
        'span' => true,
        'font' => true,
        'table' => true,
        'thead' => true,
        'tbody' => true,
        'tfoot' => true,
        'tr' => true,
        'th' => true,
        'td' => true,
        'caption' => true,
        'colgroup' => true,
        'col' => true,
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

    if ($tag === 'font') {
        $styleAttr = (string) $node->getAttribute('style');
        $fontSize = classroom_content_extract_font_size($styleAttr);
        $fontFamily = classroom_content_extract_font_family($styleAttr);
        if ($fontFamily === '') {
            $face = trim((string) $node->getAttribute('face'));
            if ($face !== '') {
                $fontFamily = classroom_content_extract_font_family('font-family:' . $face);
            }
        }
        if ($fontSize === '') {
            $legacySize = (int) $node->getAttribute('size');
            if ($legacySize > 0) {
                $fontSize = classroom_content_font_size_from_legacy($legacySize);
            }
        }

        $style = classroom_content_build_text_style('', $fontSize, $fontFamily);
        if ($style === '') {
            $fragment = $doc->createDocumentFragment();
            foreach ($node->childNodes as $child) {
                $cleanChild = classroom_content_sanitize_html_node($child, $doc);
                if ($cleanChild !== null) {
                    $fragment->appendChild($cleanChild);
                }
            }

            return $fragment;
        }

        $clean = $doc->createElement('span');
        $clean->setAttribute('style', $style);
        foreach ($node->childNodes as $child) {
            $cleanChild = classroom_content_sanitize_html_node($child, $doc);
            if ($cleanChild !== null) {
                $clean->appendChild($cleanChild);
            }
        }

        return $clean;
    }

    $clean = $doc->createElement($tag === 'span' ? 'span' : $tag);

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

    if ($tag === 'img') {
        $safeSrc = classroom_content_sanitize_img_src((string) $node->getAttribute('src'));
        if ($safeSrc === null) {
            return null;
        }
        $clean->setAttribute('src', $safeSrc);
        $alt = trim((string) $node->getAttribute('alt'));
        if ($alt !== '') {
            if (function_exists('mb_substr')) {
                $alt = mb_substr($alt, 0, 200);
            } else {
                $alt = substr($alt, 0, 200);
            }
            $clean->setAttribute('alt', $alt);
        } else {
            $clean->setAttribute('alt', '');
        }
        $clean->setAttribute('loading', 'lazy');
        $clean->setAttribute('decoding', 'async');
        $clean->setAttribute('style', 'max-width:100%;height:auto;');

        return $clean;
    }

    if (in_array($tag, ['th', 'td'], true)) {
        foreach (['colspan', 'rowspan'] as $spanAttr) {
            $spanVal = trim((string) $node->getAttribute($spanAttr));
            if ($spanVal !== '' && ctype_digit($spanVal)) {
                $spanInt = (int) $spanVal;
                if ($spanInt > 1 && $spanInt <= 50) {
                    $clean->setAttribute($spanAttr, (string) $spanInt);
                }
            }
        }
        $scope = strtolower(trim((string) $node->getAttribute('scope')));
        if ($tag === 'th' && in_array($scope, ['col', 'row', 'colgroup', 'rowgroup'], true)) {
            $clean->setAttribute('scope', $scope);
        }
        $styleAttr = (string) $node->getAttribute('style');
        $alignment = classroom_content_extract_alignment($styleAttr);
        if ($alignment === '') {
            $alignment = classroom_content_extract_alignment('text-align:' . (string) $node->getAttribute('align'));
        }
        $fontSize = classroom_content_extract_font_size($styleAttr);
        $fontFamily = classroom_content_extract_font_family($styleAttr);
        $style = classroom_content_build_text_style($alignment, $fontSize, $fontFamily);
        if ($style !== '') {
            $clean->setAttribute('style', $style);
        }
        foreach ($node->childNodes as $child) {
            $cleanChild = classroom_content_sanitize_html_node($child, $doc);
            if ($cleanChild !== null) {
                $clean->appendChild($cleanChild);
            }
        }

        return $clean;
    }

    if ($tag === 'col') {
        $spanVal = trim((string) $node->getAttribute('span'));
        if ($spanVal !== '' && ctype_digit($spanVal)) {
            $spanInt = (int) $spanVal;
            if ($spanInt > 1 && $spanInt <= 50) {
                $clean->setAttribute('span', (string) $spanInt);
            }
        }

        return $clean;
    }

    if ($tag === 'span') {
        $fontSize = classroom_content_extract_font_size((string) $node->getAttribute('style'));
        $fontFamily = classroom_content_extract_font_family((string) $node->getAttribute('style'));
        $style = classroom_content_build_text_style('', $fontSize, $fontFamily);
        if ($style === '') {
            $fragment = $doc->createDocumentFragment();
            foreach ($node->childNodes as $child) {
                $cleanChild = classroom_content_sanitize_html_node($child, $doc);
                if ($cleanChild !== null) {
                    $fragment->appendChild($cleanChild);
                }
            }

            return $fragment;
        }
        $clean->setAttribute('style', $style);
        foreach ($node->childNodes as $child) {
            $cleanChild = classroom_content_sanitize_html_node($child, $doc);
            if ($cleanChild !== null) {
                $clean->appendChild($cleanChild);
            }
        }

        return $clean;
    }

    if (in_array($tag, ['p', 'div', 'li', 'blockquote', 'h2', 'h3'], true)) {
        $styleAttr = (string) $node->getAttribute('style');
        $alignment = classroom_content_extract_alignment($styleAttr);
        if ($alignment === '') {
            $alignment = classroom_content_extract_alignment('text-align:' . (string) $node->getAttribute('align'));
        }
        $fontSize = classroom_content_extract_font_size($styleAttr);
        $fontFamily = classroom_content_extract_font_family($styleAttr);
        $style = classroom_content_build_text_style($alignment, $fontSize, $fontFamily);
        if ($style !== '') {
            $clean->setAttribute('style', $style);
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
    if ($sanitized === '') {
        return null;
    }

    $plain = html_entity_decode(
        strip_tags(str_replace('&nbsp;', ' ', $sanitized)),
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
    );
    $plain = preg_replace('/\s+/u', ' ', $plain ?? '') ?? '';
    $hasImage = preg_match('/<img\b/i', $sanitized) === 1;
    $hasTable = preg_match('/<table\b/i', $sanitized) === 1;

    return trim($plain) === '' && !$hasImage && !$hasTable ? null : $sanitized;
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
 * @return list<array{id:int,title:string,course_code:string,course_name:string,semester:string,school_year:string}>
 */
function faculty_owned_classrooms(int $facultyId, ?int $excludeClassroomId = null): array
{
    if ($facultyId < 1 || !db_table_exists('online_classrooms')) {
        return [];
    }

    $sql = 'SELECT oc.id, oc.title, c.course_code, c.course_name, s.semester, s.school_year
            FROM online_classrooms oc
            INNER JOIN schedules s ON s.id = oc.schedule_id
            INNER JOIN courses c ON c.id = oc.course_id
            WHERE oc.faculty_id = ? AND s.faculty_id = ?';
    $params = [$facultyId, $facultyId];
    if ($excludeClassroomId !== null && $excludeClassroomId > 0) {
        $sql .= ' AND oc.id <> ?';
        $params[] = $excludeClassroomId;
    }
    $sql .= ' ORDER BY s.school_year DESC, s.semester, c.course_code, oc.title';

    $st = db()->prepare($sql);
    $st->execute($params);

    return $st->fetchAll() ?: [];
}

/**
 * @throws RuntimeException
 */
function classroom_content_clone_stored_file(string $storedName): string
{
    $storedName = basename(str_replace('\\', '/', trim($storedName)));
    if ($storedName === '') {
        throw new RuntimeException('Invalid attachment.');
    }

    $source = classroom_content_attachment_storage_path($storedName);
    if (!is_file($source)) {
        throw new RuntimeException('Attachment file is missing.');
    }

    $parts = explode('__', $storedName, 2);
    $downloadPart = $parts[1] ?? 'attachment';
    $newStored = bin2hex(random_bytes(16)) . '__' . $downloadPart;
    $dest = classroom_content_attachment_storage_path($newStored);
    if (!copy($source, $dest)) {
        throw new RuntimeException('Failed to copy attachment.');
    }

    return $newStored;
}

/**
 * @throws RuntimeException
 */
function classroom_content_clone_resource_url(?string $resourceUrl): ?string
{
    $resourceUrl = trim((string) $resourceUrl);
    if ($resourceUrl === '') {
        return null;
    }

    if (classroom_content_is_attachment($resourceUrl)) {
        $stored = basename(str_replace('\\', '/', $resourceUrl));
        $newStored = classroom_content_clone_stored_file($stored);

        return classroom_content_attachment_relative_dir() . '/' . $newStored;
    }

    return $resourceUrl;
}

/**
 * Copy a classroom content item (material, link, or announcement) into another owned classroom.
 *
 * @throws RuntimeException
 */
function classroom_content_copy_to_classroom(
    int $contentId,
    int $sourceClassroomId,
    int $targetClassroomId,
    int $facultyId
): int {
    if ($contentId < 1 || $sourceClassroomId < 1 || $targetClassroomId < 1 || $facultyId < 1) {
        throw new RuntimeException('Invalid content or classroom.');
    }
    if ($sourceClassroomId === $targetClassroomId) {
        throw new RuntimeException('Choose a different course than the current class.');
    }
    if (!db_table_exists('classroom_content')) {
        throw new RuntimeException('Classroom content is not available.');
    }

    $hasContentAttachments = db_table_exists('classroom_content_attachments');
    $hasContentWeeks = db_column_exists('classroom_content', 'weeks');
    $hasContentDaysPerTopic = db_column_exists('classroom_content', 'days_per_topic');
    $hasContentTopicSchedule = $hasContentWeeks && $hasContentDaysPerTopic;
    $hasSyllabusCols = db_column_exists('online_classrooms', 'syllabus_stored_name');

    $st = db()->prepare(
        'SELECT *
         FROM classroom_content
         WHERE id = ? AND classroom_id = ? AND faculty_id = ?
         LIMIT 1'
    );
    $st->execute([$contentId, $sourceClassroomId, $facultyId]);
    $source = $st->fetch();
    if (!$source) {
        throw new RuntimeException('Content item not found.');
    }

    $st = db()->prepare(
        'SELECT oc.id, oc.title, oc.syllabus_stored_name, c.course_code, c.course_name
         FROM online_classrooms oc
         INNER JOIN schedules s ON s.id = oc.schedule_id
         INNER JOIN courses c ON c.id = oc.course_id
         WHERE oc.id = ? AND oc.faculty_id = ? AND s.faculty_id = ?
         LIMIT 1'
    );
    $st->execute([$targetClassroomId, $facultyId, $facultyId]);
    $target = $st->fetch();
    if (!$target) {
        throw new RuntimeException('Target classroom not found or you do not have access to it.');
    }

    if ($hasSyllabusCols && trim((string) ($target['syllabus_stored_name'] ?? '')) === '') {
        $targetLabel = trim((string) ($target['course_code'] ?? ''));
        if ($targetLabel === '') {
            $targetLabel = trim((string) ($target['title'] ?? 'that class'));
        }
        throw new RuntimeException(
            'Upload a syllabus for ' . $targetLabel . ' before assigning content to it.'
        );
    }

    $clonedResourceUrl = classroom_content_clone_resource_url((string) ($source['resource_url'] ?? ''));

    $attachmentRows = [];
    if ($hasContentAttachments) {
        $st = db()->prepare(
            'SELECT original_name, stored_name, mime
             FROM classroom_content_attachments
             WHERE content_id = ?
             ORDER BY created_at ASC, id ASC'
        );
        $st->execute([$contentId]);
        $attachmentRows = $st->fetchAll() ?: [];
    }

    $clonedAttachments = [];
    foreach ($attachmentRows as $row) {
        $clonedAttachments[] = [
            'original_name' => (string) ($row['original_name'] ?? ''),
            'stored_name' => classroom_content_clone_stored_file((string) ($row['stored_name'] ?? '')),
            'mime' => trim((string) ($row['mime'] ?? 'application/octet-stream')) ?: 'application/octet-stream',
        ];
    }

    db()->beginTransaction();
    try {
        if ($hasContentTopicSchedule) {
            db()->prepare(
                'INSERT INTO classroom_content (classroom_id, faculty_id, content_type, title, body, weeks, days_per_topic, resource_url)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute([
                $targetClassroomId,
                $facultyId,
                (string) ($source['content_type'] ?? 'material'),
                (string) ($source['title'] ?? ''),
                ($source['body'] ?? null) !== null && (string) $source['body'] !== '' ? (string) $source['body'] : null,
                (string) ($source['weeks'] ?? ''),
                (string) ($source['days_per_topic'] ?? ''),
                $clonedResourceUrl,
            ]);
        } else {
            db()->prepare(
                'INSERT INTO classroom_content (classroom_id, faculty_id, content_type, title, body, resource_url)
                 VALUES (?,?,?,?,?,?)'
            )->execute([
                $targetClassroomId,
                $facultyId,
                (string) ($source['content_type'] ?? 'material'),
                (string) ($source['title'] ?? ''),
                ($source['body'] ?? null) !== null && (string) $source['body'] !== '' ? (string) $source['body'] : null,
                $clonedResourceUrl,
            ]);
        }

        $newContentId = (int) db()->lastInsertId();
        if ($newContentId < 1) {
            throw new RuntimeException('Failed to assign content.');
        }

        if ($clonedAttachments !== []) {
            $insertAttachment = db()->prepare(
                'INSERT INTO classroom_content_attachments (content_id, original_name, stored_name, mime)
                 VALUES (?,?,?,?)'
            );
            foreach ($clonedAttachments as $attachment) {
                $insertAttachment->execute([
                    $newContentId,
                    $attachment['original_name'],
                    $attachment['stored_name'],
                    $attachment['mime'],
                ]);
            }
        }

        db()->commit();

        return $newContentId;
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }

        if ($clonedResourceUrl !== null && classroom_content_is_attachment($clonedResourceUrl)) {
            $path = classroom_content_attachment_path($clonedResourceUrl);
            if (is_file($path)) {
                @unlink($path);
            }
        }
        foreach ($clonedAttachments as $attachment) {
            $path = classroom_content_attachment_storage_path((string) ($attachment['stored_name'] ?? ''));
            if (is_file($path)) {
                @unlink($path);
            }
        }

        throw $e;
    }
}

/**
 * Copy a quiz or assignment (including its questions) into another owned classroom.
 *
 * @throws RuntimeException
 */
function classroom_assessment_copy_to_classroom(
    int $assessmentId,
    int $sourceClassroomId,
    int $targetClassroomId,
    int $facultyId
): int {
    if ($assessmentId < 1 || $sourceClassroomId < 1 || $targetClassroomId < 1 || $facultyId < 1) {
        throw new RuntimeException('Invalid assessment or classroom.');
    }
    if ($sourceClassroomId === $targetClassroomId) {
        throw new RuntimeException('Choose a different course than the current class.');
    }
    if (!db_table_exists('classroom_assessments') || !db_table_exists('classroom_assessment_questions')) {
        throw new RuntimeException('Assessments are not available.');
    }

    $hasCreditedWeek = db_column_exists('classroom_assessments', 'credited_week');
    $hasTimeLimit = db_column_exists('classroom_assessments', 'time_limit_minutes');

    $st = db()->prepare(
        'SELECT *
         FROM classroom_assessments
         WHERE id = ? AND classroom_id = ? AND faculty_id = ?
         LIMIT 1'
    );
    $st->execute([$assessmentId, $sourceClassroomId, $facultyId]);
    $source = $st->fetch();
    if (!$source) {
        throw new RuntimeException('Assessment not found.');
    }

    $st = db()->prepare(
        'SELECT oc.id, oc.title, c.course_code, c.course_name
         FROM online_classrooms oc
         INNER JOIN schedules s ON s.id = oc.schedule_id
         INNER JOIN courses c ON c.id = oc.course_id
         WHERE oc.id = ? AND oc.faculty_id = ? AND s.faculty_id = ?
         LIMIT 1'
    );
    $st->execute([$targetClassroomId, $facultyId, $facultyId]);
    $target = $st->fetch();
    if (!$target) {
        throw new RuntimeException('Target classroom not found or you do not have access to it.');
    }

    $st = db()->prepare(
        'SELECT question_type, question_text, options_json, answer_key, points, position, word_limit, char_limit, allow_steps
         FROM classroom_assessment_questions
         WHERE assessment_id = ?
         ORDER BY position ASC, id ASC'
    );
    $st->execute([$assessmentId]);
    $questions = $st->fetchAll() ?: [];

    $assessmentType = classroom_assessment_normalize_type((string) ($source['assessment_type'] ?? 'essay'));
    $title = (string) ($source['title'] ?? '');
    $description = ($source['description'] ?? null) !== null && (string) $source['description'] !== ''
        ? (string) $source['description']
        : null;
    $totalPoints = (float) ($source['total_points'] ?? 0);
    $dueAt = ($source['due_at'] ?? null) !== null && (string) $source['due_at'] !== ''
        ? (string) $source['due_at']
        : null;
    $timeLimitMinutes = $hasTimeLimit && ($source['time_limit_minutes'] ?? null) !== null
        ? (int) $source['time_limit_minutes']
        : null;
    $creditedWeek = $hasCreditedWeek ? (string) ($source['credited_week'] ?? '') : '';

    db()->beginTransaction();
    try {
        if ($hasCreditedWeek && $hasTimeLimit) {
            db()->prepare(
                'INSERT INTO classroom_assessments (classroom_id, faculty_id, assessment_type, title, description, total_points, due_at, time_limit_minutes, credited_week)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([
                $targetClassroomId,
                $facultyId,
                $assessmentType,
                $title,
                $description,
                $totalPoints,
                $dueAt,
                $timeLimitMinutes,
                $creditedWeek,
            ]);
        } elseif ($hasTimeLimit) {
            db()->prepare(
                'INSERT INTO classroom_assessments (classroom_id, faculty_id, assessment_type, title, description, total_points, due_at, time_limit_minutes)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute([
                $targetClassroomId,
                $facultyId,
                $assessmentType,
                $title,
                $description,
                $totalPoints,
                $dueAt,
                $timeLimitMinutes,
            ]);
        } elseif ($hasCreditedWeek) {
            db()->prepare(
                'INSERT INTO classroom_assessments (classroom_id, faculty_id, assessment_type, title, description, total_points, due_at, credited_week)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute([
                $targetClassroomId,
                $facultyId,
                $assessmentType,
                $title,
                $description,
                $totalPoints,
                $dueAt,
                $creditedWeek,
            ]);
        } else {
            db()->prepare(
                'INSERT INTO classroom_assessments (classroom_id, faculty_id, assessment_type, title, description, total_points, due_at)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute([
                $targetClassroomId,
                $facultyId,
                $assessmentType,
                $title,
                $description,
                $totalPoints,
                $dueAt,
            ]);
        }

        $newAssessmentId = (int) db()->lastInsertId();
        if ($newAssessmentId < 1) {
            throw new RuntimeException('Failed to assign assessment.');
        }

        if ($questions !== []) {
            $qStmt = db()->prepare(
                'INSERT INTO classroom_assessment_questions
                 (assessment_id, question_type, question_text, options_json, answer_key, points, position, word_limit, char_limit, allow_steps)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            );
            foreach ($questions as $question) {
                $qStmt->execute([
                    $newAssessmentId,
                    (string) ($question['question_type'] ?? 'essay'),
                    (string) ($question['question_text'] ?? ''),
                    ($question['options_json'] ?? null) !== null ? (string) $question['options_json'] : null,
                    ($question['answer_key'] ?? null) !== null && (string) $question['answer_key'] !== ''
                        ? (string) $question['answer_key']
                        : null,
                    (float) ($question['points'] ?? 0),
                    (int) ($question['position'] ?? 1),
                    ($question['word_limit'] ?? null) !== null && (string) $question['word_limit'] !== ''
                        ? (int) $question['word_limit']
                        : null,
                    ($question['char_limit'] ?? null) !== null && (string) $question['char_limit'] !== ''
                        ? (int) $question['char_limit']
                        : null,
                    !empty($question['allow_steps']) ? 1 : 0,
                ]);
            }
        }

        db()->commit();

        return $newAssessmentId;
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }

        throw $e;
    }
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
 * Abbreviate a weekday name for schedule display (MW/TTH-style).
 */
function abbreviate_day_name(string $day): string
{
    static $map = [
        'Monday' => 'M',
        'Tuesday' => 'T',
        'Wednesday' => 'W',
        'Thursday' => 'TH',
        'Friday' => 'F',
        'Saturday' => 'S',
        'Sunday' => 'Su',
    ];
    $day = trim($day);
    return $map[$day] ?? $day;
}

/**
 * Format a MySQL SET or comma-separated day string as abbreviated labels (e.g. "T, TH").
 */
function format_day_set_abbrev(?string $value): string
{
    $days = parse_day_set($value);
    if ($days === []) {
        return '';
    }
    return implode(', ', array_map('abbreviate_day_name', $days));
}

/**
 * Whether a student may record attendance login for a class at the given moment.
 *
 * @param array{day_of_week?: string|null, start_time?: string|null, end_time?: string|null} $scheduleRow
 * @return array{allowed: bool, reason: string, is_scheduled_day: bool, is_within_window: bool, session_start: string, session_end: string}
 */
function classroom_attendance_login_allowed(array $scheduleRow, ?int $timestamp = null): array
{
    $timestamp = $timestamp ?? time();
    $todayDate = date('Y-m-d', $timestamp);
    $nowStr = date('Y-m-d H:i:s', $timestamp);
    $dayName = date('l', $timestamp);

    $normalizeDayToken = static function (string $value): string {
        $lettersOnly = preg_replace('/[^a-z]/i', '', trim($value)) ?? '';
        return strtolower(substr($lettersOnly, 0, 3));
    };

    $scheduleDayTokens = [];
    foreach (parse_day_set((string) ($scheduleRow['day_of_week'] ?? '')) as $scheduledDay) {
        $token = $normalizeDayToken((string) $scheduledDay);
        if ($token !== '') {
            $scheduleDayTokens[$token] = true;
        }
    }
    $isScheduledDay = isset($scheduleDayTokens[$normalizeDayToken($dayName)]);

    $startTime = substr((string) ($scheduleRow['start_time'] ?? '00:00:00'), 0, 8);
    $endTime = substr((string) ($scheduleRow['end_time'] ?? '23:59:59'), 0, 8);
    $sessionStart = $todayDate . ' ' . $startTime;
    $sessionEnd = $todayDate . ' ' . $endTime;
    $isWithinWindow = $nowStr >= $sessionStart && $nowStr <= $sessionEnd;

    $reason = '';
    if ($scheduleDayTokens === []) {
        $reason = 'Class schedule days are not configured yet.';
    } elseif (!$isScheduledDay) {
        $reason = 'Class is not scheduled today.';
    } elseif ($nowStr < $sessionStart) {
        $reason = 'Class has not started yet. You can log in when class time begins.';
    } elseif ($nowStr > $sessionEnd) {
        $reason = 'Class time has already ended.';
    }

    return [
        'allowed' => $scheduleDayTokens !== [] && $isScheduledDay && $isWithinWindow,
        'reason' => $reason,
        'is_scheduled_day' => $isScheduledDay,
        'is_within_window' => $isWithinWindow,
        'session_start' => $sessionStart,
        'session_end' => $sessionEnd,
    ];
}

/**
 * Whether faculty marked a section live (within two hours, and still within today's class window when schedule is known).
 *
 * @param array{day_of_week?: string|null, start_time?: string|null, end_time?: string|null}|null $scheduleRow
 */
function schedule_is_faculty_live(?string $liveAt, ?int $timestamp = null, ?array $scheduleRow = null): bool
{
    if ($liveAt === null || $liveAt === '') {
        return false;
    }
    $timestamp = $timestamp ?? time();
    $t = strtotime($liveAt);
    if ($t === false) {
        return false;
    }
    if (($timestamp - $t) > 2 * 3600) {
        return false;
    }
    if ($scheduleRow !== null) {
        $window = classroom_attendance_login_allowed($scheduleRow, $timestamp);
        if (!empty($window['is_scheduled_day']) && empty($window['is_within_window'])) {
            $sessionEndTs = strtotime($window['session_end']);
            if ($sessionEndTs !== false && $timestamp > $sessionEndTs) {
                return false;
            }
        }
    }

    return true;
}

/**
 * LIVE badge helper: resolves display state and clears stale DB flags when class time or the two-hour window has passed.
 *
 * @param array{id?: int|string, faculty_id?: int|string, online_live_at?: string|null, day_of_week?: string|null, start_time?: string|null, end_time?: string|null} $scheduleRow
 */
function schedule_display_is_faculty_live(array $scheduleRow): bool
{
    $liveAt = isset($scheduleRow['online_live_at']) ? (string) $scheduleRow['online_live_at'] : '';
    if ($liveAt === '') {
        return false;
    }
    if (schedule_is_faculty_live($liveAt, null, $scheduleRow)) {
        return true;
    }

    $scheduleId = (int) ($scheduleRow['id'] ?? 0);
    $facultyId = (int) ($scheduleRow['faculty_id'] ?? 0);
    if ($scheduleId > 0 && $facultyId > 0) {
        faculty_end_live_for_schedule($scheduleId, $facultyId);
    }

    return false;
}

/** Clears online_live_at and closes any open classroom_live_sessions row for one faculty schedule. */
function faculty_end_live_for_schedule(int $scheduleId, int $facultyId): bool
{
    if ($scheduleId < 1 || $facultyId < 1 || !db_column_exists('schedules', 'online_live_at')) {
        return false;
    }

    $facultyCollegeId = faculty_college_id($facultyId);
    $scheduleCollegeClause = $facultyCollegeId !== null ? ' AND college_id=?' : '';
    $scheduleCollegeParam = $facultyCollegeId !== null ? [$facultyCollegeId] : [];

    $chk = db()->prepare(
        'SELECT online_live_at FROM schedules WHERE id=? AND faculty_id=?' . $scheduleCollegeClause . ' LIMIT 1'
    );
    $chk->execute(array_merge([$scheduleId, $facultyId], $scheduleCollegeParam));
    $liveAt = $chk->fetchColumn();
    if ($liveAt === false || $liveAt === null || $liveAt === '') {
        return false;
    }

    db()->prepare('UPDATE schedules SET online_live_at = NULL WHERE id=? AND faculty_id=?' . $scheduleCollegeClause)
        ->execute(array_merge([$scheduleId, $facultyId], $scheduleCollegeParam));

    if (db_table_exists('classroom_live_sessions')) {
        $st = db()->prepare('SELECT id FROM online_classrooms WHERE schedule_id = ? AND faculty_id = ? LIMIT 1');
        $st->execute([$scheduleId, $facultyId]);
        $classroomId = (int) ($st->fetchColumn() ?: 0);
        if ($classroomId > 0) {
            db()->prepare(
                'UPDATE classroom_live_sessions
                 SET ended_at = NOW()
                 WHERE classroom_id = ? AND schedule_id = ? AND faculty_id = ? AND ended_at IS NULL'
            )->execute([$classroomId, $scheduleId, $facultyId]);
        }
    }

    return true;
}

/**
 * Sync schedule URL, mark live during class window, and return the Meet URL for a faculty classroom.
 *
 * @throws RuntimeException
 */
function faculty_open_meet_and_go_live(int $classroomId, int $facultyId): string
{
    if ($classroomId < 1) {
        throw new RuntimeException('Classroom not found.');
    }

    $st = db()->prepare(
        'SELECT oc.id, oc.meet_link, oc.schedule_id, s.day_of_week, s.start_time, s.end_time, s.online_class_url
         FROM online_classrooms oc
         INNER JOIN schedules s ON s.id = oc.schedule_id
         WHERE oc.id = ? AND oc.faculty_id = ? AND s.faculty_id = ?
         LIMIT 1'
    );
    $st->execute([$classroomId, $facultyId, $facultyId]);
    $classroomRow = $st->fetch(PDO::FETCH_ASSOC);
    if (!$classroomRow) {
        throw new RuntimeException('Classroom not found or you do not have access to it.');
    }

    $rawMeet = trim((string) ($classroomRow['meet_link'] ?? ''));
    if ($rawMeet === '') {
        throw new RuntimeException('This classroom does not have a Meet link yet.');
    }

    $meetLink = filter_var($rawMeet, FILTER_VALIDATE_URL);
    if ($meetLink === false) {
        throw new RuntimeException('Please enter a valid Meet URL.');
    }

    $scheme = strtolower((string) (parse_url($meetLink, PHP_URL_SCHEME) ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new RuntimeException('Only http and https meeting links are allowed.');
    }

    $scheduleId = (int) $classroomRow['schedule_id'];
    $hasOnlineUrl = db_column_exists('schedules', 'online_class_url');
    $hasLiveAt = db_column_exists('schedules', 'online_live_at');
    $hasLiveSessions = db_table_exists('classroom_live_sessions');

    if ($hasOnlineUrl) {
        db()->prepare('UPDATE schedules SET online_class_url = ? WHERE id = ? AND faculty_id = ?')
            ->execute([$meetLink, $scheduleId, $facultyId]);
    }

    if ($hasLiveAt && $hasOnlineUrl) {
        $facultyCollegeId = faculty_college_id($facultyId);
        $scheduleCollegeClause = $facultyCollegeId !== null ? ' AND college_id=?' : '';
        $scheduleCollegeParam = $facultyCollegeId !== null ? [$facultyCollegeId] : [];

        db()->prepare('UPDATE schedules SET online_live_at = NOW() WHERE id=? AND faculty_id=?' . $scheduleCollegeClause)
            ->execute(array_merge([$scheduleId, $facultyId], $scheduleCollegeParam));

        if ($hasLiveSessions) {
            $st = db()->prepare(
                'SELECT id
                 FROM classroom_live_sessions
                 WHERE classroom_id = ? AND schedule_id = ? AND faculty_id = ? AND ended_at IS NULL
                 ORDER BY id DESC
                 LIMIT 1'
            );
            $st->execute([$classroomId, $scheduleId, $facultyId]);
            if ((int) ($st->fetchColumn() ?: 0) < 1) {
                db()->prepare(
                    'INSERT INTO classroom_live_sessions (classroom_id, schedule_id, faculty_id, started_at)
                     VALUES (?,?,?,NOW())'
                )->execute([$classroomId, $scheduleId, $facultyId]);
            }
        }
    }

    return $meetLink;
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

/**
 * Whether a GEN ED account may see/join live on a weekly schedule block.
 *
 * @param array<string, mixed> $scheduleRow college_id, course_id, course_is_gened, course_department, program, ge_target_program
 */
function weekly_schedule_gened_can_join_online_live(
    array $scheduleRow,
    int $viewCollegeFilter = 0,
    string $viewProgramFilter = ''
): bool {
    if (!db_column_exists('courses', 'is_gened') || (int) ($scheduleRow['course_is_gened'] ?? 0) !== 1) {
        return false;
    }

    $scheduleCollegeId = (int) ($scheduleRow['college_id'] ?? 0);
    $courseId = (int) ($scheduleRow['course_id'] ?? 0);
    $courseDepartment = trim((string) ($scheduleRow['course_department'] ?? ''));
    $scheduleProgram = trim((string) ($scheduleRow['program'] ?? ''));
    $geTargetProgram = trim((string) ($scheduleRow['ge_target_program'] ?? ''));

    if ($courseId > 0 && $scheduleCollegeId > 0 && db_table_exists('ge_course_colleges')) {
        $chk = db()->prepare('SELECT COUNT(*) FROM ge_course_colleges WHERE course_id = ? AND college_id = ?');
        $chk->execute([$courseId, $scheduleCollegeId]);
        if ((int) $chk->fetchColumn() < 1) {
            return false;
        }
    }

    if ($viewCollegeFilter > 0 && $viewProgramFilter !== '') {
        $programMatches = ($courseDepartment !== '' && strcasecmp($courseDepartment, $viewProgramFilter) === 0)
            || ($scheduleProgram !== '' && strcasecmp($scheduleProgram, $viewProgramFilter) === 0)
            || ($geTargetProgram !== '' && strcasecmp($geTargetProgram, $viewProgramFilter) === 0);

        return $scheduleCollegeId === $viewCollegeFilter && $programMatches;
    }

    return true;
}

/** Program scope label for college GE program chairs (see dean_gened_chair.php). */
function ge_program_chair_label(): string
{
    return 'General Education';
}

function is_ge_program_scope(?string $program): bool
{
    return $program !== null && strcasecmp(trim($program), ge_program_chair_label()) === 0;
}

/**
 * College of Arts and Sciences (CAS) — only this dean may join live GE sessions.
 */
function is_cas_college(?int $collegeId): bool
{
    if ($collegeId === null || $collegeId < 1) {
        return false;
    }

    static $cache = [];
    if (array_key_exists($collegeId, $cache)) {
        return $cache[$collegeId];
    }

    $st = db()->prepare('SELECT college_code, college_name FROM colleges WHERE id = ? LIMIT 1');
    $st->execute([$collegeId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $cache[$collegeId] = false;

        return false;
    }

    $code = strtoupper(trim((string) ($row['college_code'] ?? '')));
    $name = strtolower(trim((string) ($row['college_name'] ?? '')));
    $cache[$collegeId] = $code === 'CAS' || str_contains($name, 'arts and sciences');

    return $cache[$collegeId];
}

/** Whether the logged-in dean may join a live GE class session (CAS dean only). */
function dean_can_join_ge_live_session(): bool
{
    return is_dean() && is_cas_college(current_college_id());
}

/**
 * SQL AND-clause so deans see their college schedules plus cross-college loads and GE rows.
 *
 * @param list<int|string> $params
 */
function dean_schedule_scope_sql(
    int $collegeId,
    bool $hasCourseIsGenedCol,
    array &$params,
    bool $hasGeTargetsTable = false
): string {
    if ($collegeId < 1) {
        return '';
    }

    $parts = ['s.college_id = ?', 'f.college_id = ?', 'c.college_id = ?'];
    $params[] = $collegeId;
    $params[] = $collegeId;
    $params[] = $collegeId;
    if ($hasGeTargetsTable) {
        $parts[] = 'gst.college_id = ?';
        $params[] = $collegeId;
    }
    if ($hasCourseIsGenedCol) {
        $parts[] = 'COALESCE(c.is_gened, 0) = 1';
    }

    return ' AND (' . implode(' OR ', $parts) . ')';
}

/**
 * SQL AND-clause so program chairs see schedules for their program courses and their program faculty.
 *
 * @param list<int|string> $params
 */
function program_chair_schedule_scope_sql(
    int $collegeId,
    string $programScope,
    bool $hasGeTargetsTable,
    array &$params
): string {
    if ($collegeId < 1 || trim($programScope) === '') {
        return '';
    }

    $sql = ' AND (s.college_id = ? OR f.college_id = ? OR c.college_id = ?';
    $params[] = $collegeId;
    $params[] = $collegeId;
    $params[] = $collegeId;
    if ($hasGeTargetsTable) {
        $sql .= ' OR gst.college_id = ?';
        $params[] = $collegeId;
    }
    $sql .= ')';
    if ($hasGeTargetsTable) {
        $sql .= ' AND (f.department = ? OR c.department = ? OR gst.program_name = ?)';
        $params[] = $programScope;
        $params[] = $programScope;
        $params[] = $programScope;
    } else {
        $sql .= ' AND (f.department = ? OR c.department = ?)';
        $params[] = $programScope;
        $params[] = $programScope;
    }

    return $sql;
}

/**
 * SQL AND-clause for General Education program chairs on teaching load reports.
 * Includes all loads taught by GE-designated faculty, plus GE offerings tied to the chair's college.
 *
 * @param list<int|string> $params
 */
function ge_program_chair_teaching_load_scope_sql(
    int $collegeId,
    bool $hasGeTargetsTable,
    bool $hasIsGenedFaculty,
    bool $hasIsGenedCourse,
    array &$params
): string {
    if ($collegeId < 1) {
        return '';
    }

    $parts = [];

    // Full teaching load of GE Faculty (institution-wide roster; college_id often NULL).
    if ($hasIsGenedFaculty) {
        $parts[] = 'COALESCE(f.is_gened, 0) = 1';
    } else {
        $parts[] = 'TRIM(COALESCE(f.department, \'\')) = ?';
        $params[] = ge_program_chair_label();
    }

    // GE catalog offerings scheduled for / targeting this college (any instructor).
    if ($hasIsGenedCourse) {
        $geCollegeParts = ['(COALESCE(c.is_gened, 0) = 1 AND s.college_id = ?)'];
        $params[] = $collegeId;
        if ($hasGeTargetsTable) {
            $geCollegeParts[] = '(COALESCE(c.is_gened, 0) = 1 AND gst.college_id = ?)';
            $params[] = $collegeId;
        }
        $parts[] = '(' . implode(' OR ', $geCollegeParts) . ')';
    }

    if ($parts === []) {
        return '';
    }

    return ' AND (' . implode(' OR ', $parts) . ')';
}

/**
 * GE catalog courses offered to a college (read-only / scheduling reference).
 *
 * @return list<array<string, mixed>>
 */
function ge_courses_offered_to_college(int $collegeId): array
{
    if ($collegeId < 1 || !db_column_exists('courses', 'is_gened') || !db_table_exists('ge_course_colleges')) {
        return [];
    }

    $hasLab = db_column_exists('courses', 'is_laboratory');
    $hasYl = db_column_exists('courses', 'year_level');
    $hasSec = db_column_exists('courses', 'section');
    $hasLec = db_column_exists('courses', 'lecture_units');
    $hasLabU = db_column_exists('courses', 'laboratory_units');

    $extra = ($hasLab ? ', c.is_laboratory' : '')
        . ($hasYl ? ', c.year_level' : '')
        . ($hasSec ? ', c.section' : '')
        . ($hasLec ? ', c.lecture_units' : '')
        . ($hasLabU ? ', c.laboratory_units' : '');

    $st = db()->prepare(
        "SELECT c.id, c.course_code, c.course_name, c.department, c.units{$extra}
         FROM courses c
         INNER JOIN ge_course_colleges gcc ON gcc.course_id = c.id
         WHERE COALESCE(c.is_gened, 0) = 1 AND gcc.college_id = ?
         ORDER BY c.course_code"
    );
    $st->execute([$collegeId]);

    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Major subject / owning program label on a GE course (`courses.department`). */
function ge_course_major_subject(int $courseId): string
{
    if ($courseId < 1 || !db_column_exists('courses', 'is_gened')) {
        return '';
    }

    $st = db()->prepare(
        'SELECT TRIM(COALESCE(department, \'\')) FROM courses WHERE id = ? AND COALESCE(is_gened, 0) = 1 LIMIT 1'
    );
    $st->execute([$courseId]);

    return trim((string) $st->fetchColumn());
}

/**
 * Faculty who may teach a GE course at a college: instructors from the course major
 * program, GE-designated faculty (including institution-wide / null college), or
 * faculty specialized on the course.
 *
 * @return list<array{id:int,faculty_id:string,full_name:string,department:string,is_gened?:int}>
 */
function ge_eligible_faculty_for_course(int $collegeId, int $courseId): array
{
    if ($collegeId < 1 || $courseId < 1) {
        return [];
    }

    $major = ge_course_major_subject($courseId);
    $hasIsGened = db_column_exists('faculty', 'is_gened');
    $hasSpec = db_table_exists('faculty_specializations');

    $sql = 'SELECT DISTINCT f.id, f.faculty_id, f.full_name, TRIM(COALESCE(f.department, \'\')) AS department';
    $sql .= $hasIsGened ? ', COALESCE(f.is_gened, 0) AS is_gened' : ', 0 AS is_gened';
    $sql .= ' FROM faculty f WHERE f.status = \'active\' AND (';

    $params = [];
    $parts = [];

    // Institution-wide GE faculty (college_id NULL) may teach any GE section.
    if ($hasIsGened) {
        $parts[] = '(COALESCE(f.is_gened, 0) = 1 AND (f.college_id IS NULL OR f.college_id = ?))';
        $params[] = $collegeId;
    }

    // Major-program faculty at the target college.
    if ($major !== '') {
        $parts[] = '(f.college_id = ? AND LOWER(TRIM(COALESCE(f.department, \'\'))) = LOWER(?))';
        $params[] = $collegeId;
        $params[] = $major;
    }

    // Explicit roster / specialization assignments (any college membership for that faculty).
    if ($hasSpec) {
        $parts[] = '(f.id IN (SELECT fs.faculty_id FROM faculty_specializations fs WHERE fs.course_id = ?)
                    AND (f.college_id IS NULL OR f.college_id = ?))';
        $params[] = $courseId;
        $params[] = $collegeId;
    }

    if ($parts === []) {
        return [];
    }

    $sql .= implode(' OR ', $parts) . ') ORDER BY f.full_name';
    $st = db()->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Whether a faculty member may be assigned to teach a GE course at a college. */
function ge_faculty_can_teach_course(int $facultyId, int $collegeId, int $courseId): bool
{
    if ($facultyId < 1 || $collegeId < 1 || $courseId < 1) {
        return false;
    }

    foreach (ge_eligible_faculty_for_course($collegeId, $courseId) as $row) {
        if ((int) ($row['id'] ?? 0) === $facultyId) {
            return true;
        }
    }

    return false;
}

/**
 * Active faculty pools for GE schedule forms.
 * Keyed by college_id; key 0 holds institution-wide GE faculty (college_id NULL).
 *
 * @return array<int, list<array{id:int,faculty_id:string,full_name:string,department:string,is_gened:int}>>
 */
function ge_college_faculty_pool_by_college(): array
{
    $hasIsGened = db_column_exists('faculty', 'is_gened');
    $sql = 'SELECT id, college_id, faculty_id, full_name, TRIM(COALESCE(department, \'\')) AS department';
    $sql .= $hasIsGened ? ', COALESCE(is_gened, 0) AS is_gened' : ', 0 AS is_gened';
    $sql .= ' FROM faculty WHERE status = \'active\' ORDER BY full_name';

    $byCollege = [];
    $globalGe = [];
    foreach (db()->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cid = (int) ($row['college_id'] ?? 0);
        $isGened = (int) ($row['is_gened'] ?? 0) === 1;
        unset($row['college_id']);
        if ($cid < 1) {
            if ($isGened) {
                $globalGe[] = $row;
            }
            continue;
        }
        if (!isset($byCollege[$cid])) {
            $byCollege[$cid] = [];
        }
        $byCollege[$cid][] = $row;
    }

    // Merge institution-wide GE faculty into every college pool so gened_schedule filters work.
    if ($globalGe !== []) {
        $byCollege[0] = $globalGe;
        foreach (array_keys($byCollege) as $cid) {
            if ((int) $cid < 1) {
                continue;
            }
            $seen = [];
            foreach ($byCollege[$cid] as $existing) {
                $seen[(int) ($existing['id'] ?? 0)] = true;
            }
            foreach ($globalGe as $gf) {
                $gid = (int) ($gf['id'] ?? 0);
                if ($gid > 0 && empty($seen[$gid])) {
                    $byCollege[$cid][] = $gf;
                    $seen[$gid] = true;
                }
            }
        }
    }

    return $byCollege;
}

/**
 * Faculty IDs specialized per GE course (`faculty_specializations`).
 *
 * @return array<int, list<int>>
 */
function ge_faculty_specializations_by_course(): array
{
    if (!db_table_exists('faculty_specializations')) {
        return [];
    }

    $map = [];
    foreach (db()->query('SELECT faculty_id, course_id FROM faculty_specializations')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $courseId = (int) ($row['course_id'] ?? 0);
        $facultyId = (int) ($row['faculty_id'] ?? 0);
        if ($courseId < 1 || $facultyId < 1) {
            continue;
        }
        if (!isset($map[$courseId])) {
            $map[$courseId] = [];
        }
        if (!in_array($facultyId, $map[$courseId], true)) {
            $map[$courseId][] = $facultyId;
        }
    }

    return $map;
}

/** Whether a GE course is offered to a college via `ge_course_colleges`. */
function ge_course_offered_to_college(int $courseId, int $collegeId): bool
{
    if ($courseId < 1 || $collegeId < 1 || !db_table_exists('ge_course_colleges')) {
        return false;
    }

    $st = db()->prepare('SELECT COUNT(*) FROM ge_course_colleges WHERE course_id = ? AND college_id = ?');
    $st->execute([$courseId, $collegeId]);

    return (int) $st->fetchColumn() > 0;
}

/**
 * Faculty automatically eligible for a GE course (major program match or GE-designated).
 *
 * @param array<string, mixed> $facultyRow department, is_gened
 */
function ge_faculty_auto_eligible_for_course(array $facultyRow, int $courseId): bool
{
    if (!empty($facultyRow['is_gened'])) {
        return true;
    }

    $major = ge_course_major_subject($courseId);
    if ($major === '') {
        return false;
    }

    $dep = trim((string) ($facultyRow['department'] ?? ''));

    return $dep !== '' && strcasecmp($dep, $major) === 0;
}

/**
 * Persist explicit GE roster assignments (`faculty_specializations`) for one course at a college.
 *
 * @param list<int> $explicitFacultyIds Faculty explicitly checked on the roster (non-auto-eligible).
 */
function ge_save_course_faculty_roster(int $collegeId, int $courseId, array $explicitFacultyIds): void
{
    if ($collegeId < 1 || $courseId < 1 || !ge_course_offered_to_college($courseId, $collegeId)) {
        throw new RuntimeException('Invalid GE course for your college.');
    }
    if (!db_table_exists('faculty_specializations')) {
        throw new RuntimeException('Run upgrade_roles.php first to enable faculty specializations.');
    }

    $explicitFacultyIds = array_values(array_unique(array_filter(array_map('intval', $explicitFacultyIds), static fn (int $id): bool => $id > 0)));

    $hasIsGened = db_column_exists('faculty', 'is_gened');
    $stFac = db()->prepare(
        'SELECT id, TRIM(COALESCE(department, \'\')) AS department'
        . ($hasIsGened ? ', COALESCE(is_gened, 0) AS is_gened' : ', 0 AS is_gened')
        . ' FROM faculty WHERE college_id = ? AND status = \'active\''
    );
    $stFac->execute([$collegeId]);
    $collegeFaculty = $stFac->fetchAll(PDO::FETCH_ASSOC);
    $validIds = [];
    foreach ($collegeFaculty as $row) {
        $fid = (int) ($row['id'] ?? 0);
        if ($fid < 1) {
            continue;
        }
        $validIds[$fid] = true;
        if (ge_faculty_auto_eligible_for_course($row, $courseId)) {
            continue;
        }
    }

    $toSave = [];
    foreach ($explicitFacultyIds as $fid) {
        if (!isset($validIds[$fid])) {
            throw new RuntimeException('One or more selected faculty are not in your college.');
        }
        $row = null;
        foreach ($collegeFaculty as $fr) {
            if ((int) ($fr['id'] ?? 0) === $fid) {
                $row = $fr;
                break;
            }
        }
        if ($row !== null && !ge_faculty_auto_eligible_for_course($row, $courseId)) {
            $toSave[] = $fid;
        }
    }

    db()->beginTransaction();
    try {
        $stDel = db()->prepare(
            'DELETE fs FROM faculty_specializations fs
             INNER JOIN faculty f ON f.id = fs.faculty_id
             WHERE fs.course_id = ? AND f.college_id = ?'
        );
        $stDel->execute([$courseId, $collegeId]);
        if ($toSave !== []) {
            $ins = db()->prepare('INSERT INTO faculty_specializations (faculty_id, course_id) VALUES (?, ?)');
            foreach ($toSave as $fid) {
                $ins->execute([$fid, $courseId]);
            }
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }
}

/**
 * Roster rows for one GE course: all active college faculty with eligibility status.
 *
 * @return list<array<string, mixed>>
 */
function ge_course_faculty_roster_rows(
    int $collegeId,
    int $courseId,
    string $programFilter = '',
    string $search = ''
): array {
    if ($collegeId < 1 || $courseId < 1) {
        return [];
    }

    $hasIsGened = db_column_exists('faculty', 'is_gened');
    $hasSpec = db_table_exists('faculty_specializations');
    $assignedIds = [];
    if ($hasSpec) {
        $st = db()->prepare(
            'SELECT fs.faculty_id FROM faculty_specializations fs
             INNER JOIN faculty f ON f.id = fs.faculty_id
             WHERE fs.course_id = ? AND f.college_id = ?'
        );
        $st->execute([$courseId, $collegeId]);
        $assignedIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    $sql = 'SELECT id, faculty_id, full_name, TRIM(COALESCE(department, \'\')) AS department, status';
    $sql .= $hasIsGened ? ', COALESCE(is_gened, 0) AS is_gened' : ', 0 AS is_gened';
    // College faculty plus institution-wide GE Faculty (college_id NULL).
    $sql .= ' FROM faculty WHERE status = \'active\' AND (college_id = ?';
    $params = [$collegeId];
    if ($hasIsGened) {
        $sql .= ' OR (COALESCE(is_gened, 0) = 1 AND college_id IS NULL)';
    }
    $sql .= ')';

    if ($programFilter !== '') {
        $sql .= ' AND department = ?';
        $params[] = $programFilter;
    }
    if ($search !== '') {
        $sql .= ' AND (full_name LIKE ? OR faculty_id LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }
    $sql .= ' ORDER BY COALESCE(is_gened, 0) DESC, department, full_name';

    $st = db()->prepare($sql);
    $st->execute($params);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fid = (int) ($row['id'] ?? 0);
        $auto = ge_faculty_auto_eligible_for_course($row, $courseId);
        $assigned = in_array($fid, $assignedIds, true);
        $rows[] = [
            'id' => $fid,
            'faculty_id' => (string) ($row['faculty_id'] ?? ''),
            'full_name' => (string) ($row['full_name'] ?? ''),
            'department' => (string) ($row['department'] ?? ''),
            'is_gened' => (int) ($row['is_gened'] ?? 0),
            'auto_eligible' => $auto,
            'roster_assigned' => $assigned,
            'eligible' => $auto || $assigned,
        ];
    }

    return $rows;
}

/**
 * Faculty selectable on dean/program-chair college schedules:
 * college faculty (optionally limited to program) plus active GE-designated faculty
 * (institution-wide / null college, or assigned to this college).
 *
 * @return list<array{id:int,faculty_id:string,full_name:string,is_gened:int}>
 */
function college_schedule_faculty_options(int $collegeId, ?string $programScope = null): array
{
    if ($collegeId < 1) {
        return [];
    }

    $hasIsGened = db_column_exists('faculty', 'is_gened');
    $sql = 'SELECT id, faculty_id, full_name';
    $sql .= $hasIsGened ? ', COALESCE(is_gened, 0) AS is_gened' : ', 0 AS is_gened';
    $sql .= ' FROM faculty WHERE status = \'active\' AND (';

    $params = [];
    if ($programScope !== null && $programScope !== '') {
        $sql .= '(college_id = ? AND (department = ? OR department = \'\'))';
        $params[] = $collegeId;
        $params[] = $programScope;
    } else {
        $sql .= 'college_id = ?';
        $params[] = $collegeId;
    }

    if ($hasIsGened) {
        $sql .= ' OR (COALESCE(is_gened, 0) = 1 AND (college_id IS NULL OR college_id = ?))';
        $params[] = $collegeId;
    }

    $sql .= ') ORDER BY COALESCE(is_gened, 0) ASC, full_name';
    $st = db()->prepare($sql);
    $st->execute($params);

    $seen = [];
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1 || isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $out[] = [
            'id' => $id,
            'faculty_id' => (string) ($row['faculty_id'] ?? ''),
            'full_name' => (string) ($row['full_name'] ?? ''),
            'is_gened' => (int) ($row['is_gened'] ?? 0),
        ];
    }

    return $out;
}

/** Whether a faculty member may be assigned on a dean/program-chair college schedule. */
function college_schedule_faculty_allowed(int $facultyId, int $collegeId, ?string $programScope = null): bool
{
    if ($facultyId < 1 || $collegeId < 1) {
        return false;
    }

    foreach (college_schedule_faculty_options($collegeId, $programScope) as $row) {
        if ((int) ($row['id'] ?? 0) === $facultyId) {
            return true;
        }
    }

    return false;
}

/**
 * How online/live appears on the weekly schedule for one block.
 *
 * @param array<string, mixed> $scheduleRow
 * @return 'normal'|'hidden'|'unauthorized'
 */
function weekly_schedule_online_live_mode(
    string $role,
    array $scheduleRow,
    int $viewCollegeFilter = 0,
    string $viewProgramFilter = ''
): string {
    if ($role === 'gened') {
        return weekly_schedule_gened_can_join_online_live($scheduleRow, $viewCollegeFilter, $viewProgramFilter)
            ? 'normal'
            : 'hidden';
    }

    $isGeCourse = db_column_exists('courses', 'is_gened') && (int) ($scheduleRow['course_is_gened'] ?? 0) === 1;

    if ($role === 'program_chair' && $isGeCourse) {
        return 'unauthorized';
    }

    if ($role === 'dean' && $isGeCourse) {
        return dean_can_join_ge_live_session() ? 'normal' : 'unauthorized';
    }

    return 'normal';
}

function time_to_minutes(string $time): int
{
    $parts = explode(':', $time);
    $h = (int) ($parts[0] ?? 0);
    $m = (int) ($parts[1] ?? 0);
    $s = (int) ($parts[2] ?? 0);
    return $h * 60 + $m + (int) round($s / 60);
}

/**
 * Stored lecture/laboratory kind from schedule create/edit (segment label).
 */
function schedule_segment_session_kind(string $label): string
{
    return strcasecmp(trim($label), 'Laboratory') === 0 ? 'laboratory' : 'lecture';
}

/**
 * Whether a scheduled block counts as laboratory (vs lecture) for load/contact hours.
 * Uses stored session_kind when present. Pure lecture courses are never labeled Laboratory
 * from duration alone (a one-day 3-hour lecture stays Lecture).
 * For laboratory courses with no session_kind, 1 lab unit ≈ 3 hours → typical 3–4 h blocks.
 *
 * @param bool|null $courseIsLaboratory When false, duration heuristic is skipped (always lecture).
 */
function schedule_session_is_laboratory(
    ?string $startTime,
    ?string $endTime,
    ?string $roomType = null,
    ?string $sessionKind = null,
    ?bool $courseIsLaboratory = null
): bool {
    unset($roomType);
    $kind = strtolower(trim((string) $sessionKind));
    if ($kind === 'laboratory') {
        return true;
    }
    if ($kind === 'lecture') {
        return false;
    }

    // Pure lecture course: never treat a long block as lab just because of duration.
    if ($courseIsLaboratory === false) {
        return false;
    }

    $st = time_to_minutes(substr((string) $startTime, 0, 8));
    $en = time_to_minutes(substr((string) $endTime, 0, 8));
    $durationMinutes = $en - $st;
    if ($durationMinutes <= 0) {
        return false;
    }
    $hours = $durationMinutes / 60.0;

    return $hours >= 3.0 && $hours <= 4.0;
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
 * Default meeting days implied by a schedule type (empty for Custom).
 *
 * @return string[]
 */
function days_for_schedule_type(string $scheduleType): array
{
    switch ($scheduleType) {
        case 'MW':
            return ['Monday', 'Wednesday'];
        case 'TTH':
            return ['Tuesday', 'Thursday'];
        case 'MWF':
            return ['Monday', 'Wednesday', 'Friday'];
        case 'TTHS':
            return ['Tuesday', 'Thursday', 'Saturday'];
        case 'Saturday':
            return ['Saturday'];
        case 'Sunday':
            return ['Sunday'];
        case 'MW_TTH':
            return ['Monday', 'Tuesday', 'Wednesday', 'Thursday'];
        default:
            return [];
    }
}

/**
 * When no days were posted, fill from schedule type presets (MW, TTH, …).
 *
 * @param string[] $days
 * @return string[]
 */
function normalize_schedule_days(string $scheduleType, array $days): array
{
    if ($days !== []) {
        return array_values(array_unique(array_map('strval', $days)));
    }

    return days_for_schedule_type($scheduleType);
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
    $sql = "SELECT s.id, s.start_time, s.end_time, COALESCE(f.full_name, '') AS faculty_name
            FROM schedules s
            LEFT JOIN faculty f ON f.id = s.faculty_id
            WHERE s.room_id = ? AND s.semester = ? AND s.school_year = ?
            AND FIND_IN_SET(?, s.day_of_week) > 0";
    $params = [$roomId, $semester, $schoolYear, $day];
    if ($excludeId !== null) {
        $sql .= ' AND s.id != ?';
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

    $sql = "SELECT s.id, s.start_time, s.end_time, c.college_name, COALESCE(f.full_name, '') AS faculty_name
            FROM schedules s
            LEFT JOIN colleges c ON c.id = s.college_id
            LEFT JOIN faculty f ON f.id = s.faculty_id
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
            $bookedBy = trim((string) ($row['faculty_name'] ?? ''));
            $byClause = $bookedBy !== '' ? " ({$bookedBy})" : '';
            $cross[] = "Cross-college room conflict on {$day}: room is used by {$collegeName}{$byClause}.";
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

    $facultyNameStmt = db()->prepare('SELECT full_name FROM faculty WHERE id = ? LIMIT 1');
    $facultyNameStmt->execute([$faculty_id]);
    $facultyName = trim((string) ($facultyNameStmt->fetchColumn() ?: ''));
    if ($facultyName === '') {
        $facultyName = 'Faculty';
    }

    foreach ($days as $day) {
        // Faculty overlap (always internal because faculty belongs to one college)
        $frows = fetch_faculty_day_schedules($faculty_id, $day, $semester, $school_year, $exclude_id);
        foreach ($frows as $row) {
            $rs = time_to_minutes(substr($row['start_time'], 0, 8));
            $re = time_to_minutes(substr($row['end_time'], 0, 8));
            if (intervals_overlap($st, $en, $rs, $re)) {
                $existingStart = substr((string) $row['start_time'], 0, 8);
                $existingEnd = substr((string) $row['end_time'], 0, 8);
                $conflicts[] = [
                    'type' => 'faculty',
                    'description' => "{$facultyName} already has a class on {$day} overlapping {$start_time}-{$end_time} (existing: {$existingStart}-{$existingEnd}).",
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
                    $bookedBy = trim((string) ($row['faculty_name'] ?? ''));
                    $byClause = $bookedBy !== '' ? " by {$bookedBy}" : '';
                    $conflicts[] = [
                        'type' => 'room',
                        'description' => "Room is already booked on {$day} during this time{$byClause}.",
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
                'description' => "{$facultyName} would exceed {$maxH} hours on {$day}.",
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

/**
 * Find another faculty member who already holds this course load for the term.
 *
 * @param array{college_id?:int,program_name?:string,year_level?:string,section?:string}|null $geTarget
 * @return array{faculty_id:int,full_name:string,faculty_code:string}|null
 */
function find_course_load_assigned_faculty(
    int $courseId,
    string $semester,
    string $schoolYear,
    int $exceptFacultyId,
    ?array $geTarget = null
): ?array {
    if ($courseId < 1 || $semester === '' || $schoolYear === '') {
        return null;
    }

    $useGeTarget = $geTarget !== null
        && db_table_exists('ge_schedule_targets')
        && (int) ($geTarget['college_id'] ?? 0) > 0
        && trim((string) ($geTarget['program_name'] ?? '')) !== ''
        && trim((string) ($geTarget['year_level'] ?? '')) !== ''
        && trim((string) ($geTarget['section'] ?? '')) !== '';

    if ($useGeTarget) {
        $st = db()->prepare(
            'SELECT DISTINCT f.id AS faculty_id, f.full_name, f.faculty_id AS faculty_code
             FROM schedules s
             INNER JOIN faculty f ON f.id = s.faculty_id
             INNER JOIN ge_schedule_targets gst ON gst.schedule_id = s.id
             WHERE s.course_id = ?
               AND s.semester = ?
               AND s.school_year = ?
               AND s.faculty_id <> ?
               AND gst.college_id = ?
               AND gst.program_name = ?
               AND gst.year_level = ?
               AND gst.section = ?
             LIMIT 1'
        );
        $st->execute([
            $courseId,
            $semester,
            $schoolYear,
            $exceptFacultyId,
            (int) $geTarget['college_id'],
            trim((string) $geTarget['program_name']),
            trim((string) $geTarget['year_level']),
            trim((string) $geTarget['section']),
        ]);
    } else {
        $st = db()->prepare(
            'SELECT DISTINCT f.id AS faculty_id, f.full_name, f.faculty_id AS faculty_code
             FROM schedules s
             INNER JOIN faculty f ON f.id = s.faculty_id
             WHERE s.course_id = ?
               AND s.semester = ?
               AND s.school_year = ?
               AND s.faculty_id <> ?
             LIMIT 1'
        );
        $st->execute([$courseId, $semester, $schoolYear, $exceptFacultyId]);
    }

    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return [
        'faculty_id' => (int) ($row['faculty_id'] ?? 0),
        'full_name' => trim((string) ($row['full_name'] ?? '')),
        'faculty_code' => trim((string) ($row['faculty_code'] ?? '')),
    ];
}

/**
 * @param array{faculty_id:int,full_name:string,faculty_code:string} $assignedFaculty
 * @param array{college_id?:int,program_name?:string,year_level?:string,section?:string}|null $geTarget
 */
function course_load_assignment_conflict_message(
    array $assignedFaculty,
    int $courseId = 0,
    ?array $geTarget = null
): string {
    $name = trim((string) ($assignedFaculty['full_name'] ?? ''));
    $code = trim((string) ($assignedFaculty['faculty_code'] ?? ''));
    $who = $name !== '' ? $name : 'another faculty member';
    if ($code !== '') {
        $who .= ' (' . $code . ')';
    }

    $sectionLabel = '';
    if ($geTarget !== null) {
        $program = trim((string) ($geTarget['program_name'] ?? ''));
        $yearLevel = trim((string) ($geTarget['year_level'] ?? ''));
        $section = trim((string) ($geTarget['section'] ?? ''));
        if ($program !== '' && $yearLevel !== '' && $section !== '') {
            $sectionLabel = $program . ' Y' . $yearLevel . '-' . $section;
        }
    } elseif ($courseId > 0 && db_column_exists('courses', 'section') && db_column_exists('courses', 'year_level')) {
        $st = db()->prepare('SELECT course_code, year_level, section FROM courses WHERE id=? LIMIT 1');
        $st->execute([$courseId]);
        $crow = $st->fetch(PDO::FETCH_ASSOC);
        if ($crow) {
            $courseCode = trim((string) ($crow['course_code'] ?? ''));
            $yearLevel = trim((string) ($crow['year_level'] ?? ''));
            $section = trim((string) ($crow['section'] ?? ''));
            if ($yearLevel !== '' && $section !== '') {
                $sectionLabel = ($courseCode !== '' ? $courseCode . ', ' : '') . 'Y' . $yearLevel . '-' . $section;
            } elseif ($courseCode !== '') {
                $sectionLabel = $courseCode;
            }
        }
    }

    if ($sectionLabel !== '') {
        return 'This course load for ' . $sectionLabel . ' is already assigned to ' . $who . '.';
    }

    return 'This course load is already assigned to ' . $who . '.';
}

/** Full name of the administrator who signs as VPAA on teaching load memoranda. */
function vpaa_signatory_full_name(): string
{
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }

    if (defined('VPAA_ADMIN_USER_ID') && (int) VPAA_ADMIN_USER_ID > 0) {
        $st = db()->prepare(
            'SELECT full_name FROM users WHERE id = ? AND is_active = 1 AND role IN (\'admin\', \'super_admin\') LIMIT 1'
        );
        $st->execute([(int) VPAA_ADMIN_USER_ID]);
        $name = trim((string) ($st->fetchColumn() ?: ''));
        if ($name !== '') {
            $resolved = $name;

            return $resolved;
        }
    }

    if (db_column_exists('users', 'admin_log_title')) {
        $st = db()->query(
            'SELECT full_name, admin_log_title
             FROM users
             WHERE is_active = 1 AND role = \'admin\' AND admin_log_title != \'\'
             ORDER BY id ASC'
        );
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $title = strtolower(trim((string) ($row['admin_log_title'] ?? '')));
            $isVpaa = ($title !== '' && str_contains($title, 'vice president') && str_contains($title, 'academic affairs'))
                || $title === 'vpaa'
                || str_contains($title, 'vp for academic affairs');
            if (!$isVpaa) {
                continue;
            }
            $name = trim((string) ($row['full_name'] ?? ''));
            if ($name !== '') {
                $resolved = $name;

                return $resolved;
            }
        }
    }

    $st = db()->query(
        'SELECT full_name FROM users WHERE is_active = 1 AND role = \'admin\' ORDER BY id ASC LIMIT 1'
    );
    $resolved = trim((string) ($st->fetchColumn() ?: ''));

    return $resolved;
}

/**
 * Show schedule validation/conflict errors as a warning popup modal.
 *
 * @param list<string> $errors
 */
function render_schedule_errors_warning_popup(
    array $errors,
    string $title = 'Warning — Please fix the following',
    string $okLabel = 'OK'
): void {
    if ($errors === []) {
        return;
    }
    $modalId = 'scheduleErrorsWarningModal';
    ?>
    <div class="modal fade" id="<?= htmlspecialchars($modalId) ?>" tabindex="-1" aria-labelledby="<?= htmlspecialchars($modalId) ?>Label" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-warning">
                <div class="modal-header bg-warning-subtle">
                    <h5 class="modal-title text-warning-emphasis" id="<?= htmlspecialchars($modalId) ?>Label">
                        <i class="fa-solid fa-triangle-exclamation me-2"></i><?= htmlspecialchars($title) ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <ul class="mb-0">
                        <?php foreach ($errors as $e): ?>
                            <li><?= htmlspecialchars((string) $e) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-warning" data-bs-dismiss="modal"><?= htmlspecialchars($okLabel) ?></button>
                </div>
            </div>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var modalEl = document.getElementById(<?= json_encode($modalId) ?>);
        if (!modalEl) return;
        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
            return;
        }
        var items = [];
        modalEl.querySelectorAll('.modal-body li').forEach(function (li) {
            items.push(li.textContent.trim());
        });
        window.alert(<?= json_encode($title) ?> + ':\n\n- ' + items.join('\n- '));
    });
    </script>
    <?php
}

/**
 * Show a single notice as an Information popup modal.
 */
function render_information_popup(
    string $message,
    string $title = 'Information',
    string $okLabel = 'OK'
): void {
    $message = trim($message);
    if ($message === '') {
        return;
    }
    $modalId = 'appInformationModal';
    ?>
    <div class="modal fade no-print" id="<?= htmlspecialchars($modalId) ?>" tabindex="-1" aria-labelledby="<?= htmlspecialchars($modalId) ?>Label" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-info">
                <div class="modal-header bg-info-subtle">
                    <h5 class="modal-title text-info-emphasis" id="<?= htmlspecialchars($modalId) ?>Label">
                        <i class="fa-solid fa-circle-info me-2"></i><?= htmlspecialchars($title) ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?= htmlspecialchars($message) ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-info" data-bs-dismiss="modal"><?= htmlspecialchars($okLabel) ?></button>
                </div>
            </div>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var modalEl = document.getElementById(<?= json_encode($modalId) ?>);
        if (!modalEl) return;
        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
            return;
        }
        var body = modalEl.querySelector('.modal-body');
        window.alert(<?= json_encode($title) ?> + ':\n\n' + (body ? body.textContent.trim() : ''));
    });
    </script>
    <?php
}

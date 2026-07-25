<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/assessment_retake_helpers.php';
require_once __DIR__ . '/includes/wellness_ai.php';

require_role(['faculty', 'program_chair', 'dean', 'gened']);

assessment_retake_ensure_table();

$facultyId = isset($_SESSION['faculty_id']) ? (int) $_SESSION['faculty_id'] : 0;
$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($facultyId < 1) {
    $facultyId = resolve_faculty_id_for_user($userId) ?? 0;
    $_SESSION['faculty_id'] = $facultyId > 0 ? $facultyId : null;
}
if ($facultyId < 1 && in_array($_SESSION['role'] ?? '', ['program_chair', 'dean', 'gened'], true)) {
    $facultyId = ensure_faculty_profile_for_teaching_role($userId) ?? 0;
    if ($facultyId > 0) {
        $_SESSION['faculty_id'] = $facultyId;
    }
}
if ($facultyId < 1) {
    exit('Faculty profile not linked to this account. Ask your dean to create/link your faculty profile.');
}

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$classroomId = (int) ($_GET['id'] ?? $_POST['classroom_id'] ?? 0);
if (db_table_exists('classroom_assessments') && !db_column_exists('classroom_assessments', 'time_limit_minutes')) {
    try {
        db()->exec('ALTER TABLE classroom_assessments ADD COLUMN time_limit_minutes INT UNSIGNED NULL DEFAULT NULL');
    } catch (Throwable $e) {
        // Column may already exist under a concurrent request.
    }
}
$hasCreditedWeek = db_column_exists('classroom_assessments', 'credited_week');
$hasTimeLimit = db_column_exists('classroom_assessments', 'time_limit_minutes');
$hasIntegrityLocked = db_column_exists('classroom_submissions', 'integrity_locked');
$requiredTables = [
    'online_classrooms',
    'classroom_students',
    'classroom_enrollments',
    'classroom_assessments',
    'classroom_scores',
    'classroom_submissions',
    'classroom_assessment_questions',
    'classroom_submission_question_answers',
];
$missingTables = array_values(array_filter(
    $requiredTables,
    static fn (string $table): bool => !db_table_exists($table)
));

function faculty_assessment_datetime_save(?string $value): ?string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }
    $raw = str_replace('T', ' ', $raw);
    if (strlen($raw) === 16) {
        $raw .= ':00';
    }
    return $raw;
}

/** @param array{score?: mixed, feedback?: mixed} $saved */
function faculty_submission_grading_status(array $submission, array $saved): string
{
    $hasSubmission = trim((string) ($submission['answer_text'] ?? '')) !== '';
    $scoreRaw = $saved['score'] ?? null;
    $hasScore = $scoreRaw !== null && $scoreRaw !== '' && is_numeric($scoreRaw);
    $hasFeedback = trim((string) ($saved['feedback'] ?? '')) !== '';
    if ($hasScore || $hasFeedback) {
        return 'graded';
    }
    if ($hasSubmission) {
        return 'submitted';
    }
    return 'pending';
}

function faculty_assessment_due_for_input(?string $dueAt): string
{
    if ($dueAt === null || trim($dueAt) === '') {
        return '';
    }
    $ts = strtotime((string) $dueAt);
    if ($ts === false) {
        return '';
    }
    return date('Y-m-d\TH:i', $ts);
}

/** Parse timer duration from POST (hours + minutes, or legacy minutes-only). Null = no timer. */
function faculty_assessment_time_limit_from_post(array $post): ?int
{
    $enabled = !empty($post['timer_enabled']);
    if (!$enabled) {
        return null;
    }
    $hours = max(0, (int) ($post['timer_hours'] ?? 0));
    $minutes = max(0, (int) ($post['timer_minutes'] ?? 0));
    if (isset($post['time_limit_minutes']) && (string) ($post['timer_hours'] ?? '') === '' && (string) ($post['timer_minutes'] ?? '') === '') {
        $minutes = max(0, (int) $post['time_limit_minutes']);
    }
    $total = ($hours * 60) + $minutes;
    if ($total < 1) {
        return null;
    }
    return min(720, $total);
}

function faculty_assess_lc(string $s): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s);
}

/**
 * @return list<array{
 *   question_type:string,
 *   question_text:string,
 *   points:float,
 *   options_json:?string,
 *   answer_key:?string,
 *   word_limit:?int,
 *   char_limit:?int,
 *   allow_steps:int
 * }>
 */
function faculty_parse_questions_json(string $json, string $assessmentType): array
{
    $expectedType = classroom_assessment_normalize_type($assessmentType);
    $rows = json_decode($json, true);
    if (!is_array($rows)) {
        throw new RuntimeException('Question builder payload is invalid.');
    }
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $type = classroom_question_normalize_type((string) ($row['question_type'] ?? $expectedType));
        if ($type !== $expectedType) {
            throw new RuntimeException('All questions must match the selected assessment type: ' . classroom_assessment_type_label($expectedType) . '.');
        }
        $questionText = trim((string) ($row['question_text'] ?? ''));
        $points = (float) ($row['points'] ?? 0);
        if ($questionText === '' || $points <= 0) {
            continue;
        }

        $options = null;
        $answerKey = trim((string) ($row['answer_key'] ?? ''));
        if ($type === 'multiple_choice') {
            $rawOptions = $row['options'] ?? [];
            $clean = [];
            if (is_array($rawOptions)) {
                foreach ($rawOptions as $opt) {
                    $text = trim((string) $opt);
                    if ($text !== '') {
                        $clean[] = $text;
                    }
                }
            }
            if (count($clean) < 2 || count($clean) > 6) {
                throw new RuntimeException('Multiple choice questions must have 2 to 6 options.');
            }
            if ($answerKey === '') {
                throw new RuntimeException('Multiple choice questions need an answer key.');
            }
            $options = json_encode(array_values($clean), JSON_UNESCAPED_UNICODE);
        } elseif ($type === 'true_false') {
            $keyNorm = strtolower($answerKey);
            if (!in_array($keyNorm, ['true', 'false'], true)) {
                throw new RuntimeException('True/False questions require an answer key of True or False.');
            }
            $answerKey = $keyNorm;
        } elseif ($type === 'problem_solving') {
            if ($answerKey === '') {
                throw new RuntimeException('Problem-solving questions require a final answer key.');
            }
        } else {
            $answerKey = null;
        }

        $wordLimit = isset($row['word_limit']) && (string) $row['word_limit'] !== '' ? max(0, (int) $row['word_limit']) : null;
        $charLimit = isset($row['char_limit']) && (string) $row['char_limit'] !== '' ? max(0, (int) $row['char_limit']) : null;
        $allowSteps = !empty($row['allow_steps']) ? 1 : 0;

        $out[] = [
            'question_type' => $type,
            'question_text' => $questionText,
            'points' => $points,
            'options_json' => $options,
            'answer_key' => $answerKey !== '' ? $answerKey : null,
            'word_limit' => $wordLimit,
            'char_limit' => $charLimit,
            'allow_steps' => $allowSteps,
        ];
    }
    if ($out === []) {
        throw new RuntimeException('Add at least one question.');
    }
    return $out;
}

$classroom = null;
if ($classroomId > 0 && $missingTables === []) {
    $st = db()->prepare(
        'SELECT oc.*, s.semester, s.school_year, s.day_of_week, s.start_time, s.end_time, s.id AS schedule_id, s.college_id,
                c.course_code, c.course_name
         FROM online_classrooms oc
         INNER JOIN schedules s ON s.id = oc.schedule_id
         INNER JOIN courses c ON c.id = oc.course_id
         WHERE oc.id = ? AND oc.faculty_id = ? AND s.faculty_id = ?
         LIMIT 1'
    );
    $st->execute([$classroomId, $facultyId, $facultyId]);
    $classroom = $st->fetch() ?: null;
}

if ($missingTables === [] && !$classroom) {
    http_response_code(404);
    exit('Classroom not found or you do not have access to it.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $missingTables === [] && $classroom) {
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'add_assessment') {
            $assessmentType = classroom_assessment_normalize_type((string) ($_POST['assessment_type'] ?? 'essay'));
            $title = trim((string) ($_POST['title'] ?? ''));
            $description = classroom_content_prepare_body((string) ($_POST['description'] ?? ''));
            $totalPoints = (float) ($_POST['total_points'] ?? 0);
            $dueAt = faculty_assessment_datetime_save((string) ($_POST['due_at'] ?? ''));
            $timeLimitMinutes = $hasTimeLimit ? faculty_assessment_time_limit_from_post($_POST) : null;
            $creditedWeek = $hasCreditedWeek ? trim((string) ($_POST['credited_week'] ?? '')) : '';
            $questions = faculty_parse_questions_json((string) ($_POST['questions_json'] ?? '[]'), $assessmentType);

            if ($title === '') {
                throw new RuntimeException('Assessment title is required.');
            }
            $calculatedPoints = array_sum(array_map(static fn (array $q): float => (float) $q['points'], $questions));
            if ($calculatedPoints <= 0) {
                throw new RuntimeException('Total points must be greater than zero.');
            }
            $totalPoints = $calculatedPoints;

            db()->beginTransaction();
            if ($hasCreditedWeek && $hasTimeLimit) {
                db()->prepare(
                    'INSERT INTO classroom_assessments (classroom_id, faculty_id, assessment_type, title, description, total_points, due_at, time_limit_minutes, credited_week)
                     VALUES (?,?,?,?,?,?,?,?,?)'
                )->execute([
                    $classroomId,
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
                    $classroomId,
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
                    $classroomId,
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
                    $classroomId,
                    $facultyId,
                    $assessmentType,
                    $title,
                    $description,
                    $totalPoints,
                    $dueAt,
                ]);
            }
            $assessmentId = (int) db()->lastInsertId();
            $qStmt = db()->prepare(
                'INSERT INTO classroom_assessment_questions
                (assessment_id, question_type, question_text, options_json, answer_key, points, position, word_limit, char_limit, allow_steps)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            );
            foreach ($questions as $idx => $q) {
                $qStmt->execute([
                    $assessmentId,
                    $q['question_type'],
                    $q['question_text'],
                    $q['options_json'],
                    $q['answer_key'],
                    $q['points'],
                    $idx + 1,
                    $q['word_limit'],
                    $q['char_limit'],
                    $q['allow_steps'],
                ]);
            }
            db()->commit();

            $_SESSION['flash'] = classroom_assessment_type_label($assessmentType) . ' added successfully.';
        } elseif ($action === 'update_assessment') {
            $assessmentId = (int) ($_POST['assessment_id'] ?? 0);
            $assessmentType = classroom_assessment_normalize_type((string) ($_POST['assessment_type'] ?? 'essay'));
            $title = trim((string) ($_POST['title'] ?? ''));
            $description = classroom_content_prepare_body((string) ($_POST['description'] ?? ''));
            $totalPoints = (float) ($_POST['total_points'] ?? 0);
            $dueAt = faculty_assessment_datetime_save((string) ($_POST['due_at'] ?? ''));
            $timeLimitMinutes = $hasTimeLimit ? faculty_assessment_time_limit_from_post($_POST) : null;
            $creditedWeek = $hasCreditedWeek ? trim((string) ($_POST['credited_week'] ?? '')) : '';
            $questions = faculty_parse_questions_json((string) ($_POST['questions_json'] ?? '[]'), $assessmentType);

            if ($assessmentId < 1) {
                throw new RuntimeException('Invalid assessment.');
            }
            if ($title === '') {
                throw new RuntimeException('Assessment title is required.');
            }
            $totalPoints = array_sum(array_map(static fn (array $q): float => (float) $q['points'], $questions));

            db()->beginTransaction();
            if ($hasCreditedWeek && $hasTimeLimit) {
                $st = db()->prepare(
                    'UPDATE classroom_assessments
                     SET assessment_type = ?, title = ?, description = ?, total_points = ?, due_at = ?, time_limit_minutes = ?, credited_week = ?
                     WHERE id = ? AND classroom_id = ? AND faculty_id = ?'
                );
                $st->execute([
                    $assessmentType,
                    $title,
                    $description,
                    $totalPoints,
                    $dueAt,
                    $timeLimitMinutes,
                    $creditedWeek,
                    $assessmentId,
                    $classroomId,
                    $facultyId,
                ]);
            } elseif ($hasTimeLimit) {
                $st = db()->prepare(
                    'UPDATE classroom_assessments
                     SET assessment_type = ?, title = ?, description = ?, total_points = ?, due_at = ?, time_limit_minutes = ?
                     WHERE id = ? AND classroom_id = ? AND faculty_id = ?'
                );
                $st->execute([
                    $assessmentType,
                    $title,
                    $description,
                    $totalPoints,
                    $dueAt,
                    $timeLimitMinutes,
                    $assessmentId,
                    $classroomId,
                    $facultyId,
                ]);
            } elseif ($hasCreditedWeek) {
                $st = db()->prepare(
                    'UPDATE classroom_assessments
                     SET assessment_type = ?, title = ?, description = ?, total_points = ?, due_at = ?, credited_week = ?
                     WHERE id = ? AND classroom_id = ? AND faculty_id = ?'
                );
                $st->execute([
                    $assessmentType,
                    $title,
                    $description,
                    $totalPoints,
                    $dueAt,
                    $creditedWeek,
                    $assessmentId,
                    $classroomId,
                    $facultyId,
                ]);
            } else {
                $st = db()->prepare(
                    'UPDATE classroom_assessments
                     SET assessment_type = ?, title = ?, description = ?, total_points = ?, due_at = ?
                     WHERE id = ? AND classroom_id = ? AND faculty_id = ?'
                );
                $st->execute([
                    $assessmentType,
                    $title,
                    $description,
                    $totalPoints,
                    $dueAt,
                    $assessmentId,
                    $classroomId,
                    $facultyId,
                ]);
            }
            if ($st->rowCount() < 1) {
                $check = db()->prepare('SELECT id FROM classroom_assessments WHERE id = ? AND classroom_id = ? AND faculty_id = ? LIMIT 1');
                $check->execute([$assessmentId, $classroomId, $facultyId]);
                if (!$check->fetchColumn()) {
                    throw new RuntimeException('Assessment not found.');
                }
            }
            db()->prepare('DELETE FROM classroom_assessment_questions WHERE assessment_id = ?')->execute([$assessmentId]);
            $qStmt = db()->prepare(
                'INSERT INTO classroom_assessment_questions
                (assessment_id, question_type, question_text, options_json, answer_key, points, position, word_limit, char_limit, allow_steps)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            );
            foreach ($questions as $idx => $q) {
                $qStmt->execute([
                    $assessmentId,
                    $q['question_type'],
                    $q['question_text'],
                    $q['options_json'],
                    $q['answer_key'],
                    $q['points'],
                    $idx + 1,
                    $q['word_limit'],
                    $q['char_limit'],
                    $q['allow_steps'],
                ]);
            }
            db()->commit();
            $_SESSION['flash'] = 'Assessment updated.';
        } elseif ($action === 'delete_assessment') {
            $assessmentId = (int) ($_POST['assessment_id'] ?? 0);
            db()->prepare(
                'DELETE FROM classroom_assessments WHERE id = ? AND classroom_id = ? AND faculty_id = ?'
            )->execute([$assessmentId, $classroomId, $facultyId]);
            $_SESSION['flash'] = 'Assessment deleted.';
        } elseif ($action === 'save_scores') {
            $assessmentId = (int) ($_POST['assessment_id'] ?? 0);

            $st = db()->prepare(
                'SELECT id FROM classroom_assessments WHERE id = ? AND classroom_id = ? AND faculty_id = ? LIMIT 1'
            );
            $st->execute([$assessmentId, $classroomId, $facultyId]);
            if (!$st->fetchColumn()) {
                throw new RuntimeException('Assessment not found.');
            }

            $allowedStudentIds = db()->prepare(
                'SELECT ce.student_id FROM classroom_enrollments ce WHERE ce.classroom_id = ?'
            );
            $allowedStudentIds->execute([$classroomId]);
            $allowedMap = array_fill_keys(
                array_map('intval', $allowedStudentIds->fetchAll(PDO::FETCH_COLUMN) ?: []),
                true
            );

            $scores = $_POST['scores'] ?? [];
            $feedback = $_POST['feedback'] ?? [];
            foreach ($scores as $studentIdRaw => $scoreRaw) {
                $studentId = (int) $studentIdRaw;
                if (!isset($allowedMap[$studentId])) {
                    continue;
                }

                $scoreText = trim((string) $scoreRaw);
                $feedbackText = trim((string) ($feedback[$studentIdRaw] ?? ''));
                if ($scoreText === '' && $feedbackText === '') {
                    db()->prepare('DELETE FROM classroom_scores WHERE assessment_id = ? AND student_id = ?')
                        ->execute([$assessmentId, $studentId]);
                    continue;
                }

                $score = $scoreText === '' ? null : (float) $scoreText;
                db()->prepare(
                    'INSERT INTO classroom_scores (assessment_id, student_id, score, feedback, graded_at)
                     VALUES (?,?,?,?,NOW())
                     ON DUPLICATE KEY UPDATE score = VALUES(score), feedback = VALUES(feedback), graded_at = NOW()'
                )->execute([
                    $assessmentId,
                    $studentId,
                    $score,
                    $feedbackText !== '' ? $feedbackText : null,
                ]);
            }

            $_SESSION['flash'] = 'Grades saved successfully.';
        } elseif ($action === 'save_single_submission') {
            $assessmentId = (int) ($_POST['assessment_id'] ?? 0);
            $studentId = (int) ($_POST['student_id'] ?? 0);
            $scoreText = trim((string) ($_POST['score'] ?? ''));
            $feedbackText = trim((string) ($_POST['feedback'] ?? ''));

            $st = db()->prepare(
                'SELECT id FROM classroom_assessments WHERE id = ? AND classroom_id = ? AND faculty_id = ? LIMIT 1'
            );
            $st->execute([$assessmentId, $classroomId, $facultyId]);
            if (!$st->fetchColumn()) {
                throw new RuntimeException('Assessment not found.');
            }

            $allowedStudentIds = db()->prepare(
                'SELECT ce.student_id FROM classroom_enrollments ce WHERE ce.classroom_id = ? AND ce.student_id = ? LIMIT 1'
            );
            $allowedStudentIds->execute([$classroomId, $studentId]);
            if (!$allowedStudentIds->fetchColumn()) {
                throw new RuntimeException('Student not in this classroom.');
            }

            if ($scoreText === '' && $feedbackText === '') {
                db()->prepare('DELETE FROM classroom_scores WHERE assessment_id = ? AND student_id = ?')
                    ->execute([$assessmentId, $studentId]);
            } else {
                $score = $scoreText === '' ? null : (float) $scoreText;
                db()->prepare(
                    'INSERT INTO classroom_scores (assessment_id, student_id, score, feedback, graded_at)
                     VALUES (?,?,?,?,NOW())
                     ON DUPLICATE KEY UPDATE score = VALUES(score), feedback = VALUES(feedback), graded_at = NOW()'
                )->execute([
                    $assessmentId,
                    $studentId,
                    $score,
                    $feedbackText !== '' ? $feedbackText : null,
                ]);
            }

            $_SESSION['flash'] = 'Saved grade for student.';
        } elseif ($action === 'approve_retake_request') {
            $requestId = (int) ($_POST['request_id'] ?? 0);
            if ($requestId < 1) {
                throw new RuntimeException('Invalid retake request.');
            }
            approve_assessment_retake_request($requestId, $classroomId, $userId);
            $_SESSION['flash'] = 'Retake approved. The student may take the assessment again.';
        } elseif ($action === 'reject_retake_request') {
            $requestId = (int) ($_POST['request_id'] ?? 0);
            $remarks = trim((string) ($_POST['faculty_remarks'] ?? ''));
            if ($requestId < 1) {
                throw new RuntimeException('Invalid retake request.');
            }
            reject_assessment_retake_request($requestId, $classroomId, $userId, $remarks);
            $_SESSION['flash'] = 'Retake request denied.';
        } elseif ($action === 'upload_banner' || $action === 'delete_banner') {
            $bannerFlash = faculty_classroom_process_banner_post($classroomId, $facultyId, $classroom);
            if ($bannerFlash !== null) {
                $_SESSION['flash'] = $bannerFlash;
            }
        }
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        $_SESSION['flash'] = 'Error: ' . $e->getMessage();
    }

    $redirect = 'faculty_classroom_assessments.php?id=' . $classroomId;
    $pick = (int) ($_POST['submissions_assessment_id'] ?? 0);
    if ($pick > 0) {
        $redirect .= '&submissions=' . $pick;
    }
    $anchor = trim((string) ($_POST['scroll_anchor'] ?? ''));
    if ($anchor !== '' && preg_match('/^[a-zA-Z0-9_-]+$/', $anchor)) {
        $redirect .= '#' . $anchor;
    }
    header('Location: ' . $redirect);
    exit;
}

$enrollments = [];
$assessments = [];
$scoreMap = [];
$submissionMap = [];
$questionMap = [];
$pendingRetakeRequests = [];

if ($missingTables === [] && $classroom) {
    $st = db()->prepare(
        'SELECT ce.id AS enrollment_id, cs.id AS student_id, cs.user_id, cs.student_number, cs.full_name, cs.email, u.username
         FROM classroom_enrollments ce
         INNER JOIN classroom_students cs ON cs.id = ce.student_id
         LEFT JOIN users u ON u.id = cs.user_id
         WHERE ce.classroom_id = ?
         ORDER BY cs.full_name ASC'
    );
    $st->execute([$classroomId]);
    $enrollments = $st->fetchAll();

    $assessmentOrderBy = $hasCreditedWeek
        ? 'ORDER BY credited_week ASC, created_at DESC'
        : 'ORDER BY created_at DESC';
    $st = db()->prepare(
        "SELECT *
         FROM classroom_assessments
         WHERE classroom_id = ? AND faculty_id = ?
         {$assessmentOrderBy}"
    );
    $st->execute([$classroomId, $facultyId]);
    $assessments = $st->fetchAll();

    if ($assessments !== []) {
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $assessments);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $qst = db()->prepare(
            "SELECT * FROM classroom_assessment_questions
             WHERE assessment_id IN ($placeholders)
             ORDER BY assessment_id ASC, position ASC, id ASC"
        );
        $qst->execute($ids);
        foreach ($qst->fetchAll() as $qr) {
            $aid = (int) ($qr['assessment_id'] ?? 0);
            if ($aid < 1) {
                continue;
            }
            $questionMap[$aid][] = $qr;
        }
    }

    $st = db()->prepare(
        'SELECT cs.assessment_id, cs.student_id, cs.score, cs.feedback
         FROM classroom_scores cs
         INNER JOIN classroom_assessments ca ON ca.id = cs.assessment_id
         WHERE ca.classroom_id = ?'
    );
    $st->execute([$classroomId]);
    foreach ($st->fetchAll() as $row) {
        $scoreMap[(int) $row['assessment_id']][(int) $row['student_id']] = [
            'score' => $row['score'],
            'feedback' => $row['feedback'],
        ];
    }

    $st = db()->prepare(
        'SELECT sub.assessment_id, sub.student_id, sub.answer_text, sub.status, sub.submitted_at'
        . ($hasIntegrityLocked ? ', sub.integrity_locked' : '') . '
         FROM classroom_submissions sub
         INNER JOIN classroom_assessments ca ON ca.id = sub.assessment_id
         WHERE ca.classroom_id = ?'
    );
    $st->execute([$classroomId]);
    foreach ($st->fetchAll() as $row) {
        $submissionMap[(int) $row['assessment_id']][(int) $row['student_id']] = [
            'answer_text' => $row['answer_text'],
            'status' => $row['status'],
            'submitted_at' => $row['submitted_at'],
            'integrity_locked' => $hasIntegrityLocked ? (int) ($row['integrity_locked'] ?? 0) : 0,
        ];
    }

    if (assessment_retake_table_ready()) {
        $pendingRetakeRequests = pending_assessment_retake_requests_for_classroom($classroomId);
    }
}

$facultyUser = current_user();
$facultyDisplayName = trim((string) ($facultyUser['full_name'] ?? '')) !== ''
    ? (string) $facultyUser['full_name']
    : (trim((string) ($facultyUser['username'] ?? '')) !== '' ? (string) $facultyUser['username'] : 'Faculty');

$facultyInitials = '';
$initialParts = preg_split('/\s+/', trim($facultyDisplayName)) ?: [];
foreach ($initialParts as $ip) {
    if ($ip === '') {
        continue;
    }
    $facultyInitials .= strtoupper((function_exists('mb_substr') ? mb_substr($ip, 0, 1) : substr($ip, 0, 1)));
    if (strlen($facultyInitials) >= 2) {
        break;
    }
}
if ($facultyInitials === '') {
    $facultyInitials = 'F';
}

$submissionsPickId = (int) ($_GET['submissions'] ?? 0);
if ($submissionsPickId < 1 && $assessments !== []) {
    $submissionsPickId = (int) $assessments[0]['id'];
}

$assessmentAiAvailable = wellness_ai_is_enabled();

$pageTitle = $classroom ? 'Classroom Assessments' : 'Assessments';
require_once __DIR__ . '/includes/header.php';
?>
<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700&display=swap');

    .fac-assess-dashboard {
        font-family: 'Inter', system-ui, sans-serif;
        color: #1e293b;
        line-height: 1.4;
        background: #f4f7fc;
        margin: -1.5rem -0.75rem 0;
        padding: 2rem 1.25rem 2.5rem;
    }
    @media (min-width: 576px) {
        .fac-assess-dashboard {
            margin: -1.5rem -1rem 0;
            padding: 2rem 1.5rem 2.5rem;
        }
    }
    .fac-assess-dashboard .dashboard-inner {
        max-width: 1400px;
        margin: 0 auto;
    }
    .fac-assess-dashboard .top-nav {
        background: white;
        border-radius: 1.5rem;
        padding: 1rem 1.8rem;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.02), 0 1px 2px rgba(0, 0, 0, 0.05);
        margin-bottom: 2rem;
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
    }
    .fac-assess-dashboard .course-info h1 {
        font-size: 1.5rem;
        font-weight: 600;
        letter-spacing: -0.3px;
        color: #0f3b5c;
        margin: 0;
    }
    .fac-assess-dashboard .course-info .sub {
        font-size: 0.85rem;
        color: #5b6e8c;
        margin-top: 0.2rem;
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        align-items: center;
    }
    .fac-assess-dashboard .semester-badge {
        background: #eef2ff;
        padding: 0.2rem 0.7rem;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 500;
        color: #1e40af;
    }
    .fac-assess-dashboard .top-nav-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        align-items: center;
    }
    .fac-assess-dashboard .top-nav-actions .fac-nav-link {
        font-size: 0.8rem;
        font-weight: 500;
        color: #2c7da0;
        text-decoration: none;
        padding: 0.35rem 0.9rem;
        border-radius: 2rem;
        background: #f1f5f9;
        border: none;
        transition: background 0.15s;
    }
    .fac-assess-dashboard .top-nav-actions .fac-nav-link:hover {
        background: #e2e8f0;
        color: #0f3b5c;
    }
    .fac-assess-dashboard .faculty-profile {
        display: flex;
        align-items: center;
        gap: 0.8rem;
        background: #f8fafc;
        padding: 0.4rem 1rem 0.4rem 0.8rem;
        border-radius: 40px;
    }
    .fac-assess-dashboard .avatar {
        width: 38px;
        height: 38px;
        background: #1e6f5c;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 600;
        font-size: 0.95rem;
        flex-shrink: 0;
    }
    .fac-assess-dashboard .faculty-name {
        font-weight: 600;
        font-size: 0.9rem;
    }
    .fac-assess-dashboard .faculty-role {
        font-size: 0.7rem;
        color: #5f6c84;
    }
    .fac-assess-dashboard .fac-dash-card {
        background: white;
        border-radius: 1.25rem;
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.03), 0 2px 4px rgba(0, 0, 0, 0.05);
        padding: 1.5rem;
        margin-bottom: 2rem;
    }
    .fac-assess-dashboard .section-title {
        font-size: 1.3rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 1.25rem;
    }
    .fac-assess-dashboard .section-title i {
        color: #2c7da0;
        font-size: 1.2rem;
    }
    .fac-assess-dashboard .assessment-toolbar {
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1rem;
    }
    .fac-assess-dashboard .assessment-table-wrapper {
        overflow-x: auto;
    }
    .fac-assess-dashboard .assessment-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.9rem;
    }
    .fac-assess-dashboard .assessment-table th {
        text-align: left;
        padding: 0.9rem 0.5rem 0.7rem 0;
        font-weight: 600;
        color: #4a5b7a;
        border-bottom: 1px solid #e2e8f0;
        user-select: none;
        white-space: nowrap;
    }
    .fac-assess-dashboard .assessment-table th.sortable { cursor: pointer; }
    .fac-assess-dashboard .assessment-table th.sortable:hover { color: #1e6f5c; }
    .fac-assess-dashboard .assessment-table td {
        padding: 0.9rem 0.5rem 0.9rem 0;
        border-bottom: 1px solid #f0f2f5;
        vertical-align: middle;
    }
    .fac-assess-dashboard .week-group-header td {
        border-bottom: none;
        padding-top: 1.25rem;
        padding-bottom: 0.4rem;
    }
    .fac-assess-dashboard .week-group-cell {
        background: #f1f5f9;
        border-radius: 0.6rem;
        padding: 0.55rem 1rem !important;
        font-size: 0.85rem;
        color: #0f3b5c;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .fac-assess-dashboard .week-group-cell i {
        color: #2c7da0;
        font-size: 0.9rem;
    }
    .fac-assess-dashboard .week-count-badge {
        background: #e2e8f0;
        padding: 0.15rem 0.6rem;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 500;
        color: #475569;
        margin-left: 0.25rem;
    }
    .fac-assess-dashboard .type-badge {
        background: #eef2ff;
        padding: 0.2rem 0.7rem;
        border-radius: 30px;
        font-size: 0.75rem;
        font-weight: 500;
        display: inline-block;
        color: #1e3a8a;
    }
    .fac-assess-dashboard .type-badge.type-performance {
        background: #e0f2fe;
        color: #0369a1;
    }
    .fac-assess-dashboard .points {
        font-weight: 500;
    }
    .fac-assess-dashboard .action-icons {
        white-space: nowrap;
    }
    .fac-assess-dashboard .action-icons .fac-icon-btn {
        background: none;
        border: none;
        padding: 0 6px;
        color: #7e8b9f;
        cursor: pointer;
        transition: color 0.1s;
    }
    .fac-assess-dashboard .action-icons .fac-icon-btn:hover {
        color: #1e6f5c;
    }
    .fac-assess-dashboard .btn-add {
        background: #1e6f5c;
        color: white;
        border: none;
        padding: 0.5rem 1.2rem;
        border-radius: 2rem;
        font-weight: 500;
        font-size: 0.8rem;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        cursor: pointer;
        transition: background 0.2s;
    }
    .fac-assess-dashboard .btn-add:hover {
        background: #0f5a49;
        color: white;
    }
    .fac-assess-dashboard .filter-search input {
        border: 1px solid #cbd5e1;
        border-radius: 2rem;
        padding: 0.45rem 1rem;
        font-size: 0.8rem;
        width: 200px;
    }
    .fac-assess-dashboard .submissions-header {
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
        gap: 1rem;
    }
    .fac-assess-dashboard .assessment-tabs {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
    }
    .fac-assess-dashboard .tab-btn {
        background: #f1f5f9;
        border: none;
        padding: 0.5rem 1.2rem;
        border-radius: 40px;
        font-weight: 500;
        font-size: 0.8rem;
        cursor: pointer;
        transition: all 0.2s;
        color: #2c3e66;
    }
    .fac-assess-dashboard .tab-btn.active {
        background: #1e6f5c;
        color: white;
        box-shadow: 0 2px 6px rgba(30, 111, 92, 0.2);
    }
    .fac-assess-dashboard .filter-search {
        display: flex;
        gap: 0.5rem;
        align-items: center;
    }
    .fac-assess-dashboard .submissions-grid {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }
    .fac-assess-dashboard .submission-card {
        background: #ffffff;
        border: 1px solid #eef2ff;
        border-radius: 1rem;
        padding: 1.2rem;
        transition: 0.1s;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
    }
    .fac-assess-dashboard .submission-card.fac-filter-hide {
        display: none !important;
    }
    .fac-assess-dashboard .submission-card .card-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-bottom: 0.75rem;
    }
    .fac-assess-dashboard .student-name {
        font-weight: 700;
        font-size: 1rem;
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.5rem;
    }
    .fac-assess-dashboard .student-name .status {
        font-size: 0.7rem;
        font-weight: 600;
        padding: 0.2rem 0.7rem;
        border-radius: 20px;
        background: #fef9e3;
        color: #b45309;
    }
    .fac-assess-dashboard .student-name .status.graded {
        background: #e0f2e9;
        color: #1f6e43;
    }
    .fac-assess-dashboard .student-name .status.pending {
        background: #f1f5f9;
        color: #475569;
    }
    .fac-assess-dashboard .submission-meta {
        font-size: 0.75rem;
        color: #5f6c84;
        margin-bottom: 0.75rem;
        display: flex;
        gap: 1rem;
        flex-wrap: wrap;
    }
    .fac-assess-dashboard .submission-answer-box {
        font-size: 0.8rem;
        color: #475569;
        border: 1px solid #e2e8f0;
        border-radius: 0.8rem;
        padding: 0.75rem 1rem;
        margin-bottom: 1rem;
        max-height: 14rem;
        overflow-y: auto;
        background: #fafcff;
    }
    .fac-assess-dashboard .score-feedback-area {
        display: flex;
        flex-wrap: wrap;
        gap: 1.5rem;
        align-items: flex-start;
        margin-top: 0.5rem;
    }
    .fac-assess-dashboard .score-box {
        display: flex;
        align-items: center;
        gap: 0.6rem;
        flex-wrap: wrap;
    }
    .fac-assess-dashboard .score-box label {
        font-weight: 500;
        font-size: 0.8rem;
    }
    .fac-assess-dashboard .score-input {
        width: 90px;
        padding: 0.45rem 0.6rem;
        border: 1px solid #cbd5e1;
        border-radius: 0.8rem;
        font-weight: 500;
    }
    .fac-assess-dashboard .feedback-box {
        flex: 2;
        min-width: 180px;
    }
    .fac-assess-dashboard .feedback-box textarea {
        width: 100%;
        border: 1px solid #cbd5e1;
        border-radius: 0.8rem;
        padding: 0.5rem;
        font-family: inherit;
        font-size: 0.8rem;
        resize: vertical;
    }
    .fac-assess-dashboard .char-count {
        font-size: 0.65rem;
        color: #7e8b9f;
        text-align: right;
        margin-top: 0.2rem;
    }
    .fac-assess-dashboard .action-buttons {
        display: flex;
        gap: 0.6rem;
        align-items: center;
        margin-left: auto;
    }
    .fac-assess-dashboard .save-btn {
        background: #1e6f5c;
        color: white;
        border: none;
        padding: 0.4rem 1rem;
        border-radius: 2rem;
        font-size: 0.75rem;
        font-weight: 500;
        cursor: pointer;
    }
    .fac-assess-dashboard .save-btn:hover {
        background: #0f5a49;
        color: white;
    }
    .fac-assess-dashboard .cancel-btn {
        background: #eef2ff;
        border: none;
        padding: 0.4rem 1rem;
        border-radius: 2rem;
        font-size: 0.75rem;
        cursor: pointer;
    }
    .fac-assess-dashboard .empty-hint {
        text-align: center;
        padding: 2rem;
        background: #fafcff;
        border-radius: 1rem;
        color: #64748b;
        font-size: 0.9rem;
        border: 1px dashed #e2e8f0;
    }
    #toastMsg.toast-msg {
        position: fixed;
        bottom: 20px;
        right: 20px;
        background: #1e293b;
        color: white;
        padding: 0.6rem 1.2rem;
        border-radius: 40px;
        font-size: 0.8rem;
        font-family: 'Inter', system-ui, sans-serif;
        z-index: 2000;
        opacity: 0;
        transition: opacity 0.2s;
        pointer-events: none;
        max-width: 90vw;
    }
    .fac-assess-dashboard .install-alert {
        background: #fffbeb;
        border: 1px solid #fcd34d;
        border-radius: 1rem;
        padding: 1rem 1.25rem;
        color: #92400e;
        margin-bottom: 1.5rem;
    }
    @media (max-width: 700px) {
        .fac-assess-dashboard .score-feedback-area { flex-direction: column; }
        .fac-assess-dashboard .action-buttons { margin-left: 0; margin-top: 0.5rem; }
        .fac-assess-dashboard .filter-search input { width: 150px; }
        .fac-assess-dashboard .top-nav { flex-direction: column; align-items: flex-start; }
    }
    .fac-assess-modal .btn-primary {
        background: #1e6f5c;
        border-color: #1e6f5c;
    }
    .fac-assess-modal .btn-primary:hover {
        background: #0f5a49;
        border-color: #0f5a49;
    }
    .assessment-timer-preview {
        display: inline-block;
        font-family: "Consolas", "Courier New", monospace;
        font-size: 1.35rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        background: #0b1220;
        color: #22d3ee;
        padding: 0.35rem 0.75rem;
        border-radius: 0.5rem;
        box-shadow: inset 0 0 0 1px rgba(34, 211, 238, 0.35);
        min-width: 6.5rem;
        text-align: center;
    }
</style>

<div class="fac-assess-dashboard">
    <div class="dashboard-inner">
        <?php if ($classroom && $missingTables === []): ?>
            <?php faculty_classroom_render_banner($classroom, [
                'meta_extra' => 'Assessments & grading',
                'form_id' => 'facultyAssessBanner',
            ]); ?>
            <div class="top-nav">
                <div class="course-info">
                    <h1 class="h5 mb-0"><i class="fa-solid fa-clipboard-list" style="margin-right: 8px; color:#1e6f5c;"></i> Assessment tools</h1>
                    <div class="sub">
                        <span><i class="fa-solid fa-list-check"></i> Written work, performance tasks, submissions &amp; grades</span>
                    </div>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-3">
                    <div class="top-nav-actions">
                        <a href="faculty_classroom.php?id=<?= (int) $classroomId ?>" class="fac-nav-link">Classroom</a>
                        <a href="faculty_classrooms.php" class="fac-nav-link">All classes</a>
                    </div>
                    <div class="faculty-profile">
                        <div class="avatar"><?= htmlspecialchars($facultyInitials) ?></div>
                        <div>
                            <div class="faculty-name"><?= htmlspecialchars($facultyDisplayName) ?></div>
                            <div class="faculty-role">Course instructor</div>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="top-nav">
                <div class="course-info">
                    <h1><i class="fa-solid fa-clipboard-list" style="margin-right: 8px; color:#1e6f5c;"></i> Assessments &amp; grading</h1>
                    <div class="sub"><span>Faculty scheduling</span></div>
                </div>
                <div class="faculty-profile">
                    <div class="avatar"><?= htmlspecialchars($facultyInitials) ?></div>
                    <div>
                        <div class="faculty-name"><?= htmlspecialchars($facultyDisplayName) ?></div>
                        <div class="faculty-role">Course instructor</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($missingTables !== []): ?>
            <div class="install-alert">
                Assessment features are not installed yet. Run <a href="upgrade_roles.php">upgrade_roles.php</a> once, then reload this page.
            </div>
        <?php else: ?>

            <?php if ($flash !== ''): ?><?php render_information_popup((string) $flash); ?><?php endif; ?>

            <?php if ($pendingRetakeRequests !== []): ?>
            <div class="fac-dash-card">
                <div class="section-title">
                    <i class="fa-solid fa-rotate-left"></i> Retake requests
                    <span class="badge bg-warning text-dark ms-1"><?= count($pendingRetakeRequests) ?> pending</span>
                </div>
                <p class="text-muted small mb-3">Students with locked assessments may request another attempt. Review their explanation and approve or deny.</p>
                <?php foreach ($pendingRetakeRequests as $retakeReq): ?>
                    <?php $reqId = (int) ($retakeReq['id'] ?? 0); ?>
                    <div class="border rounded p-3 mb-3">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                            <div>
                                <strong><?= htmlspecialchars((string) ($retakeReq['student_name'] ?? '')) ?></strong>
                                <?php if ((string) ($retakeReq['student_number'] ?? '') !== ''): ?>
                                    <span class="text-muted small">(<?= htmlspecialchars((string) $retakeReq['student_number']) ?>)</span>
                                <?php endif; ?>
                                <div class="small text-muted mt-1">
                                    <i class="fa-solid fa-file-pen me-1"></i><?= htmlspecialchars((string) ($retakeReq['assessment_title'] ?? '')) ?>
                                    &bull; Requested <?= htmlspecialchars((string) ($retakeReq['created_at'] ?? '')) ?>
                                </div>
                            </div>
                            <span class="badge bg-danger">Integrity violation</span>
                        </div>
                        <div class="small mb-3 p-2 bg-light rounded">
                            <strong>Student explanation:</strong><br>
                            <?= nl2br(htmlspecialchars((string) ($retakeReq['reason'] ?? ''))) ?>
                        </div>
                        <div class="d-flex flex-wrap gap-2 align-items-start">
                            <form method="post" class="d-inline" onsubmit="return confirm('Approve this retake? The student\'s locked submission and score will be cleared so they can try again.');">
                                <input type="hidden" name="action" value="approve_retake_request">
                                <input type="hidden" name="classroom_id" value="<?= (int) $classroomId ?>">
                                <input type="hidden" name="request_id" value="<?= $reqId ?>">
                                <button type="submit" class="btn btn-success btn-sm">
                                    <i class="fa-solid fa-check me-1"></i>Approve retake
                                </button>
                            </form>
                            <form method="post" class="d-flex flex-wrap gap-2 align-items-start flex-grow-1">
                                <input type="hidden" name="action" value="reject_retake_request">
                                <input type="hidden" name="classroom_id" value="<?= (int) $classroomId ?>">
                                <input type="hidden" name="request_id" value="<?= $reqId ?>">
                                <input type="text" name="faculty_remarks" class="form-control form-control-sm" placeholder="Optional note to student" maxlength="500" style="max-width:280px;">
                                <button type="submit" class="btn btn-outline-secondary btn-sm">
                                    <i class="fa-solid fa-xmark me-1"></i>Deny
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="fac-dash-card">
                <div class="section-title">
                    <i class="fa-solid fa-clipboard-list"></i> Assessment manager
                </div>
                <div class="assessment-toolbar">
                    <input type="search" class="filter-search" id="assessmentTableFilter" placeholder="Filter table…" style="border:1px solid #cbd5e1;border-radius:2rem;padding:0.45rem 1rem;font-size:0.8rem;width:min(220px,100%);" autocomplete="off">
                    <button type="button" class="btn-add" data-bs-toggle="modal" data-bs-target="#modalAddAssessment"<?= app_tooltip_attr('Opens the form to add a quiz or assignment students will see in this class.') ?>>
                        <i class="fa-solid fa-plus-circle"></i> Add assessment
                    </button>
                </div>
                <?php if ($assessments === []): ?>
                    <div class="empty-hint">
                        No assessments yet. Use <strong>Add assessment</strong> to create one. Student cards appear here once learners are enrolled.
                    </div>
                <?php else: ?>
                    <?php
                    $weekGroups = [];
                    foreach ($assessments as $row) {
                        $wk = $hasCreditedWeek ? trim((string) ($row['credited_week'] ?? '')) : '';
                        $label = $wk !== '' ? $wk : 'Unassigned';
                        if (!isset($weekGroups[$label])) {
                            $weekGroups[$label] = [];
                        }
                        $weekGroups[$label][] = $row;
                    }
                    uksort($weekGroups, static function (string $a, string $b): int {
                        $na = $a === 'Unassigned' ? 999 : ((int) preg_replace('/\D/', '', $a) ?: 999);
                        $nb = $b === 'Unassigned' ? 999 : ((int) preg_replace('/\D/', '', $b) ?: 999);
                        return $na <=> $nb;
                    });
                    $tableCols = $hasCreditedWeek ? 6 : 6;
                    ?>
                    <div class="assessment-table-wrapper">
                        <table class="assessment-table" id="assessmentsDataTable">
                            <thead>
                                <tr>
                                    <th class="sortable" data-sort="type" data-type="string">Type</th>
                                    <th class="sortable" data-sort="title" data-type="string">Title</th>
                                    <th class="sortable" data-sort="points" data-type="number">Points</th>
                                    <th class="d-none d-md-table-cell">Description</th>
                                    <th class="sortable d-none d-lg-table-cell" data-sort="due" data-type="string">Due</th>
                                    <th style="width: 72px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($weekGroups as $weekLabel => $weekRows): ?>
                                    <?php if ($hasCreditedWeek): ?>
                                    <tr class="week-group-header" data-filter="<?= htmlspecialchars(faculty_assess_lc((string) $weekLabel)) ?>">
                                        <td colspan="<?= $tableCols ?>">
                                            <div class="week-group-cell">
                                                <i class="fa-regular fa-calendar-alt"></i>
                                                <strong><?= htmlspecialchars((string) $weekLabel) ?></strong>
                                                <span class="week-count-badge"><?= count($weekRows) ?> assessment<?= count($weekRows) === 1 ? '' : 's' ?></span>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php foreach ($weekRows as $row): ?>
                                        <?php
                                        $aid = (int) $row['id'];
                                        $questionsForAssessment = $questionMap[$aid] ?? [];
                                        $typeLabel = classroom_assessment_type_label((string) ($row['assessment_type'] ?? 'essay'));
                                        $dueRaw = (string) ($row['due_at'] ?? '');
                                        $desc = trim((string) ($row['description'] ?? ''));
                                        $descPlain = $desc === '' ? '' : (preg_replace('/\s+/u', ' ', trim(html_entity_decode(strip_tags(str_replace('&nbsp;', ' ', $desc)), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?? '');
                                        $isPerf = classroom_assessment_normalize_type((string) ($row['assessment_type'] ?? 'essay')) === 'problem_solving';
                                        $serializedQuestions = [];
                                        foreach ($questionsForAssessment as $q) {
                                            $serializedQuestions[] = [
                                                'question_type' => (string) ($q['question_type'] ?? 'essay'),
                                                'question_text' => (string) ($q['question_text'] ?? ''),
                                                'points' => (float) ($q['points'] ?? 0),
                                                'options' => json_decode((string) ($q['options_json'] ?? '[]'), true) ?: [],
                                                'answer_key' => (string) ($q['answer_key'] ?? ''),
                                                'word_limit' => $q['word_limit'],
                                                'char_limit' => $q['char_limit'],
                                                'allow_steps' => (int) ($q['allow_steps'] ?? 0),
                                            ];
                                        }
                                        $questionCount = count($questionsForAssessment);
                                        ?>
                                        <tr
                                            data-filter="<?= htmlspecialchars(faculty_assess_lc($typeLabel . ' ' . (string) $row['title'] . ' ' . $row['total_points'] . ' ' . $dueRaw . ' ' . $descPlain . ' ' . $questionCount . ' questions ' . ($weekLabel !== 'Unassigned' ? $weekLabel : ''))) ?>"
                                            data-type="<?= htmlspecialchars(faculty_assess_lc($typeLabel)) ?>"
                                            data-title="<?= htmlspecialchars(faculty_assess_lc((string) $row['title'])) ?>"
                                            data-points="<?= htmlspecialchars((string) (float) $row['total_points']) ?>"
                                            data-due="<?= htmlspecialchars($dueRaw !== '' ? $dueRaw : '9999-99-99') ?>"
                                            data-week="<?= htmlspecialchars((string) $weekLabel) ?>"
                                        >
                                            <td>
                                                <span class="type-badge <?= $isPerf ? 'type-performance' : '' ?>"><?= htmlspecialchars($typeLabel) ?></span>
                                            </td>
                                            <td><strong><?= htmlspecialchars((string) $row['title']) ?></strong></td>
                                            <td><span class="points"><?= number_format((float) $row['total_points'], 2) ?></span></td>
                                            <td class="d-none d-md-table-cell">
                                                <?php if ($descPlain !== ''): ?>
                                                    <?php
                                                    $descPreview = $descPlain;
                                                    if (function_exists('mb_strlen') && mb_strlen($descPreview) > 120) {
                                                        $descPreview = mb_substr($descPreview, 0, 117) . '…';
                                                    } elseif (strlen($descPreview) > 120) {
                                                        $descPreview = substr($descPreview, 0, 117) . '…';
                                                    }
                                                    ?>
                                                    <span style="color:#5b6e8c;font-size:0.85rem;"><?= htmlspecialchars($descPreview) ?></span>
                                                <?php else: ?>
                                                    <span style="color:#94a3b8;font-size:0.8rem;font-style:italic;">No description</span>
                                                <?php endif; ?>
                                                <div style="color:#64748b;font-size:0.75rem;margin-top:0.25rem;"><?= (int) $questionCount ?> question<?= $questionCount === 1 ? '' : 's' ?><?php
                                                    $rowTimeLimit = (int) ($row['time_limit_minutes'] ?? 0);
                                                    if ($hasTimeLimit && $rowTimeLimit > 0):
                                                        $rh = intdiv($rowTimeLimit, 60);
                                                        $rm = $rowTimeLimit % 60;
                                                        $rLabel = $rh > 0 ? ($rh . 'h' . ($rm > 0 ? ' ' . $rm . 'm' : '')) : ($rm . ' min');
                                                        echo ' · <i class="fa-regular fa-clock"></i> ' . htmlspecialchars($rLabel);
                                                    endif;
                                                ?></div>
                                            </td>
                                            <td class="d-none d-lg-table-cell" style="color:#5b6e8c;font-size:0.85rem;"><?= $dueRaw !== '' ? htmlspecialchars($dueRaw) : '—' ?></td>
                                            <td class="action-icons">
                                                <button type="button" class="fac-icon-btn" title="Edit"<?= app_tooltip_attr('Opens the editor to change title, points, due date, or instructions for this assessment.') ?>
                                                    data-bs-toggle="modal" data-bs-target="#modalEditAssessment"
                                                    data-id="<?= $aid ?>"
                                                    data-type="<?= htmlspecialchars((string) ($row['assessment_type'] ?? 'essay')) ?>"
                                                    data-title="<?= htmlspecialchars((string) $row['title']) ?>"
                                                    data-points="<?= htmlspecialchars((string) $row['total_points']) ?>"
                                                    data-due="<?= htmlspecialchars(faculty_assessment_due_for_input($dueRaw !== '' ? $dueRaw : null)) ?>"
                                                    data-time-limit="<?= (int) ($row['time_limit_minutes'] ?? 0) ?>"
                                                    data-credited-week="<?= htmlspecialchars((string) ($row['credited_week'] ?? '')) ?>"
                                                    data-description="<?= htmlspecialchars(str_replace(["\r", "\n", "\t"], ' ', $desc), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-questions="<?= htmlspecialchars((string) json_encode($serializedQuestions, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">
                                                    <i class="fa-solid fa-pen-to-square"></i>
                                                </button>
                                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this assessment and all related grades and submissions?');">
                                                    <input type="hidden" name="action" value="delete_assessment">
                                                    <input type="hidden" name="classroom_id" value="<?= (int) $classroomId ?>">
                                                    <input type="hidden" name="assessment_id" value="<?= $aid ?>">
                                                    <button type="submit" class="fac-icon-btn" title="Delete"<?= app_tooltip_attr('Deletes this assessment and related grades after confirmation.') ?>><i class="fa-solid fa-trash-can"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="fac-dash-card">
                <div class="section-title">
                    <i class="fa-solid fa-inbox"></i> Submissions by assessment
                </div>
                <?php if ($assessments === []): ?>
                    <div class="empty-hint">Add an assessment first; submissions and grades will show here.</div>
                <?php elseif ($enrollments === []): ?>
                    <div class="empty-hint">Enroll students in this class to see one card per learner with submission text, score, and feedback.</div>
                <?php else: ?>
                    <div class="submissions-header">
                        <div class="assessment-tabs" id="assessmentTabsContainer" role="tablist">
                            <?php foreach ($assessments as $a): ?>
                                <?php $optId = (int) $a['id']; ?>
                                <button type="button" role="tab" class="tab-btn <?= $optId === $submissionsPickId ? 'active' : '' ?>"
                                    data-assessment-tab="<?= $optId ?>"
                                    aria-selected="<?= $optId === $submissionsPickId ? 'true' : 'false' ?>">
                                    <?= htmlspecialchars((string) $a['title']) ?> (<?= number_format((float) $a['total_points'], 0) ?> pts)
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <div class="filter-search">
                            <input type="text" id="searchSubmissionInput" placeholder="Search student…" autocomplete="off">
                            <i class="fa-solid fa-magnifying-glass" style="color:#7e8b9f;font-size:0.85rem;"></i>
                        </div>
                    </div>
                    <div id="submissionsListContainer" class="submissions-grid">
                        <?php foreach ($assessments as $a): ?>
                            <?php
                            $panelId = (int) $a['id'];
                            $maxPts = (float) $a['total_points'];
                            $maxPtsDisp = abs($maxPts - (float) (int) $maxPts) < 0.00001
                                ? (string) (int) $maxPts
                                : rtrim(rtrim(number_format($maxPts, 2, '.', ''), '0'), '.');
                            ?>
                            <div class="submissions-panel <?= $panelId === $submissionsPickId ? '' : 'd-none' ?>" data-assessment-panel="<?= $panelId ?>">
                                <?php foreach ($enrollments as $student): ?>
                                    <?php
                                    $studentId = (int) $student['student_id'];
                                    $saved = $scoreMap[$panelId][$studentId] ?? ['score' => '', 'feedback' => ''];
                                    $submission = $submissionMap[$panelId][$studentId] ?? ['answer_text' => '', 'status' => '', 'submitted_at' => '', 'integrity_locked' => 0];
                                    $integrityLocked = (int) ($submission['integrity_locked'] ?? 0) === 1
                                        || stripos((string) ($saved['feedback'] ?? ''), 'left or minimized the assessment window') !== false;
                                    $status = faculty_submission_grading_status($submission, $saved);
                                    $fbVal = (string) ($saved['feedback'] ?? '');
                                    $scoreVal = $saved['score'] !== null && $saved['score'] !== '' ? (string) $saved['score'] : '';
                                    $anchor = 's-' . $panelId . '-' . $studentId;
                                    $filterHay = faculty_assess_lc((string) $student['full_name'] . ' ' . (string) $student['student_number']);
                                    $hasScoreShow = $scoreVal !== '';
                                    if ($status === 'graded' && $hasScoreShow) {
                                        $statusText = 'Graded: ' . $scoreVal . '/' . $maxPtsDisp;
                                        $statusClass = 'graded';
                                    } elseif ($status === 'graded') {
                                        $statusText = 'Graded';
                                        $statusClass = 'graded';
                                    } elseif ($status === 'submitted') {
                                        $statusText = 'Submitted (pending)';
                                        $statusClass = '';
                                    } else {
                                        $statusText = 'Pending';
                                        $statusClass = 'pending';
                                    }
                                    ?>
                                    <div class="submission-card" id="<?= htmlspecialchars($anchor) ?>" data-student-filter="<?= htmlspecialchars($filterHay) ?>">
                                        <div class="card-header">
                                            <div style="flex:1;min-width:0;">
                                                <div class="student-name">
                                                    <i class="fa-solid fa-user-graduate" style="color:#2c7da0;"></i>
                                                    <?= htmlspecialchars((string) $student['full_name']) ?>
                                                    <span class="status <?= htmlspecialchars($statusClass) ?>"><?= htmlspecialchars($statusText) ?></span>
                                                    <?php if ($integrityLocked): ?>
                                                        <span class="badge bg-danger ms-1">Locked (violation)</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="submission-meta">
                                                    <span><i class="fa-regular fa-clock"></i>
                                                        <?php if (trim((string) ($submission['answer_text'] ?? '')) !== '' && !empty($submission['submitted_at'])): ?>
                                                            Submitted: <?= htmlspecialchars((string) $submission['submitted_at']) ?>
                                                        <?php else: ?>
                                                            No submission on file
                                                        <?php endif; ?>
                                                    </span>
                                                    <span><i class="fa-solid fa-file-lines"></i> Max points: <?= htmlspecialchars($maxPtsDisp) ?></span>
                                                    <?php if ((string) ($student['student_number'] ?? '') !== ''): ?>
                                                        <span><i class="fa-solid fa-id-card"></i> <?= htmlspecialchars((string) $student['student_number']) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="submission-answer-box">
                                            <?php if (trim((string) ($submission['answer_text'] ?? '')) !== ''): ?>
                                                <?php
                                                $rawSubmissionAnswer = (string) $submission['answer_text'];
                                                $decodedSubmission = json_decode($rawSubmissionAnswer, true);
                                                ?>
                                                <?php if (is_array($decodedSubmission)): ?>
                                                    <?php foreach ($decodedSubmission as $idx => $ans): ?>
                                                        <div class="small mb-2">
                                                            <strong>Q<?= (int) ($idx + 1) ?>:</strong>
                                                            <span><?= htmlspecialchars((string) ($ans['answer_text'] ?? '')) ?></span>
                                                            <?php if (trim((string) ($ans['answer_steps'] ?? '')) !== ''): ?>
                                                                <div class="text-muted">Steps: <?= nl2br(htmlspecialchars((string) $ans['answer_steps'])) ?></div>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <div class="classroom-content-body small"><?= classroom_content_render_body($rawSubmissionAnswer) ?></div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="color:#94a3b8;font-style:italic;">Student has not submitted yet. Their answer will display here.</span>
                                            <?php endif; ?>
                                        </div>
                                        <form method="post" class="js-submission-form" data-original-score="<?= htmlspecialchars($scoreVal) ?>" data-original-feedback="<?= htmlspecialchars($fbVal) ?>">
                                            <input type="hidden" name="action" value="save_single_submission">
                                            <input type="hidden" name="classroom_id" value="<?= (int) $classroomId ?>">
                                            <input type="hidden" name="assessment_id" value="<?= $panelId ?>">
                                            <input type="hidden" name="student_id" value="<?= $studentId ?>">
                                            <input type="hidden" name="submissions_assessment_id" value="<?= $panelId ?>">
                                            <input type="hidden" name="scroll_anchor" value="<?= htmlspecialchars($anchor) ?>">
                                            <div class="score-feedback-area">
                                                <div class="score-box">
                                                    <label for="score-<?= $panelId ?>-<?= $studentId ?>">Score:</label>
                                                    <input
                                                        type="number"
                                                        name="score"
                                                        id="score-<?= $panelId ?>-<?= $studentId ?>"
                                                        class="score-input js-score-input"
                                                        min="0"
                                                        step="0.01"
                                                        max="<?= htmlspecialchars((string) $maxPts) ?>"
                                                        value="<?= htmlspecialchars($scoreVal) ?>"
                                                        inputmode="decimal"
                                                        autocomplete="off"
                                                        placeholder="—"
                                                    >
                                                    <span style="font-size:0.7rem;color:#64748b;"> / <?= htmlspecialchars($maxPtsDisp) ?></span>
                                                </div>
                                                <div class="feedback-box">
                                                    <textarea
                                                        name="feedback"
                                                        id="fb-<?= $panelId ?>-<?= $studentId ?>"
                                                        class="js-feedback-input"
                                                        rows="2"
                                                        maxlength="4000"
                                                        placeholder="Write feedback…"
                                                        data-feedback-id="<?= (int) $panelId ?>-<?= (int) $studentId ?>"
                                                    ><?= htmlspecialchars($fbVal) ?></textarea>
                                                    <div class="char-count"><span class="js-char-count-num"><?= (int) (function_exists('mb_strlen') ? mb_strlen($fbVal) : strlen($fbVal)) ?></span> characters</div>
                                                </div>
                                                <div class="action-buttons">
                                                    <button type="submit" class="save-btn"<?= app_tooltip_attr('Saves the score and feedback you entered for this student’s submission.') ?>><i class="fa-solid fa-floppy-disk"></i> Save</button>
                                                    <button type="button" class="cancel-btn js-cancel-edit"<?= app_tooltip_attr('Closes inline grading without saving changes to this attempt.') ?>>Cancel</button>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        <?php endif; ?>
    </div>
</div>

<div id="toastMsg" class="toast-msg" aria-live="polite"></div>

<div class="modal fade" id="modalAddAssessment" tabindex="-1" aria-labelledby="modalAddAssessmentLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg fac-assess-modal">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-0 pb-0">
                <h2 class="modal-title h5 fw-semibold" id="modalAddAssessmentLabel">Add assessment</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"<?= app_tooltip_attr('Closes the dialog without submitting the form.') ?>></button>
            </div>
            <div class="modal-body pt-2">
                <form method="post" id="formAddAssessment">
                    <input type="hidden" name="action" value="add_assessment">
                    <input type="hidden" name="classroom_id" value="<?= (int) $classroomId ?>">
                    <input type="hidden" name="questions_json" id="add_questions_json" value="[]">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small">Type</label>
                            <select name="assessment_type" class="form-select">
                                <option value="multiple_choice">Multiple Choice</option>
                                <option value="true_false">True or False</option>
                                <option value="essay">Essay</option>
                                <option value="problem_solving">Problem Solving</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label small">Title</label>
                            <input type="text" name="title" class="form-control" maxlength="150" required placeholder="e.g. Module 3 — Case analysis">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Points</label>
                            <input type="number" name="total_points" class="form-control" min="1" step="0.01" value="100" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Due date</label>
                            <input type="datetime-local" name="due_at" class="form-control">
                        </div>
                        <?php if ($hasCreditedWeek): ?>
                        <div class="col-md-4">
                            <label class="form-label small">Credited week</label>
                            <select name="credited_week" class="form-select">
                                <option value="">No specific week</option>
                                <?php for ($wn = 1; $wn <= 18; $wn++): ?>
                                    <option value="Week <?= $wn ?>">Week <?= $wn ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <?php if ($hasTimeLimit): ?>
                        <div class="col-12">
                            <div class="border rounded-3 p-3 bg-light-subtle">
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" role="switch" name="timer_enabled" value="1" id="add_timer_enabled">
                                    <label class="form-check-label fw-semibold" for="add_timer_enabled">Timer settings</label>
                                </div>
                                <div class="row g-2 align-items-end" id="addTimerFields" style="display:none;">
                                    <div class="col-auto">
                                        <label class="form-label small mb-0" for="add_timer_hours">Hours</label>
                                        <input type="number" name="timer_hours" id="add_timer_hours" class="form-control" min="0" max="12" value="0" style="width:5.5rem;">
                                    </div>
                                    <div class="col-auto">
                                        <label class="form-label small mb-0" for="add_timer_minutes">Minutes</label>
                                        <input type="number" name="timer_minutes" id="add_timer_minutes" class="form-control" min="0" max="59" value="30" style="width:5.5rem;">
                                    </div>
                                    <div class="col-auto pb-1">
                                        <span class="assessment-timer-preview" id="addTimerPreview" aria-hidden="true">00:30:00</span>
                                    </div>
                                </div>
                                <div class="form-text small mt-1">When enabled, students see a digital countdown after they start the assessment. Leave off for untimed work.</div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Questions</label>
                            <div class="border rounded-3 p-3 mb-2 bg-light-subtle" id="addAutoGeneratePanel"
                                 data-classroom-id="<?= (int) $classroomId ?>"
                                 data-api="<?= htmlspecialchars('api/faculty_assessment_generate_questions.php', ENT_QUOTES, 'UTF-8') ?>"
                                 data-ai-available="<?= $assessmentAiAvailable ? '1' : '0' ?>">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                    <span class="small fw-semibold"><i class="fa-solid fa-wand-magic-sparkles me-1 text-primary"></i>Auto-generate questions</span>
                                    <?php if ($assessmentAiAvailable): ?>
                                        <span class="badge text-bg-primary-subtle border text-primary-emphasis">AI-assisted</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-secondary-subtle border">Template-based</span>
                                    <?php endif; ?>
                                </div>
                                <div class="row g-2 align-items-end">
                                    <div class="col-sm-3">
                                        <label class="form-label small mb-0" for="add_auto_count">Count</label>
                                        <input type="number" class="form-control form-control-sm" id="add_auto_count" min="1" max="20" value="5">
                                    </div>
                                    <div class="col-sm-5">
                                        <label class="form-label small mb-0" for="add_auto_topic">Topic (optional)</label>
                                        <input type="text" class="form-control form-control-sm" id="add_auto_topic" maxlength="200" placeholder="Uses assessment title if blank">
                                    </div>
                                    <?php if ($hasCreditedWeek): ?>
                                    <div class="col-sm-4">
                                        <label class="form-label small mb-0" for="add_auto_week">From week materials</label>
                                        <select class="form-select form-select-sm" id="add_auto_week">
                                            <option value="">Assessment title / description only</option>
                                            <?php for ($wn = 1; $wn <= 18; $wn++): ?>
                                                <option value="Week <?= $wn ?>">Week <?= $wn ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($hasCreditedWeek): ?>
                                    <div class="col-12">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" value="1" id="add_auto_use_materials" checked>
                                            <label class="form-check-label small" for="add_auto_use_materials">Include posted course materials for the selected week</label>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    <div class="col-12 d-flex flex-wrap gap-2">
                                        <button type="button" class="btn btn-primary btn-sm" id="addAutoGenerateBtn"<?= app_tooltip_attr('Creates questions with answer keys based on your title, description, and optional week materials.') ?>>
                                            <i class="fa-solid fa-wand-magic-sparkles me-1"></i>Generate questions
                                        </button>
                                        <button type="button" class="btn btn-outline-primary btn-sm d-none" id="addAutoGenerateMoreBtn"<?= app_tooltip_attr('Adds more questions without removing existing ones.') ?>>
                                            <i class="fa-solid fa-plus me-1"></i>Generate more
                                        </button>
                                    </div>
                                    <div class="col-12">
                                        <div class="small text-muted d-none" id="addAutoGenerateStatus"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="small text-muted mb-1">Total items: <span id="addTotalItems">0</span></div>
                            <div class="border rounded-3 p-2" id="addQuestionBuilder"></div>
                            <button type="button" class="btn btn-outline-secondary btn-sm mt-2" id="addQuestionRowBtn">+ Add question</button>
                            <div class="form-text small">Choose a type above, then auto-generate or add questions manually. Answer keys are filled for auto-graded types.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small" for="add-assessment-description">Description</label>
                            <div class="wordpad-shell" data-wordpad data-wordpad-name="description">
                                <div class="wordpad-toolbar d-none" role="toolbar" aria-label="Formatting toolbar">
                                    <select class="form-select form-select-sm wordpad-select" data-wordpad-block aria-label="Text style">
                                        <option value="<p>">Normal text</option>
                                        <option value="<h3>">Heading</option>
                                        <option value="<blockquote>">Quote</option>
                                    </select>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-command="bold" title="Bold"<?= app_tooltip_attr('Makes selected text bold in the description.') ?>><i class="fa-solid fa-bold"></i></button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-command="italic" title="Italic"<?= app_tooltip_attr('Italicizes selected text for emphasis.') ?>><i class="fa-solid fa-italic"></i></button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-command="underline" title="Underline"<?= app_tooltip_attr('Underlines selected text.') ?>><i class="fa-solid fa-underline"></i></button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-command="insertUnorderedList" title="Bulleted list"<?= app_tooltip_attr('Starts or continues a bulleted list.') ?>><i class="fa-solid fa-list-ul"></i></button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-command="insertOrderedList" title="Numbered list"<?= app_tooltip_attr('Starts or continues a numbered list.') ?>><i class="fa-solid fa-list-ol"></i></button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-command="justifyLeft" title="Align left"<?= app_tooltip_attr('Aligns paragraph text to the left.') ?>><i class="fa-solid fa-align-left"></i></button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-command="justifyCenter" title="Align center"<?= app_tooltip_attr('Centers paragraph text.') ?>><i class="fa-solid fa-align-center"></i></button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-command="justifyRight" title="Align right"<?= app_tooltip_attr('Aligns paragraph text to the right.') ?>><i class="fa-solid fa-align-right"></i></button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-command="createLink" title="Insert link"<?= app_tooltip_attr('Turns the selection into a hyperlink.') ?>><i class="fa-solid fa-link"></i></button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-command="unlink" title="Remove link"<?= app_tooltip_attr('Removes the link from the selected text without deleting the text.') ?>><i class="fa-solid fa-link-slash"></i></button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-command="removeFormat" title="Clear formatting"<?= app_tooltip_attr('Strips bold, italics, and other formatting from the selection.') ?>><i class="fa-solid fa-eraser"></i></button>
                                </div>
                                <div class="wordpad-editor form-control d-none" contenteditable="true" data-placeholder="Instructions and expectations for students."></div>
                                <textarea id="add-assessment-description" name="description" class="form-control" rows="5" placeholder="Plain text if the rich editor is unavailable"></textarea>
                            </div>
                            <div class="form-text small">Bold, lists, alignment, and links—similar to WordPad.</div>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end gap-2 mt-4">
                        <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal"<?= app_tooltip_attr('Closes the add dialog without creating an assessment.') ?>>Cancel</button>
                        <button type="submit" class="btn btn-primary rounded-pill px-4"<?= app_tooltip_attr('Creates the assessment with the details you entered. Students can then submit work.') ?>>Create assessment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalEditAssessment" tabindex="-1" aria-labelledby="modalEditAssessmentLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg fac-assess-modal">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-0 pb-0">
                <h2 class="modal-title h5 fw-semibold" id="modalEditAssessmentLabel">Edit assessment</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"<?= app_tooltip_attr('Closes the edit dialog without saving.') ?>></button>
            </div>
            <div class="modal-body pt-2">
                <form method="post" id="formEditAssessment">
                    <input type="hidden" name="action" value="update_assessment">
                    <input type="hidden" name="classroom_id" value="<?= (int) $classroomId ?>">
                    <input type="hidden" name="assessment_id" id="edit_assessment_id" value="">
                    <input type="hidden" name="questions_json" id="edit_questions_json" value="[]">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small">Type</label>
                            <select name="assessment_type" class="form-select" id="edit_assessment_type">
                                <option value="multiple_choice">Multiple Choice</option>
                                <option value="true_false">True or False</option>
                                <option value="essay">Essay</option>
                                <option value="problem_solving">Problem Solving</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label small">Title</label>
                            <input type="text" name="title" class="form-control" id="edit_title" maxlength="150" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Points</label>
                            <input type="number" name="total_points" class="form-control" id="edit_points" min="1" step="0.01" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Due date</label>
                            <input type="datetime-local" name="due_at" class="form-control" id="edit_due">
                        </div>
                        <?php if ($hasCreditedWeek): ?>
                        <div class="col-md-4">
                            <label class="form-label small">Credited week</label>
                            <select name="credited_week" id="edit_credited_week" class="form-select">
                                <option value="">No specific week</option>
                                <?php for ($wn = 1; $wn <= 18; $wn++): ?>
                                    <option value="Week <?= $wn ?>">Week <?= $wn ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <?php if ($hasTimeLimit): ?>
                        <div class="col-12">
                            <div class="border rounded-3 p-3 bg-light-subtle">
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" role="switch" name="timer_enabled" value="1" id="edit_timer_enabled">
                                    <label class="form-check-label fw-semibold" for="edit_timer_enabled">Timer settings</label>
                                </div>
                                <div class="row g-2 align-items-end" id="editTimerFields" style="display:none;">
                                    <div class="col-auto">
                                        <label class="form-label small mb-0" for="edit_timer_hours">Hours</label>
                                        <input type="number" name="timer_hours" id="edit_timer_hours" class="form-control" min="0" max="12" value="0" style="width:5.5rem;">
                                    </div>
                                    <div class="col-auto">
                                        <label class="form-label small mb-0" for="edit_timer_minutes">Minutes</label>
                                        <input type="number" name="timer_minutes" id="edit_timer_minutes" class="form-control" min="0" max="59" value="30" style="width:5.5rem;">
                                    </div>
                                    <div class="col-auto pb-1">
                                        <span class="assessment-timer-preview" id="editTimerPreview" aria-hidden="true">00:30:00</span>
                                    </div>
                                </div>
                                <div class="form-text small mt-1">When enabled, students see a digital countdown after they start the assessment.</div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Questions</label>
                            <div class="small text-muted mb-1">Total items: <span id="editTotalItems">0</span></div>
                            <div class="border rounded-3 p-2" id="editQuestionBuilder"></div>
                            <button type="button" class="btn btn-outline-secondary btn-sm mt-2" id="editQuestionRowBtn">+ Add question</button>
                            <div class="form-text small">Update questions and answer keys. Objective items auto-correct.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small" for="edit_description">Description</label>
                            <div class="wordpad-shell" data-wordpad data-wordpad-name="description">
                                <div class="wordpad-toolbar d-none" role="toolbar" aria-label="Formatting toolbar">
                                    <select class="form-select form-select-sm wordpad-select" data-wordpad-block aria-label="Text style">
                                        <option value="<p>">Normal text</option>
                                        <option value="<h3>">Heading</option>
                                        <option value="<blockquote>">Quote</option>
                                    </select>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-command="bold" title="Bold"<?= app_tooltip_attr('Makes selected text bold in the description.') ?>><i class="fa-solid fa-bold"></i></button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-command="italic" title="Italic"<?= app_tooltip_attr('Italicizes selected text for emphasis.') ?>><i class="fa-solid fa-italic"></i></button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-command="underline" title="Underline"<?= app_tooltip_attr('Underlines selected text.') ?>><i class="fa-solid fa-underline"></i></button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-command="insertUnorderedList" title="Bulleted list"<?= app_tooltip_attr('Starts or continues a bulleted list.') ?>><i class="fa-solid fa-list-ul"></i></button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-command="insertOrderedList" title="Numbered list"<?= app_tooltip_attr('Starts or continues a numbered list.') ?>><i class="fa-solid fa-list-ol"></i></button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-command="justifyLeft" title="Align left"<?= app_tooltip_attr('Aligns paragraph text to the left.') ?>><i class="fa-solid fa-align-left"></i></button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-command="justifyCenter" title="Align center"<?= app_tooltip_attr('Centers paragraph text.') ?>><i class="fa-solid fa-align-center"></i></button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-command="justifyRight" title="Align right"<?= app_tooltip_attr('Aligns paragraph text to the right.') ?>><i class="fa-solid fa-align-right"></i></button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-command="createLink" title="Insert link"<?= app_tooltip_attr('Turns the selection into a hyperlink.') ?>><i class="fa-solid fa-link"></i></button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-command="unlink" title="Remove link"<?= app_tooltip_attr('Removes the link from the selected text without deleting the text.') ?>><i class="fa-solid fa-link-slash"></i></button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-command="removeFormat" title="Clear formatting"<?= app_tooltip_attr('Strips bold, italics, and other formatting from the selection.') ?>><i class="fa-solid fa-eraser"></i></button>
                                </div>
                                <div class="wordpad-editor form-control d-none" contenteditable="true" data-placeholder="Instructions and expectations for students."></div>
                                <textarea name="description" class="form-control" id="edit_description" rows="5" placeholder="Plain text if the rich editor is unavailable"></textarea>
                            </div>
                            <div class="form-text small">Bold, lists, alignment, and links—similar to WordPad.</div>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end gap-2 mt-4">
                        <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal"<?= app_tooltip_attr('Closes without saving edits to this assessment.') ?>>Cancel</button>
                        <button type="submit" class="btn btn-primary rounded-pill px-4"<?= app_tooltip_attr('Saves your changes to type, title, points, due date, and description.') ?>>Save changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if ($missingTables === [] && $classroom): ?>
<script>
(function () {
    function showToast(msg, duration) {
        var ms = typeof duration === 'number' ? duration : 2200;
        var toast = document.getElementById('toastMsg');
        if (!toast) return;
        toast.textContent = msg;
        toast.style.opacity = '1';
        setTimeout(function () { toast.style.opacity = '0'; }, ms);
    }

    var filterInput = document.getElementById('assessmentTableFilter');
    var table = document.getElementById('assessmentsDataTable');
    if (filterInput && table) {
        filterInput.addEventListener('input', function () {
            var q = this.value.trim().toLowerCase();
            var weekHeaders = {};
            table.querySelectorAll('tbody tr:not(.week-group-header)').forEach(function (row) {
                var hay = row.getAttribute('data-filter') || '';
                var visible = q === '' || hay.indexOf(q) !== -1;
                row.classList.toggle('d-none', !visible);
                if (visible) {
                    var wk = (row.getAttribute('data-week') || '').toLowerCase();
                    if (wk) weekHeaders[wk] = true;
                }
            });
            table.querySelectorAll('tbody tr.week-group-header').forEach(function (hdr) {
                var hdrKey = (hdr.getAttribute('data-filter') || '').toLowerCase();
                hdr.classList.toggle('d-none', q !== '' && !weekHeaders[hdrKey]);
            });
        });
    }

    if (table) {
        var sortState = { col: null, dir: 1 };
        table.querySelectorAll('thead th.sortable').forEach(function (th) {
            th.addEventListener('click', function () {
                var key = th.getAttribute('data-sort');
                var type = th.getAttribute('data-type') || 'string';
                if (!key) return;
                sortState.dir = sortState.col === key ? -sortState.dir : 1;
                sortState.col = key;
                var tbody = table.querySelector('tbody');
                var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr:not(.week-group-header)'));
                rows.sort(function (a, b) {
                    var av, bv;
                    if (key === 'type') { av = a.getAttribute('data-type') || ''; bv = b.getAttribute('data-type') || ''; }
                    else if (key === 'title') { av = a.getAttribute('data-title') || ''; bv = b.getAttribute('data-title') || ''; }
                    else if (key === 'points') { av = parseFloat(a.getAttribute('data-points') || '0'); bv = parseFloat(b.getAttribute('data-points') || '0'); }
                    else if (key === 'due') { av = a.getAttribute('data-due') || ''; bv = b.getAttribute('data-due') || ''; }
                    else { return 0; }
                    var cmp = 0;
                    if (type === 'number') { cmp = av - bv; }
                    else { cmp = av < bv ? -1 : (av > bv ? 1 : 0); }
                    return cmp * sortState.dir;
                });
                rows.forEach(function (r) { tbody.appendChild(r); });
            });
        });
    }

    var searchInput = document.getElementById('searchSubmissionInput');
    function applySubmissionSearch() {
        var q = (searchInput && searchInput.value || '').trim().toLowerCase();
        var visiblePanel = document.querySelector('.submissions-panel:not(.d-none)');
        if (!visiblePanel) return;
        visiblePanel.querySelectorAll('.submission-card').forEach(function (card) {
            var hay = (card.getAttribute('data-student-filter') || '').toLowerCase();
            card.classList.toggle('fac-filter-hide', q !== '' && hay.indexOf(q) === -1);
        });
    }

    function switchAssessmentPanel(id) {
        document.querySelectorAll('.submissions-panel').forEach(function (p) {
            p.classList.toggle('d-none', p.getAttribute('data-assessment-panel') !== id);
        });
        document.querySelectorAll('[data-assessment-tab]').forEach(function (btn) {
            var on = btn.getAttribute('data-assessment-tab') === id;
            btn.classList.toggle('active', on);
            btn.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        applySubmissionSearch();
        try {
            var path = window.location.pathname || '';
            history.replaceState(null, '', path + '?id=<?= (int) $classroomId ?>&submissions=' + encodeURIComponent(id));
        } catch (e) {}
    }

    var tabContainer = document.getElementById('assessmentTabsContainer');
    if (tabContainer) {
        tabContainer.querySelectorAll('[data-assessment-tab]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                switchAssessmentPanel(btn.getAttribute('data-assessment-tab'));
            });
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', applySubmissionSearch);
    }

    document.querySelectorAll('.js-submission-form').forEach(function (form) {
        var ta = form.querySelector('.js-feedback-input');
        var countEl = form.querySelector('.js-char-count-num');
        function updateCount() {
            if (!ta || !countEl) return;
            countEl.textContent = String(ta.value.length);
        }
        if (ta) {
            ta.addEventListener('input', updateCount);
            updateCount();
        }
        var btnCancel = form.querySelector('.js-cancel-edit');
        if (btnCancel) {
            btnCancel.addEventListener('click', function () {
                var os = form.getAttribute('data-original-score') || '';
                var of = form.getAttribute('data-original-feedback') || '';
                var scoreIn = form.querySelector('.js-score-input');
                if (scoreIn) scoreIn.value = os;
                if (ta) ta.value = of;
                updateCount();
            });
        }
    });

    function buildQuestionRow(q, index) {
        var wrap = document.createElement('div');
        wrap.className = 'border rounded-3 p-2 mb-2';
        var optionsText = Array.isArray(q.options) ? q.options.join("\n") : '';
        wrap.innerHTML =
            '<div class="d-flex justify-content-between align-items-center mb-2">' +
            '<strong class="small">Question ' + (index + 1) + '</strong>' +
            '<button type="button" class="btn btn-sm btn-outline-danger js-q-remove">Remove</button>' +
            '</div>' +
            '<div class="row g-2">' +
            '<div class="col-md-4"><label class="form-label small">Type</label><select class="form-select form-select-sm js-q-type">' +
            '<option value="multiple_choice">Multiple Choice</option>' +
            '<option value="true_false">True/False</option>' +
            '<option value="essay">Essay</option>' +
            '<option value="problem_solving">Problem Solving</option>' +
            '</select></div>' +
            '<div class="col-md-2"><label class="form-label small">Points</label><input type="number" class="form-control form-control-sm js-q-points" min="0.1" step="0.01" value="' + (q.points || 1) + '"></div>' +
            '<div class="col-md-3"><label class="form-label small">Word limit</label><input type="number" class="form-control form-control-sm js-q-word" min="0" step="1" value="' + (q.word_limit || '') + '"></div>' +
            '<div class="col-md-3"><label class="form-label small">Char limit</label><input type="number" class="form-control form-control-sm js-q-char" min="0" step="1" value="' + (q.char_limit || '') + '"></div>' +
            '<div class="col-12"><label class="form-label small">Question text</label><textarea class="form-control form-control-sm js-q-text" rows="2"></textarea></div>' +
            '<div class="col-12 js-q-options-wrap"><label class="form-label small">Options (one per line; 2-6)</label><textarea class="form-control form-control-sm js-q-options" rows="3"></textarea></div>' +
            '<div class="col-12"><label class="form-label small">Answer key</label><input type="text" class="form-control form-control-sm js-q-key" placeholder="e.g. B, true, 3.14, x=5"></div>' +
            '<div class="col-12"><div class="form-check"><input class="form-check-input js-q-steps" type="checkbox" id="q-steps-' + index + '"><label class="form-check-label small" for="q-steps-' + index + '">Allow optional step-by-step answer</label></div></div>' +
            '</div>';
        wrap.querySelector('.js-q-type').value = q.question_type || 'essay';
        wrap.querySelector('.js-q-text').value = q.question_text || '';
        wrap.querySelector('.js-q-key').value = q.answer_key || '';
        wrap.querySelector('.js-q-options').value = optionsText;
        wrap.querySelector('.js-q-steps').checked = !!q.allow_steps;
        return wrap;
    }

    function initBuilder(builderId, addBtnId, hiddenInputId, pointsInputSelector, assessmentTypeSelector, totalItemsSelector) {
        var builder = document.getElementById(builderId);
        var addBtn = document.getElementById(addBtnId);
        var hidden = document.getElementById(hiddenInputId);
        var form = hidden ? hidden.closest('form') : null;
        var pointsInput = form ? form.querySelector(pointsInputSelector) : null;
        var assessmentTypeInput = form ? form.querySelector(assessmentTypeSelector) : null;
        var totalItemsEl = document.querySelector(totalItemsSelector);
        if (!builder || !addBtn || !hidden || !form) return;

        function currentAssessmentType() {
            return assessmentTypeInput ? (assessmentTypeInput.value || 'essay') : 'essay';
        }

        function applyTypeLock(row) {
            var forced = currentAssessmentType();
            var typeSelect = row.querySelector('.js-q-type');
            if (!typeSelect) return;
            typeSelect.value = forced;
            typeSelect.disabled = true;
        }

        function collect() {
            var rows = [];
            var sum = 0;
            builder.querySelectorAll('.border.rounded-3').forEach(function (row) {
                var type = currentAssessmentType();
                var points = parseFloat(row.querySelector('.js-q-points').value || '0');
                var questionText = (row.querySelector('.js-q-text').value || '').trim();
                var options = (row.querySelector('.js-q-options').value || '').split(/\r?\n/).map(function (v) { return v.trim(); }).filter(Boolean);
                var answerKey = (row.querySelector('.js-q-key').value || '').trim();
                var wordLimit = (row.querySelector('.js-q-word').value || '').trim();
                var charLimit = (row.querySelector('.js-q-char').value || '').trim();
                var allowSteps = row.querySelector('.js-q-steps').checked ? 1 : 0;
                if (!questionText || points <= 0) return;
                rows.push({
                    question_type: type,
                    question_text: questionText,
                    points: points,
                    options: options,
                    answer_key: answerKey,
                    word_limit: wordLimit === '' ? null : parseInt(wordLimit, 10),
                    char_limit: charLimit === '' ? null : parseInt(charLimit, 10),
                    allow_steps: allowSteps
                });
                sum += points;
            });
            hidden.value = JSON.stringify(rows);
            if (totalItemsEl) {
                totalItemsEl.textContent = String(rows.length);
            }
            if (pointsInput) {
                pointsInput.value = sum > 0 ? (Math.round(sum * 100) / 100).toString() : '';
                pointsInput.readOnly = true;
            }
            return rows;
        }

        function renumber() {
            builder.querySelectorAll('.border.rounded-3').forEach(function (row, idx) {
                var label = row.querySelector('strong');
                if (label) label.textContent = 'Question ' + (idx + 1);
            });
        }

        function attachRowEvents(row) {
            applyTypeLock(row);
            row.addEventListener('input', collect);
            row.querySelector('.js-q-type').addEventListener('change', function () {
                var t = currentAssessmentType();
                var opts = row.querySelector('.js-q-options-wrap');
                var key = row.querySelector('.js-q-key');
                var steps = row.querySelector('.js-q-steps');
                var word = row.querySelector('.js-q-word');
                var chr = row.querySelector('.js-q-char');
                if (opts) opts.style.display = t === 'multiple_choice' ? '' : 'none';
                if (key) key.placeholder = t === 'true_false' ? 'true or false' : (t === 'essay' ? 'No answer key needed for essay' : 'e.g. B, 3.14, x=5');
                if (steps) {
                    var canSteps = t === 'problem_solving';
                    steps.checked = canSteps ? steps.checked : false;
                    steps.disabled = !canSteps;
                }
                if (word) word.disabled = t !== 'essay';
                if (chr) chr.disabled = t !== 'essay';
                collect();
            });
            row.querySelector('.js-q-remove').addEventListener('click', function () {
                row.remove();
                renumber();
                collect();
            });
            row.querySelector('.js-q-type').dispatchEvent(new Event('change'));
        }

        addBtn.addEventListener('click', function () {
            var row = buildQuestionRow({ question_type: currentAssessmentType(), points: 1, options: [] }, builder.querySelectorAll('.border.rounded-3').length);
            builder.appendChild(row);
            attachRowEvents(row);
            renumber();
            collect();
        });

        if (assessmentTypeInput) {
            assessmentTypeInput.addEventListener('change', function () {
                builder.querySelectorAll('.border.rounded-3').forEach(function (row) {
                    applyTypeLock(row);
                    row.querySelector('.js-q-type').dispatchEvent(new Event('change'));
                });
                collect();
            });
        }

        form.addEventListener('submit', function (event) {
            var rows = collect();
            if (!rows.length) {
                event.preventDefault();
                showToast('Add at least one question.', 2800);
            }
        });

        return {
            setRows: function (rows) {
                builder.innerHTML = '';
                (rows || []).forEach(function (r, idx) {
                    var row = buildQuestionRow(r, idx);
                    builder.appendChild(row);
                    attachRowEvents(row);
                });
                if (!rows || !rows.length) {
                    addBtn.click();
                } else {
                    renumber();
                    collect();
                }
            },
            appendRows: function (rows) {
                var existing = collect();
                var merged = existing.concat(rows || []);
                builder.innerHTML = '';
                merged.forEach(function (r, idx) {
                    var row = buildQuestionRow(r, idx);
                    builder.appendChild(row);
                    attachRowEvents(row);
                });
                renumber();
                collect();
            },
            collect: collect
        };
    }

    var addBuilderApi = initBuilder('addQuestionBuilder', 'addQuestionRowBtn', 'add_questions_json', 'input[name="total_points"]', 'select[name="assessment_type"]', '#addTotalItems');
    var editBuilderApi = initBuilder('editQuestionBuilder', 'editQuestionRowBtn', 'edit_questions_json', 'input[name="total_points"]', '#edit_assessment_type', '#editTotalItems');
    if (addBuilderApi) {
        addBuilderApi.setRows([]);
    }

    function bindAutoGenerate(panelId, generateBtnId, moreBtnId, statusId, builderApi, formId, countId, topicId, weekId, useMaterialsId) {
        var panel = document.getElementById(panelId);
        var generateBtn = document.getElementById(generateBtnId);
        var moreBtn = document.getElementById(moreBtnId);
        var statusEl = document.getElementById(statusId);
        var form = document.getElementById(formId);
        if (!panel || !generateBtn || !builderApi || !form) {
            return;
        }

        function setStatus(text, isError) {
            if (!statusEl) return;
            statusEl.textContent = text;
            statusEl.classList.toggle('text-danger', !!isError);
            statusEl.classList.toggle('text-muted', !isError);
            statusEl.classList.toggle('d-none', !text);
        }

        function readDescription(formEl) {
            var ta = formEl.querySelector('textarea[name="description"]');
            if (!ta) return '';
            var shell = ta.closest('.wordpad-shell');
            var ed = shell && shell.querySelector('.wordpad-editor');
            if (ed && !ed.classList.contains('d-none')) {
                return (ed.innerText || ed.textContent || '').trim();
            }
            return (ta.value || '').trim();
        }

        async function requestQuestions(append) {
            var classroomId = parseInt(panel.dataset.classroomId || '0', 10);
            var api = panel.dataset.api || '';
            if (!classroomId || !api) {
                setStatus('Missing classroom context.', true);
                return;
            }

            var typeSelect = form.querySelector('select[name="assessment_type"]');
            var titleInput = form.querySelector('input[name="title"]');
            var countInput = document.getElementById(countId);
            var topicInput = document.getElementById(topicId);
            var weekSelect = weekId ? document.getElementById(weekId) : null;
            var useMaterialsInput = document.getElementById(useMaterialsId);

            var assessmentType = typeSelect ? typeSelect.value : 'essay';
            var title = titleInput ? (titleInput.value || '').trim() : '';
            var description = readDescription(form);
            var count = countInput ? parseInt(countInput.value || '5', 10) : 5;
            if (count < 1) count = 1;
            if (count > 20) count = 20;
            var topic = topicInput ? (topicInput.value || '').trim() : '';
            var week = weekSelect ? (weekSelect.value || '').trim() : '';
            var useMaterials = useMaterialsInput ? (useMaterialsInput.checked && week !== '') : false;

            var existing = append ? builderApi.collect() : [];
            var exclude = existing.map(function (q) { return q.question_text || ''; });

            generateBtn.disabled = true;
            if (moreBtn) moreBtn.disabled = true;
            setStatus(append ? 'Generating more questions…' : 'Generating questions…', false);

            try {
                var res = await fetch(api, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        classroom_id: classroomId,
                        assessment_type: assessmentType,
                        title: title,
                        description: description,
                        count: count,
                        topic: topic,
                        credited_week: week,
                        use_materials: useMaterials,
                        exclude: exclude
                    })
                });
                var data = await res.json();
                if (!res.ok || !data.ok) {
                    throw new Error(data.error || 'Could not generate questions.');
                }

                var incoming = Array.isArray(data.questions) ? data.questions : [];
                if (!incoming.length) {
                    throw new Error('No questions were generated. Try adding a title or selecting a week with materials.');
                }

                var mapped = incoming.map(function (q) {
                    return {
                        question_type: q.question_type || assessmentType,
                        question_text: q.question_text || '',
                        points: q.points || 1,
                        options: Array.isArray(q.options) ? q.options : [],
                        answer_key: q.answer_key || '',
                        word_limit: q.word_limit,
                        char_limit: q.char_limit,
                        allow_steps: q.allow_steps ? 1 : 0
                    };
                });

                if (append && existing.length) {
                    builderApi.appendRows(mapped);
                } else {
                    builderApi.setRows(mapped);
                }

                if (moreBtn) {
                    moreBtn.classList.remove('d-none');
                }

                var sourceNote = data.source === 'ai' ? 'AI-assisted' : 'template-based';
                setStatus('Added ' + mapped.length + ' question(s) (' + sourceNote + '). Review and edit before saving.', false);
                showToast('Generated ' + mapped.length + ' question(s).');
            } catch (err) {
                setStatus(err.message || 'Something went wrong.', true);
            } finally {
                generateBtn.disabled = false;
                if (moreBtn) moreBtn.disabled = false;
            }
        }

        generateBtn.addEventListener('click', function () { requestQuestions(false); });
        if (moreBtn) {
            moreBtn.addEventListener('click', function () { requestQuestions(true); });
        }
    }

    bindAutoGenerate(
        'addAutoGeneratePanel',
        'addAutoGenerateBtn',
        'addAutoGenerateMoreBtn',
        'addAutoGenerateStatus',
        addBuilderApi,
        'formAddAssessment',
        'add_auto_count',
        'add_auto_topic',
        'add_auto_week',
        'add_auto_use_materials'
    );

    function formatTimerPreview(hours, minutes) {
        var h = Math.max(0, parseInt(hours, 10) || 0);
        var m = Math.max(0, parseInt(minutes, 10) || 0);
        if (m > 59) {
            h += Math.floor(m / 60);
            m = m % 60;
        }
        h = Math.min(12, h);
        var totalSecs = (h * 3600) + (m * 60);
        var hh = String(Math.floor(totalSecs / 3600)).padStart(2, '0');
        var mm = String(Math.floor((totalSecs % 3600) / 60)).padStart(2, '0');
        var ss = String(totalSecs % 60).padStart(2, '0');
        return hh + ':' + mm + ':' + ss;
    }

    function bindTimerSettings(enabledId, fieldsId, hoursId, minutesId, previewId) {
        var enabled = document.getElementById(enabledId);
        var fields = document.getElementById(fieldsId);
        var hours = document.getElementById(hoursId);
        var minutes = document.getElementById(minutesId);
        var preview = document.getElementById(previewId);
        if (!enabled || !fields || !hours || !minutes) {
            return;
        }
        var sync = function () {
            fields.style.display = enabled.checked ? '' : 'none';
            if (preview) {
                preview.textContent = formatTimerPreview(hours.value, minutes.value);
            }
        };
        enabled.addEventListener('change', sync);
        hours.addEventListener('input', sync);
        minutes.addEventListener('input', sync);
        sync();
    }

    bindTimerSettings('add_timer_enabled', 'addTimerFields', 'add_timer_hours', 'add_timer_minutes', 'addTimerPreview');
    bindTimerSettings('edit_timer_enabled', 'editTimerFields', 'edit_timer_hours', 'edit_timer_minutes', 'editTimerPreview');

    var editModal = document.getElementById('modalEditAssessment');
    if (editModal) {
        editModal.addEventListener('show.bs.modal', function (ev) {
            var btn = ev.relatedTarget;
            if (!btn || !btn.getAttribute) return;
            document.getElementById('edit_assessment_id').value = btn.getAttribute('data-id') || '';
            document.getElementById('edit_title').value = btn.getAttribute('data-title') || '';
            document.getElementById('edit_points').value = btn.getAttribute('data-points') || '';
            document.getElementById('edit_due').value = btn.getAttribute('data-due') || '';
            var editWeekSel = document.getElementById('edit_credited_week');
            if (editWeekSel) {
                editWeekSel.value = btn.getAttribute('data-credited-week') || '';
            }
            var editTimerEnabled = document.getElementById('edit_timer_enabled');
            var editTimerHours = document.getElementById('edit_timer_hours');
            var editTimerMinutes = document.getElementById('edit_timer_minutes');
            if (editTimerEnabled && editTimerHours && editTimerMinutes) {
                var limitMins = parseInt(btn.getAttribute('data-time-limit') || '0', 10) || 0;
                if (limitMins > 0) {
                    editTimerEnabled.checked = true;
                    editTimerHours.value = String(Math.floor(limitMins / 60));
                    editTimerMinutes.value = String(limitMins % 60);
                } else {
                    editTimerEnabled.checked = false;
                    editTimerHours.value = '0';
                    editTimerMinutes.value = '30';
                }
                editTimerEnabled.dispatchEvent(new Event('change'));
            }
            var editDescTa = document.getElementById('edit_description');
            if (editDescTa) {
                editDescTa.value = btn.getAttribute('data-description') || '';
                var shell = editDescTa.closest('.wordpad-shell');
                var ed = shell && shell.querySelector('.wordpad-editor');
                if (ed) {
                    var v = (editDescTa.value || '').trim();
                    ed.innerHTML = v !== '' ? editDescTa.value : '';
                }
            }
            var t = (btn.getAttribute('data-type') || 'essay').toLowerCase();
            var sel = document.getElementById('edit_assessment_type');
            if (sel) {
                var allowed = ['multiple_choice', 'true_false', 'essay', 'problem_solving'];
                sel.value = allowed.indexOf(t) !== -1 ? t : 'essay';
                sel.dispatchEvent(new Event('change'));
            }
            var qRaw = btn.getAttribute('data-questions') || '[]';
            if (editBuilderApi) {
                try {
                    editBuilderApi.setRows(JSON.parse(qRaw));
                } catch (e) {
                    editBuilderApi.setRows([]);
                }
            }
        });
    }
})();

document.querySelectorAll('[data-wordpad]').forEach(function (shell) {
    var fieldName = shell.getAttribute('data-wordpad-name') || 'body';
    var textarea = shell.querySelector('textarea[name="' + fieldName + '"]');
    var editor = shell.querySelector('.wordpad-editor');
    var toolbar = shell.querySelector('.wordpad-toolbar');
    var blockSelect = shell.querySelector('[data-wordpad-block]');
    var form = shell.closest('form');

    if (!textarea || !editor || !toolbar || !form) {
        return;
    }

    toolbar.classList.remove('d-none');
    editor.classList.remove('d-none');
    editor.innerHTML = textarea.value.trim() !== '' ? textarea.value : '';
    textarea.classList.add('d-none');

    var syncEditor = function () {
        var html = editor.innerHTML
            .replace(/<div><br><\/div>/gi, '')
            .replace(/&nbsp;/gi, ' ')
            .trim();
        textarea.value = html;
    };

    var runCommand = function (command, value) {
        editor.focus();
        document.execCommand('styleWithCSS', false, false);
        document.execCommand(command, false, value);
        syncEditor();
    };

    toolbar.addEventListener('click', function (event) {
        var btnEl = event.target.closest('button[data-command]');
        if (!btnEl) {
            return;
        }

        event.preventDefault();
        var command = btnEl.getAttribute('data-command') || '';

        if (command === 'createLink') {
            var url = window.prompt('Enter link URL', 'https://');
            if (url) {
                runCommand(command, url);
            }
            return;
        }

        runCommand(command);
    });

    if (blockSelect) {
        blockSelect.addEventListener('change', function (event) {
            var value = event.target.value || '<p>';
            runCommand('formatBlock', value);
        });
    }

    editor.addEventListener('input', syncEditor);
    editor.addEventListener('blur', syncEditor);
    form.addEventListener('submit', syncEditor);
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

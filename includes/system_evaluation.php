<?php
declare(strict_types=1);

/**
 * System-use evaluation (student / faculty feedback on the portal).
 */

function system_evaluation_tables_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        $ready = db_table_exists('system_evaluation_responses')
            && db_table_exists('system_evaluation_answers');
    }

    return $ready;
}

/**
 * Current evaluation period label (school year style).
 */
function system_evaluation_current_term(): string
{
    $now = new DateTimeImmutable('now');
    $year = (int) $now->format('Y');
    $month = (int) $now->format('n');

    // June–May school year (common PH calendar); before June → prior SY.
    if ($month >= 6) {
        return $year . '-' . ($year + 1);
    }

    return ($year - 1) . '-' . $year;
}

/**
 * Likert scale labels (1–5).
 *
 * @return array<int, string>
 */
function system_evaluation_rating_labels(): array
{
    return [
        1 => 'Poor',
        2 => 'Fair',
        3 => 'Good',
        4 => 'Very good',
        5 => 'Excellent',
    ];
}

/**
 * Role-specific Likert items.
 *
 * @return array<string, string> question_key => prompt
 */
function system_evaluation_questions(string $role): array
{
    if ($role === 'faculty') {
        return [
            'ease_of_use' => 'How easy is the portal to navigate and use for your daily work?',
            'classroom_tools' => 'How useful are the online classroom tools (content, Meet links, weekly topics)?',
            'assessments_grading' => 'How well do assessments, submissions, and grading support your teaching?',
            'attendance_students' => 'How helpful are attendance tracking and the student list?',
            'schedule_reports' => 'How useful are schedules, teaching load, and reports?',
            'reliability' => 'How reliable is the system (speed, uptime, fewer errors)?',
            'overall' => 'Overall, how satisfied are you with using this system?',
        ];
    }

    return [
        'ease_of_use' => 'How easy is the portal to navigate and use for your studies?',
        'classroom_access' => 'How useful are the online classrooms (materials, announcements, Meet)?',
        'assessments' => 'How clear and usable are quizzes, assignments, and viewing your scores?',
        'learning_tools' => 'How helpful are learning tools (offline reading, EduTools, materials reviewer, calculator)?',
        'communication' => 'How useful are messages and class communication features?',
        'reliability' => 'How reliable is the system (speed, uptime, fewer errors)?',
        'overall' => 'Overall, how satisfied are you with using this system?',
    ];
}

/**
 * @return array<string, mixed>|null
 */
function system_evaluation_get_response(int $userId, string $role, string $termLabel): ?array
{
    if (!system_evaluation_tables_ready() || $userId < 1) {
        return null;
    }

    $st = db()->prepare(
        'SELECT id, user_id, role, term_label, overall_rating, comments, created_at, updated_at
         FROM system_evaluation_responses
         WHERE user_id = ? AND role = ? AND term_label = ?
         LIMIT 1'
    );
    $st->execute([$userId, $role, $termLabel]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * @return array<string, int> question_key => rating
 */
function system_evaluation_get_answers(int $responseId): array
{
    if (!system_evaluation_tables_ready() || $responseId < 1) {
        return [];
    }

    $st = db()->prepare(
        'SELECT question_key, rating
         FROM system_evaluation_answers
         WHERE response_id = ?'
    );
    $st->execute([$responseId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = (string) ($row['question_key'] ?? '');
        if ($key === '') {
            continue;
        }
        $out[$key] = (int) ($row['rating'] ?? 0);
    }

    return $out;
}

/**
 * Save or update one evaluation response for the term.
 *
 * @param array<string, int> $ratings question_key => 1–5
 */
function system_evaluation_save(
    int $userId,
    string $role,
    string $termLabel,
    array $ratings,
    string $comments
): void {
    if (!system_evaluation_tables_ready()) {
        throw new RuntimeException('Evaluation tables are not ready. Run upgrade_roles.php once.');
    }
    if ($userId < 1) {
        throw new RuntimeException('Invalid user session.');
    }
    if (!in_array($role, ['student', 'faculty'], true)) {
        throw new RuntimeException('Invalid evaluation role.');
    }

    $questions = system_evaluation_questions($role);
    $normalized = [];
    foreach ($questions as $key => $_prompt) {
        $rating = (int) ($ratings[$key] ?? 0);
        if ($rating < 1 || $rating > 5) {
            throw new RuntimeException('Please rate every item from 1 (Poor) to 5 (Excellent).');
        }
        $normalized[$key] = $rating;
    }

    $overall = (int) ($normalized['overall'] ?? 0);
    $comments = trim($comments);
    if (mb_strlen($comments) > 2000) {
        throw new RuntimeException('Comments must be 2000 characters or fewer.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $existing = system_evaluation_get_response($userId, $role, $termLabel);
        if ($existing) {
            $responseId = (int) $existing['id'];
            $pdo->prepare(
                'UPDATE system_evaluation_responses
                 SET overall_rating = ?, comments = ?
                 WHERE id = ?'
            )->execute([$overall, $comments !== '' ? $comments : null, $responseId]);
            $pdo->prepare('DELETE FROM system_evaluation_answers WHERE response_id = ?')
                ->execute([$responseId]);
        } else {
            $pdo->prepare(
                'INSERT INTO system_evaluation_responses (user_id, role, term_label, overall_rating, comments)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$userId, $role, $termLabel, $overall, $comments !== '' ? $comments : null]);
            $responseId = (int) $pdo->lastInsertId();
        }

        $ins = $pdo->prepare(
            'INSERT INTO system_evaluation_answers (response_id, question_key, rating)
             VALUES (?, ?, ?)'
        );
        foreach ($normalized as $key => $rating) {
            $ins->execute([$responseId, $key, $rating]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * @return list<string>
 */
function system_evaluation_available_terms(): array
{
    if (!system_evaluation_tables_ready()) {
        return [system_evaluation_current_term()];
    }

    $rows = db()->query(
        'SELECT DISTINCT term_label
         FROM system_evaluation_responses
         ORDER BY term_label DESC'
    )->fetchAll(PDO::FETCH_COLUMN);

    $terms = array_values(array_filter(array_map('strval', $rows)));
    $current = system_evaluation_current_term();
    if (!in_array($current, $terms, true)) {
        array_unshift($terms, $current);
    }

    return $terms !== [] ? $terms : [$current];
}

/**
 * Summary aggregates for admin reports.
 *
 * @return array{
 *   total:int,
 *   student_count:int,
 *   faculty_count:int,
 *   avg_overall:?float,
 *   by_question:array<string, array{label:string, role:string, avg:?float, count:int}>
 * }
 */
function system_evaluation_summary(?string $termLabel = null, ?string $roleFilter = null): array
{
    $empty = [
        'total' => 0,
        'student_count' => 0,
        'faculty_count' => 0,
        'avg_overall' => null,
        'by_question' => [],
    ];

    if (!system_evaluation_tables_ready()) {
        return $empty;
    }

    $where = ['1=1'];
    $params = [];
    if ($termLabel !== null && $termLabel !== '') {
        $where[] = 'r.term_label = ?';
        $params[] = $termLabel;
    }
    if ($roleFilter !== null && in_array($roleFilter, ['student', 'faculty'], true)) {
        $where[] = 'r.role = ?';
        $params[] = $roleFilter;
    }
    $whereSql = implode(' AND ', $where);

    $st = db()->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN role = 'student' THEN 1 ELSE 0 END) AS student_count,
            SUM(CASE WHEN role = 'faculty' THEN 1 ELSE 0 END) AS faculty_count,
            AVG(overall_rating) AS avg_overall
         FROM system_evaluation_responses r
         WHERE {$whereSql}"
    );
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $byQuestion = [];
    $roles = $roleFilter !== null && $roleFilter !== ''
        ? [$roleFilter]
        : ['student', 'faculty'];

    foreach ($roles as $role) {
        foreach (system_evaluation_questions($role) as $key => $label) {
            $qParams = $params;
            $qWhere = $where;
            $qWhere[] = 'r.role = ?';
            $qParams[] = $role;
            $qWhere[] = 'a.question_key = ?';
            $qParams[] = $key;
            $qSql = implode(' AND ', $qWhere);

            $qs = db()->prepare(
                "SELECT AVG(a.rating) AS avg_rating, COUNT(*) AS cnt
                 FROM system_evaluation_answers a
                 INNER JOIN system_evaluation_responses r ON r.id = a.response_id
                 WHERE {$qSql}"
            );
            $qs->execute($qParams);
            $qRow = $qs->fetch(PDO::FETCH_ASSOC) ?: [];
            $count = (int) ($qRow['cnt'] ?? 0);
            $byQuestion[$role . ':' . $key] = [
                'label' => $label,
                'role' => $role,
                'avg' => $count > 0 ? round((float) $qRow['avg_rating'], 2) : null,
                'count' => $count,
            ];
        }
    }

    $total = (int) ($row['total'] ?? 0);

    return [
        'total' => $total,
        'student_count' => (int) ($row['student_count'] ?? 0),
        'faculty_count' => (int) ($row['faculty_count'] ?? 0),
        'avg_overall' => $total > 0 && $row['avg_overall'] !== null
            ? round((float) $row['avg_overall'], 2)
            : null,
        'by_question' => $byQuestion,
    ];
}

/**
 * Recent responses for admin list (no raw PII beyond display name).
 *
 * @return list<array<string, mixed>>
 */
function system_evaluation_list_responses(?string $termLabel = null, ?string $roleFilter = null, int $limit = 100): array
{
    if (!system_evaluation_tables_ready()) {
        return [];
    }

    $where = ['1=1'];
    $params = [];
    if ($termLabel !== null && $termLabel !== '') {
        $where[] = 'r.term_label = ?';
        $params[] = $termLabel;
    }
    if ($roleFilter !== null && in_array($roleFilter, ['student', 'faculty'], true)) {
        $where[] = 'r.role = ?';
        $params[] = $roleFilter;
    }
    $whereSql = implode(' AND ', $where);
    $limit = max(1, min(500, $limit));

    $st = db()->prepare(
        "SELECT r.id, r.role, r.term_label, r.overall_rating, r.comments, r.created_at, r.updated_at,
                u.full_name, u.username
         FROM system_evaluation_responses r
         INNER JOIN users u ON u.id = r.user_id
         WHERE {$whereSql}
         ORDER BY r.updated_at DESC, r.id DESC
         LIMIT {$limit}"
    );
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Render the evaluation form (shared student/faculty markup).
 *
 * @param array<string, int> $answers
 */
function system_evaluation_render_form(
    string $role,
    string $termLabel,
    array $answers,
    string $comments,
    bool $alreadySubmitted
): void {
    $questions = system_evaluation_questions($role);
    $labels = system_evaluation_rating_labels();
    $roleLabel = $role === 'faculty' ? 'Faculty' : 'Student';
    ?>
    <form method="post" class="system-eval-form" style="max-width: 760px;">
        <input type="hidden" name="action" value="save_evaluation">
        <div class="alert alert-light border mb-3">
            <div class="small text-muted mb-1">Evaluation period</div>
            <strong><?= htmlspecialchars($termLabel, ENT_QUOTES, 'UTF-8') ?></strong>
            <span class="text-muted"> · <?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?> feedback</span>
            <?php if ($alreadySubmitted): ?>
                <div class="small text-success mt-2 mb-0">
                    <i class="fa-solid fa-circle-check me-1"></i>You already submitted for this period. You may update your answers below.
                </div>
            <?php endif; ?>
        </div>

        <p class="text-muted small mb-3">
            Rate each item from <strong>1 (Poor)</strong> to <strong>5 (Excellent)</strong>.
            Your feedback helps improve WPU SABLAe for everyone.
        </p>

        <?php $i = 0; foreach ($questions as $key => $prompt): $i++;
            $selected = (int) ($answers[$key] ?? 0);
            ?>
            <div class="mb-4">
                <label class="form-label fw-semibold mb-2">
                    <?= $i ?>. <?= htmlspecialchars($prompt, ENT_QUOTES, 'UTF-8') ?>
                </label>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($labels as $value => $label): ?>
                        <input type="radio"
                               class="btn-check"
                               name="rating_<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"
                               id="rating_<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>_<?= $value ?>"
                               value="<?= $value ?>"
                               <?= $selected === $value ? 'checked' : '' ?>
                               required>
                        <label class="btn btn-outline-primary btn-sm"
                               for="rating_<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>_<?= $value ?>">
                            <?= $value ?> · <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="mb-3">
            <label for="eval_comments" class="form-label fw-semibold">Additional comments (optional)</label>
            <textarea class="form-control" id="eval_comments" name="comments" rows="4"
                      maxlength="2000"
                      placeholder="What works well? What should we improve?"><?= htmlspecialchars($comments, ENT_QUOTES, 'UTF-8') ?></textarea>
            <div class="form-text">Maximum 2000 characters.</div>
        </div>

        <button type="submit" class="btn btn-primary">
            <i class="fa-solid fa-paper-plane me-1"></i>
            <?= $alreadySubmitted ? 'Update evaluation' : 'Submit evaluation' ?>
        </button>
    </form>
    <?php
}

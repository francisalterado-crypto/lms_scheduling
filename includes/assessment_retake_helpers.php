<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

function assessment_retake_table_ready(): bool
{
    return db_table_exists('assessment_retake_requests');
}

function assessment_retake_ensure_table(): void
{
    if (assessment_retake_table_ready()) {
        return;
    }
    if (!db_table_exists('online_classrooms') || !db_table_exists('classroom_assessments') || !db_table_exists('classroom_students')) {
        return;
    }
    try {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS assessment_retake_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                classroom_id INT NOT NULL,
                assessment_id INT NOT NULL,
                student_id INT NOT NULL,
                submission_id INT NULL,
                reason TEXT NOT NULL,
                status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                reviewed_by_user_id INT NULL,
                reviewed_at DATETIME NULL,
                faculty_remarks VARCHAR(500) NOT NULL DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_arr_pending_classroom (status, classroom_id),
                INDEX idx_arr_student_assessment (student_id, assessment_id),
                CONSTRAINT fk_arr_classroom FOREIGN KEY (classroom_id) REFERENCES online_classrooms(id) ON DELETE CASCADE,
                CONSTRAINT fk_arr_assessment FOREIGN KEY (assessment_id) REFERENCES classroom_assessments(id) ON DELETE CASCADE,
                CONSTRAINT fk_arr_student FOREIGN KEY (student_id) REFERENCES classroom_students(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        // Table may already exist under a concurrent request.
    }
}

/**
 * @return array<string, array<string, mixed>>
 */
function assessment_retake_requests_for_student(int $studentId, array $assessmentIds): array
{
    if ($studentId < 1 || $assessmentIds === [] || !assessment_retake_table_ready()) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($assessmentIds), '?'));
    $st = db()->prepare(
        "SELECT *
         FROM assessment_retake_requests
         WHERE student_id = ? AND assessment_id IN ($placeholders)
         ORDER BY created_at DESC"
    );
    $st->execute(array_merge([$studentId], $assessmentIds));
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $aid = (int) ($row['assessment_id'] ?? 0);
        if ($aid > 0 && !isset($out[(string) $aid])) {
            $out[(string) $aid] = $row;
        }
    }
    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function pending_assessment_retake_requests_for_classroom(int $classroomId): array
{
    if ($classroomId < 1 || !assessment_retake_table_ready()) {
        return [];
    }
    $st = db()->prepare(
        "SELECT r.*, cs.full_name AS student_name, cs.student_number, ca.title AS assessment_title
         FROM assessment_retake_requests r
         INNER JOIN classroom_students cs ON cs.id = r.student_id
         INNER JOIN classroom_assessments ca ON ca.id = r.assessment_id
         WHERE r.classroom_id = ? AND r.status = 'pending'
         ORDER BY r.created_at ASC"
    );
    $st->execute([$classroomId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function create_assessment_retake_request(
    int $classroomId,
    int $assessmentId,
    int $studentId,
    string $reason
): void {
    if (!assessment_retake_table_ready()) {
        throw new RuntimeException('Retake requests are not available yet. Ask your instructor to run upgrade_roles.php.');
    }
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('Please explain why you are requesting another attempt.');
    }
    if (strlen($reason) > 2000) {
        throw new RuntimeException('Your explanation is too long (maximum 2000 characters).');
    }

    $st = db()->prepare(
        'SELECT sub.id, sub.integrity_locked
         FROM classroom_submissions sub
         INNER JOIN classroom_assessments ca ON ca.id = sub.assessment_id
         WHERE sub.assessment_id = ? AND sub.student_id = ? AND ca.classroom_id = ?
         LIMIT 1'
    );
    $st->execute([$assessmentId, $studentId, $classroomId]);
    $submission = $st->fetch(PDO::FETCH_ASSOC);
    if (!$submission) {
        throw new RuntimeException('No locked submission found for this assessment.');
    }
    $integrityLocked = (int) ($submission['integrity_locked'] ?? 0) === 1;
    if (!$integrityLocked && db_column_exists('classroom_submissions', 'integrity_locked')) {
        $fbSt = db()->prepare(
            'SELECT feedback FROM classroom_scores WHERE assessment_id = ? AND student_id = ? LIMIT 1'
        );
        $fbSt->execute([$assessmentId, $studentId]);
        $feedback = (string) ($fbSt->fetchColumn() ?: '');
        $integrityLocked = stripos($feedback, 'left or minimized the assessment window') !== false;
    }
    if (!$integrityLocked) {
        throw new RuntimeException('Retake requests are only available when an assessment was locked due to an integrity violation.');
    }

    $pendingSt = db()->prepare(
        "SELECT id FROM assessment_retake_requests
         WHERE assessment_id = ? AND student_id = ? AND status = 'pending'
         LIMIT 1"
    );
    $pendingSt->execute([$assessmentId, $studentId]);
    if ($pendingSt->fetchColumn()) {
        throw new RuntimeException('You already have a pending retake request for this assessment.');
    }

    db()->prepare(
        'INSERT INTO assessment_retake_requests (classroom_id, assessment_id, student_id, submission_id, reason, status)
         VALUES (?,?,?,?,?,?)'
    )->execute([
        $classroomId,
        $assessmentId,
        $studentId,
        (int) ($submission['id'] ?? 0) ?: null,
        $reason,
        'pending',
    ]);
}

function approve_assessment_retake_request(int $requestId, int $classroomId, int $facultyUserId): void
{
    if (!assessment_retake_table_ready()) {
        throw new RuntimeException('Retake requests are not available.');
    }

    $st = db()->prepare(
        'SELECT r.* FROM assessment_retake_requests r
         INNER JOIN online_classrooms oc ON oc.id = r.classroom_id
         WHERE r.id = ? AND r.classroom_id = ? LIMIT 1'
    );
    $st->execute([$requestId, $classroomId]);
    $req = $st->fetch(PDO::FETCH_ASSOC);
    if (!$req) {
        throw new RuntimeException('Retake request not found.');
    }
    if ((string) ($req['status'] ?? '') !== 'pending') {
        throw new RuntimeException('This retake request is no longer pending.');
    }

    $assessmentId = (int) ($req['assessment_id'] ?? 0);
    $studentId = (int) ($req['student_id'] ?? 0);

    db()->beginTransaction();
    try {
        db()->prepare(
            'DELETE FROM classroom_scores WHERE assessment_id = ? AND student_id = ?'
        )->execute([$assessmentId, $studentId]);

        db()->prepare(
            'DELETE FROM classroom_submissions WHERE assessment_id = ? AND student_id = ?'
        )->execute([$assessmentId, $studentId]);

        db()->prepare(
            "UPDATE assessment_retake_requests
             SET status = 'approved', reviewed_by_user_id = ?, reviewed_at = NOW()
             WHERE id = ?"
        )->execute([$facultyUserId, $requestId]);

        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }
}

function reject_assessment_retake_request(
    int $requestId,
    int $classroomId,
    int $facultyUserId,
    string $remarks = ''
): void {
    if (!assessment_retake_table_ready()) {
        throw new RuntimeException('Retake requests are not available.');
    }

    $st = db()->prepare(
        'SELECT id, status FROM assessment_retake_requests
         WHERE id = ? AND classroom_id = ? LIMIT 1'
    );
    $st->execute([$requestId, $classroomId]);
    $req = $st->fetch(PDO::FETCH_ASSOC);
    if (!$req) {
        throw new RuntimeException('Retake request not found.');
    }
    if ((string) ($req['status'] ?? '') !== 'pending') {
        throw new RuntimeException('This retake request is no longer pending.');
    }

    db()->prepare(
        "UPDATE assessment_retake_requests
         SET status = 'rejected', reviewed_by_user_id = ?, reviewed_at = NOW(), faculty_remarks = ?
         WHERE id = ?"
    )->execute([$facultyUserId, trim($remarks), $requestId]);
}

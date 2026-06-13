<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/mail_helpers.php';

function student_registration_table_ready(): bool
{
    return db_table_exists('student_registration_requests');
}

/**
 * @return array<int, array{id: int, college_code: string, college_name: string}>
 */
function active_colleges_for_registration(): array
{
    if (!db_table_exists('colleges')) {
        return [];
    }
    $st = db()->query(
        "SELECT id, college_code, college_name FROM colleges WHERE status = 'active' ORDER BY college_name"
    );
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return string[]
 */
function active_programs_for_college(int $collegeId): array
{
    if ($collegeId < 1 || !db_table_exists('programs')) {
        return [];
    }
    $st = db()->prepare(
        "SELECT program_name FROM programs WHERE college_id = ? AND status = 'active' ORDER BY program_name"
    );
    $st->execute([$collegeId]);
    return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

/**
 * @return list<string>
 */
function active_year_levels_for_program(int $collegeId, string $programName): array
{
    $defaults = sort_schedule_year_levels(['1', '2', '3', '4', '5']);
    if ($collegeId < 1 || $programName === '' || !db_table_exists('programs')) {
        return $defaults;
    }
    $st = db()->prepare(
        "SELECT id FROM programs WHERE college_id = ? AND program_name = ? AND status = 'active' LIMIT 1"
    );
    $st->execute([$collegeId, $programName]);
    $programId = (int) ($st->fetchColumn() ?: 0);
    if ($programId < 1) {
        return $defaults;
    }
    $levels = program_defined_year_levels($programId);
    return $levels !== [] ? $levels : $defaults;
}

/**
 * @return array<string, list<string>>
 */
function active_year_levels_by_program_for_college(int $collegeId): array
{
    if ($collegeId < 1 || !db_table_exists('programs')) {
        return [];
    }
    if (db_table_exists('programs_year_levels')) {
        return dean_program_year_levels_map($collegeId);
    }
    $defaults = sort_schedule_year_levels(['1', '2', '3', '4', '5']);
    $out = [];
    foreach (active_programs_for_college($collegeId) as $programName) {
        $out[(string) $programName] = $defaults;
    }
    return $out;
}

function validate_student_registration_input(
    string $username,
    string $password,
    string $fullName,
    string $email,
    string $studentNumber,
    int $collegeId,
    string $programName,
    string $yearLevel = ''
): void {
    if ($username === '' || $fullName === '') {
        throw new RuntimeException('Username and full name are required.');
    }
    if (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $username)) {
        throw new RuntimeException('Username must be 3–50 characters (letters, numbers, . _ -).');
    }
    if ($password === '' || strlen($password) < 8) {
        throw new RuntimeException('Password must be at least 8 characters.');
    }
    if ($studentNumber === '') {
        throw new RuntimeException('Student number is required.');
    }
    if ($collegeId < 1) {
        throw new RuntimeException('Please select your college.');
    }
    if ($programName === '') {
        throw new RuntimeException('Please select your program.');
    }
    $yearLevel = trim($yearLevel);
    if ($yearLevel === '') {
        throw new RuntimeException('Please select your year level.');
    }
    if (!in_array($yearLevel, active_year_levels_for_program($collegeId, $programName), true)) {
        throw new RuntimeException('Invalid year level for the selected program.');
    }
    $hasUserEmail = db_column_exists('users', 'email');
    if ($hasUserEmail) {
        if ($email === '') {
            throw new RuntimeException('Email is required so we can notify you when your registration is approved.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid email address.');
        }
    }
    if (!in_array($programName, active_programs_for_college($collegeId), true)) {
        throw new RuntimeException('Invalid program for the selected college.');
    }
}

function username_taken_for_registration(string $username): bool
{
    $st = db()->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
    $st->execute([$username]);
    if ((int) $st->fetchColumn() > 0) {
        return true;
    }
    if (!student_registration_table_ready()) {
        return false;
    }
    $pending = db()->prepare(
        "SELECT COUNT(*) FROM student_registration_requests WHERE username = ? AND status = 'pending'"
    );
    $pending->execute([$username]);
    return (int) $pending->fetchColumn() > 0;
}

function submit_student_registration(
    string $username,
    string $password,
    string $fullName,
    string $email,
    string $studentNumber,
    int $collegeId,
    string $programName,
    string $yearLevel
): void {
    if (!student_registration_table_ready()) {
        throw new RuntimeException('Student registration is not available yet. Ask your administrator to run upgrade_roles.php.');
    }
    if (!db_table_exists('classroom_students') || !db_column_exists('users', 'assigned_program')) {
        throw new RuntimeException('Student accounts are not fully configured. Run upgrade_roles.php first.');
    }

    $username = trim($username);
    $fullName = trim($fullName);
    $email = trim($email);
    $studentNumber = trim($studentNumber);
    $programName = trim($programName);
    $yearLevel = trim($yearLevel);

    validate_student_registration_input(
        $username,
        $password,
        $fullName,
        $email,
        $studentNumber,
        $collegeId,
        $programName,
        $yearLevel
    );

    if (!db_column_exists('student_registration_requests', 'year_level')) {
        throw new RuntimeException('Student registration year level is not available yet. Ask your administrator to run upgrade_roles.php.');
    }

    if (username_taken_for_registration($username)) {
        $s = suggest_available_usernames($username, 3);
        throw new RuntimeException(
            'Username is already taken or pending approval.'
            . ($s ? ' Try: ' . implode(', ', $s) : '')
        );
    }

    db()->prepare(
        'INSERT INTO student_registration_requests
         (username, password, full_name, email, student_number, college_id, program_name, year_level, status)
         VALUES (?,?,?,?,?,?,?,?,?)'
    )->execute([
        $username,
        password_hash($password, PASSWORD_DEFAULT),
        $fullName,
        $email,
        $studentNumber,
        $collegeId,
        $programName,
        $yearLevel,
        'pending',
    ]);
}

function registration_status_message_for_username(string $username): ?string
{
    if ($username === '' || !student_registration_table_ready()) {
        return null;
    }
    $st = db()->prepare(
        "SELECT status, rejection_reason FROM student_registration_requests
         WHERE username = ? ORDER BY id DESC LIMIT 1"
    );
    $st->execute([trim($username)]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    $status = (string) ($row['status'] ?? '');
    if ($status === 'pending') {
        return 'Your registration is pending Program Chair approval. You will receive an email with your sign-in credentials once approved.';
    }
    if ($status === 'rejected') {
        $reason = trim((string) ($row['rejection_reason'] ?? ''));
        return 'Your registration was not approved.'
            . ($reason !== '' ? ' Reason: ' . $reason : ' You may register again with corrected details.');
    }
    return null;
}

/**
 * Create users + classroom_students row (same outcome as program chair manual add).
 */
function create_student_account(
    string $username,
    string $passwordHash,
    string $fullName,
    string $email,
    string $studentNumber,
    int $collegeId,
    string $programName,
    bool $plainPasswordWasHashed = true,
    string $yearLevel = ''
): int {
    $hasUserEmail = db_column_exists('users', 'email');
    $hash = $plainPasswordWasHashed ? $passwordHash : password_hash($passwordHash, PASSWORD_DEFAULT);

    db()->beginTransaction();
    try {
        if ($hasUserEmail) {
            db()->prepare(
                'INSERT INTO users (username, password, full_name, email, role, assigned_program, college_id, is_active)
                 VALUES (?,?,?,?,?,?,?,1)'
            )->execute([
                $username,
                $hash,
                $fullName,
                $email,
                'student',
                $programName,
                $collegeId,
            ]);
        } else {
            db()->prepare(
                'INSERT INTO users (username, password, full_name, role, assigned_program, college_id, is_active)
                 VALUES (?,?,?,?,?,?,1)'
            )->execute([
                $username,
                $hash,
                $fullName,
                'student',
                $programName,
                $collegeId,
            ]);
        }
        $uid = (int) db()->lastInsertId();

        $yearLevel = trim($yearLevel);
        if (db_column_exists('classroom_students', 'year_level')) {
            db()->prepare(
                'INSERT INTO classroom_students (user_id, student_number, full_name, email, year_level) VALUES (?,?,?,?,?)'
            )->execute([
                $uid,
                $studentNumber,
                $fullName,
                $hasUserEmail ? $email : '',
                $yearLevel,
            ]);
        } else {
            db()->prepare(
                'INSERT INTO classroom_students (user_id, student_number, full_name, email) VALUES (?,?,?,?)'
            )->execute([
                $uid,
                $studentNumber,
                $fullName,
                $hasUserEmail ? $email : '',
            ]);
        }

        db()->commit();
        return $uid;
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }
}

/**
 * @return array<string, mixed>
 */
function load_student_registration_request_for_chair(int $requestId, int $collegeId, string $programScope): array
{
    $st = db()->prepare('SELECT * FROM student_registration_requests WHERE id = ?');
    $st->execute([$requestId]);
    $req = $st->fetch(PDO::FETCH_ASSOC);
    if (!$req) {
        throw new RuntimeException('Registration request not found.');
    }
    if ((int) ($req['college_id'] ?? 0) !== $collegeId
        || trim((string) ($req['program_name'] ?? '')) !== trim($programScope)) {
        throw new RuntimeException('This registration belongs to another college or program.');
    }

    return $req;
}

function student_user_exists(string $username): bool
{
    $st = db()->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
    $st->execute([trim($username)]);

    return (int) $st->fetchColumn() > 0;
}

/**
 * @return array{email: string, mail_sent: bool, temp_password: string, already_approved?: bool}
 */
function approve_student_registration_request(int $requestId, int $chairUserId, int $collegeId, string $programScope): array
{
    if (!student_registration_table_ready()) {
        throw new RuntimeException('Registration requests are not available.');
    }

    $req = load_student_registration_request_for_chair($requestId, $collegeId, $programScope);
    $status = (string) ($req['status'] ?? '');
    $username = (string) $req['username'];
    $email = trim((string) ($req['email'] ?? ''));
    $fullName = (string) $req['full_name'];

    if ($status === 'rejected') {
        throw new RuntimeException('This registration was already rejected.');
    }

    if ($status === 'approved') {
        if (student_user_exists($username)) {
            return [
                'email' => $email,
                'mail_sent' => false,
                'temp_password' => '',
                'already_approved' => true,
            ];
        }
    } elseif ($status !== 'pending') {
        throw new RuntimeException('Registration request is not pending approval.');
    }

    if (student_user_exists($username)) {
        throw new RuntimeException('A user with this username already exists. Reject this request or contact admin.');
    }

    $plainPassword = generate_temp_password();

    create_student_account(
        $username,
        $plainPassword,
        $fullName,
        $email,
        (string) ($req['student_number'] ?? ''),
        (int) $req['college_id'],
        (string) $req['program_name'],
        false,
        (string) ($req['year_level'] ?? '')
    );

    if ($status === 'pending') {
        db()->prepare(
            "UPDATE student_registration_requests
             SET status = 'approved', reviewed_by_user_id = ?, reviewed_at = NOW()
             WHERE id = ?"
        )->execute([$chairUserId, $requestId]);
    }

    $mailSent = false;
    if (db_column_exists('users', 'email') && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $mailSent = send_account_credentials_mail($email, $fullName, $username, $plainPassword, 'student_registration');
    }

    return [
        'email' => $email,
        'mail_sent' => $mailSent,
        'temp_password' => $plainPassword,
    ];
}

function reject_student_registration_request(
    int $requestId,
    int $chairUserId,
    int $collegeId,
    string $programScope,
    string $reason = ''
): void {
    if (!student_registration_table_ready()) {
        throw new RuntimeException('Registration requests are not available.');
    }

    $req = load_student_registration_request_for_chair($requestId, $collegeId, $programScope);
    if ((string) ($req['status'] ?? '') === 'rejected') {
        throw new RuntimeException('This registration was already rejected.');
    }
    if ((string) ($req['status'] ?? '') !== 'pending') {
        throw new RuntimeException('This registration is not pending approval.');
    }

    db()->prepare(
        "UPDATE student_registration_requests
         SET status = 'rejected', reviewed_by_user_id = ?, reviewed_at = NOW(), rejection_reason = ?
         WHERE id = ?"
    )->execute([$chairUserId, trim($reason), $requestId]);
}

/**
 * @return array<int, array<string, mixed>>
 */
function pending_registrations_for_program(int $collegeId, string $programScope): array
{
    if (!student_registration_table_ready()) {
        return [];
    }
    $st = db()->prepare(
        "SELECT r.*, c.college_name
         FROM student_registration_requests r
         INNER JOIN colleges c ON c.id = r.college_id
         WHERE r.status = 'pending' AND r.college_id = ? AND r.program_name = ?
         ORDER BY r.created_at ASC"
    );
    $st->execute([$collegeId, $programScope]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function count_pending_registrations_for_program(int $collegeId, string $programScope): int
{
    if (!student_registration_table_ready()) {
        return 0;
    }
    $st = db()->prepare(
        "SELECT COUNT(*) FROM student_registration_requests
         WHERE status = 'pending' AND college_id = ? AND program_name = ?"
    );
    $st->execute([$collegeId, $programScope]);
    return (int) $st->fetchColumn();
}

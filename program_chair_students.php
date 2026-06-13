<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mail_helpers.php';

require_role(['program_chair']);
$collegeId = dean_or_program_chair_college_id_or_fail();
$programScope = program_scope_or_fail();
$collegeName = college_name_by_id($collegeId);

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$hasUserEmail = db_column_exists('users', 'email');
$hasAssignedProgram = db_column_exists('users', 'assigned_program');

if (!$hasAssignedProgram) {
    exit('Run upgrade_roles.php first to enable student accounts for your program.');
}
if (!db_table_exists('classroom_students')) {
    exit('Classroom student profiles are not installed. Run upgrade_roles.php once.');
}

$hasClassroomEnrollments = db_table_exists('classroom_enrollments') && db_table_exists('online_classrooms');

if (isset($_GET['download_students_csv_template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="students_import_template.csv"');
    $out = fopen('php://output', 'w');
    if ($hasUserEmail) {
        fputcsv($out, ['username', 'password', 'full_name', 'student_number', 'email']);
        fputcsv($out, ['jdoe', 'ChangeMe123', 'Jane Doe', '2024-0001', 'jane@example.com']);
    } else {
        fputcsv($out, ['username', 'password', 'full_name', 'student_number']);
        fputcsv($out, ['jdoe', 'ChangeMe123', 'Jane Doe', '2024-0001']);
    }
    fclose($out);
    exit;
}

/**
 * @param array<int, string> $headerCells
 * @return array<string, int> canonical key => column index
 */
function program_chair_students_csv_column_map(array $headerCells, bool $includeEmail): array
{
    $aliases = [
        'username' => ['username', 'user_name', 'login'],
        'password' => ['password', 'pass', 'passwd'],
        'full_name' => ['full_name', 'fullname', 'name', 'full name'],
        'student_number' => ['student_number', 'studentnumber', 'student_id', 'student_no', 'student_#', 'id_number'],
        'email' => ['email', 'e_mail'],
    ];
    $norm = [];
    foreach ($headerCells as $i => $cell) {
        $h = strtolower(trim((string) $cell));
        if ($i === 0 && strncmp($h, "\xEF\xBB\xBF", 3) === 0) {
            $h = substr($h, 3);
        }
        $h = str_replace([' ', '-'], '_', $h);
        $norm[$i] = $h;
    }
    $map = [];
    foreach ($aliases as $key => $names) {
        if ($key === 'email' && !$includeEmail) {
            continue;
        }
        foreach ($norm as $idx => $h) {
            if (in_array($h, $names, true)) {
                $map[$key] = $idx;
                break;
            }
        }
    }
    return $map;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'add') {
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $email = $hasUserEmail ? trim((string) ($_POST['email'] ?? '')) : '';
            $studentNumber = trim((string) ($_POST['student_number'] ?? ''));
            $sendByEmail = $hasUserEmail && !empty($_POST['send_credentials_email']);
            $plainForMail = '';

            if ($username === '' || $fullName === '') {
                throw new RuntimeException('Username and full name are required.');
            }
            if ($sendByEmail) {
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('A valid email address is required to send the temporary password.');
                }
                $plainForMail = generate_temp_password();
                $password = $plainForMail;
            } elseif ($password === '' || strlen($password) < 8) {
                throw new RuntimeException('Password is required and must be at least 8 characters, or enable sending credentials by email.');
            }
            if ($hasUserEmail && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Invalid email address.');
            }

            $exists = db()->prepare('SELECT COUNT(*) FROM users WHERE username=?');
            $exists->execute([$username]);
            if ((int) $exists->fetchColumn() > 0) {
                $s = suggest_available_usernames($username, 3);
                throw new RuntimeException('Username already exists.' . ($s ? ' Try: ' . implode(', ', $s) : ''));
            }

            db()->beginTransaction();
            if ($hasUserEmail) {
                db()->prepare(
                    'INSERT INTO users (username, password, full_name, email, role, assigned_program, college_id, is_active)
                     VALUES (?,?,?,?,?,?,?,1)'
                )->execute([
                    $username,
                    password_hash($password, PASSWORD_DEFAULT),
                    $fullName,
                    $email,
                    'student',
                    $programScope,
                    $collegeId,
                ]);
            } else {
                db()->prepare(
                    'INSERT INTO users (username, password, full_name, role, assigned_program, college_id, is_active)
                     VALUES (?,?,?,?,?,?,1)'
                )->execute([
                    $username,
                    password_hash($password, PASSWORD_DEFAULT),
                    $fullName,
                    'student',
                    $programScope,
                    $collegeId,
                ]);
            }
            $uid = (int) db()->lastInsertId();

            db()->prepare(
                'INSERT INTO classroom_students (user_id, student_number, full_name, email) VALUES (?,?,?,?)'
            )->execute([
                $uid,
                $studentNumber,
                $fullName,
                $hasUserEmail ? $email : '',
            ]);

            db()->commit();
            log_dean_activity('student_create', 'Created student login for program ' . $programScope . ': ' . $username);

            $mailOk = false;
            if ($hasUserEmail && $sendByEmail && $email !== '' && $plainForMail !== '') {
                $mailOk = send_account_credentials_mail($email, $fullName, $username, $plainForMail, 'student');
            }
            if ($hasUserEmail && $sendByEmail && $email !== '' && $plainForMail !== '') {
                $_SESSION['flash'] = $mailOk
                    ? 'Student account created. Temporary password sent to ' . $email . '.'
                    : 'Student account created, but the email could not be sent. Temporary password: ' . $plainForMail;
            } else {
                $_SESSION['flash'] = 'Student account created. Share their username and password, then they can join classes with each instructor’s class code.';
            }
        } elseif ($action === 'edit' && isset($_POST['id'])) {
            $userId = (int) $_POST['id'];
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $email = $hasUserEmail ? trim((string) ($_POST['email'] ?? '')) : '';
            $studentNumber = trim((string) ($_POST['student_number'] ?? ''));
            $resetPassword = trim((string) ($_POST['reset_password'] ?? ''));

            if ($fullName === '') {
                throw new RuntimeException('Full name is required.');
            }
            if ($hasUserEmail && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Invalid email address.');
            }

            $st = db()->prepare(
                'SELECT u.id, u.username FROM users u
                 WHERE u.id = ? AND u.role = ? AND u.college_id = ? AND u.assigned_program = ?'
            );
            $st->execute([$userId, 'student', $collegeId, $programScope]);
            $studentRow = $st->fetch();
            if (!$studentRow) {
                throw new RuntimeException('Student not found in your program.');
            }
            $uname = (string) $studentRow['username'];

            if ($hasUserEmail) {
                db()->prepare('UPDATE users SET full_name = ?, email = ?, is_active = ? WHERE id = ? AND role = ? AND college_id = ? AND assigned_program = ?')
                    ->execute([
                        $fullName,
                        $email,
                        !empty($_POST['is_active']) ? 1 : 0,
                        $userId,
                        'student',
                        $collegeId,
                        $programScope,
                    ]);
            } else {
                db()->prepare('UPDATE users SET full_name = ?, is_active = ? WHERE id = ? AND role = ? AND college_id = ? AND assigned_program = ?')
                    ->execute([
                        $fullName,
                        !empty($_POST['is_active']) ? 1 : 0,
                        $userId,
                        'student',
                        $collegeId,
                        $programScope,
                    ]);
            }

            db()->prepare(
                'UPDATE classroom_students SET full_name = ?, email = ?, student_number = ? WHERE user_id = ?'
            )->execute([
                $fullName,
                $hasUserEmail ? $email : '',
                $studentNumber,
                $userId,
            ]);

            $generateAndEmail = $hasUserEmail && !empty($_POST['generate_temp_password_email']);
            $plainForMail = '';

            if ($generateAndEmail) {
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('A valid email is required to generate and send a temporary password.');
                }
                $plainForMail = generate_temp_password();
                db()->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([
                    password_hash($plainForMail, PASSWORD_DEFAULT),
                    $userId,
                ]);
            } elseif ($resetPassword !== '') {
                if (strlen($resetPassword) < 8) {
                    throw new RuntimeException('New password must be at least 8 characters.');
                }
                db()->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([
                    password_hash($resetPassword, PASSWORD_DEFAULT),
                    $userId,
                ]);
                if (!empty($_POST['email_reset_password']) && $hasUserEmail && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $plainForMail = $resetPassword;
                }
            }

            log_dean_activity('student_update', 'Updated student user #' . $userId);
            if ($plainForMail !== '') {
                $mailOk = send_account_credentials_mail($email, $fullName, $uname, $plainForMail, 'student');
                $_SESSION['flash'] = $mailOk
                    ? 'Student updated. Password instructions sent to ' . $email . '.'
                    : 'Student updated, but email could not be sent. Temporary password: ' . $plainForMail;
            } else {
                $_SESSION['flash'] = 'Student updated.';
            }
        } elseif ($action === 'delete' && isset($_POST['id'])) {
            $userId = (int) $_POST['id'];
            $st = db()->prepare(
                'SELECT u.id FROM users u
                 WHERE u.id = ? AND u.role = ? AND u.college_id = ? AND u.assigned_program = ?'
            );
            $st->execute([$userId, 'student', $collegeId, $programScope]);
            if (!$st->fetch()) {
                throw new RuntimeException('Student not found in your program.');
            }

            db()->prepare('DELETE FROM classroom_students WHERE user_id = ?')->execute([$userId]);
            db()->prepare('DELETE FROM users WHERE id = ? AND role = ?')->execute([$userId, 'student']);
            log_dean_activity('student_delete', 'Deleted student user #' . $userId);
            $_SESSION['flash'] = 'Student account removed.';
        } elseif ($action === 'batch_upload') {
            if (!isset($_FILES['batch_csv']) || !is_uploaded_file($_FILES['batch_csv']['tmp_name'] ?? '')) {
                throw new RuntimeException('Please choose a CSV file to upload.');
            }
            if ((int) ($_FILES['batch_csv']['error'] ?? 0) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('File upload failed.');
            }
            $tmp = (string) $_FILES['batch_csv']['tmp_name'];
            $size = (int) ($_FILES['batch_csv']['size'] ?? 0);
            if ($size > 2 * 1024 * 1024) {
                throw new RuntimeException('CSV file is too large (max 2 MB).');
            }
            $fh = fopen($tmp, 'rb');
            if ($fh === false) {
                throw new RuntimeException('Could not read the uploaded file.');
            }
            $header = fgetcsv($fh);
            if ($header === false || $header === [null] || $header === []) {
                fclose($fh);
                throw new RuntimeException('CSV is empty or invalid.');
            }
            $colMap = program_chair_students_csv_column_map($header, $hasUserEmail);
            foreach (['username', 'password', 'full_name'] as $req) {
                if (!isset($colMap[$req])) {
                    fclose($fh);
                    throw new RuntimeException(
                        'CSV must include a header row with columns: username, password, full_name'
                        . ($hasUserEmail ? ', email (optional)' : '')
                        . ', student_number (optional). Download the template for the exact format.'
                    );
                }
            }
            $maxRows = 1000;
            $created = 0;
            $failures = [];
            $seenLowerUsernames = [];
            $rowNum = 1;
            while (($row = fgetcsv($fh)) !== false) {
                $rowNum++;
                if ($row === [null] || $row === [] || trim(implode('', $row)) === '') {
                    continue;
                }
                if ($created + count($failures) >= $maxRows) {
                    $failures[] = 'Row ' . $rowNum . ': skipped (limit of ' . $maxRows . ' data rows reached).';
                    break;
                }
                $get = static function (string $key) use ($colMap, $row): string {
                    $i = $colMap[$key] ?? null;
                    if ($i === null || !isset($row[$i])) {
                        return '';
                    }
                    return trim((string) $row[$i]);
                };
                $username = $get('username');
                $password = $get('password');
                $fullName = $get('full_name');
                $studentNumber = $get('student_number');
                $email = $hasUserEmail ? $get('email') : '';

                if ($username === '' && $fullName === '' && $password === '') {
                    continue;
                }
                if ($username === '' || $fullName === '') {
                    $failures[] = 'Row ' . $rowNum . ': username and full name are required.';
                    continue;
                }
                if ($password === '' || strlen($password) < 8) {
                    $failures[] = 'Row ' . $rowNum . ': password must be at least 8 characters.';
                    continue;
                }
                if ($hasUserEmail && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $failures[] = 'Row ' . $rowNum . ': invalid email.';
                    continue;
                }
                $lower = strtolower($username);
                if (isset($seenLowerUsernames[$lower])) {
                    $failures[] = 'Row ' . $rowNum . ': duplicate username in this file (' . $username . ').';
                    continue;
                }
                $seenLowerUsernames[$lower] = true;

                $exists = db()->prepare('SELECT COUNT(*) FROM users WHERE username=?');
                $exists->execute([$username]);
                if ((int) $exists->fetchColumn() > 0) {
                    $failures[] = 'Row ' . $rowNum . ': username already exists (' . $username . ').';
                    continue;
                }

                try {
                    db()->beginTransaction();
                    if ($hasUserEmail) {
                        db()->prepare(
                            'INSERT INTO users (username, password, full_name, email, role, assigned_program, college_id, is_active)
                             VALUES (?,?,?,?,?,?,?,1)'
                        )->execute([
                            $username,
                            password_hash($password, PASSWORD_DEFAULT),
                            $fullName,
                            $email,
                            'student',
                            $programScope,
                            $collegeId,
                        ]);
                    } else {
                        db()->prepare(
                            'INSERT INTO users (username, password, full_name, role, assigned_program, college_id, is_active)
                             VALUES (?,?,?,?,?,?,1)'
                        )->execute([
                            $username,
                            password_hash($password, PASSWORD_DEFAULT),
                            $fullName,
                            'student',
                            $programScope,
                            $collegeId,
                        ]);
                    }
                    $uid = (int) db()->lastInsertId();
                    db()->prepare(
                        'INSERT INTO classroom_students (user_id, student_number, full_name, email) VALUES (?,?,?,?)'
                    )->execute([
                        $uid,
                        $studentNumber,
                        $fullName,
                        $hasUserEmail ? $email : '',
                    ]);
                    db()->commit();
                    $created++;
                } catch (Throwable $rowEx) {
                    if (db()->inTransaction()) {
                        db()->rollBack();
                    }
                    $failures[] = 'Row ' . $rowNum . ': ' . $rowEx->getMessage();
                }
            }
            fclose($fh);
            $parts = ['Batch import finished: ' . $created . ' student account(s) created.'];
            if ($failures !== []) {
                $show = array_slice($failures, 0, 15);
                $parts[] = count($failures) . ' row(s) skipped: ' . implode(' ', $show);
                if (count($failures) > 15) {
                    $parts[] = '(and ' . (count($failures) - 15) . ' more)';
                }
            }
            log_dean_activity('student_batch_import', 'Batch CSV import for program ' . $programScope . ': ' . $created . ' created');
            $_SESSION['flash'] = implode(' ', $parts);
        }
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        $_SESSION['flash'] = 'Error: ' . $e->getMessage();
    }
    header('Location: program_chair_students.php');
    exit;
}

$editRow = null;
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $st = db()->prepare(
        'SELECT u.id, u.username, u.full_name, u.email, u.is_active, cs.student_number
         FROM users u
         INNER JOIN classroom_students cs ON cs.user_id = u.id
         WHERE u.id = ? AND u.role = ? AND u.college_id = ? AND u.assigned_program = ?'
    );
    $st->execute([$editId, 'student', $collegeId, $programScope]);
    $editRow = $st->fetch() ?: null;
}

$enrolledFacultySelect = $hasClassroomEnrollments
    ? ', (SELECT GROUP_CONCAT(DISTINCT CONCAT(f.full_name, \' \', f.faculty_id) SEPARATOR \' \')
         FROM classroom_enrollments ce
         INNER JOIN online_classrooms oc ON oc.id = ce.classroom_id
         INNER JOIN faculty f ON f.id = oc.faculty_id
         WHERE ce.student_id = cs.id) AS enrolled_faculty_text'
    : '';
$st = db()->prepare(
    'SELECT u.id, u.username, u.full_name, u.email, u.is_active, cs.student_number' . $enrolledFacultySelect . '
     FROM users u
     INNER JOIN classroom_students cs ON cs.user_id = u.id
     WHERE u.role = ? AND u.college_id = ? AND u.assigned_program = ?
     ORDER BY u.full_name ASC'
);
$st->execute(['student', $collegeId, $programScope]);
$list = $st->fetchAll();

$pcsStudentsPayload = [];
foreach ($list as $r) {
    $pcsStudentsPayload[] = [
        'id' => (int) $r['id'],
        'full_name' => (string) $r['full_name'],
        'username' => (string) $r['username'],
        'student_number' => (string) ($r['student_number'] ?? ''),
        'email' => $hasUserEmail ? (string) ($r['email'] ?? '') : '',
        'is_active' => (int) ($r['is_active'] ?? 1),
        'enrolled_faculty_text' => $hasClassroomEnrollments ? (string) ($r['enrolled_faculty_text'] ?? '') : '',
    ];
}

$pageTitle = 'Student management';
$mainContainerClass = 'container-fluid py-4 py-md-5 app-main';
require_once __DIR__ . '/includes/header.php';
?>
<link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
<style>
    .pcs-app {
        --pcs-bg: #f1f5f9;
        --pcs-text: #0f172a;
        --pcs-muted: #475569;
        --pcs-border: #e2e8f0;
        max-width: 1400px;
        margin: 0 auto;
        font-family: 'Inter', system-ui, sans-serif;
        color: var(--pcs-text);
        line-height: 1.5;
    }
    .pcs-app * { box-sizing: border-box; }
    .pcs-top-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        margin-bottom: 28px;
        gap: 16px;
    }
    .pcs-logo-area h1 {
        font-size: 1.8rem;
        font-weight: 700;
        letter-spacing: -0.3px;
        background: linear-gradient(135deg, #1e293b, #2d3a4e);
        -webkit-background-clip: text;
        background-clip: text;
        color: transparent;
        margin: 0;
    }
    .pcs-logo-area p {
        font-size: 0.85rem;
        color: var(--pcs-muted);
        margin: 4px 0 0;
    }
    .pcs-college-badge {
        background: #fff;
        padding: 8px 18px;
        border-radius: 40px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        font-size: 0.9rem;
        font-weight: 500;
        border: 1px solid var(--pcs-border);
    }
    .pcs-college-badge i { margin-right: 6px; color: #3b82f6; }
    .pcs-nav-tabs {
        display: flex;
        gap: 8px;
        border-bottom: 2px solid var(--pcs-border);
        margin-bottom: 28px;
        flex-wrap: wrap;
    }
    .pcs-nav-tab {
        padding: 10px 20px;
        font-weight: 600;
        font-size: 0.95rem;
        border-radius: 30px 30px 0 0;
        color: #64748b;
        text-decoration: none;
        transition: color 0.2s, background 0.2s;
    }
    .pcs-nav-tab:hover { color: #1e40af; background: #ffffff80; }
    .pcs-nav-tab.pcs-active {
        color: #1e40af;
        border-bottom: 3px solid #1e40af;
        background: #ffffff80;
        cursor: default;
        pointer-events: none;
    }
    .pcs-nav-tab i { margin-right: 8px; }
    .pcs-card {
        background: #fff;
        border-radius: 24px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.03), 0 1px 2px rgba(0,0,0,0.05);
        margin-bottom: 32px;
        border: 1px solid #eef2f6;
        overflow: hidden;
    }
    .pcs-card-header {
        padding: 20px 28px;
        border-bottom: 1px solid #edf2f7;
    }
    .pcs-card-header h2 {
        font-size: 1.4rem;
        font-weight: 600;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .pcs-card-header h2 i { color: #3b82f6; font-size: 1.3rem; }
    .pcs-card-header .pcs-lead {
        font-size: 0.8rem;
        color: var(--pcs-muted);
        margin: 6px 0 0;
    }
    .pcs-card-body { padding: 24px 28px; }
    .pcs-form-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 20px 28px;
    }
    .pcs-field { display: flex; flex-direction: column; gap: 6px; }
    .pcs-field.pcs-full { grid-column: span 2; }
    .pcs-field label {
        font-size: 0.85rem;
        font-weight: 600;
        color: #334155;
        letter-spacing: 0.3px;
    }
    .pcs-field label .pcs-opt {
        font-weight: normal;
        color: #94a3b8;
        font-size: 0.75rem;
        margin-left: 6px;
    }
    .pcs-field input, .pcs-field select {
        padding: 12px 14px;
        border: 1px solid #cbd5e1;
        border-radius: 14px;
        font-family: 'Inter', system-ui, sans-serif;
        font-size: 0.9rem;
        transition: border-color 0.2s, box-shadow 0.2s;
        background: #fff;
        width: 100%;
    }
    .pcs-field input:focus, .pcs-field select:focus {
        outline: none;
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59,130,246,0.2);
    }
    .pcs-password-hint { font-size: 0.7rem; color: #5b6e8c; margin-top: 4px; }
    .pcs-btn-group { display: flex; gap: 12px; align-items: center; margin-top: 12px; flex-wrap: wrap; }
    .pcs-btn-primary {
        background: #1e40af;
        border: none;
        padding: 10px 20px;
        border-radius: 40px;
        font-weight: 600;
        color: #fff;
        font-size: 0.85rem;
        cursor: pointer;
        transition: 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
    }
    .pcs-btn-primary:hover { background: #1e3a8a; color: #fff; transform: translateY(-1px); box-shadow: 0 6px 12px -8px #1e3a8a50; }
    .pcs-btn-secondary {
        background: #f1f5f9;
        border: 1px solid #cbd5e1;
        padding: 9px 18px;
        border-radius: 40px;
        font-weight: 500;
        color: #1f2937;
        cursor: pointer;
        transition: 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        text-decoration: none;
    }
    .pcs-btn-secondary:hover { background: #e6edf4; border-color: #94a3b8; color: #1f2937; }
    .pcs-btn-outline {
        background: transparent;
        border: 1px solid #cbd5e1;
        padding: 9px 18px;
        border-radius: 40px;
        font-weight: 500;
        cursor: pointer;
        transition: 0.2s;
        color: #1f2937;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        text-decoration: none;
    }
    .pcs-btn-outline i { margin-right: 0; }
    .pcs-btn-outline:hover { background: #f8fafc; color: #1f2937; }
    .pcs-batch-zone { background: #fafcff; border-radius: 20px; padding: 0; }
    .pcs-batch-inner { background: #f9fbfe; border-radius: 18px; padding: 12px 16px; }
    .pcs-batch-inner > p { font-size: 0.85rem; margin-bottom: 12px; }
    .pcs-file-row { display: flex; gap: 16px; flex-wrap: wrap; align-items: center; margin: 0; }
    .pcs-fake-file {
        background: #f1f5f9;
        padding: 10px 16px;
        border-radius: 40px;
        font-size: 0.85rem;
        color: #334155;
        border: 1px dashed #94a3b8;
        flex: 1;
        min-width: 140px;
    }
    .pcs-table-wrap { overflow-x: auto; border-radius: 20px; }
    .pcs-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
    .pcs-table th {
        text-align: left;
        padding: 16px 12px;
        background: #f8fafc;
        font-weight: 600;
        color: #1e293b;
        border-bottom: 1px solid var(--pcs-border);
    }
    .pcs-table td {
        padding: 14px 12px;
        border-bottom: 1px solid #ecf3fa;
        vertical-align: middle;
    }
    .pcs-status {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 10px;
        border-radius: 30px;
        font-size: 0.75rem;
        font-weight: 600;
    }
    .pcs-status-active { background: #e6f7ec; color: #15803d; }
    .pcs-status-off { background: #f1f5f9; color: #64748b; }
    .pcs-actions { display: flex; gap: 12px; }
    .pcs-actions a, .pcs-actions button {
        background: none;
        border: none;
        cursor: pointer;
        font-size: 1.1rem;
        padding: 4px;
        border-radius: 30px;
        width: 28px;
        height: 28px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #5b6e8c;
        text-decoration: none;
        transition: 0.1s;
    }
    .pcs-actions a:hover, .pcs-actions button:hover { background: #f1f5f9; transform: scale(1.05); }
    .pcs-edit:hover { color: #2563eb; }
    .pcs-del:hover { color: #dc2626; }
    .pcs-controls {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        flex-wrap: wrap;
        gap: 12px;
    }
    .pcs-search {
        display: flex;
        align-items: center;
        background: #fff;
        border: 1px solid var(--pcs-border);
        border-radius: 40px;
        padding: 6px 16px;
        gap: 8px;
    }
    .pcs-search i { color: #94a3b8; }
    .pcs-search input {
        border: none;
        padding: 8px 0;
        outline: none;
        background: transparent;
        width: min(220px, 50vw);
        font-family: inherit;
        font-size: 0.9rem;
    }
    .pcs-total-pill {
        font-size: 0.75rem;
        background: #eef2ff;
        padding: 6px 12px;
        border-radius: 40px;
    }
    .pcs-pagination { display: flex; gap: 8px; align-items: center; margin-top: 20px; justify-content: flex-end; flex-wrap: wrap; }
    .pcs-page-btn {
        background: #fff;
        border: 1px solid var(--pcs-border);
        padding: 6px 12px;
        border-radius: 30px;
        font-size: 0.8rem;
        cursor: pointer;
        transition: 0.1s;
    }
    .pcs-page-btn.pcs-page-on { background: #1e40af; color: #fff; border-color: #1e40af; }
    .pcs-modal {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.5);
        align-items: center;
        justify-content: center;
        z-index: 1000;
    }
    .pcs-modal.pcs-open { display: flex; }
    .pcs-modal-card {
        background: #fff;
        max-width: 400px;
        width: 90%;
        border-radius: 32px;
        padding: 24px;
        text-align: center;
    }
    .pcs-modal-btns { display: flex; gap: 12px; justify-content: center; margin-top: 24px; flex-wrap: wrap; }
    .pcs-modal-btns .pcs-btn-primary.pcs-danger { background: #b91c1c; }
    .pcs-modal-btns .pcs-btn-primary.pcs-danger:hover { background: #991b1b; }
    .pcs-toast {
        position: fixed;
        bottom: 24px;
        right: 24px;
        color: #fff;
        padding: 12px 20px;
        border-radius: 40px;
        font-size: 0.85rem;
        z-index: 1100;
        box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        max-width: min(420px, calc(100vw - 48px));
    }
    .pcs-toast.pcs-toast-err { background: #b91c1c; }
    .pcs-toast:not(.pcs-toast-err) { background: #1f2937; }
    .pcs-empty { text-align: center; color: #64748b; padding: 28px 16px; font-size: 0.9rem; }
    @media (max-width: 760px) {
        .pcs-form-grid { grid-template-columns: 1fr; }
        .pcs-field.pcs-full { grid-column: span 1; }
        .pcs-card-body { padding: 20px; }
    }
</style>

<div class="pcs-app no-print">
    <div class="pcs-top-header">
        <div class="pcs-logo-area">
            <h1><i class="fa-solid fa-chalkboard-user" aria-hidden="true"></i> WPU SABLAe Portal</h1>
            <p>Course enrollment &amp; student management</p>
        </div>
        <div class="pcs-college-badge">
            <i class="fa-solid fa-building-columns" aria-hidden="true"></i>
            <?= htmlspecialchars($collegeName) ?> &nbsp;|&nbsp;
            <strong><?= htmlspecialchars($programScope) ?></strong>
        </div>
    </div>

    <nav class="pcs-nav-tabs" aria-label="Program chair sections">
        <span class="pcs-nav-tab pcs-active"><i class="fa-solid fa-users" aria-hidden="true"></i> Students</span>
        <a class="pcs-nav-tab" href="schedule.php"><i class="fa-solid fa-calendar-alt" aria-hidden="true"></i> Schedules</a>
        <a class="pcs-nav-tab" href="courses.php"><i class="fa-solid fa-book" aria-hidden="true"></i> Courses</a>
    </nav>

    <div class="pcs-card">
        <div class="pcs-card-header">
            <h2><i class="fa-solid fa-user-plus" aria-hidden="true"></i> <?= $editRow ? 'Edit student' : 'Add student account' ?></h2>
            <?php if (!$editRow): ?>
                <p class="pcs-lead">After signing in, students use &quot;My Classes&quot; and enter each instructor’s class join code.</p>
            <?php endif; ?>
        </div>
        <div class="pcs-card-body">
            <form method="post" id="pcs-add-form" autocomplete="off">
                <input type="hidden" name="action" value="<?= $editRow ? 'edit' : 'add' ?>">
                <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>"><?php endif; ?>
                <div class="pcs-form-grid">
                    <div class="pcs-field">
                        <label for="pcs-username">Username <span class="pcs-opt">*</span></label>
                        <input id="pcs-username" type="text" name="username" maxlength="50" <?= $editRow ? 'readonly' : 'required' ?> value="<?= htmlspecialchars((string) ($editRow['username'] ?? '')) ?>" autocomplete="username">
                    </div>
                    <div class="pcs-field">
                        <label for="pcs-password"><?= $editRow ? 'New password' : 'Password' ?> <span class="pcs-opt"><?= $editRow ? '(optional)' : '*' ?></span></label>
                        <input id="pcs-password" type="password" name="<?= $editRow ? 'reset_password' : 'password' ?>" <?= $editRow ? '' : 'required minlength="8"' ?> autocomplete="new-password" minlength="<?= $editRow ? '0' : '8' ?>">
                        <?php if (!$editRow): ?><div class="pcs-password-hint">At least 8 characters.</div><?php endif; ?>
                        <?php if ($editRow && $hasUserEmail): ?>
                            <div class="pcs-field pcs-full" style="margin-top:10px;">
                                <label class="pcs-field" style="flex-direction:row;align-items:center;gap:8px;font-weight:500;">
                                    <input type="checkbox" name="email_reset_password" id="pcs-email-reset-pw" value="1" class="form-check-input" style="width:auto;">
                                    <span>Email this new password</span>
                                </label>
                                <label class="pcs-field" style="flex-direction:row;align-items:center;gap:8px;font-weight:500;margin-top:6px;">
                                    <input type="checkbox" name="generate_temp_password_email" id="pcs-gen-email-pw" value="1" class="form-check-input" style="width:auto;">
                                    <span>Generate temporary password and email it</span>
                                </label>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="pcs-field">
                        <label for="pcs-fullname">Full name <span class="pcs-opt">*</span></label>
                        <input id="pcs-fullname" type="text" name="full_name" required maxlength="100" value="<?= htmlspecialchars((string) ($editRow['full_name'] ?? '')) ?>" autocomplete="name">
                    </div>
                    <div class="pcs-field">
                        <label for="pcs-stunum">Student number <span class="pcs-opt">(optional)</span></label>
                        <input id="pcs-stunum" type="text" name="student_number" maxlength="30" value="<?= htmlspecialchars((string) ($editRow['student_number'] ?? '')) ?>">
                    </div>
                    <?php if ($hasUserEmail): ?>
                        <div class="pcs-field pcs-full">
                            <label for="pcs-email">Email <span class="pcs-opt">(optional)</span></label>
                            <input id="pcs-email" type="email" name="email" maxlength="190" value="<?= htmlspecialchars((string) ($editRow['email'] ?? '')) ?>" autocomplete="email">
                            <?php if (!$editRow): ?>
                                <div class="pcs-field" style="margin-top:10px;">
                                    <label class="pcs-field" style="flex-direction:row;align-items:center;gap:8px;font-weight:500;">
                                        <input type="checkbox" name="send_credentials_email" id="pcs_send_credentials_email" value="1" class="form-check-input" style="width:auto;" checked>
                                        <span>Generate temporary password and email it</span>
                                    </label>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($editRow): ?>
                        <div class="pcs-field">
                            <label for="pcs-status">Account status</label>
                            <select id="pcs-status" name="is_active">
                                <option value="1" <?= (int) ($editRow['is_active'] ?? 1) === 1 ? 'selected' : '' ?>>Active</option>
                                <option value="0" <?= (int) ($editRow['is_active'] ?? 1) === 0 ? 'selected' : '' ?>>Disabled</option>
                            </select>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="pcs-btn-group">
                    <button type="submit" class="pcs-btn-primary"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Save</button>
                    <?php if ($editRow): ?><a href="program_chair_students.php" class="pcs-btn-secondary">Cancel</a><?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <?php if (!$editRow && $hasUserEmail): ?>
    <script>
    (function () {
        var cb = document.getElementById('pcs_send_credentials_email');
        var pw = document.getElementById('pcs-password');
        var em = document.getElementById('pcs-email');
        if (!cb || !pw) return;
        function sync() {
            var on = cb.checked;
            pw.required = !on;
            pw.disabled = on;
            if (on) pw.value = '';
            if (em) em.required = on;
        }
        cb.addEventListener('change', sync);
        sync();
    })();
    </script>
    <?php endif; ?>

    <?php if ($editRow && $hasUserEmail): ?>
    <script>
    (function () {
        var gen = document.getElementById('pcs-gen-email-pw');
        var pw = document.getElementById('pcs-password');
        if (!gen || !pw) return;
        function sync() {
            if (gen.checked) {
                pw.value = '';
                pw.disabled = true;
            } else {
                pw.disabled = false;
            }
        }
        gen.addEventListener('change', sync);
        sync();
    })();
    </script>
    <?php endif; ?>

    <?php if (!$editRow): ?>
    <div class="pcs-card">
        <div class="pcs-card-header">
            <h2><i class="fa-solid fa-upload" aria-hidden="true"></i> Batch upload (CSV)</h2>
        </div>
        <div class="pcs-card-body pcs-batch-zone">
            <div class="pcs-batch-inner">
                <p><i class="fa-solid fa-circle-info" aria-hidden="true"></i> Upload a spreadsheet saved as CSV. First row must match:
                    <strong>username, password, full_name</strong><?= $hasUserEmail ? ', <strong>email</strong> (optional)' : '' ?>,
                    <strong>student_number</strong> (optional). Up to 1000 rows, max 2&nbsp;MB.</p>
                <div class="pcs-file-row">
                    <a href="program_chair_students.php?download_students_csv_template=1" class="pcs-btn-outline"><i class="fa-solid fa-download" aria-hidden="true"></i> Download CSV template</a>
                    <form method="post" enctype="multipart/form-data" id="pcs-batch-form" class="pcs-file-row" style="flex:1;min-width:200px;">
                        <input type="hidden" name="action" value="batch_upload">
                        <label for="pcs-csv" class="pcs-btn-secondary" style="cursor:pointer;margin:0;"><i class="fa-solid fa-folder-open" aria-hidden="true"></i> Choose file</label>
                        <input id="pcs-csv" type="file" name="batch_csv" accept=".csv,text/csv" required class="visually-hidden">
                        <span id="pcs-file-label" class="pcs-fake-file">No file chosen</span>
                        <button type="submit" class="pcs-btn-primary"><i class="fa-solid fa-users" aria-hidden="true"></i> Import students</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="pcs-card" style="margin-bottom:0;">
        <div class="pcs-card-header">
            <h2><i class="fa-solid fa-list-ul" aria-hidden="true"></i> Enrolled students</h2>
        </div>
        <div class="pcs-card-body">
            <div class="pcs-controls">
                <div class="pcs-search">
                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                    <label class="visually-hidden" for="pcs-search-input">Search students</label>
                    <input type="search" id="pcs-search-input" placeholder="Search name, username, email, instructor…" autocomplete="off">
                </div>
                <div>
                    <span class="pcs-total-pill">Total: <span id="pcs-total">0</span> students</span>
                </div>
            </div>
            <div class="pcs-table-wrap">
                <table class="pcs-table" id="pcs-student-table">
                    <thead>
                        <tr>
                            <th scope="col">Name</th>
                            <th scope="col">Username</th>
                            <th scope="col">Student #</th>
                            <?php if ($hasUserEmail): ?><th scope="col">Email</th><?php endif; ?>
                            <th scope="col">Status</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="pcs-table-body"></tbody>
                </table>
            </div>
            <div class="pcs-pagination" id="pcs-pagination" aria-label="Pagination"></div>
        </div>
    </div>
</div>

<form id="pcs-delete-form" method="post" class="visually-hidden" aria-hidden="true">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="pcs-delete-id" value="">
</form>

<div id="pcs-delete-modal" class="pcs-modal no-print" role="dialog" aria-modal="true" aria-labelledby="pcs-del-title" aria-hidden="true">
    <div class="pcs-modal-card">
        <i class="fa-solid fa-triangle-exclamation" style="font-size:2rem;color:#e11d48;margin-bottom:10px;" aria-hidden="true"></i>
        <h3 id="pcs-del-title" style="margin:0 0 8px;font-size:1.1rem;">Confirm delete</h3>
        <p id="pcs-delete-text" style="margin:0;color:#475569;font-size:0.9rem;">Remove this student account and their class enrollments?</p>
        <div class="pcs-modal-btns">
            <button type="button" class="pcs-btn-secondary" id="pcs-del-cancel">Cancel</button>
            <button type="button" class="pcs-btn-primary pcs-danger" id="pcs-del-confirm">Delete</button>
        </div>
    </div>
</div>

<script>
(function() {
    var students = <?= json_encode($pcsStudentsPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) ?>;
    var hasEmail = <?= $hasUserEmail ? 'true' : 'false' ?>;
    var rowsPerPage = 5;
    var currentPage = 1;
    var searchQuery = '';
    var pendingDeleteId = null;

    function escapeHtml(s) {
        if (!s) return '';
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function filteredList() {
        var q = searchQuery;
        return students.filter(function(s) {
            if (!q) return true;
            var hay = (s.full_name + ' ' + s.username + ' ' + (s.email || '') + ' ' + (s.student_number || '') + ' ' + (s.enrolled_faculty_text || '')).toLowerCase();
            return hay.indexOf(q) !== -1;
        });
    }

    function renderTable() {
        var filtered = filteredList();
        var total = filtered.length;
        var totalEl = document.getElementById('pcs-total');
        if (totalEl) totalEl.textContent = String(total);

        var totalPages = Math.max(1, Math.ceil(total / rowsPerPage));
        if (currentPage > totalPages) currentPage = totalPages;
        var start = (currentPage - 1) * rowsPerPage;
        var pageRows = filtered.slice(start, start + rowsPerPage);

        var tbody = document.getElementById('pcs-table-body');
        tbody.innerHTML = '';

        if (pageRows.length === 0) {
            var tr = document.createElement('tr');
            var td = document.createElement('td');
            td.colSpan = hasEmail ? 6 : 5;
            td.className = 'pcs-empty';
            td.textContent = total === 0 && !searchQuery
                ? 'No student accounts yet. Add a student above or import a CSV.'
                : 'No students match your search.';
            tr.appendChild(td);
            tbody.appendChild(tr);
        } else {
            pageRows.forEach(function(s) {
                var tr = document.createElement('tr');
                var active = s.is_active === 1;
                var statusHtml = active
                    ? '<span class="pcs-status pcs-status-active"><i class="fa-solid fa-circle" style="font-size:0.5rem;" aria-hidden="true"></i> Active</span>'
                    : '<span class="pcs-status pcs-status-off"><i class="fa-solid fa-circle" style="font-size:0.5rem;" aria-hidden="true"></i> Disabled</span>';
                var emailCell = hasEmail ? '<td>' + (s.email ? escapeHtml(s.email) : '—') + '</td>' : '';
                tr.innerHTML =
                    '<td>' + escapeHtml(s.full_name) + '</td>' +
                    '<td>' + escapeHtml(s.username) + '</td>' +
                    '<td>' + (s.student_number ? escapeHtml(s.student_number) : '—') + '</td>' +
                    emailCell +
                    '<td>' + statusHtml + '</td>' +
                    '<td class="pcs-actions">' +
                    '<a class="pcs-edit" href="program_chair_students.php?edit=' + s.id + '" title="Edit"><i class="fa-solid fa-pen" aria-hidden="true"></i><span class="visually-hidden">Edit</span></a>' +
                    '<button type="button" class="pcs-del" data-id="' + s.id + '" title="Delete"><i class="fa-solid fa-trash-can" aria-hidden="true"></i><span class="visually-hidden">Delete</span></button>' +
                    '</td>';
                tbody.appendChild(tr);
            });
        }

        tbody.querySelectorAll('.pcs-del').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var id = parseInt(btn.getAttribute('data-id'), 10);
                pendingDeleteId = id;
                var st = students.find(function(x) { return x.id === id; });
                var msg = document.getElementById('pcs-delete-text');
                if (msg) msg.textContent = st
                    ? 'Delete "' + st.full_name + '"? This removes the account and class enrollments.'
                    : 'Remove this student account and their class enrollments?';
                var m = document.getElementById('pcs-delete-modal');
                if (m) { m.classList.add('pcs-open'); m.setAttribute('aria-hidden', 'false'); }
            });
        });

        var pag = document.getElementById('pcs-pagination');
        pag.innerHTML = '';
        for (var i = 1; i <= totalPages; i++) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'pcs-page-btn' + (i === currentPage ? ' pcs-page-on' : '');
            b.textContent = String(i);
            if (i === currentPage) b.setAttribute('aria-current', 'page');
            else b.removeAttribute('aria-current');
            (function(pi) {
                b.addEventListener('click', function() { currentPage = pi; renderTable(); });
            })(i);
            pag.appendChild(b);
        }
    }

    var searchInput = document.getElementById('pcs-search-input');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            searchQuery = (searchInput.value || '').toLowerCase().trim();
            currentPage = 1;
            renderTable();
        });
    }

    document.getElementById('pcs-del-cancel').addEventListener('click', function() {
        pendingDeleteId = null;
        var m = document.getElementById('pcs-delete-modal');
        m.classList.remove('pcs-open');
        m.setAttribute('aria-hidden', 'true');
    });
    document.getElementById('pcs-del-confirm').addEventListener('click', function() {
        if (pendingDeleteId == null) return;
        document.getElementById('pcs-delete-id').value = String(pendingDeleteId);
        document.getElementById('pcs-delete-form').submit();
    });
    document.getElementById('pcs-delete-modal').addEventListener('click', function(e) {
        if (e.target === this) {
            pendingDeleteId = null;
            this.classList.remove('pcs-open');
            this.setAttribute('aria-hidden', 'true');
        }
    });

    var csvInput = document.getElementById('pcs-csv');
    var fileLabel = document.getElementById('pcs-file-label');
    if (csvInput && fileLabel) {
        csvInput.addEventListener('change', function() {
            var f = csvInput.files && csvInput.files[0];
            fileLabel.textContent = f ? f.name : 'No file chosen';
        });
    }

    function pcsToast(msg, isError) {
        var old = document.querySelector('.pcs-toast');
        if (old) old.remove();
        var t = document.createElement('div');
        t.className = 'pcs-toast' + (isError ? ' pcs-toast-err' : '');
        t.setAttribute('role', 'status');
        t.innerHTML = '<i class="fa-solid ' + (isError ? 'fa-circle-exclamation' : 'fa-circle-check') + '" aria-hidden="true"></i> ' + escapeHtml(msg);
        document.body.appendChild(t);
        setTimeout(function() { t.remove(); }, 3200);
    }

    <?php if ($flash !== ''): ?>
    document.addEventListener('DOMContentLoaded', function() {
        pcsToast(<?= json_encode($flash, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>, <?= str_starts_with((string) $flash, 'Error:') ? 'true' : 'false' ?>);
    });
    <?php endif; ?>

    renderTable();
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/admin_activity_log.php';
require_once __DIR__ . '/includes/account_registration_helpers.php';
require_once __DIR__ . '/includes/mail_helpers.php';
require_once __DIR__ . '/includes/student_registration_helpers.php';

require_role(['super_admin']);

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$hasUserEmail = db_column_exists('users', 'email');
$hasMustChange = db_column_exists('users', 'must_change_password');
$hasLastLogin = db_column_exists('users', 'last_login_at');
$hasCollegeId = db_column_exists('users', 'college_id');
$hasAssignedProgram = db_column_exists('users', 'assigned_program');
$hasAdminLogTitle = db_column_exists('users', 'admin_log_title');
$hasProgramsTable = db_table_exists('programs');
$hasCollegesTable = db_table_exists('colleges');
$hasFacultyTable = db_table_exists('faculty');
$hasEmploymentStatus = $hasFacultyTable && db_column_exists('faculty', 'employment_status');
$hasChairProgramsTable = ensure_program_chair_programs_table();

/** @var array<string, string> */
$roleLabels = [
    'super_admin' => 'Super Administrator',
    'admin' => 'Administrator',
    'dean' => 'Dean',
    'program_chair' => 'Program Chair',
    'faculty' => 'Faculty',
    'gened' => 'General Education',
    'student' => 'Student',
];

/** Roles Super Admin may create from this page. */
$creatableRoles = array_keys($roleLabels);

function super_admin_active_count(): int
{
    return (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'super_admin' AND is_active = 1")->fetchColumn();
}

function sa_role_label(string $role, array $roleLabels): string
{
    return $roleLabels[$role] ?? ucfirst(str_replace('_', ' ', $role));
}

/**
 * Delete one user account and related role rows. Returns true when deleted.
 *
 * @param array<string, string> $roleLabels
 * @throws RuntimeException
 */
function sa_delete_account(int $id, int $currentUserId, array $roleLabels): bool
{
    if ($id < 1) {
        throw new RuntimeException('Invalid account.');
    }
    if ($id === $currentUserId) {
        throw new RuntimeException('You cannot delete your own account.');
    }

    $selCols = 'id, username, full_name, role, is_active';
    if (db_column_exists('users', 'email')) {
        $selCols .= ', email';
    }
    if (db_column_exists('users', 'college_id')) {
        $selCols .= ', college_id';
    }
    if (db_column_exists('users', 'assigned_program')) {
        $selCols .= ', assigned_program';
    }
    $st = db()->prepare("SELECT {$selCols} FROM users WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $before = $st->fetch(PDO::FETCH_ASSOC);
    if (!$before) {
        return false;
    }

    $role = (string) ($before['role'] ?? '');
    if ($role === 'super_admin' && (int) ($before['is_active'] ?? 0) === 1 && super_admin_active_count() <= 1) {
        throw new RuntimeException('At least one active Super Administrator account is required.');
    }

    db()->beginTransaction();
    try {
        if ($role === 'dean' && db_table_exists('colleges') && db_column_exists('colleges', 'dean_user_id')) {
            db()->prepare('UPDATE colleges SET dean_user_id = NULL WHERE dean_user_id = ?')->execute([$id]);
        }
        if ($role === 'program_chair' && function_exists('program_chair_programs_table_ready') && program_chair_programs_table_ready()) {
            db()->prepare('DELETE FROM program_chair_programs WHERE user_id = ?')->execute([$id]);
        }
        if ($role === 'faculty' && db_table_exists('faculty')) {
            db()->prepare('DELETE FROM faculty WHERE user_id = ?')->execute([$id]);
        }
        if ($role === 'student' && db_table_exists('classroom_students')) {
            db()->prepare('DELETE FROM classroom_students WHERE user_id = ?')->execute([$id]);
        }

        $del = db()->prepare('DELETE FROM users WHERE id = ?');
        $del->execute([$id]);
        if ($del->rowCount() < 1) {
            throw new RuntimeException('Account could not be deleted.');
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }

    $roleLabel = sa_role_label($role, $roleLabels);
    log_admin_activity(
        'delete',
        'Account monitor',
        $roleLabel . ' user #' . $id . ' (' . (string) $before['username'] . ')',
        (array) $before,
        null
    );

    return true;
}

$tab = (string) ($_GET['tab'] ?? 'monitor');
if (!in_array($tab, ['monitor', 'create', 'super_admins'], true)) {
    $tab = 'monitor';
}
$preselectRole = trim((string) ($_GET['role'] ?? ''));
if ($preselectRole !== '' && !isset($roleLabels[$preselectRole])) {
    $preselectRole = '';
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;
if ($editId > 0) {
    $tab = 'super_admins';
    $selCols = 'id, username, full_name, is_active';
    if ($hasUserEmail) {
        $selCols .= ', email';
    }
    $st = db()->prepare("SELECT {$selCols} FROM users WHERE id = ? AND role = 'super_admin' LIMIT 1");
    $st->execute([$editId]);
    $editRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$editRow) {
        $editId = 0;
    }
}

$resetId = isset($_GET['reset']) ? (int) $_GET['reset'] : 0;
$resetRow = null;
if ($resetId > 0) {
    $tab = 'monitor';
    $selCols = 'id, username, full_name, role, is_active';
    if ($hasUserEmail) {
        $selCols .= ', email';
    }
    $st = db()->prepare("SELECT {$selCols} FROM users WHERE id = ? LIMIT 1");
    $st->execute([$resetId]);
    $resetRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$resetRow) {
        $resetId = 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'add') {
            $role = trim((string) ($_POST['role'] ?? ''));
            $username = trim((string) ($_POST['username'] ?? ''));
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $email = $hasUserEmail ? trim((string) ($_POST['email'] ?? '')) : '';
            $hasValidEmail = $hasUserEmail && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL);
            $password = (string) ($_POST['password'] ?? '');
            $isActive = !empty($_POST['is_active']) ? 1 : 0;
            $collegeIdRaw = trim((string) ($_POST['college_id'] ?? ''));
            $collegeId = $collegeIdRaw !== '' ? (int) $collegeIdRaw : 0;
            $assignedProgram = trim((string) ($_POST['assigned_program'] ?? ''));
            $adminLogTitle = $hasAdminLogTitle ? trim((string) ($_POST['admin_log_title'] ?? '')) : '';
            $facultyCode = trim((string) ($_POST['faculty_id'] ?? ''));
            $studentNumber = trim((string) ($_POST['student_number'] ?? ''));
            $yearLevel = trim((string) ($_POST['year_level'] ?? ''));

            if (!in_array($role, $creatableRoles, true)) {
                throw new RuntimeException('Invalid role.');
            }
            if ($username === '' || $fullName === '') {
                throw new RuntimeException('Username and full name are required.');
            }
            if ($hasUserEmail && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Invalid email address.');
            }

            $needsCollege = in_array($role, ['dean', 'program_chair', 'faculty', 'student'], true);
            if ($needsCollege) {
                if (!$hasCollegeId || !$hasCollegesTable) {
                    throw new RuntimeException('College assignment requires an upgraded schema. Run upgrade_roles.php first.');
                }
                if ($collegeId < 1) {
                    throw new RuntimeException('College is required for this role.');
                }
                $chkCollege = db()->prepare('SELECT COUNT(*) FROM colleges WHERE id = ?');
                $chkCollege->execute([$collegeId]);
                if ((int) $chkCollege->fetchColumn() < 1) {
                    throw new RuntimeException('Selected college was not found.');
                }
            }

            $needsProgram = in_array($role, ['program_chair', 'faculty', 'student'], true);
            if ($needsProgram) {
                if (!$hasAssignedProgram && $role !== 'faculty') {
                    throw new RuntimeException('Program assignment requires an upgraded schema. Run upgrade_roles.php first.');
                }
                if ($assignedProgram === '') {
                    throw new RuntimeException('Program is required for this role.');
                }
                if ($hasProgramsTable) {
                    $chkProg = db()->prepare(
                        "SELECT COUNT(*) FROM programs WHERE college_id = ? AND program_name = ? AND status = 'active'"
                    );
                    $chkProg->execute([$collegeId, $assignedProgram]);
                    if ((int) $chkProg->fetchColumn() < 1) {
                        throw new RuntimeException('Selected program was not found for that college.');
                    }
                }
            }

            if ($role === 'faculty') {
                if (!$hasFacultyTable) {
                    throw new RuntimeException('Faculty table is missing. Run upgrade_roles.php first.');
                }
                if ($facultyCode === '') {
                    throw new RuntimeException('Faculty ID is required.');
                }
                $chkFac = db()->prepare('SELECT COUNT(*) FROM faculty WHERE faculty_id = ?');
                $chkFac->execute([$facultyCode]);
                if ((int) $chkFac->fetchColumn() > 0) {
                    throw new RuntimeException('Faculty ID already exists.');
                }
            }

            if ($role === 'student' && $studentNumber === '') {
                throw new RuntimeException('Student number is required.');
            }
            if ($role === 'student' && !db_table_exists('classroom_students')) {
                throw new RuntimeException('Student accounts require classroom_students. Run upgrade_roles.php first.');
            }

            if ($role === 'program_chair' && !$hasChairProgramsTable) {
                throw new RuntimeException('Could not prepare program_chair_programs. Run upgrade_roles.php.');
            }

            $cred = prepare_new_account_password($password, $hasValidEmail);
            $plainForMail = $hasValidEmail ? $cred['plain'] : '';

            $exists = db()->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
            $exists->execute([$username]);
            if ((int) $exists->fetchColumn() > 0) {
                $s = suggest_available_usernames($username, 3);
                $msg = 'Username already exists.';
                if ($s) {
                    $msg .= ' Try: ' . implode(', ', $s);
                }
                throw new RuntimeException($msg);
            }

            $newId = 0;

            if ($role === 'student') {
                $newId = create_student_account(
                    $username,
                    $cred['hash'],
                    $fullName,
                    $email,
                    $studentNumber,
                    $collegeId,
                    $assignedProgram,
                    true,
                    $yearLevel
                );
                if ($isActive === 0) {
                    db()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$newId]);
                }
            } else {
                $fields = ['username', 'password', 'full_name'];
                $params = [$username, $cred['hash'], $fullName];
                if ($hasUserEmail) {
                    $fields[] = 'email';
                    $params[] = $email;
                }
                if ($role === 'admin' && $hasAdminLogTitle) {
                    $fields[] = 'admin_log_title';
                    $params[] = $adminLogTitle;
                }
                $fields[] = 'role';
                $params[] = $role;
                if ($needsCollege || ($role === 'gened' && $collegeId > 0 && $hasCollegeId)) {
                    $fields[] = 'college_id';
                    $params[] = $collegeId > 0 ? $collegeId : null;
                }
                if (in_array($role, ['program_chair', 'faculty'], true) && $hasAssignedProgram) {
                    $fields[] = 'assigned_program';
                    $params[] = $assignedProgram;
                }
                $fields[] = 'is_active';
                $params[] = $isActive;

                $ph = implode(', ', array_fill(0, count($fields), '?'));
                $sql = 'INSERT INTO users (' . implode(', ', $fields) . ') VALUES (' . $ph . ')';
                db()->prepare($sql)->execute($params);
                $newId = (int) db()->lastInsertId();

                if ($role === 'dean' && $collegeId > 0) {
                    db()->prepare('UPDATE colleges SET dean_user_id = ? WHERE id = ?')->execute([$newId, $collegeId]);
                }
                if ($role === 'program_chair') {
                    program_chair_set_assigned_programs($newId, [$assignedProgram], $assignedProgram);
                }
                if ($role === 'faculty') {
                    $facStatus = $isActive === 1 ? 'active' : 'inactive';
                    if ($hasEmploymentStatus) {
                        db()->prepare(
                            'INSERT INTO faculty (user_id, faculty_id, full_name, department, email, max_hours_per_day, college_id, status, employment_status, is_gened)
                             VALUES (?,?,?,?,?,?,?,?,?,0)'
                        )->execute([
                            $newId,
                            $facultyCode,
                            $fullName,
                            $assignedProgram,
                            $email,
                            8,
                            $collegeId,
                            $facStatus,
                            'Permanent',
                        ]);
                    } else {
                        db()->prepare(
                            'INSERT INTO faculty (user_id, faculty_id, full_name, department, email, max_hours_per_day, college_id, status, is_gened)
                             VALUES (?,?,?,?,?,?,?,?,0)'
                        )->execute([
                            $newId,
                            $facultyCode,
                            $fullName,
                            $assignedProgram,
                            $email,
                            8,
                            $collegeId,
                            $facStatus,
                        ]);
                    }
                }
            }

            $selAfter = 'id, username, full_name, role, is_active';
            if ($hasUserEmail) {
                $selAfter .= ', email';
            }
            if ($hasCollegeId) {
                $selAfter .= ', college_id';
            }
            if ($hasAssignedProgram) {
                $selAfter .= ', assigned_program';
            }
            $stAfter = db()->prepare("SELECT {$selAfter} FROM users WHERE id = ? LIMIT 1");
            $stAfter->execute([$newId]);
            $afterRow = $stAfter->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($afterRow !== []) {
                $afterRow['password'] = '[set at creation]';
            }
            $roleLabel = sa_role_label($role, $roleLabels);
            log_admin_activity(
                'add',
                'Account creation',
                $roleLabel . ' user #' . $newId,
                null,
                $afterRow !== [] ? $afterRow : null
            );

            $mailOk = $hasValidEmail
                ? notify_new_account_credentials($newId, $email, $fullName, $username, $plainForMail, $role)
                : null;
            if (!$hasValidEmail) {
                mark_new_account_requires_password_change($newId);
            }
            $_SESSION['flash'] = registration_email_flash_message($mailOk, $email, $roleLabel . ' account');
            $redirectTab = $role === 'super_admin' ? 'super_admins' : 'create';
            header('Location: super_admin_accounts.php?tab=' . $redirectTab . ($role !== 'super_admin' ? '&role=' . rawurlencode($role) : ''));
            exit;
        }

        if ($action === 'edit') {
            $id = (int) ($_POST['id'] ?? 0);
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $email = $hasUserEmail ? trim((string) ($_POST['email'] ?? '')) : '';
            $resetPassword = (string) ($_POST['reset_password'] ?? '');
            $isActive = !empty($_POST['is_active']) ? 1 : 0;

            if ($id < 1) {
                throw new RuntimeException('Invalid account.');
            }
            if ($fullName === '') {
                throw new RuntimeException('Full name is required.');
            }
            if ($hasUserEmail && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Invalid email address.');
            }
            if ($resetPassword !== '' && strlen($resetPassword) < 8) {
                throw new RuntimeException('New password must be at least 8 characters.');
            }

            $selCols = 'id, username, full_name, is_active';
            if ($hasUserEmail) {
                $selCols .= ', email';
            }
            $st = db()->prepare("SELECT {$selCols} FROM users WHERE id = ? AND role = 'super_admin' LIMIT 1");
            $st->execute([$id]);
            $before = $st->fetch(PDO::FETCH_ASSOC);
            if (!$before) {
                throw new RuntimeException('Super Administrator not found.');
            }

            if ($isActive === 0 && (int) ($before['is_active'] ?? 0) === 1 && super_admin_active_count() <= 1) {
                throw new RuntimeException('At least one active Super Administrator account is required.');
            }

            if ($resetPassword !== '') {
                if ($hasUserEmail) {
                    db()->prepare(
                        'UPDATE users SET full_name = ?, email = ?, password = ?, is_active = ? WHERE id = ? AND role = ?'
                    )->execute([
                        $fullName,
                        $email,
                        password_hash($resetPassword, PASSWORD_DEFAULT),
                        $isActive,
                        $id,
                        'super_admin',
                    ]);
                } else {
                    db()->prepare(
                        'UPDATE users SET full_name = ?, password = ?, is_active = ? WHERE id = ? AND role = ?'
                    )->execute([
                        $fullName,
                        password_hash($resetPassword, PASSWORD_DEFAULT),
                        $isActive,
                        $id,
                        'super_admin',
                    ]);
                }
                if ($id !== $currentUserId) {
                    mark_new_account_requires_password_change($id);
                } else {
                    clear_must_change_password($id);
                }
            } elseif ($hasUserEmail) {
                db()->prepare(
                    'UPDATE users SET full_name = ?, email = ?, is_active = ? WHERE id = ? AND role = ?'
                )->execute([$fullName, $email, $isActive, $id, 'super_admin']);
            } else {
                db()->prepare(
                    'UPDATE users SET full_name = ?, is_active = ? WHERE id = ? AND role = ?'
                )->execute([$fullName, $isActive, $id, 'super_admin']);
            }

            $selAfter = 'id, username, full_name, role, is_active';
            if ($hasUserEmail) {
                $selAfter .= ', email';
            }
            $stAfter = db()->prepare("SELECT {$selAfter} FROM users WHERE id = ? LIMIT 1");
            $stAfter->execute([$id]);
            $afterRow = $stAfter->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($afterRow !== []) {
                $afterRow['password'] = $resetPassword !== '' ? '[changed]' : '[unchanged]';
            }
            log_admin_activity(
                'edit',
                'Super Administrator accounts',
                'Super Admin user #' . $id,
                $before ? (array) $before : null,
                $afterRow !== [] ? $afterRow : null
            );

            $_SESSION['flash'] = 'Super Administrator account updated.';
            header('Location: super_admin_accounts.php?tab=super_admins');
            exit;
        }

        if ($action === 'reset_password') {
            $id = (int) ($_POST['id'] ?? 0);
            $manualPassword = trim((string) ($_POST['new_password'] ?? ''));
            $confirmPassword = trim((string) ($_POST['confirm_password'] ?? ''));
            $generateAndEmail = !empty($_POST['generate_temp_password_email']);
            $emailReset = !empty($_POST['email_reset_password']);
            $forceChange = !empty($_POST['force_password_change']);

            if ($id < 1) {
                throw new RuntimeException('Invalid account.');
            }

            $selCols = 'id, username, full_name, role, is_active';
            if ($hasUserEmail) {
                $selCols .= ', email';
            }
            $st = db()->prepare("SELECT {$selCols} FROM users WHERE id = ? LIMIT 1");
            $st->execute([$id]);
            $before = $st->fetch(PDO::FETCH_ASSOC);
            if (!$before) {
                throw new RuntimeException('Account not found.');
            }

            $email = $hasUserEmail ? trim((string) ($before['email'] ?? '')) : '';
            $hasValidEmail = $hasUserEmail && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL);
            $plainForMail = '';

            if ($generateAndEmail) {
                if (!$hasValidEmail) {
                    throw new RuntimeException('This account needs a valid email address before a temporary password can be emailed.');
                }
                $plainForMail = generate_temp_password();
                db()->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([
                    password_hash($plainForMail, PASSWORD_DEFAULT),
                    $id,
                ]);
            } else {
                if ($manualPassword === '' || strlen($manualPassword) < 8) {
                    throw new RuntimeException('New password must be at least 8 characters.');
                }
                if ($manualPassword !== $confirmPassword) {
                    throw new RuntimeException('Password confirmation does not match.');
                }
                db()->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([
                    password_hash($manualPassword, PASSWORD_DEFAULT),
                    $id,
                ]);
                if ($emailReset) {
                    if (!$hasValidEmail) {
                        throw new RuntimeException('This account needs a valid email address to email the new password.');
                    }
                    $plainForMail = $manualPassword;
                }
            }

            if ($id === $currentUserId) {
                clear_must_change_password($id);
                unset($_SESSION['must_change_password']);
            } elseif ($forceChange || $generateAndEmail || $plainForMail !== '') {
                mark_new_account_requires_password_change($id);
            } else {
                clear_must_change_password($id);
            }

            $afterRow = $before;
            $afterRow['password'] = '[changed]';
            log_admin_activity(
                'edit',
                'Account monitor',
                'Password reset for user #' . $id . ' (' . (string) $before['username'] . ')',
                ['id' => $id, 'username' => $before['username'], 'role' => $before['role'], 'password' => '[unchanged]'],
                $afterRow
            );

            $roleKey = (string) ($before['role'] ?? 'admin');
            $displayName = (string) ($before['full_name'] ?? '');
            $uname = (string) ($before['username'] ?? '');

            if ($plainForMail !== '') {
                $mailOk = send_account_credentials_mail($email, $displayName, $uname, $plainForMail, $roleKey, $email);
                $_SESSION['flash'] = credentials_email_flash_message($mailOk, $email, 'Password updated for ' . $uname);
            } else {
                $_SESSION['flash'] = 'Password updated for ' . $uname . '.';
            }

            $q = http_build_query(array_filter([
                'tab' => 'monitor',
                'q' => trim((string) ($_POST['return_q'] ?? '')),
                'role' => trim((string) ($_POST['return_role'] ?? '')),
                'status' => trim((string) ($_POST['return_status'] ?? '')),
                'page' => (int) ($_POST['return_page'] ?? 1) > 1 ? (int) ($_POST['return_page'] ?? 1) : null,
            ], static fn ($v) => $v !== null && $v !== ''));
            header('Location: super_admin_accounts.php' . ($q !== '' ? '?' . $q : ''));
            exit;
        }

        if ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if (!sa_delete_account($id, $currentUserId, $roleLabels)) {
                throw new RuntimeException('Account not found.');
            }
            $_SESSION['flash'] = 'Account deleted.';
            $q = http_build_query(array_filter([
                'tab' => 'monitor',
                'q' => trim((string) ($_POST['return_q'] ?? '')),
                'role' => trim((string) ($_POST['return_role'] ?? '')),
                'status' => trim((string) ($_POST['return_status'] ?? '')),
                'page' => (int) ($_POST['return_page'] ?? 1) > 1 ? (int) ($_POST['return_page'] ?? 1) : null,
            ], static fn ($v) => $v !== null && $v !== ''));
            header('Location: super_admin_accounts.php' . ($q !== '' ? '?' . $q : ''));
            exit;
        }

        if ($action === 'delete_bulk') {
            $ids = isset($_POST['ids']) && is_array($_POST['ids'])
                ? array_values(array_unique(array_filter(array_map('intval', $_POST['ids']), static fn (int $id): bool => $id > 0)))
                : [];
            if ($ids === []) {
                throw new RuntimeException('Select at least one account to delete.');
            }

            $deleted = 0;
            $skipped = [];
            foreach ($ids as $delId) {
                try {
                    if (sa_delete_account($delId, $currentUserId, $roleLabels)) {
                        $deleted++;
                    }
                } catch (RuntimeException $ex) {
                    $skipped[] = $ex->getMessage();
                }
            }

            if ($deleted === 0) {
                $detail = $skipped !== [] ? ' ' . $skipped[0] : '';
                throw new RuntimeException('No selected accounts could be deleted.' . $detail);
            }

            $flash = $deleted === 1 ? 'Account deleted.' : $deleted . ' accounts deleted.';
            if ($skipped !== []) {
                $flash .= ' Some were skipped: ' . $skipped[0];
            }
            $_SESSION['flash'] = $flash;
            $q = http_build_query(array_filter([
                'tab' => 'monitor',
                'q' => trim((string) ($_POST['return_q'] ?? '')),
                'role' => trim((string) ($_POST['return_role'] ?? '')),
                'status' => trim((string) ($_POST['return_status'] ?? '')),
                'page' => (int) ($_POST['return_page'] ?? 1) > 1 ? (int) ($_POST['return_page'] ?? 1) : null,
            ], static fn ($v) => $v !== null && $v !== ''));
            header('Location: super_admin_accounts.php' . ($q !== '' ? '?' . $q : ''));
            exit;
        }

        throw new RuntimeException('Unknown action.');
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        $_SESSION['flash'] = 'Error: ' . $e->getMessage();
        if ($action === 'reset_password') {
            $rid = (int) ($_POST['id'] ?? 0);
            header('Location: super_admin_accounts.php?tab=monitor' . ($rid > 0 ? '&reset=' . $rid : ''));
        } elseif ($action === 'edit') {
            header('Location: super_admin_accounts.php?tab=super_admins' . ($editId > 0 ? '&edit=' . $editId : ''));
        } elseif ($action === 'add') {
            $failRole = trim((string) ($_POST['role'] ?? ''));
            $q = 'tab=create';
            if ($failRole !== '' && isset($roleLabels[$failRole])) {
                $q .= '&role=' . rawurlencode($failRole);
            }
            header('Location: super_admin_accounts.php?' . $q);
        } elseif (in_array($action, ['delete', 'delete_bulk'], true)) {
            $q = http_build_query(array_filter([
                'tab' => 'monitor',
                'q' => trim((string) ($_POST['return_q'] ?? '')),
                'role' => trim((string) ($_POST['return_role'] ?? '')),
                'status' => trim((string) ($_POST['return_status'] ?? '')),
                'page' => (int) ($_POST['return_page'] ?? 1) > 1 ? (int) ($_POST['return_page'] ?? 1) : null,
            ], static fn ($v) => $v !== null && $v !== ''));
            header('Location: super_admin_accounts.php' . ($q !== '' ? '?' . $q : ''));
        } else {
            header('Location: super_admin_accounts.php');
        }
        exit;
    }
}

// --- Monitor filters ---
$filterQ = trim((string) ($_GET['q'] ?? ''));
$filterRole = trim((string) ($_GET['role'] ?? ''));
$filterStatus = trim((string) ($_GET['status'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 40;

$where = ['1=1'];
$params = [];
if ($filterQ !== '') {
    $where[] = '(u.username LIKE ? OR u.full_name LIKE ?' . ($hasUserEmail ? ' OR u.email LIKE ?' : '') . ')';
    $like = '%' . $filterQ . '%';
    $params[] = $like;
    $params[] = $like;
    if ($hasUserEmail) {
        $params[] = $like;
    }
}
if ($filterRole !== '' && isset($roleLabels[$filterRole])) {
    $where[] = 'u.role = ?';
    $params[] = $filterRole;
}
if ($filterStatus === 'active') {
    $where[] = 'u.is_active = 1';
} elseif ($filterStatus === 'disabled') {
    $where[] = 'u.is_active = 0';
}

$whereSql = implode(' AND ', $where);

$countSt = db()->prepare("SELECT COUNT(*) FROM users u WHERE {$whereSql}");
$countSt->execute($params);
$totalAccounts = (int) $countSt->fetchColumn();
$totalPages = max(1, (int) ceil($totalAccounts / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$listCols = 'u.id, u.username, u.full_name, u.role, u.is_active';
if ($hasUserEmail) {
    $listCols .= ', u.email';
}
if ($hasMustChange) {
    $listCols .= ', u.must_change_password';
}
if ($hasLastLogin) {
    $listCols .= ', u.last_login_at';
}
if ($hasCollegeId) {
    $listCols .= ', c.college_name';
}

$joinCollege = ($hasCollegeId && db_table_exists('colleges'))
    ? 'LEFT JOIN colleges c ON c.id = u.college_id'
    : '';
if ($joinCollege === '' && $hasCollegeId) {
    // college table missing — drop college_name from select
    $listCols = str_replace(', c.college_name', '', $listCols);
}

$listSql = "SELECT {$listCols} FROM users u {$joinCollege} WHERE {$whereSql} ORDER BY u.role ASC, u.username ASC LIMIT {$perPage} OFFSET {$offset}";
$listSt = db()->prepare($listSql);
$listSt->execute($params);
$accounts = $listSt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$roleCounts = db()->query(
    "SELECT role, COUNT(*) AS cnt FROM users GROUP BY role ORDER BY role"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$listColsSa = 'id, username, full_name, is_active';
if ($hasUserEmail) {
    $listColsSa .= ', email';
}
$superAdmins = db()->query("SELECT {$listColsSa} FROM users WHERE role = 'super_admin' ORDER BY username")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$colleges = [];
if ($hasCollegesTable) {
    $colleges = db()->query('SELECT id, college_code, college_name FROM colleges ORDER BY college_code')->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @var array<int, list<string>> */
$programsByCollege = [];
if ($hasProgramsTable) {
    $progRows = db()->query(
        "SELECT college_id, program_name FROM programs WHERE status = 'active' ORDER BY program_name"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($progRows as $pr) {
        $cid = (int) ($pr['college_id'] ?? 0);
        $pname = trim((string) ($pr['program_name'] ?? ''));
        if ($cid > 0 && $pname !== '') {
            $programsByCollege[$cid][] = $pname;
        }
    }
}

$monitorQueryBase = array_filter([
    'tab' => 'monitor',
    'q' => $filterQ !== '' ? $filterQ : null,
    'role' => $filterRole !== '' ? $filterRole : null,
    'status' => $filterStatus !== '' ? $filterStatus : null,
], static fn ($v) => $v !== null);

$pageTitle = 'Account monitor';
require_once __DIR__ . '/includes/header.php';
?>
<h1 class="h3 mb-2"><i class="fa-solid fa-users-gear me-2 text-primary"></i>Account monitor</h1>
<p class="text-muted mb-3">Review every login, create accounts for any role, and manage Super Administrator logins.</p>
<?php if ($flash): ?><div class="alert alert-info"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link<?= $tab === 'monitor' ? ' active' : '' ?>" href="super_admin_accounts.php?tab=monitor">
            <i class="fa-solid fa-desktop me-1"></i>All accounts
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link<?= $tab === 'create' ? ' active' : '' ?>" href="super_admin_accounts.php?tab=create">
            <i class="fa-solid fa-user-plus me-1"></i>Create account
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link<?= $tab === 'super_admins' ? ' active' : '' ?>" href="super_admin_accounts.php?tab=super_admins">
            <i class="fa-solid fa-user-secret me-1"></i>Super Administrators
        </a>
    </li>
</ul>

<?php if ($tab === 'monitor'): ?>
    <div class="row g-3 mb-3">
        <?php foreach ($roleCounts as $rc): ?>
            <?php
            $r = (string) ($rc['role'] ?? '');
            $cnt = (int) ($rc['cnt'] ?? 0);
            $activeFilter = $filterRole === $r;
            $href = 'super_admin_accounts.php?' . http_build_query(array_filter([
                'tab' => 'monitor',
                'role' => $activeFilter ? null : $r,
                'q' => $filterQ !== '' ? $filterQ : null,
                'status' => $filterStatus !== '' ? $filterStatus : null,
            ], static fn ($v) => $v !== null));
            ?>
            <div class="col-6 col-md-4 col-xl-auto">
                <a href="<?= htmlspecialchars($href) ?>" class="text-decoration-none">
                    <div class="card shadow-sm h-100<?= $activeFilter ? ' border-primary' : '' ?>">
                        <div class="card-body py-2 px-3">
                            <div class="small text-muted"><?= htmlspecialchars(sa_role_label($r, $roleLabels)) ?></div>
                            <div class="fs-5 fw-semibold"><?= number_format($cnt) ?></div>
                        </div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
                <input type="hidden" name="tab" value="monitor">
                <div class="col-md-4">
                    <label class="form-label">Search</label>
                    <input type="search" name="q" class="form-control" value="<?= htmlspecialchars($filterQ) ?>" placeholder="Username, name<?= $hasUserEmail ? ', or email' : '' ?>" autocomplete="off">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Role</label>
                    <select name="role" class="form-select">
                        <option value="">All roles</option>
                        <?php foreach ($roleLabels as $rk => $rl): ?>
                            <option value="<?= htmlspecialchars($rk) ?>"<?= $filterRole === $rk ? ' selected' : '' ?>><?= htmlspecialchars($rl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All</option>
                        <option value="active"<?= $filterStatus === 'active' ? ' selected' : '' ?>>Active</option>
                        <option value="disabled"<?= $filterStatus === 'disabled' ? ' selected' : '' ?>>Disabled</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a class="btn btn-outline-secondary" href="super_admin_accounts.php?tab=monitor">Clear</a>
                </div>
            </form>
        </div>
    </div>

    <?php if ($resetRow): ?>
        <div class="card shadow-sm border-warning mb-4">
            <div class="card-header bg-white">
                <strong><i class="fa-solid fa-key me-1 text-warning"></i>Reset / change password</strong>
                — <?= htmlspecialchars((string) $resetRow['username']) ?>
                <span class="badge bg-secondary ms-1"><?= htmlspecialchars(sa_role_label((string) $resetRow['role'], $roleLabels)) ?></span>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    <?= htmlspecialchars((string) $resetRow['full_name']) ?>
                    <?php if ($hasUserEmail && trim((string) ($resetRow['email'] ?? '')) !== ''): ?>
                        · <?= htmlspecialchars((string) $resetRow['email']) ?>
                    <?php endif; ?>
                </p>
                <form method="post" class="row g-3">
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="id" value="<?= (int) $resetRow['id'] ?>">
                    <input type="hidden" name="return_q" value="<?= htmlspecialchars($filterQ) ?>">
                    <input type="hidden" name="return_role" value="<?= htmlspecialchars($filterRole) ?>">
                    <input type="hidden" name="return_status" value="<?= htmlspecialchars($filterStatus) ?>">
                    <input type="hidden" name="return_page" value="<?= (int) $page ?>">

                    <div class="col-md-4">
                        <label class="form-label">New password</label>
                        <input type="password" name="new_password" class="form-control" minlength="8" autocomplete="new-password" id="saNewPassword">
                        <div class="form-text">At least 8 characters (unless generating a temporary password below).</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Confirm password</label>
                        <input type="password" name="confirm_password" class="form-control" minlength="8" autocomplete="new-password" id="saConfirmPassword">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label d-block">&nbsp;</label>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="force_password_change" value="1" id="saForceChange" checked>
                            <label class="form-check-label" for="saForceChange">Require password change on next login</label>
                        </div>
                        <?php if ($hasUserEmail): ?>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="email_reset_password" value="1" id="saEmailReset">
                                <label class="form-check-label" for="saEmailReset">Email this new password</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="generate_temp_password_email" value="1" id="saGenerateTemp">
                                <label class="form-check-label" for="saGenerateTemp">Generate temporary password and email it</label>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-warning">
                            <i class="fa-solid fa-key me-1"></i>Update password
                        </button>
                        <a class="btn btn-outline-secondary" href="super_admin_accounts.php?<?= htmlspecialchars(http_build_query($monitorQueryBase)) ?>">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
        <script>
            (function () {
                var gen = document.getElementById('saGenerateTemp');
                var pw = document.getElementById('saNewPassword');
                var cf = document.getElementById('saConfirmPassword');
                if (!gen || !pw || !cf) return;
                function sync() {
                    var on = gen.checked;
                    pw.required = !on;
                    cf.required = !on;
                    pw.disabled = on;
                    cf.disabled = on;
                    if (on) { pw.value = ''; cf.value = ''; }
                }
                gen.addEventListener('change', sync);
                sync();
            })();
        </script>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <strong>All accounts</strong>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <?php if ($accounts): ?>
                    <form id="sa-bulk-delete-form" method="post" class="d-inline" onsubmit="return confirm('Delete the selected accounts? This cannot be undone.');">
                        <input type="hidden" name="action" value="delete_bulk">
                        <input type="hidden" name="return_q" value="<?= htmlspecialchars($filterQ) ?>">
                        <input type="hidden" name="return_role" value="<?= htmlspecialchars($filterRole) ?>">
                        <input type="hidden" name="return_status" value="<?= htmlspecialchars($filterStatus) ?>">
                        <input type="hidden" name="return_page" value="<?= (int) $page ?>">
                        <button type="submit" id="sa-bulk-delete-btn" class="btn btn-sm btn-outline-danger" disabled>
                            <i class="fa-solid fa-trash me-1"></i>Delete selected
                        </button>
                    </form>
                <?php endif; ?>
                <span class="text-muted small"><?= number_format($totalAccounts) ?> account<?= $totalAccounts === 1 ? '' : 's' ?></span>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if (!$accounts): ?>
                <p class="text-muted p-3 mb-0">No accounts match the current filters.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th scope="col" style="width:2.25rem">
                                    <input type="checkbox" class="form-check-input" id="sa-select-all" aria-label="Select all deletable accounts on this page">
                                </th>
                                <th>Username</th>
                                <th>Full name</th>
                                <th>Role</th>
                                <?php if ($hasUserEmail): ?><th>Email</th><?php endif; ?>
                                <?php if ($hasCollegeId && $joinCollege !== ''): ?><th>College</th><?php endif; ?>
                                <th>Status</th>
                                <?php if ($hasMustChange): ?><th>Password</th><?php endif; ?>
                                <?php if ($hasLastLogin): ?><th>Last login</th><?php endif; ?>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($accounts as $acc): ?>
                            <?php
                            $accId = (int) $acc['id'];
                            $isSelf = $accId === $currentUserId;
                            $resetHref = 'super_admin_accounts.php?' . http_build_query(array_merge($monitorQueryBase, [
                                'reset' => $accId,
                                'page' => $page > 1 ? $page : null,
                            ]));
                            ?>
                            <tr<?= $resetId === $accId ? ' class="table-warning"' : '' ?>>
                                <td>
                                    <?php if (!$isSelf): ?>
                                        <input type="checkbox" class="form-check-input sa-row-select" name="ids[]" form="sa-bulk-delete-form" value="<?= $accId ?>" aria-label="Select <?= htmlspecialchars((string) $acc['username']) ?>">
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars((string) $acc['username']) ?>
                                    <?php if ($isSelf): ?>
                                        <span class="badge bg-primary-subtle text-primary-emphasis border ms-1">You</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars((string) $acc['full_name']) ?></td>
                                <td><span class="badge text-bg-light border"><?= htmlspecialchars(sa_role_label((string) $acc['role'], $roleLabels)) ?></span></td>
                                <?php if ($hasUserEmail): ?>
                                    <td class="small"><?= htmlspecialchars((string) ($acc['email'] ?? '')) ?></td>
                                <?php endif; ?>
                                <?php if ($hasCollegeId && $joinCollege !== ''): ?>
                                    <td class="small"><?= htmlspecialchars((string) ($acc['college_name'] ?? '—')) ?></td>
                                <?php endif; ?>
                                <td>
                                    <?php if ((int) $acc['is_active'] === 1): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Disabled</span>
                                    <?php endif; ?>
                                </td>
                                <?php if ($hasMustChange): ?>
                                    <td>
                                        <?php if ((int) ($acc['must_change_password'] ?? 0) === 1): ?>
                                            <span class="badge bg-warning text-dark">Must change</span>
                                        <?php else: ?>
                                            <span class="text-muted small">OK</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <?php if ($hasLastLogin): ?>
                                    <td class="small text-muted">
                                        <?php
                                        $ll = (string) ($acc['last_login_at'] ?? '');
                                        echo $ll !== '' ? htmlspecialchars(date('M j, Y g:i A', strtotime($ll))) : '—';
                                        ?>
                                    </td>
                                <?php endif; ?>
                                <td class="text-end text-nowrap">
                                    <a class="btn btn-sm btn-outline-warning" href="<?= htmlspecialchars($resetHref) ?>">
                                        <i class="fa-solid fa-key me-1"></i>Password
                                    </a>
                                    <?php if (!$isSelf): ?>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this account? This cannot be undone.');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $accId ?>">
                                            <input type="hidden" name="return_q" value="<?= htmlspecialchars($filterQ) ?>">
                                            <input type="hidden" name="return_role" value="<?= htmlspecialchars($filterRole) ?>">
                                            <input type="hidden" name="return_status" value="<?= htmlspecialchars($filterStatus) ?>">
                                            <input type="hidden" name="return_page" value="<?= (int) $page ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($totalPages > 1): ?>
            <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span class="text-muted small">Page <?= (int) $page ?> of <?= (int) $totalPages ?></span>
                <div class="btn-group">
                    <?php if ($page > 1): ?>
                        <a class="btn btn-sm btn-outline-secondary" href="super_admin_accounts.php?<?= htmlspecialchars(http_build_query(array_merge($monitorQueryBase, ['page' => $page - 1]))) ?>">Previous</a>
                    <?php endif; ?>
                    <?php if ($page < $totalPages): ?>
                        <a class="btn btn-sm btn-outline-secondary" href="super_admin_accounts.php?<?= htmlspecialchars(http_build_query(array_merge($monitorQueryBase, ['page' => $page + 1]))) ?>">Next</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php if ($accounts): ?>
        <script>
            (function () {
                var selectAll = document.getElementById('sa-select-all');
                var deleteBtn = document.getElementById('sa-bulk-delete-btn');
                if (!selectAll || !deleteBtn) return;

                var rowChecks = function () {
                    return Array.prototype.slice.call(document.querySelectorAll('.sa-row-select'));
                };

                var syncBulkDelete = function () {
                    var checks = rowChecks();
                    var selected = checks.filter(function (el) { return el.checked; });
                    deleteBtn.disabled = selected.length === 0;
                    selectAll.indeterminate = selected.length > 0 && selected.length < checks.length;
                    selectAll.checked = checks.length > 0 && selected.length === checks.length;
                };

                selectAll.addEventListener('change', function () {
                    var checked = selectAll.checked;
                    rowChecks().forEach(function (el) { el.checked = checked; });
                    syncBulkDelete();
                });

                rowChecks().forEach(function (el) {
                    el.addEventListener('change', syncBulkDelete);
                });

                syncBulkDelete();
            })();
        </script>
    <?php endif; ?>

<?php elseif ($tab === 'create'): ?>
    <?php
    $createRole = $preselectRole !== '' ? $preselectRole : 'admin';
    ?>
    <p class="text-muted">Create a login for any role. College and program fields appear when the selected role needs them.</p>

    <div class="row g-4">
        <div class="col-lg-7 col-xl-6">
            <div class="card shadow-sm">
                <div class="card-header bg-white"><strong>New account</strong></div>
                <div class="card-body">
                    <form method="post" class="row g-3" id="saCreateAccountForm">
                        <input type="hidden" name="action" value="add">
                        <div class="col-12">
                            <label class="form-label">Role</label>
                            <select name="role" id="saCreateRole" class="form-select" required>
                                <?php foreach ($roleLabels as $rk => $rl): ?>
                                    <option value="<?= htmlspecialchars($rk) ?>"<?= $createRole === $rk ? ' selected' : '' ?>><?= htmlspecialchars($rl) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" class="form-control" required maxlength="50" autocomplete="username">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Full name</label>
                            <input type="text" name="full_name" class="form-control" required maxlength="100" autocomplete="name">
                        </div>
                        <?php if ($hasUserEmail): ?>
                            <div class="col-12">
                                <label class="form-label">Email <span class="text-muted">(optional)</span></label>
                                <input type="email" name="email" class="form-control" maxlength="190" autocomplete="email">
                                <div class="form-text">If provided, a temporary password can be emailed automatically when the password field is left blank.</div>
                            </div>
                        <?php endif; ?>
                        <div class="col-12">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control" minlength="8" autocomplete="new-password" id="saCreatePassword">
                            <div class="form-text">Required when no email is provided (min. 8 characters).</div>
                        </div>

                        <div class="col-12 sa-role-field" data-roles="admin"<?= $hasAdminLogTitle ? '' : ' hidden' ?>>
                            <label class="form-label">Activity log title <span class="text-muted">(optional)</span></label>
                            <input type="text" name="admin_log_title" class="form-control" maxlength="120" placeholder="e.g. Scheduling Office">
                        </div>

                        <div class="col-12 sa-role-field" data-roles="dean program_chair faculty student">
                            <label class="form-label">College</label>
                            <select name="college_id" id="saCreateCollege" class="form-select">
                                <option value="">Select college…</option>
                                <?php foreach ($colleges as $c): ?>
                                    <option value="<?= (int) $c['id'] ?>">
                                        <?= htmlspecialchars((string) $c['college_code'] . ' — ' . (string) $c['college_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($colleges === []): ?>
                                <div class="form-text text-warning">No colleges found. Add colleges first.</div>
                            <?php endif; ?>
                        </div>

                        <div class="col-12 sa-role-field" data-roles="program_chair faculty student">
                            <label class="form-label">Program</label>
                            <select name="assigned_program" id="saCreateProgram" class="form-select">
                                <option value="">Select college first…</option>
                            </select>
                        </div>

                        <div class="col-md-6 sa-role-field" data-roles="faculty">
                            <label class="form-label">Faculty ID</label>
                            <input type="text" name="faculty_id" class="form-control" maxlength="50" placeholder="Employee / faculty code">
                        </div>

                        <div class="col-md-6 sa-role-field" data-roles="student">
                            <label class="form-label">Student number</label>
                            <input type="text" name="student_number" class="form-control" maxlength="50">
                        </div>
                        <div class="col-md-6 sa-role-field" data-roles="student">
                            <label class="form-label">Year level <span class="text-muted">(optional)</span></label>
                            <input type="text" name="year_level" class="form-control" maxlength="40" placeholder="e.g. 1st Year">
                        </div>

                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" value="1" id="saCreateActive" checked>
                                <label class="form-check-label" for="saCreateActive">Account active</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">
                                <i class="fa-solid fa-user-plus me-1"></i>Create account
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card shadow-sm border-0 bg-light">
                <div class="card-body">
                    <h2 class="h6">Role notes</h2>
                    <ul class="small text-muted mb-0 ps-3">
                        <li><strong>Super Administrator</strong> — institution-wide oversight</li>
                        <li><strong>Administrator</strong> — day-to-day scheduling admin</li>
                        <li><strong>Dean</strong> — requires a college; linked as that college’s dean</li>
                        <li><strong>Program Chair</strong> — college + program</li>
                        <li><strong>Faculty</strong> — college, program, and Faculty ID (creates faculty profile)</li>
                        <li><strong>General Education</strong> — institution-wide GE coordinator</li>
                        <li><strong>Student</strong> — college, program, and student number</li>
                    </ul>
                    <p class="small text-muted mt-3 mb-0">
                        Prefer dedicated pages for bulk work:
                        <a href="super_admin_admins.php">Administrators</a>
                        · college deans / chairs are usually managed by Admin or Dean roles.
                    </p>
                </div>
            </div>
        </div>
    </div>
    <script>
        (function () {
            var programsByCollege = <?= json_encode($programsByCollege, JSON_UNESCAPED_UNICODE) ?>;
            var roleEl = document.getElementById('saCreateRole');
            var collegeEl = document.getElementById('saCreateCollege');
            var programEl = document.getElementById('saCreateProgram');
            if (!roleEl) return;

            function syncRoleFields() {
                var role = roleEl.value;
                document.querySelectorAll('.sa-role-field').forEach(function (el) {
                    var roles = (el.getAttribute('data-roles') || '').split(/\s+/);
                    var show = roles.indexOf(role) !== -1;
                    el.classList.toggle('d-none', !show);
                    el.querySelectorAll('input, select').forEach(function (inp) {
                        if (inp.name === 'admin_log_title') return;
                        if (show && (inp.name === 'college_id' || inp.name === 'assigned_program' || inp.name === 'faculty_id' || inp.name === 'student_number')) {
                            inp.required = true;
                        } else if (!show) {
                            inp.required = false;
                        }
                    });
                });
            }

            function fillPrograms() {
                if (!programEl || !collegeEl) return;
                var cid = collegeEl.value;
                var list = programsByCollege[cid] || programsByCollege[String(cid)] || [];
                var prev = programEl.value;
                programEl.innerHTML = '';
                var opt0 = document.createElement('option');
                opt0.value = '';
                opt0.textContent = list.length ? 'Select program…' : (cid ? 'No active programs for this college' : 'Select college first…');
                programEl.appendChild(opt0);
                list.forEach(function (name) {
                    var opt = document.createElement('option');
                    opt.value = name;
                    opt.textContent = name;
                    if (name === prev) opt.selected = true;
                    programEl.appendChild(opt);
                });
            }

            roleEl.addEventListener('change', syncRoleFields);
            if (collegeEl) collegeEl.addEventListener('change', fillPrograms);
            syncRoleFields();
            fillPrograms();
        })();
    </script>

<?php else: ?>
    <p class="text-muted">Update existing <strong>super_admin</strong> logins. To create a new Super Administrator (or any other role), use the <a href="super_admin_accounts.php?tab=create&role=super_admin">Create account</a> tab.</p>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card shadow-sm">
                <div class="card-header bg-white"><strong><?= $editRow ? 'Edit Super Administrator' : 'Super Administrator' ?></strong></div>
                <div class="card-body">
                    <?php if ($editRow): ?>
                        <form method="post" class="row g-3">
                            <input type="hidden" name="action" value="edit">
                            <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
                            <div class="col-12">
                                <label class="form-label">Username</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars((string) $editRow['username']) ?>" disabled autocomplete="username">
                                <div class="form-text">Username cannot be changed here.</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Full name</label>
                                <input type="text" name="full_name" class="form-control" required value="<?= htmlspecialchars((string) $editRow['full_name']) ?>" autocomplete="name">
                            </div>
                            <?php if ($hasUserEmail): ?>
                                <div class="col-12">
                                    <label class="form-label">Email</label>
                                    <input type="email" name="email" class="form-control" value="<?= htmlspecialchars((string) ($editRow['email'] ?? '')) ?>" autocomplete="email">
                                </div>
                            <?php endif; ?>
                            <div class="col-12">
                                <label class="form-label">New password</label>
                                <input type="password" name="reset_password" class="form-control" minlength="8" autocomplete="new-password">
                                <div class="form-text">Leave blank to keep the current password.</div>
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active_edit" <?= (int) $editRow['is_active'] === 1 ? 'checked' : '' ?><?= super_admin_active_count() <= 1 && (int) $editRow['is_active'] === 1 ? ' disabled' : '' ?>>
                                    <label class="form-check-label" for="is_active_edit">Account active</label>
                                    <?php if (super_admin_active_count() <= 1 && (int) $editRow['is_active'] === 1): ?>
                                        <input type="hidden" name="is_active" value="1">
                                        <div class="form-text">The last active Super Administrator cannot be disabled.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-12 d-flex flex-wrap gap-2">
                                <button type="submit" class="btn btn-primary">Save changes</button>
                                <a class="btn btn-outline-secondary" href="super_admin_accounts.php?tab=super_admins">Cancel</a>
                            </div>
                        </form>
                    <?php else: ?>
                        <p class="text-muted mb-3">Select <strong>Edit</strong> on an account in the list, or create a new Super Administrator from the Create account tab.</p>
                        <a class="btn btn-primary" href="super_admin_accounts.php?tab=create&role=super_admin">
                            <i class="fa-solid fa-user-plus me-1"></i>Create Super Administrator
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card shadow-sm">
                <div class="card-header bg-white"><strong>Existing Super Administrators</strong></div>
                <div class="card-body p-0">
                    <?php if (!$superAdmins): ?>
                        <p class="text-muted p-3 mb-0">No Super Administrator accounts found. Run <a href="upgrade_roles.php">upgrade_roles.php</a> or <a href="super_admin_accounts.php?tab=create&role=super_admin">create one</a>.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped mb-0 align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Username</th>
                                        <th>Full name</th>
                                        <th>Status</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($superAdmins as $sa): ?>
                                    <tr>
                                        <td>
                                            <?= htmlspecialchars((string) $sa['username']) ?>
                                            <?php if ((int) $sa['id'] === $currentUserId): ?>
                                                <span class="badge bg-primary-subtle text-primary-emphasis border ms-1">You</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars((string) $sa['full_name']) ?></td>
                                        <td>
                                            <?php if ((int) $sa['is_active'] === 1): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Disabled</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <a class="btn btn-sm btn-outline-primary" href="super_admin_accounts.php?tab=super_admins&edit=<?= (int) $sa['id'] ?>">Edit</a>
                                            <a class="btn btn-sm btn-outline-warning" href="super_admin_accounts.php?tab=monitor&reset=<?= (int) $sa['id'] ?>">Password</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php';

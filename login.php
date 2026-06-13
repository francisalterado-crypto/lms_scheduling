<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/student_registration_helpers.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$loginRoles = [
    'student' => [
        'label' => 'Student',
        'icon' => 'fa-user-graduate',
        'roles' => ['student'],
        'subtitle' => 'Sign in to join classes, view schedules, and use EduTools.',
    ],
    'admin' => [
        'label' => 'Admin',
        'icon' => 'fa-user-shield',
        'roles' => ['admin', 'super_admin'],
        'subtitle' => 'System administration, colleges, deans, and institution-wide settings.',
    ],
    'program_chair' => [
        'label' => 'Program Chair',
        'icon' => 'fa-user-tie',
        'roles' => ['program_chair'],
        'subtitle' => 'Manage faculty, students, courses, and program schedules.',
    ],
    'dean' => [
        'label' => 'Dean',
        'icon' => 'fa-building-columns',
        'roles' => ['dean'],
        'subtitle' => 'College oversight, program chairs, faculty, and scheduling.',
    ],
    'faculty' => [
        'label' => 'Faculty',
        'icon' => 'fa-chalkboard-user',
        'roles' => ['faculty'],
        'subtitle' => 'Classrooms, teaching load, schedules, and academic tools.',
    ],
];

$selectedRole = (string) ($_GET['role'] ?? $_POST['login_role'] ?? 'student');
if (!isset($loginRoles[$selectedRole])) {
    $selectedRole = 'student';
}

$viewMode = (string) ($_GET['mode'] ?? $_POST['form_mode'] ?? 'login');
if ($viewMode !== 'register') {
    $viewMode = 'login';
}
if ($viewMode === 'register' && $selectedRole !== 'student') {
    $viewMode = 'login';
}

$error = '';
$success = '';
$usernameValue = '';
$regFullName = '';
$regEmail = '';
$regStudentNumber = '';
$regCollegeId = 0;
$regProgramName = '';
$regYearLevel = '';
$hasAssignedProgram = false;
$hasAdminLogTitle = false;
$registrationReady = student_registration_table_ready();
$colleges = $registrationReady ? active_colleges_for_registration() : [];
$programsForCollege = $regCollegeId > 0 ? active_programs_for_college($regCollegeId) : [];

try {
    $colStmt = db()->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $colStmt->execute([DB_NAME, 'users', 'assigned_program']);
    $hasAssignedProgram = (int) $colStmt->fetchColumn() > 0;
    $colStmt->execute([DB_NAME, 'users', 'admin_log_title']);
    $hasAdminLogTitle = (int) $colStmt->fetchColumn() > 0;
} catch (Throwable $e) {
    $hasAssignedProgram = false;
    $hasAdminLogTitle = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formMode = (string) ($_POST['form_mode'] ?? 'login');
    $postedRole = (string) ($_POST['login_role'] ?? $selectedRole);
    if (!isset($loginRoles[$postedRole])) {
        $postedRole = 'student';
    }
    $selectedRole = $postedRole;
    $allowedRoles = $loginRoles[$selectedRole]['roles'];

    if ($formMode === 'register') {
        $viewMode = 'register';
        $usernameValue = trim((string) ($_POST['username'] ?? ''));
        $regFullName = trim((string) ($_POST['full_name'] ?? ''));
        $regEmail = trim((string) ($_POST['email'] ?? ''));
        $regStudentNumber = trim((string) ($_POST['student_number'] ?? ''));
        $regCollegeId = (int) ($_POST['college_id'] ?? 0);
        $regProgramName = trim((string) ($_POST['program_name'] ?? ''));
        $regYearLevel = trim((string) ($_POST['year_level'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

        if ($password !== $passwordConfirm) {
            $error = 'Passwords do not match.';
        } else {
            try {
                submit_student_registration(
                    $usernameValue,
                    $password,
                    $regFullName,
                    $regEmail,
                    $regStudentNumber,
                    $regCollegeId,
                    $regProgramName,
                    $regYearLevel
                );
                $success = 'Registration submitted successfully. Your Program Chair will review your request. Once approved, you will receive an email with your username and temporary password.';
                $viewMode = 'login';
                $usernameValue = '';
                $regFullName = '';
                $regEmail = '';
                $regStudentNumber = '';
                $regCollegeId = 0;
                $regProgramName = '';
                $regYearLevel = '';
            } catch (Throwable $e) {
                $error = $e->getMessage();
                $programsForCollege = $regCollegeId > 0 ? active_programs_for_college($regCollegeId) : [];
            }
        }
    } else {
        $viewMode = 'login';
        $username = trim((string) ($_POST['username'] ?? ''));
        $usernameValue = $username;
        $password = (string) ($_POST['password'] ?? '');
        if ($username === '' || $password === '') {
            $error = 'Please enter username and password.';
        } else {
            try {
                $pendingMsg = registration_status_message_for_username($username);
                if ($pendingMsg !== null) {
                    $error = $pendingMsg;
                } else {
                    $stmt = db()->prepare(
                        'SELECT u.id, u.username, u.password, u.full_name, u.role, '
                        . ($hasAssignedProgram ? 'u.assigned_program' : '"" AS assigned_program') . ','
                        . ($hasAdminLogTitle ? ' u.admin_log_title' : ' "" AS admin_log_title') . ',
                         u.college_id, u.is_active, f.id AS faculty_id
                         FROM users u
                         LEFT JOIN faculty f ON f.user_id = u.id
                         WHERE u.username = ? LIMIT 1'
                    );
                    $stmt->execute([$username]);
                    $user = $stmt->fetch();
                    if ($user && (int) $user['is_active'] === 1 && password_verify($password, $user['password'])) {
                        $userRole = (string) $user['role'];
                        if (!in_array($userRole, $allowedRoles, true)) {
                            $error = 'This account is not a ' . strtolower($loginRoles[$selectedRole]['label'])
                                . ' account. Please select the correct role tab and try again.';
                        } else {
                            $resolvedFacultyId = $user['faculty_id'] !== null ? (int) $user['faculty_id'] : null;
                            $resolvedStudentId = null;
                            if ($userRole === 'faculty' && $resolvedFacultyId === null) {
                                $resolvedFacultyId = resolve_faculty_id_for_user((int) $user['id']);
                            }
                            if ($userRole === 'student') {
                                $resolvedStudentId = resolve_student_id_for_user((int) $user['id']);
                            }
                            $_SESSION['user_id'] = (int) $user['id'];
                            $_SESSION['username'] = $user['username'];
                            $_SESSION['full_name'] = $user['full_name'];
                            $_SESSION['role'] = $user['role'];
                            $_SESSION['assigned_program'] = (string) ($user['assigned_program'] ?? '');
                            $_SESSION['admin_log_title'] = trim((string) ($user['admin_log_title'] ?? ''));
                            $_SESSION['college_id'] = $user['college_id'] !== null ? (int) $user['college_id'] : null;
                            $_SESSION['faculty_id'] = $resolvedFacultyId;
                            $_SESSION['student_id'] = $resolvedStudentId;
                            try {
                                if (db_column_exists('users', 'last_logout_at')) {
                                    db()->prepare('UPDATE users SET last_login_at = NOW(), last_seen_at = NOW(), last_logout_at = NULL WHERE id = ?')
                                        ->execute([(int) $user['id']]);
                                } else {
                                    db()->prepare('UPDATE users SET last_login_at = NOW(), last_seen_at = NOW() WHERE id = ?')
                                        ->execute([(int) $user['id']]);
                                }
                            } catch (Throwable $e) {
                                // Ignore if legacy schema does not yet have activity columns.
                            }
                            require_once __DIR__ . '/includes/admin_activity_log.php';
                            $loginMeta = [
                                'role' => $userRole,
                                'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                            ];
                            if ($hasAdminLogTitle) {
                                $loginMeta['log_title'] = trim((string) ($user['admin_log_title'] ?? ''));
                            }
                            log_user_activity(
                                'login',
                                'Authentication',
                                'Signed in',
                                null,
                                $loginMeta
                            );
                            header('Location: dashboard.php');
                            exit;
                        }
                    } else {
                        $error = $user && (int) $user['is_active'] !== 1
                            ? 'Your account is disabled. Please contact your dean/admin.'
                            : 'Invalid username or password.';
                    }
                }
            } catch (Throwable $e) {
                $prev = $e->getPrevious();
                $error = $e instanceof RuntimeException && $prev instanceof PDOException
                    ? $e->getMessage()
                    : 'Database error: ' . $e->getMessage();
            }
        }
    }
}

if ($regCollegeId > 0) {
    $programsForCollege = active_programs_for_college($regCollegeId);
}

$roleSubtitle = $loginRoles[$selectedRole]['subtitle'];

$sealDataUri = '';
$sealCandidates = [
    __DIR__ . '/assets/images/wpu-seal.png',
    'C:\Users\wpu-0\.cursor\projects\c-xampp-htdocs-CLASS\assets\c__Users_wpu-0_AppData_Roaming_Cursor_User_workspaceStorage_f94ec644ac39e85d4f1f3f887fe3104f_images_A_LOGO-ba32c997-80f6-4298-97e0-4ca3b192b5d0.png',
];
foreach ($sealCandidates as $sealPath) {
    if (is_file($sealPath)) {
        $sealRaw = @file_get_contents($sealPath);
        if ($sealRaw !== false) {
            $sealDataUri = 'data:image/png;base64,' . base64_encode($sealRaw);
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — WPU SABLAe Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --hero-dark: #082032;
            --hero-green: #1f6f43;
            --hero-light: #f4f8f6;
            --hero-card: rgba(255, 255, 255, 0.92);
            --hero-shadow: 0 24px 60px rgba(8, 32, 50, 0.22);
        }

        body.login-page {
            min-height: 100vh;
            margin: 0;
            background:
                radial-gradient(circle at top left, rgba(255, 255, 255, 0.22), transparent 28%),
                radial-gradient(circle at bottom right, rgba(56, 161, 105, 0.24), transparent 25%),
                linear-gradient(135deg, #061826 0%, var(--hero-dark) 42%, #114b2b 100%);
        }

        .login-shell {
            min-height: 100vh;
            display: flex;
            align-items: center;
            padding: 32px 0;
        }

        .login-panel {
            overflow: hidden;
            border: 0;
            border-radius: 28px;
            background: var(--hero-card);
            box-shadow: var(--hero-shadow);
            backdrop-filter: blur(10px);
        }

        .login-brand {
            height: 100%;
            color: #fff;
            background:
                linear-gradient(180deg, rgba(255, 255, 255, 0.08), rgba(255, 255, 255, 0)),
                linear-gradient(145deg, #0a2235 0%, #12394d 45%, #1f6f43 100%);
            padding: 48px 38px;
            position: relative;
        }

        .login-brand::after {
            content: "";
            position: absolute;
            inset: auto -70px -70px auto;
            width: 180px;
            height: 180px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.08);
            filter: blur(2px);
        }

        .seal-wrap {
            width: clamp(168px, 38vw, 220px);
            height: clamp(168px, 38vw, 220px);
            flex-shrink: 0;
            border-radius: 50%;
            margin-left: auto;
            margin-right: auto;
            margin-bottom: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.14);
            border: 1px solid rgba(255, 255, 255, 0.28);
            box-shadow: 0 16px 35px rgba(0, 0, 0, 0.18);
        }

        .login-brand .seal-wrap {
            width: min(82%, 320px);
            height: min(82%, 320px);
            max-width: 320px;
            max-height: 320px;
            margin-bottom: 32px;
        }

        .seal-wrap img {
            width: 90%;
            height: 90%;
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            border-radius: 50%;
            background: #fff;
            padding: 8px;
        }

        .brand-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.18);
            font-size: 0.85rem;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .brand-title {
            font-size: clamp(1.8rem, 4vw, 2.5rem);
            font-weight: 700;
            line-height: 1.15;
            margin: 16px 0 12px;
        }

        .brand-text {
            max-width: 440px;
            color: rgba(255, 255, 255, 0.78);
            font-size: 1rem;
            margin-bottom: 0;
        }

        .login-form-side {
            padding: 42px 38px;
            background: linear-gradient(180deg, rgba(255,255,255,0.96), rgba(246,249,247,0.98));
        }

        .login-kicker {
            color: var(--hero-green);
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            font-size: 0.78rem;
            margin-bottom: 8px;
        }

        .login-heading {
            font-size: 1.95rem;
            font-weight: 700;
            color: #163043;
            margin-bottom: 8px;
        }

        .login-subtitle {
            color: #6c7a86;
            margin-bottom: 28px;
        }

        .input-group-text {
            border-radius: 16px 0 0 16px;
            border-right: 0;
            background: #f3f6f5;
            color: #5a6874;
        }

        .form-control {
            min-height: 52px;
            border-radius: 0 16px 16px 0;
            border-left: 0;
            box-shadow: none;
            background: #fbfcfc;
        }

        .form-control:focus {
            border-color: #9bcbb0;
            box-shadow: 0 0 0 0.2rem rgba(31, 111, 67, 0.12);
        }

        .field-stack .form-control,
        .field-stack .input-group-text {
            border-color: #d8e4dd;
        }

        .btn-login {
            min-height: 54px;
            border: 0;
            border-radius: 16px;
            background: linear-gradient(135deg, #1f6f43 0%, #2f8e57 100%);
            box-shadow: 0 14px 28px rgba(31, 111, 67, 0.22);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .btn-login:hover {
            transform: translateY(-1px);
            box-shadow: 0 18px 30px rgba(31, 111, 67, 0.26);
        }

        .login-note {
            color: #6c7a86;
        }

        .login-note a {
            color: var(--hero-green);
            font-weight: 600;
            text-decoration: none;
        }

        .login-note a:hover {
            text-decoration: underline;
        }

        .role-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 22px;
        }

        .role-tab {
            flex: 1 1 calc(50% - 8px);
            min-width: 120px;
            border: 1px solid #d8e4dd;
            border-radius: 14px;
            background: #fff;
            color: #5a6874;
            font-size: 0.82rem;
            font-weight: 600;
            padding: 10px 12px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .role-tab i {
            display: block;
            font-size: 1rem;
            margin-bottom: 4px;
        }

        .role-tab.active {
            border-color: #1f6f43;
            background: linear-gradient(135deg, rgba(31, 111, 67, 0.12), rgba(47, 142, 87, 0.08));
            color: #1f6f43;
            box-shadow: 0 8px 18px rgba(31, 111, 67, 0.12);
        }

        .auth-switch {
            display: flex;
            gap: 0;
            margin-bottom: 22px;
            border: 1px solid #d8e4dd;
            border-radius: 14px;
            overflow: hidden;
            background: #fff;
        }

        .auth-switch-btn {
            flex: 1;
            border: 0;
            background: transparent;
            padding: 12px 16px;
            font-weight: 600;
            color: #6c7a86;
        }

        .auth-switch-btn.active {
            background: linear-gradient(135deg, #1f6f43 0%, #2f8e57 100%);
            color: #fff;
        }

        .register-panel {
            display: none;
        }

        .register-panel.show {
            display: block;
        }

        .login-panel-form.hide {
            display: none;
        }

        .form-select {
            min-height: 52px;
            border-radius: 16px;
            border-color: #d8e4dd;
            background: #fbfcfc;
        }

        .form-select:focus {
            border-color: #9bcbb0;
            box-shadow: 0 0 0 0.2rem rgba(31, 111, 67, 0.12);
        }

        .rounded-field .form-control,
        .rounded-field .form-select {
            border-radius: 16px;
            border-left: 1px solid #d8e4dd;
        }

        @media (max-width: 991.98px) {
            .login-brand,
            .login-form-side {
                padding: 32px 26px;
            }

            .login-form-side .seal-wrap {
                width: clamp(152px, 44vw, 220px);
                height: clamp(152px, 44vw, 220px);
            }
        }
    </style>
</head>
<body class="login-page">
<div class="container login-shell">
    <div class="login-panel w-100">
        <div class="row g-0 align-items-stretch">
            <div class="col-lg-6 d-none d-lg-block">
                <div class="login-brand d-flex flex-column justify-content-center">
                    <div class="seal-wrap">
                        <?php if ($sealDataUri !== ''): ?>
                            <img src="<?= htmlspecialchars($sealDataUri) ?>" alt="Western Philippines University seal">
                        <?php else: ?>
                            <img src="assets/logo.png" alt="Western Philippines University seal">
                        <?php endif; ?>
                    </div>
                    <div class="brand-chip">
                        <i class="fa-solid fa-shield-halved"></i>
                        SAFE WPU Scheduling and LMS Portal
                    </div>
                    <h1 class="brand-title">WPU SABLAe Portal</h1>
                    <p class="brand-text">A smoother way to manage academic schedules, faculty coordination, and college operations in one secure workspace.</p>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="login-form-side h-100 d-flex flex-column justify-content-center">
                    <div class="d-lg-none text-center mb-4">
                        <div class="seal-wrap mx-auto">
                            <?php if ($sealDataUri !== ''): ?>
                                <img src="<?= htmlspecialchars($sealDataUri) ?>" alt="Western Philippines University seal">
                            <?php else: ?>
                                <img src="assets/logo.png" alt="Western Philippines University seal">
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="login-kicker">Welcome back</div>
                    <h2 class="login-heading" id="authHeading"><?= $viewMode === 'register' ? 'Create student account' : 'Sign in to continue' ?></h2>
                    <p class="login-subtitle" id="authSubtitle"><?= htmlspecialchars($viewMode === 'register' ? 'Register under your college and program. Your Program Chair must approve before you can sign in. You will receive an email with your temporary password once approved.' : $roleSubtitle) ?></p>

                    <div class="role-tabs" id="roleTabs" role="tablist" aria-label="Sign in as">
                        <?php foreach ($loginRoles as $roleKey => $roleMeta): ?>
                            <button type="button"
                                    class="role-tab<?= $selectedRole === $roleKey ? ' active' : '' ?>"
                                    data-role="<?= htmlspecialchars($roleKey) ?>"
                                    aria-pressed="<?= $selectedRole === $roleKey ? 'true' : 'false' ?>">
                                <i class="fa-solid <?= htmlspecialchars($roleMeta['icon']) ?>"></i>
                                <?= htmlspecialchars($roleMeta['label']) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <div class="auth-switch<?= $selectedRole !== 'student' ? ' d-none' : '' ?>" id="studentAuthSwitch">
                        <button type="button" class="auth-switch-btn<?= $viewMode === 'login' ? ' active' : '' ?>" data-mode="login">Sign in</button>
                        <button type="button" class="auth-switch-btn<?= $viewMode === 'register' ? ' active' : '' ?>" data-mode="register"
                                <?= !$registrationReady ? ' disabled title="Run upgrade_roles.php first"' : '' ?>>Register</button>
                    </div>

                    <?php if ($success): ?>
                        <div class="alert alert-success rounded-4"><?= htmlspecialchars($success) ?></div>
                    <?php endif; ?>
                    <?php if ($error): ?>
                        <div class="alert alert-danger rounded-4"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <form method="post" autocomplete="on" class="login-panel-form<?= $viewMode === 'register' ? ' hide' : '' ?>" id="loginForm">
                        <input type="hidden" name="form_mode" value="login">
                        <input type="hidden" name="login_role" id="loginRoleField" value="<?= htmlspecialchars($selectedRole) ?>">
                        <div class="mb-3 field-stack">
                            <label class="form-label fw-semibold" for="username">Username</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fa-solid fa-user"></i></span>
                                <input type="text" name="username" id="username" class="form-control" value="<?= htmlspecialchars($usernameValue) ?>" required autofocus>
                            </div>
                        </div>
                        <div class="mb-4 field-stack">
                            <label class="form-label fw-semibold" for="password">Password</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
                                <input type="password" name="password" id="password" class="form-control" required>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-success btn-login w-100">
                            <i class="fa-solid fa-right-to-bracket me-2"></i>Login
                        </button>
                    </form>

                    <form method="post" autocomplete="on" class="register-panel<?= $viewMode === 'register' ? ' show' : '' ?>" id="registerForm">
                        <input type="hidden" name="form_mode" value="register">
                        <input type="hidden" name="login_role" value="student">
                        <div class="mb-3 rounded-field">
                            <label class="form-label fw-semibold" for="reg_full_name">Full name</label>
                            <input type="text" name="full_name" id="reg_full_name" class="form-control" value="<?= htmlspecialchars($regFullName) ?>" required>
                        </div>
                        <div class="mb-3 rounded-field">
                            <label class="form-label fw-semibold" for="reg_student_number">Student number</label>
                            <input type="text" name="student_number" id="reg_student_number" class="form-control" value="<?= htmlspecialchars($regStudentNumber) ?>" required>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6 rounded-field">
                                <label class="form-label fw-semibold" for="reg_college_id">College</label>
                                <select name="college_id" id="reg_college_id" class="form-select" required>
                                    <option value="">Select college</option>
                                    <?php foreach ($colleges as $college): ?>
                                        <option value="<?= (int) $college['id'] ?>"
                                            <?= $regCollegeId === (int) $college['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars((string) $college['college_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 rounded-field">
                                <label class="form-label fw-semibold" for="reg_program_name">Program</label>
                                <select name="program_name" id="reg_program_name" class="form-select" required>
                                    <option value="">Select program</option>
                                    <?php foreach ($programsForCollege as $program): ?>
                                        <option value="<?= htmlspecialchars((string) $program) ?>"
                                            <?= $regProgramName === (string) $program ? 'selected' : '' ?>>
                                            <?= htmlspecialchars((string) $program) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 rounded-field">
                                <label class="form-label fw-semibold" for="reg_year_level">Year level</label>
                                <select name="year_level" id="reg_year_level" class="form-select" required>
                                    <option value="">Select year level</option>
                                    <?php
                                    $yearLevelsForForm = $regCollegeId > 0 && $regProgramName !== ''
                                        ? active_year_levels_for_program($regCollegeId, $regProgramName)
                                        : [];
                                    foreach ($yearLevelsForForm as $yl):
                                    ?>
                                        <option value="<?= htmlspecialchars((string) $yl) ?>"
                                            <?= $regYearLevel === (string) $yl ? 'selected' : '' ?>>
                                            Year <?= htmlspecialchars((string) $yl) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3 rounded-field">
                            <label class="form-label fw-semibold" for="reg_username">Username</label>
                            <input type="text" name="username" id="reg_username" class="form-control" value="<?= htmlspecialchars($usernameValue) ?>" required pattern="[A-Za-z0-9._-]{3,50}">
                        </div>
                        <?php if (db_column_exists('users', 'email')): ?>
                        <div class="mb-3 rounded-field">
                            <label class="form-label fw-semibold" for="reg_email">Email</label>
                            <input type="email" name="email" id="reg_email" class="form-control" value="<?= htmlspecialchars($regEmail) ?>" required>
                        </div>
                        <?php endif; ?>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6 rounded-field">
                                <label class="form-label fw-semibold" for="reg_password">Password</label>
                                <input type="password" name="password" id="reg_password" class="form-control" required minlength="8">
                            </div>
                            <div class="col-md-6 rounded-field">
                                <label class="form-label fw-semibold" for="reg_password_confirm">Confirm password</label>
                                <input type="password" name="password_confirm" id="reg_password_confirm" class="form-control" required minlength="8">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-success btn-login w-100" <?= !$registrationReady ? 'disabled' : '' ?>>
                            <i class="fa-solid fa-user-plus me-2"></i>Submit registration
                        </button>
                        <p class="login-note text-center small mt-3 mb-0">Your Program Chair will review your registration before you can sign in.</p>
                    </form>

                    <p class="login-note text-center small mt-4 mb-0">First-time setup? Run <a href="install.php">install.php</a> (database) or <a href="upgrade_roles.php">upgrade_roles.php</a> (schema updates including student registration).</p>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    var roleSubtitles = <?= json_encode(array_map(static fn ($r) => $r['subtitle'], $loginRoles), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
    var selectedRole = <?= json_encode($selectedRole, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) ?>;
    var viewMode = <?= json_encode($viewMode, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) ?>;

    var roleTabs = document.getElementById('roleTabs');
    var loginRoleField = document.getElementById('loginRoleField');
    var authHeading = document.getElementById('authHeading');
    var authSubtitle = document.getElementById('authSubtitle');
    var studentAuthSwitch = document.getElementById('studentAuthSwitch');
    var loginForm = document.getElementById('loginForm');
    var registerForm = document.getElementById('registerForm');
    var collegeSelect = document.getElementById('reg_college_id');
    var programSelect = document.getElementById('reg_program_name');
    var yearLevelSelect = document.getElementById('reg_year_level');
    var yearLevelsByProgram = {};
    var selectedYearInitial = <?= json_encode($regYearLevel, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) ?>;

    function setViewMode(mode) {
        viewMode = mode;
        var isRegister = mode === 'register';
        loginForm.classList.toggle('hide', isRegister);
        registerForm.classList.toggle('show', isRegister);
        authHeading.textContent = isRegister ? 'Create student account' : 'Sign in to continue';
        authSubtitle.textContent = isRegister
            ? 'Register under your college and program. Your Program Chair must approve before you can sign in.'
            : (roleSubtitles[selectedRole] || '');
        document.querySelectorAll('.auth-switch-btn').forEach(function (btn) {
            btn.classList.toggle('active', btn.getAttribute('data-mode') === mode);
        });
    }

    function setRole(role) {
        selectedRole = role;
        if (loginRoleField) {
            loginRoleField.value = role;
        }
        document.querySelectorAll('.role-tab').forEach(function (tab) {
            var active = tab.getAttribute('data-role') === role;
            tab.classList.toggle('active', active);
            tab.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        if (studentAuthSwitch) {
            studentAuthSwitch.classList.toggle('d-none', role !== 'student');
        }
        if (role !== 'student' && viewMode === 'register') {
            setViewMode('login');
        } else if (viewMode === 'login') {
            authSubtitle.textContent = roleSubtitles[role] || '';
        }
    }

    if (roleTabs) {
        roleTabs.addEventListener('click', function (e) {
            var tab = e.target.closest('.role-tab');
            if (!tab) return;
            setRole(tab.getAttribute('data-role'));
        });
    }

    if (studentAuthSwitch) {
        studentAuthSwitch.addEventListener('click', function (e) {
            var btn = e.target.closest('.auth-switch-btn');
            if (!btn || btn.disabled) return;
            setViewMode(btn.getAttribute('data-mode'));
        });
    }

    function renderYearLevels(selectedProgram, selectedYear) {
        if (!yearLevelSelect) {
            return;
        }
        var list = ['1', '2', '3', '4', '5'];
        if (selectedProgram && yearLevelsByProgram[selectedProgram] && yearLevelsByProgram[selectedProgram].length) {
            list = yearLevelsByProgram[selectedProgram];
        }
        var html = '<option value="">Select year level</option>';
        list.forEach(function (yl) {
            var selected = selectedYear === yl ? ' selected' : '';
            html += '<option value="' + String(yl).replace(/"/g, '&quot;') + '"' + selected + '>Year ' + yl + '</option>';
        });
        yearLevelSelect.innerHTML = html;
    }

    function loadPrograms(collegeId, selectedProgram, selectedYear) {
        if (!programSelect || !collegeId) {
            if (programSelect) {
                programSelect.innerHTML = '<option value="">Select program</option>';
            }
            yearLevelsByProgram = {};
            renderYearLevels('', '');
            return;
        }
        programSelect.innerHTML = '<option value="">Loading…</option>';
        if (yearLevelSelect) {
            yearLevelSelect.innerHTML = '<option value="">Loading…</option>';
        }
        fetch('api/programs_by_college.php?college_id=' + encodeURIComponent(collegeId))
            .then(function (res) { return res.json(); })
            .then(function (data) {
                yearLevelsByProgram = data.year_levels_by_program || {};
                var html = '<option value="">Select program</option>';
                (data.programs || []).forEach(function (name) {
                    var selected = selectedProgram === name ? ' selected' : '';
                    html += '<option value="' + name.replace(/"/g, '&quot;') + '"' + selected + '>' + name + '</option>';
                });
                programSelect.innerHTML = html;
                renderYearLevels(selectedProgram, selectedYear);
            })
            .catch(function () {
                programSelect.innerHTML = '<option value="">Select program</option>';
                yearLevelsByProgram = {};
                renderYearLevels('', '');
            });
    }

    if (collegeSelect) {
        collegeSelect.addEventListener('change', function () {
            loadPrograms(collegeSelect.value, '', '');
        });
    }

    if (programSelect) {
        programSelect.addEventListener('change', function () {
            renderYearLevels(programSelect.value, '');
        });
    }

    if (collegeSelect && collegeSelect.value) {
        loadPrograms(collegeSelect.value, programSelect ? programSelect.value : '', selectedYearInitial);
    }

    setRole(selectedRole);
    setViewMode(viewMode);
})();
</script>
</body>
</html>

<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/student_registration_helpers.php';
require_once __DIR__ . '/includes/login_slideshow_helpers.php';

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
        'roles' => ['admin'],
        'subtitle' => 'Day-to-day scheduling, colleges, deans, and institution-wide settings.',
    ],
    'super_admin' => [
        'label' => 'Super Admin',
        'short' => 'Super',
        'icon' => 'fa-user-secret',
        'roles' => ['super_admin'],
        'subtitle' => 'Provision administrator accounts, faculty inventory, and institution-wide reports.',
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
    'gened' => [
        'label' => 'General Education',
        'short' => 'Gen Ed',
        'icon' => 'fa-book-open',
        'roles' => ['gened'],
        'subtitle' => 'Institution-wide GE courses, faculty, rooms, and scheduling.',
        'accent' => '#059669',
        'accent_bg' => '#ecfdf5',
        'accent_border' => '#6ee7b7',
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
        try {
            submit_student_registration(
                $usernameValue,
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
                    $userSql = 'SELECT u.id, u.username, u.password, u.full_name, u.role, '
                        . ($hasAssignedProgram ? 'u.assigned_program' : '"" AS assigned_program') . ','
                        . ($hasAdminLogTitle ? ' u.admin_log_title' : ' "" AS admin_log_title') . ',
                         u.college_id, u.is_active, f.id AS faculty_id
                         FROM users u
                         LEFT JOIN faculty f ON f.user_id = u.id
                         WHERE u.username = ? LIMIT 1';
                    $user = false;
                    foreach (login_username_lookup_variants($username) as $usernameTry) {
                        $stmt = db()->prepare($userSql);
                        $stmt->execute([$usernameTry]);
                        $found = $stmt->fetch();
                        if ($found) {
                            $user = $found;
                            break;
                        }
                    }
                    if ($user && (int) $user['is_active'] === 1 && password_verify($password, $user['password'])) {
                        $userRole = (string) $user['role'];
                        if (!in_array($userRole, $allowedRoles, true)) {
                            if ($userRole === 'super_admin' && $postedRole === 'admin') {
                                $error = 'This is a Super Administrator account. Select the Super Admin tab and sign in again.';
                            } elseif ($userRole === 'admin' && $postedRole === 'super_admin') {
                                $error = 'This is an Administrator (scheduling) account. Select the Admin tab and sign in again.';
                            } else {
                                $error = 'This account is not a ' . strtolower($loginRoles[$selectedRole]['label'])
                                    . ' account. Please select the correct role tab and try again.';
                            }
                        } else {
                            $resolvedFacultyId = $user['faculty_id'] !== null ? (int) $user['faculty_id'] : null;
                            $resolvedStudentId = null;
                            if (in_array($userRole, ['faculty', 'program_chair', 'dean', 'gened'], true) && $resolvedFacultyId === null) {
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
                            $mustChangePassword = user_must_change_password((int) $user['id']);
                            $_SESSION['must_change_password'] = $mustChangePassword;
                            if ($mustChangePassword) {
                                header('Location: settings.php?force_password=1');
                                exit;
                            }
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
$loginSlideshowImages = login_slideshow_active_images();

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>WPU SABLA ePortal | Smart Academic Hub</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --portal-accent: #4f46e5;
            --portal-accent-soft: #eef2ff;
            --portal-accent-border: #c7d2fe;
        }
        body {
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(145deg, #eef2ff 0%, #e0e7ff 45%, #ecfdf5 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            position: relative;
        }
        body::before {
            content: "";
            position: fixed;
            inset: 0;
            background-image:
                radial-gradient(circle at 12% 22%, rgba(79, 70, 229, 0.06) 0%, transparent 42%),
                radial-gradient(circle at 88% 78%, rgba(5, 150, 105, 0.05) 0%, transparent 38%),
                radial-gradient(circle at 10% 30%, rgba(79, 70, 229, 0.03) 2%, transparent 2.5%);
            background-size: auto, auto, 48px 48px;
            pointer-events: none;
            z-index: 0;
        }
        .portal-container {
            max-width: 1320px;
            width: 100%;
            background: #fff;
            border-radius: 2rem;
            box-shadow: 0 25px 50px -16px rgba(15, 23, 42, 0.28), 0 8px 20px rgba(15, 23, 42, 0.06);
            overflow: hidden;
            display: flex;
            flex-wrap: wrap;
            z-index: 2;
            border: 1px solid rgba(255, 255, 255, 0.7);
        }
        .hero-panel {
            flex: 1.15;
            background: linear-gradient(155deg, #1e293b 0%, #0f172a 55%, #134e4a 100%);
            padding: 2rem 2rem;
            color: #fff;
            display: flex;
            flex-direction: column;
            position: relative;
            overflow: hidden;
        }
        .hero-panel::before {
            content: "";
            position: absolute;
            inset: 0;
            background: radial-gradient(ellipse 80% 60% at 100% 100%, rgba(52, 211, 153, 0.12), transparent 55%);
            pointer-events: none;
        }
        .hero-panel::after {
            content: "✦";
            font-size: 280px;
            opacity: 0.05;
            position: absolute;
            bottom: -50px;
            right: -40px;
            pointer-events: none;
        }
        .logo-area { margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.75rem; position: relative; z-index: 1; }
        .logo-seal {
            width: 58px; height: 58px; border-radius: 50%; object-fit: contain;
            background: #fff; padding: 5px; flex-shrink: 0;
            box-shadow: 0 10px 28px rgba(0, 0, 0, 0.22);
            border: 2px solid rgba(255, 255, 255, 0.28);
        }
        .logo-text h1 { font-size: 1.35rem; font-weight: 700; letter-spacing: -0.35px; line-height: 1.2; }
        .logo-text span { font-size: 0.68rem; opacity: 0.78; display: block; margin-top: 0.15rem; letter-spacing: 0.02em; }
        .hero-quote { margin-top: auto; margin-bottom: 1rem; position: relative; z-index: 1; }
        .hero-quote h2 {
            font-size: 1.35rem; font-weight: 600; line-height: 1.3; margin-bottom: 0.5rem;
            background: linear-gradient(120deg, #fff 0%, #c7d2fe 55%, #6ee7b7 100%);
            background-clip: text; -webkit-background-clip: text; color: transparent;
        }
        .hero-quote p { font-size: 0.8rem; color: #cbd5e1; max-width: 90%; line-height: 1.45; }
        .feature-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.45rem;
            margin-top: 1rem;
            position: relative;
            z-index: 1;
        }
        .feature-item {
            display: flex; align-items: center; gap: 0.45rem; font-size: 0.72rem;
            background: rgba(255, 255, 255, 0.06); padding: 0.45rem 0.7rem; border-radius: 0.75rem;
            border: 1px solid rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(4px);
        }
        .feature-item i { color: #a5b4fc; width: 1rem; text-align: center; flex-shrink: 0; }
        .feature-item.feature-gened i { color: #6ee7b7; }
        .hero-slideshow {
            flex: 1 1 auto;
            margin: 0.5rem 0 1rem;
            position: relative;
            z-index: 1;
            width: 100%;
            min-height: clamp(260px, 42vh, 420px);
            height: clamp(260px, 42vh, 420px);
            border-radius: 1.1rem;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.14);
            background: rgba(15, 23, 42, 0.45);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.06);
        }
        .hero-slide {
            position: absolute;
            inset: 0;
            opacity: 0;
            transition: opacity 1.1s ease-in-out;
            pointer-events: none;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0.15rem;
        }
        .hero-slide.active {
            opacity: 1;
            pointer-events: auto;
        }
        .hero-slide img {
            max-width: 100%;
            max-height: 100%;
            width: auto;
            height: auto;
            object-fit: contain;
            object-position: center;
            display: block;
            border-radius: 0.65rem;
        }
        .hero-slide-caption {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            padding: 0.55rem 0.75rem;
            font-size: 0.72rem;
            line-height: 1.4;
            color: #f8fafc;
            background: linear-gradient(180deg, transparent, rgba(15, 23, 42, 0.82));
        }
        .hero-slideshow-dots {
            position: absolute;
            top: 0.65rem;
            right: 0.75rem;
            display: flex;
            gap: 0.35rem;
            z-index: 2;
        }
        .hero-slideshow-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            border: none;
            padding: 0;
            background: rgba(255, 255, 255, 0.35);
            cursor: pointer;
            transition: background 0.2s, transform 0.2s;
        }
        .hero-slideshow-dot.active {
            background: #fff;
            transform: scale(1.15);
        }
        .form-panel {
            flex: 1;
            padding: 2.75rem 2.25rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
        }
        .welcome-header { margin-bottom: 1.6rem; }
        .welcome-header h3 { font-size: 1.95rem; font-weight: 700; color: #0f172a; letter-spacing: -0.02em; }
        .welcome-header p { color: #475569; font-size: 0.9rem; margin-top: 0.55rem; line-height: 1.55; max-width: 36rem; }
        .role-section-label {
            font-size: 0.72rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #94a3b8;
            margin-bottom: 0.65rem;
        }
        .role-tabs {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0.55rem;
            margin-bottom: 1.5rem;
        }
        .role-btn {
            border: 1.5px solid #e2e8f0;
            padding: 0.7rem 0.45rem;
            border-radius: 0.95rem;
            font-weight: 600;
            font-size: 0.72rem;
            line-height: 1.25;
            cursor: pointer;
            color: #475569;
            font-family: 'Inter', sans-serif;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
            background: #f8fafc;
            transition: border-color 0.2s, background 0.2s, color 0.2s, box-shadow 0.2s, transform 0.15s;
            min-height: 4.1rem;
        }
        .role-btn i { font-size: 1.05rem; color: #64748b; transition: color 0.2s; }
        .role-btn .role-short { display: none; }
        .role-btn.active {
            background: var(--portal-accent-soft);
            color: var(--portal-accent);
            border-color: var(--portal-accent-border);
            box-shadow: 0 4px 14px rgba(79, 70, 229, 0.12);
            transform: translateY(-1px);
        }
        .role-btn.active i { color: var(--portal-accent); }
        .role-btn:not(.active):hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
        }
        .auth-switch {
            display: flex; gap: 0.5rem; background: #f1f5f9; padding: 0.4rem;
            border-radius: 60px; margin-bottom: 1.5rem;
        }
        .auth-switch.hidden { display: none; }
        .auth-switch-btn {
            flex: 1; border: none; background: transparent; padding: 0.6rem 1rem;
            border-radius: 40px; font-weight: 600; font-size: 0.85rem; color: #334155;
            cursor: pointer; font-family: 'Inter', sans-serif;
        }
        .auth-switch-btn.active {
            background: #fff; color: #4f46e5;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.05); border: 1px solid #e2e8f0;
        }
        .auth-switch-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .alert-banner {
            padding: 0.85rem 1rem; border-radius: 1rem; font-size: 0.88rem;
            margin-bottom: 1.25rem; line-height: 1.45;
        }
        .alert-banner.success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-banner.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .input-group { margin-bottom: 1.25rem; }
        .input-label {
            display: flex; align-items: center; gap: 8px; font-weight: 500;
            font-size: 0.85rem; margin-bottom: 0.5rem; color: #1e293b;
        }
        .input-label i { color: #64748b; width: 18px; }
        .input-field {
            width: 100%; padding: 0.9rem 1rem; border: 1.5px solid #e2e8f0;
            border-radius: 1rem; font-size: 0.95rem; font-family: 'Inter', sans-serif;
            background: #fefefe; transition: all 0.2s;
        }
        .input-field:focus {
            outline: none; border-color: #818cf8;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        .password-wrapper { position: relative; width: 100%; }
        .password-wrapper .input-field { padding-right: 2.75rem; }
        .pwd-toggle {
            position: absolute; right: 14px; top: 50%; transform: translateY(-50%);
            cursor: pointer; color: #94a3b8;
        }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .login-panel-form.hide, .register-panel { display: none; }
        .register-panel.show { display: block; }
        .login-btn {
            width: 100%; background: var(--portal-accent); border: none; padding: 0.95rem;
            border-radius: 1rem; font-weight: 700; font-size: 1rem; color: #fff;
            font-family: 'Inter', sans-serif; display: flex; align-items: center;
            justify-content: center; gap: 0.5rem; cursor: pointer; margin-bottom: 1.25rem;
            box-shadow: 0 6px 16px rgba(79, 70, 229, 0.22); transition: all 0.25s;
        }
        .login-btn:hover:not(:disabled) { background: #4338ca; transform: translateY(-1px); box-shadow: 0 8px 20px rgba(79, 70, 229, 0.28); }
        .login-btn:disabled { opacity: 0.55; cursor: not-allowed; }
        .register-note { text-align: center; color: #64748b; font-size: 0.85rem; line-height: 1.45; }
        .portal-footer {
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid #e2e8f0;
            font-size: 0.75rem;
            color: #94a3b8;
            text-align: center;
        }
        @media (max-width: 880px) {
            .portal-container { flex-direction: column; border-radius: 1.5rem; }
            .hero-panel, .form-panel { padding: 2rem 1.5rem; }
            .hero-quote h2 { font-size: 1.2rem; }
            .hero-slideshow { min-height: clamp(200px, 32vh, 300px); height: clamp(200px, 32vh, 300px); }
            .hero-quote p { max-width: 100%; }
            .form-row { grid-template-columns: 1fr; }
            .feature-grid { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 550px) {
            .role-tabs { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .role-btn .role-full { display: none; }
            .role-btn .role-short { display: inline; }
            .role-btn { min-height: 3.6rem; font-size: 0.68rem; }
            .welcome-header h3 { font-size: 1.55rem; }
            .feature-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<div class="portal-container">
    <div class="hero-panel">
        <div class="logo-area">
            <?php if ($sealDataUri !== ''): ?>
                <img src="<?= htmlspecialchars($sealDataUri) ?>" alt="Western Philippines University seal" class="logo-seal">
            <?php else: ?>
                <img src="assets/logo.png" alt="Western Philippines University seal" class="logo-seal">
            <?php endif; ?>
            <div class="logo-text">
                <h1>WPU SABLA ePortal</h1>
                <span>scheduling &amp; LMS · unified workspace</span>
            </div>
        </div>
        <?php if ($loginSlideshowImages !== []): ?>
        <div class="hero-slideshow" id="heroSlideshow" aria-live="polite" aria-label="Campus highlights">
            <?php foreach ($loginSlideshowImages as $i => $slide): ?>
                <div class="hero-slide<?= $i === 0 ? ' active' : '' ?>" data-slide="<?= $i ?>">
                    <img src="<?= htmlspecialchars($slide['url']) ?>" alt="<?= htmlspecialchars($slide['caption'] !== '' ? $slide['caption'] : 'Campus highlight') ?>" loading="<?= $i === 0 ? 'eager' : 'lazy' ?>" decoding="async">
                    <?php if ($slide['caption'] !== ''): ?>
                        <div class="hero-slide-caption"><?= htmlspecialchars($slide['caption']) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if (count($loginSlideshowImages) > 1): ?>
                <div class="hero-slideshow-dots" role="tablist" aria-label="Slideshow navigation">
                    <?php foreach ($loginSlideshowImages as $i => $slide): ?>
                        <button type="button" class="hero-slideshow-dot<?= $i === 0 ? ' active' : '' ?>" data-slide="<?= $i ?>" aria-label="Show slide <?= $i + 1 ?>"<?= $i === 0 ? ' aria-selected="true"' : '' ?>></button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="hero-quote">
            <h2>Academic scheduling, classrooms, and General Education — unified in one hub.</h2>
            <p>Seamless access for students, faculty, deans, program chairs, and the GE coordinator — all in one intelligent workspace.</p>
        </div>
        <div class="feature-grid">
            <div class="feature-item"><i class="fas fa-calendar-alt"></i> <span>Smart scheduling</span></div>
            <div class="feature-item"><i class="fas fa-chalkboard"></i> <span>EduTools integrated</span></div>
            <div class="feature-item feature-gened"><i class="fas fa-book-open"></i> <span>General Education</span></div>
            <div class="feature-item"><i class="fas fa-shield-alt"></i> <span>Secure workspace</span></div>
        </div>
    </div>

    <div class="form-panel">
        <div class="welcome-header">
            <h3 id="authHeading"><?= $viewMode === 'register' ? 'Create student account' : 'Welcome back' ?></h3>
            <p id="authSubtitle"><?= htmlspecialchars($viewMode === 'register' ? 'Register under your college and program. Your Program Chair must approve before you can sign in. You will receive an email with your temporary password once approved.' : $roleSubtitle) ?></p>
        </div>

        <div class="role-section-label">Sign in as</div>
        <div class="role-tabs" id="roleTabs" role="tablist" aria-label="Sign in as">
            <?php foreach ($loginRoles as $roleKey => $roleMeta): ?>
                <button type="button"
                        class="role-btn<?= $selectedRole === $roleKey ? ' active' : '' ?>"
                        data-role="<?= htmlspecialchars($roleKey) ?>"
                        aria-pressed="<?= $selectedRole === $roleKey ? 'true' : 'false' ?>">
                    <i class="fa-solid <?= htmlspecialchars($roleMeta['icon']) ?>"></i>
                    <span class="role-full"><?= htmlspecialchars($roleMeta['label']) ?></span>
                    <?php if (!empty($roleMeta['short'])): ?>
                        <span class="role-short"><?= htmlspecialchars($roleMeta['short']) ?></span>
                    <?php else: ?>
                        <span class="role-short"><?= htmlspecialchars($roleMeta['label']) ?></span>
                    <?php endif; ?>
                </button>
            <?php endforeach; ?>
        </div>

        <div class="auth-switch<?= $selectedRole !== 'student' ? ' hidden' : '' ?>" id="studentAuthSwitch">
            <button type="button" class="auth-switch-btn<?= $viewMode === 'login' ? ' active' : '' ?>" data-mode="login">Sign in</button>
            <button type="button" class="auth-switch-btn<?= $viewMode === 'register' ? ' active' : '' ?>" data-mode="register"
                    <?= !$registrationReady ? ' disabled title="Run upgrade_roles.php first"' : '' ?>>Register</button>
        </div>

        <?php if ($success): ?>
            <div class="alert-banner success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert-banner error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="on" class="login-panel-form<?= $viewMode === 'register' ? ' hide' : '' ?>" id="loginForm">
            <input type="hidden" name="form_mode" value="login">
            <input type="hidden" name="login_role" id="loginRoleField" value="<?= htmlspecialchars($selectedRole) ?>">
            <div class="input-group">
                <div class="input-label"><i class="fas fa-user-circle"></i> <span>Username</span></div>
                <input type="text" name="username" id="username" class="input-field" value="<?= htmlspecialchars($usernameValue) ?>" placeholder="Enter your username" required autofocus>
            </div>
            <div class="input-group">
                <div class="input-label"><i class="fas fa-lock"></i> <span>Password</span></div>
                <input type="password" name="password" id="password" class="input-field" placeholder="••••••••" autocomplete="current-password" required>
            </div>
            <button type="submit" class="login-btn">
                <i class="fas fa-arrow-right-to-bracket"></i> Sign in
            </button>
        </form>

        <form method="post" autocomplete="on" class="register-panel<?= $viewMode === 'register' ? ' show' : '' ?>" id="registerForm">
            <input type="hidden" name="form_mode" value="register">
            <input type="hidden" name="login_role" value="student">
            <div class="input-group">
                <div class="input-label"><i class="fas fa-id-card"></i> <span>Full name</span></div>
                <input type="text" name="full_name" id="reg_full_name" class="input-field" value="<?= htmlspecialchars($regFullName) ?>" required>
            </div>
            <div class="input-group">
                <div class="input-label"><i class="fas fa-hashtag"></i> <span>Student number</span></div>
                <input type="text" name="student_number" id="reg_student_number" class="input-field" value="<?= htmlspecialchars($regStudentNumber) ?>" required>
            </div>
            <div class="form-row">
                <div class="input-group">
                    <div class="input-label"><i class="fas fa-building"></i> <span>College</span></div>
                    <select name="college_id" id="reg_college_id" class="input-field" required>
                        <option value="">Select college</option>
                        <?php foreach ($colleges as $college): ?>
                            <option value="<?= (int) $college['id'] ?>"<?= $regCollegeId === (int) $college['id'] ? ' selected' : '' ?>>
                                <?= htmlspecialchars((string) $college['college_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="input-group">
                    <div class="input-label"><i class="fas fa-graduation-cap"></i> <span>Program</span></div>
                    <select name="program_name" id="reg_program_name" class="input-field" required>
                        <option value="">Select program</option>
                        <?php foreach ($programsForCollege as $program): ?>
                            <option value="<?= htmlspecialchars((string) $program) ?>"<?= $regProgramName === (string) $program ? ' selected' : '' ?>>
                                <?= htmlspecialchars((string) $program) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="input-group">
                <div class="input-label"><i class="fas fa-layer-group"></i> <span>Year level</span></div>
                <select name="year_level" id="reg_year_level" class="input-field" required>
                    <option value="">Select year level</option>
                    <?php
                    $yearLevelsForForm = $regCollegeId > 0 && $regProgramName !== ''
                        ? active_year_levels_for_program($regCollegeId, $regProgramName)
                        : [];
                    foreach ($yearLevelsForForm as $yl):
                    ?>
                        <option value="<?= htmlspecialchars((string) $yl) ?>"<?= $regYearLevel === (string) $yl ? ' selected' : '' ?>>
                            Year <?= htmlspecialchars((string) $yl) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="input-group">
                <div class="input-label"><i class="fas fa-user"></i> <span>Username</span></div>
                <input type="text" name="username" id="reg_username" class="input-field" value="<?= htmlspecialchars($usernameValue) ?>" required pattern="[A-Za-z0-9._-]{3,50}">
            </div>
            <?php if (db_column_exists('users', 'email')): ?>
            <div class="input-group">
                <div class="input-label"><i class="fas fa-envelope"></i> <span>Email</span></div>
                <input type="email" name="email" id="reg_email" class="input-field" value="<?= htmlspecialchars($regEmail) ?>" required>
            </div>
            <?php endif; ?>
            <button type="submit" class="login-btn"<?= !$registrationReady ? ' disabled' : '' ?>>
                <i class="fas fa-user-plus"></i> Submit registration
            </button>
            <p class="register-note">Your Program Chair will review your registration before you can sign in.</p>
        </form>

        <p class="portal-footer">Western Philippines University · WPU SABLA ePortal</p>
    </div>
</div>

<script>
(function () {
    var roleSubtitles = <?= json_encode(array_map(static fn ($r) => $r['subtitle'], $loginRoles), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
    var roleAccents = <?= json_encode(array_map(static function ($r) {
        return [
            'accent' => $r['accent'] ?? '#4f46e5',
            'soft' => $r['accent_bg'] ?? '#eef2ff',
            'border' => $r['accent_border'] ?? '#c7d2fe',
        ];
    }, $loginRoles), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) ?>;
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
        authHeading.textContent = isRegister ? 'Create student account' : 'Welcome back';
        authSubtitle.textContent = isRegister
            ? 'Register under your college and program. Your Program Chair must approve before you can sign in. You will receive an email with your temporary password once approved.'
            : (roleSubtitles[selectedRole] || '');
        document.querySelectorAll('.auth-switch-btn').forEach(function (btn) {
            btn.classList.toggle('active', btn.getAttribute('data-mode') === mode);
        });
    }

    function applyRoleAccent(role) {
        var accent = roleAccents[role] || roleAccents.student || { accent: '#4f46e5', soft: '#eef2ff', border: '#c7d2fe' };
        document.documentElement.style.setProperty('--portal-accent', accent.accent);
        document.documentElement.style.setProperty('--portal-accent-soft', accent.soft);
        document.documentElement.style.setProperty('--portal-accent-border', accent.border);
    }

    function setRole(role) {
        selectedRole = role;
        if (loginRoleField) loginRoleField.value = role;
        document.querySelectorAll('.role-btn').forEach(function (tab) {
            var active = tab.getAttribute('data-role') === role;
            tab.classList.toggle('active', active);
            tab.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        applyRoleAccent(role);
        if (studentAuthSwitch) {
            studentAuthSwitch.classList.toggle('hidden', role !== 'student');
        }
        if (role !== 'student' && viewMode === 'register') {
            setViewMode('login');
        } else if (viewMode === 'login') {
            authSubtitle.textContent = roleSubtitles[role] || '';
        }
    }

    if (roleTabs) {
        roleTabs.addEventListener('click', function (e) {
            var tab = e.target.closest('.role-btn');
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
        if (!yearLevelSelect) return;
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
            if (programSelect) programSelect.innerHTML = '<option value="">Select program</option>';
            yearLevelsByProgram = {};
            renderYearLevels('', '');
            return;
        }
        programSelect.innerHTML = '<option value="">Loading…</option>';
        if (yearLevelSelect) yearLevelSelect.innerHTML = '<option value="">Loading…</option>';
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

    var passwordInput = document.getElementById('password');
    if (passwordInput) {
        var wrapperDiv = document.createElement('div');
        wrapperDiv.className = 'password-wrapper';
        passwordInput.parentNode.insertBefore(wrapperDiv, passwordInput);
        wrapperDiv.appendChild(passwordInput);
        var toggleEye = document.createElement('i');
        toggleEye.className = 'far fa-eye-slash pwd-toggle';
        toggleEye.setAttribute('role', 'button');
        toggleEye.setAttribute('tabindex', '0');
        toggleEye.setAttribute('aria-label', 'Toggle password visibility');
        wrapperDiv.appendChild(toggleEye);
        var pwdVisible = false;
        toggleEye.addEventListener('click', function () {
            pwdVisible = !pwdVisible;
            passwordInput.type = pwdVisible ? 'text' : 'password';
            toggleEye.className = pwdVisible ? 'far fa-eye pwd-toggle' : 'far fa-eye-slash pwd-toggle';
        });
    }

    setRole(selectedRole);
    setViewMode(viewMode);

    var slideshow = document.getElementById('heroSlideshow');
    if (slideshow) {
        var slides = slideshow.querySelectorAll('.hero-slide');
        var dots = slideshow.querySelectorAll('.hero-slideshow-dot');
        var current = 0;
        var timer = null;
        var intervalMs = 5000;

        function showSlide(index) {
            if (!slides.length) return;
            current = (index + slides.length) % slides.length;
            slides.forEach(function (slide, i) {
                slide.classList.toggle('active', i === current);
            });
            dots.forEach(function (dot, i) {
                dot.classList.toggle('active', i === current);
                dot.setAttribute('aria-selected', i === current ? 'true' : 'false');
            });
        }

        function nextSlide() {
            showSlide(current + 1);
        }

        function resetTimer() {
            if (timer) clearInterval(timer);
            if (slides.length > 1) {
                timer = setInterval(nextSlide, intervalMs);
            }
        }

        dots.forEach(function (dot) {
            dot.addEventListener('click', function () {
                var idx = parseInt(dot.getAttribute('data-slide'), 10);
                if (!isNaN(idx)) {
                    showSlide(idx);
                    resetTimer();
                }
            });
        });

        resetTimer();
    }
})();
</script>
</body>
</html>

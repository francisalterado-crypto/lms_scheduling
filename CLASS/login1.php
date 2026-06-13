<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$usernameValue = '';
$hasAssignedProgram = false;
try {
    $colStmt = db()->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $colStmt->execute([DB_NAME, 'users', 'assigned_program']);
    $hasAssignedProgram = (int) $colStmt->fetchColumn() > 0;
} catch (Throwable $e) {
    $hasAssignedProgram = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $usernameValue = $username;
    $password = (string) ($_POST['password'] ?? '');
    if ($username === '' || $password === '') {
        $error = 'Please enter username and password.';
    } else {
        try {
            $stmt = db()->prepare(
                'SELECT u.id, u.username, u.password, u.full_name, u.role, '
                . ($hasAssignedProgram ? 'u.assigned_program' : '"" AS assigned_program') . ',
                 u.college_id, u.is_active, f.id AS faculty_id
                 FROM users u
                 LEFT JOIN faculty f ON f.user_id = u.id
                 WHERE u.username = ? LIMIT 1'
            );
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            if ($user && (int) $user['is_active'] === 1 && password_verify($password, $user['password'])) {
                $resolvedFacultyId = $user['faculty_id'] !== null ? (int) $user['faculty_id'] : null;
                $resolvedStudentId = null;
                if ((string) $user['role'] === 'faculty' && $resolvedFacultyId === null) {
                    $resolvedFacultyId = resolve_faculty_id_for_user((int) $user['id']);
                }
                if ((string) $user['role'] === 'student') {
                    $resolvedStudentId = resolve_student_id_for_user((int) $user['id']);
                }
                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['assigned_program'] = (string) ($user['assigned_program'] ?? '');
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
                header('Location: dashboard.php');
                exit;
            }
            $error = $user && (int) $user['is_active'] !== 1
                ? 'Your account is disabled. Please contact your dean/admin.'
                : 'Invalid username or password.';
        } catch (Throwable $e) {
            $prev = $e->getPrevious();
            $error = $e instanceof RuntimeException && $prev instanceof PDOException
                ? $e->getMessage()
                : 'Database error: ' . $e->getMessage();
        }
    }
}

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

        .seal-fallback {
            font-size: 3rem;
            color: #fff;
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
                            <span class="seal-fallback"><i class="fa-solid fa-building-columns"></i></span>
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
                                <span class="seal-fallback text-dark"><i class="fa-solid fa-building-columns"></i></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="login-kicker">Welcome back</div>
                    <h2 class="login-heading">Sign in to continue</h2>
                    <p class="login-subtitle">Access faculty schedules, reports, announcements, and academic management tools.</p>

                    <?php if ($error): ?>
                        <div class="alert alert-danger rounded-4"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <form method="post" autocomplete="on">
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

                    <p class="login-note text-center small mt-4 mb-0">First time setup? Run <a href="install.php">install.php</a> to create the database.</p>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

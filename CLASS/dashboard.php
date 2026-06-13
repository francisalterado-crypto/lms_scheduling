<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_login();

$role = (string) ($_SESSION['role'] ?? '');
$collegeId = current_college_id();
$programScope = is_program_chair() ? program_scope_or_fail() : null;
$today = date('l');
$isAdmin = $role === 'admin';
$autoRefreshSeconds = 30;
$stat1Label = 'Active faculty';
$stat2Label = 'Courses';
$stat3Label = 'Schedules';
$stat4Label = 'Open conflicts';

if ($isAdmin) {
    $activeFaculty = (int) db()->query("SELECT COUNT(*) FROM faculty WHERE status = 'active' AND college_id IS NOT NULL")->fetchColumn();
    $totalCourses = (int) db()->query('SELECT COUNT(*) FROM courses WHERE college_id IS NOT NULL')->fetchColumn();
    $totalSchedules = (int) db()->query('SELECT COUNT(*) FROM schedules WHERE college_id IS NOT NULL')->fetchColumn();
    $openConflicts = db_table_exists('conflict_requests')
        ? (int) db()->query("SELECT COUNT(*) FROM conflict_requests WHERE status = 'pending'")->fetchColumn()
        : 0;
} elseif (($role === 'dean' || $role === 'program_chair') && $collegeId) {
    $facultySql = "SELECT COUNT(*) FROM faculty WHERE status='active' AND college_id=?";
    $courseSql = "SELECT COUNT(*) FROM courses WHERE college_id=?";
    $scheduleSql = "SELECT COUNT(*) FROM schedules s INNER JOIN courses c ON c.id = s.course_id WHERE s.college_id=?";
    $params = [$collegeId];
    if ($programScope !== null) {
        $facultySql .= " AND department=?";
        $courseSql .= " AND department=?";
        $scheduleSql .= " AND c.department=?";
        $params[] = $programScope;
    }
    $st = db()->prepare($facultySql);
    $st->execute($params);
    $activeFaculty = (int) $st->fetchColumn();
    $st = db()->prepare($courseSql);
    $st->execute($params);
    $totalCourses = (int) $st->fetchColumn();
    $st = db()->prepare($scheduleSql);
    $st->execute($params);
    $totalSchedules = (int) $st->fetchColumn();
    if ($role === 'program_chair' && db_table_exists('schedule_change_requests')) {
        $st = db()->prepare(
            "SELECT COUNT(*) FROM schedule_change_requests scr
             INNER JOIN schedules s ON s.id = scr.schedule_id
             INNER JOIN courses c ON c.id = s.course_id
             WHERE s.college_id=? AND c.department=? AND scr.status='pending'"
        );
        $st->execute([$collegeId, $programScope]);
        $openConflicts = (int) $st->fetchColumn();
    } elseif ($role === 'program_chair') {
        $openConflicts = 0;
    } elseif (db_table_exists('schedule_change_requests')) {
        $st = db()->prepare(
            "SELECT COUNT(*) FROM schedule_change_requests scr INNER JOIN schedules s ON s.id = scr.schedule_id WHERE s.college_id=? AND scr.status='pending'"
        );
        $st->execute([$collegeId]);
        $openConflicts = (int) $st->fetchColumn();
    } else {
        $openConflicts = 0;
    }
} elseif ($role === 'gened') {
    $activeFaculty = (int) db()->query("SELECT COUNT(*) FROM faculty WHERE status='active' AND is_gened=1")->fetchColumn();
    $totalCourses = (int) db()->query("SELECT COUNT(*) FROM courses WHERE is_gened=1")->fetchColumn();
    $totalSchedules = (int) db()->query(
        "SELECT COUNT(*) FROM schedules s INNER JOIN courses c ON c.id=s.course_id WHERE c.is_gened=1"
    )->fetchColumn();
    $openConflicts = 0;
} elseif ($role === 'student') {
    $studentSelfId = isset($_SESSION['student_id']) ? (int) $_SESSION['student_id'] : 0;
    if ($studentSelfId < 1) {
        $studentSelfId = resolve_student_id_for_user((int) ($_SESSION['user_id'] ?? 0)) ?? 0;
        $_SESSION['student_id'] = $studentSelfId > 0 ? $studentSelfId : null;
    }

    $activeFaculty = 0;
    $totalCourses = 0;
    $totalSchedules = 0;
    $openConflicts = 0;
    $stat1Label = 'My classes';
    $stat2Label = 'Assessments';
    $stat3Label = 'Announcements';
    $stat4Label = 'Pending work';

    if ($studentSelfId > 0 && db_table_exists('classroom_enrollments')) {
        $st = db()->prepare('SELECT COUNT(*) FROM classroom_enrollments WHERE student_id = ?');
        $st->execute([$studentSelfId]);
        $activeFaculty = (int) $st->fetchColumn();

        if (db_table_exists('classroom_assessments')) {
            $st = db()->prepare(
                'SELECT COUNT(*)
                 FROM classroom_assessments ca
                 INNER JOIN classroom_enrollments ce ON ce.classroom_id = ca.classroom_id
                 WHERE ce.student_id = ?'
            );
            $st->execute([$studentSelfId]);
            $totalCourses = (int) $st->fetchColumn();
        }

        if (db_table_exists('classroom_content')) {
            $st = db()->prepare(
                'SELECT COUNT(*)
                 FROM classroom_content cc
                 INNER JOIN classroom_enrollments ce ON ce.classroom_id = cc.classroom_id
                 WHERE ce.student_id = ? AND cc.content_type = "announcement"'
            );
            $st->execute([$studentSelfId]);
            $totalSchedules = (int) $st->fetchColumn();
        }

        if (db_table_exists('classroom_submissions') && db_table_exists('classroom_assessments')) {
            $st = db()->prepare(
                'SELECT COUNT(*)
                 FROM classroom_assessments ca
                 INNER JOIN classroom_enrollments ce ON ce.classroom_id = ca.classroom_id
                 LEFT JOIN classroom_submissions sub
                   ON sub.assessment_id = ca.id AND sub.student_id = ce.student_id
                 WHERE ce.student_id = ? AND sub.id IS NULL'
            );
            $st->execute([$studentSelfId]);
            $openConflicts = (int) $st->fetchColumn();
        }
    }
} else {
    $activeFaculty = 0;
    $totalCourses = 0;
    $totalSchedules = 0;
    $openConflicts = 0;
}

$upSql = "SELECT s.start_time, s.end_time, f.full_name AS faculty_name, c.course_code, c.course_name, r.room_code
     FROM schedules s
     INNER JOIN faculty f ON f.id = s.faculty_id
     INNER JOIN courses c ON c.id = s.course_id
     INNER JOIN rooms r ON r.id = s.room_id
     WHERE FIND_IN_SET(?, s.day_of_week) > 0 AND s.start_time >= CURTIME()";
$upParams = [$today];
if ($isAdmin) {
    $upSql .= ' AND s.college_id IS NOT NULL';
}
if (($role === 'dean' || $role === 'program_chair') && $collegeId) {
    $upSql .= ' AND s.college_id = ?';
    $upParams[] = $collegeId;
}
if ($programScope !== null) {
    $upSql .= ' AND c.department = ?';
    $upParams[] = $programScope;
}
if ($role === 'faculty' && !empty($_SESSION['faculty_id'])) {
    $upSql .= ' AND s.faculty_id = ?';
    $upParams[] = (int) $_SESSION['faculty_id'];
}
if ($role === 'gened') {
    $upSql .= ' AND c.is_gened = 1';
}
$showUpcoming = $role !== 'student';
$upSql .= ' ORDER BY s.start_time ASC LIMIT 15';
$upStmt = db()->prepare($upSql);
$upStmt->execute($upParams);
$upcoming = $upStmt->fetchAll();

$formatTime12h = static function (?string $time): string {
    $raw = substr((string) $time, 0, 5);
    $dt = DateTime::createFromFormat('H:i', $raw);
    return $dt ? $dt->format('g:i A') : $raw;
};

$roleLabel = match ($role) {
    'admin' => 'Administrator',
    'dean' => 'Dean',
    'program_chair' => 'Program Chair',
    'gened' => 'General Education',
    'faculty' => 'Faculty',
    'student' => 'Student',
    default => 'User',
};

$heroMessage = match ($role) {
    'admin' => 'Monitor colleges, faculty activity, schedules, and system-wide academic coordination from one place.',
    'dean' => 'Stay on top of college schedules, faculty assignments, and pending coordination concerns with a smoother dashboard.',
    'program_chair' => 'Review your program workload, faculty activity, and schedule updates with a focused dashboard experience.',
    'gened' => 'Track General Education faculty, courses, and schedules with a cleaner and more polished view.',
    'faculty' => 'Manage your assigned classes, run online sessions, and work within the courses officially assigned to your faculty account.',
    'student' => 'Access your enrolled online classes, read announcements, submit assessments, and keep up with graded work in one place.',
    default => 'Manage your academic scheduling tasks with a streamlined dashboard.',
};

$sealDataUri = '';
$sealCandidates = [
    __DIR__ . '/assets/images/wpu-seal.png',
    'C:\Users\wpu-0\.cursor\projects\c-xampp-htdocs-CLASS\assets\c__Users_wpu-0_AppData_Roaming_Cursor_User_workspaceStorage_f94ec644ac39e85d4f1f3f887fe3104f_images_A_LOGO-58c392b9-fcf1-44cf-828c-95229f4ba88a.png',
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

$pageTitle = 'Dashboard';
require_once __DIR__ . '/includes/header.php';
?>
<style>
    .dashboard-shell {
        --dash-deep: #0c2435;
        --dash-green: #1f6f43;
        --dash-soft: #f5f8f6;
        --dash-border: #dbe7e0;
    }

    .dashboard-hero {
        position: relative;
        overflow: hidden;
        border: 0;
        border-radius: 28px;
        background:
            radial-gradient(circle at top right, rgba(255,255,255,0.22), transparent 22%),
            linear-gradient(135deg, #0a2235 0%, #143a4d 48%, #1f6f43 100%);
        box-shadow: 0 22px 55px rgba(12, 36, 53, 0.18);
    }

    .dashboard-hero::after {
        content: "";
        position: absolute;
        right: -40px;
        bottom: -60px;
        width: 220px;
        height: 220px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.08);
    }

    .dashboard-hero .card-body {
        padding: 2rem;
    }

    .hero-chip {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 14px;
        border-radius: 999px;
        color: #fff;
        background: rgba(255,255,255,0.12);
        border: 1px solid rgba(255,255,255,0.16);
        font-size: 0.82rem;
        text-transform: uppercase;
        letter-spacing: 0.06em;
    }

    .hero-title {
        color: #fff;
        font-size: clamp(1.7rem, 3vw, 2.6rem);
        font-weight: 700;
        line-height: 1.15;
        margin: 0.9rem 0 0.65rem;
    }

    .hero-text {
        color: rgba(255,255,255,0.8);
        max-width: 700px;
        margin-bottom: 0;
    }

    .hero-seal-wrap {
        width: 138px;
        height: 138px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: rgba(255,255,255,0.12);
        border: 1px solid rgba(255,255,255,0.2);
        box-shadow: 0 18px 40px rgba(0,0,0,0.18);
    }

    .hero-seal-wrap img {
        width: 120px;
        height: 120px;
        object-fit: contain;
        border-radius: 50%;
        background: #fff;
        padding: 5px;
    }

    .hero-seal-fallback {
        color: #fff;
        font-size: 3rem;
    }

    .dashboard-refresh {
        color: rgba(255,255,255,0.82);
    }

    .dashboard-refresh .btn {
        border-radius: 999px;
    }

    .dash-stat {
        border: 0;
        border-radius: 22px;
        overflow: hidden;
        box-shadow: 0 16px 34px rgba(15, 23, 42, 0.08);
    }

    .dash-stat .card-body {
        padding: 1.25rem 1.3rem;
    }

    .dash-stat-label {
        font-size: 0.92rem;
        opacity: 0.82;
    }

    .dash-stat-value {
        font-size: 2rem;
        font-weight: 700;
        line-height: 1.1;
    }

    .glass-card {
        border: 1px solid var(--dash-border);
        border-radius: 24px;
        overflow: hidden;
        box-shadow: 0 16px 36px rgba(15, 23, 42, 0.06);
    }

    .glass-card .card-header {
        background: linear-gradient(180deg, rgba(255,255,255,0.96), rgba(247,249,248,0.96));
        border-bottom: 1px solid var(--dash-border);
        padding: 1rem 1.25rem;
    }

    .upcoming-table thead th {
        background: #f5f8f6;
        border-bottom-width: 1px;
    }

    .upcoming-table tbody tr:hover {
        background: #f8fbf9;
    }

    .conflict-card {
        border: 1px solid rgba(255, 193, 7, 0.35);
        border-radius: 24px;
        box-shadow: 0 16px 36px rgba(255, 193, 7, 0.08);
    }

    .conflict-card .card-header {
        background: linear-gradient(180deg, rgba(255, 193, 7, 0.12), rgba(255, 255, 255, 0.8));
        border-bottom: 1px solid rgba(255, 193, 7, 0.2);
        padding: 1rem 1.25rem;
    }

    @media (max-width: 991.98px) {
        .dashboard-hero .card-body {
            padding: 1.5rem;
        }

        .hero-seal-wrap {
            width: 110px;
            height: 110px;
        }

        .hero-seal-wrap img {
            width: 96px;
            height: 96px;
        }
    }
</style>

<div class="dashboard-shell">
    <div class="card dashboard-hero text-white mb-4">
        <div class="card-body">
            <div class="row align-items-center g-4">
                <div class="col-lg-8">
                    <div class="hero-chip">
                        <i class="fa-solid fa-gauge-high"></i>
                        <?= htmlspecialchars($roleLabel) ?> dashboard
                    </div>
                    <h1 class="hero-title"><?= $role === 'student' ? 'Western Philippines University LMS Dashboard' : 'Western Philippines University Scheduling Dashboard' ?></h1>
                    <p class="hero-text"><?= htmlspecialchars($heroMessage) ?></p>
                </div>
                <div class="col-lg-4 text-lg-end">
                    <div class="hero-seal-wrap">
                        <?php if ($sealDataUri !== ''): ?>
                            <img src="<?= htmlspecialchars($sealDataUri) ?>" alt="Western Philippines University seal">
                        <?php else: ?>
                            <img src="assets/logo.png" alt="Western Philippines University seal">
                        <?php endif; ?>
                    </div>
                    <div class="dashboard-refresh mt-3 text-lg-end">
                        <div class="small no-print"><i class="fa-regular fa-calendar me-1"></i><?= htmlspecialchars(date('l, F j, Y')) ?></div>
                        <div class="small no-print mt-1">
                            <span id="autoRefreshLabel">Auto refresh every <?= $autoRefreshSeconds ?>s</span>
                            <button type="button" class="btn btn-sm btn-outline-light ms-2 py-0 px-3" id="toggleAutoRefresh"<?= app_tooltip_attr('Pauses or resumes automatic dashboard refresh. Use this to stop the page reloading while you read numbers or links.') ?>>Pause</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card dash-stat h-100 bg-primary text-white">
            <div class="card-body">
                <div class="dash-stat-label"><?= htmlspecialchars($stat1Label) ?></div>
                <div class="dash-stat-value"><?= $activeFaculty ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card dash-stat h-100 bg-success text-white">
            <div class="card-body">
                <div class="dash-stat-label"><?= htmlspecialchars($stat2Label) ?></div>
                <div class="dash-stat-value"><?= $totalCourses ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card dash-stat h-100 bg-secondary text-white">
            <div class="card-body">
                <div class="dash-stat-label"><?= htmlspecialchars($stat3Label) ?></div>
                <div class="dash-stat-value"><?= $totalSchedules ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card dash-stat h-100 bg-warning text-dark">
            <div class="card-body">
                <div class="dash-stat-label"><?= htmlspecialchars($stat4Label) ?></div>
                <div class="dash-stat-value"><?= $openConflicts ?></div>
            </div>
        </div>
    </div>
</div>

<?php if ($role === 'faculty'): ?>
    <div class="card glass-card mb-4">
        <div class="card-header">
            <i class="fa-solid fa-user-check me-2 text-primary"></i>Faculty Account Access
        </div>
        <div class="card-body">
            <ul class="mb-0">
                <li>Can create online classrooms for assigned courses only.</li>
                <li>Can add or enroll students to online classes.</li>
                <li>Can manage course content, assignments, quizzes, and grades.</li>
                <li>Can conduct live video sessions using a Google Meet link.</li>
                <li>Cannot access courses that are not assigned to the faculty account.</li>
            </ul>
            <div class="mt-3 d-flex flex-wrap gap-2">
                <a href="faculty_classrooms.php" class="btn btn-primary btn-sm"<?= app_tooltip_attr('Opens your online classrooms for assigned courses. Use this to manage materials, Meet links, and student access.') ?>><i class="fa-solid fa-chalkboard me-1"></i>Open Classrooms</a>
                <a href="faculty_schedule.php" class="btn btn-outline-primary btn-sm"<?= app_tooltip_attr('Opens your teaching timetable and online class links. Use this to confirm times and join sessions.') ?>><i class="fa-solid fa-calendar-check me-1"></i>Open My Schedule</a>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($role === 'student'): ?>
    <div class="card glass-card mb-4">
        <div class="card-header">
            <i class="fa-solid fa-user-graduate me-2 text-primary"></i>Student Access
        </div>
        <div class="card-body">
            <ul class="mb-0">
                <li>View only the classes where your student account is enrolled.</li>
                <li>Read classroom announcements and course materials from your instructors.</li>
                <li>Answer assignments and quizzes using your student login.</li>
                <li>Review grades and feedback once your instructor evaluates your work.</li>
                <li>Cannot access classrooms or assessments where you are not enrolled.</li>
            </ul>
            <div class="mt-3 d-flex flex-wrap gap-2">
                <a href="student_classrooms.php" class="btn btn-primary btn-sm"<?= student_tooltip_attr('Opens the list of online classes you are enrolled in. Use this to join Meet, open materials, or submit work.') ?>><i class="fa-solid fa-user-graduate me-1"></i>Open My Classes</a>
                <a href="settings.php" class="btn btn-outline-primary btn-sm"<?= student_tooltip_attr('Opens account settings to change your password. Use this when your password expires or you want a stronger one.') ?>><i class="fa-solid fa-key me-1"></i>Change Password</a>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card glass-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa-solid fa-clock me-2 text-primary"></i><?= $showUpcoming ? 'Upcoming classes today (' . htmlspecialchars($today) . ')' : 'Student overview' ?></span>
            </div>
            <div class="card-body p-0">
                <?php if (!$showUpcoming): ?>
                    <div class="p-3">
                        <p class="text-muted mb-2">Use your student account to open enrolled classrooms, receive announcements, and submit assessment answers.</p>
                        <a href="student_classrooms.php" class="btn btn-outline-primary btn-sm"<?= student_tooltip_attr('Opens your enrolled online classes. Use this shortcut when the overview reminds you to check announcements or assessments.') ?>>Go to My Classes</a>
                    </div>
                <?php elseif (!$upcoming): ?>
                    <p class="text-muted p-3 mb-0">No more classes scheduled for today after the current time.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover upcoming-table mb-0">
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>Course</th>
                                    <th>Faculty</th>
                                    <th>Room</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($upcoming as $u): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($formatTime12h((string) ($u['start_time'] ?? ''))) ?> – <?= htmlspecialchars($formatTime12h((string) ($u['end_time'] ?? ''))) ?></td>
                                        <td><strong><?= htmlspecialchars($u['course_code']) ?></strong> <?= htmlspecialchars($u['course_name']) ?></td>
                                        <td><?= htmlspecialchars($u['faculty_name']) ?></td>
                                        <td><?= htmlspecialchars($u['room_code']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card conflict-card">
            <div class="card-header">
                <i class="fa-solid fa-triangle-exclamation me-2 text-warning"></i>Conflict alerts
            </div>
            <div class="card-body">
                <?php if ($openConflicts === 0): ?>
                    <p class="text-success mb-0"><i class="fa-solid fa-check-circle me-1"></i>No pending alerts.</p>
                <?php else: ?>
                    <p class="mb-2">You have <strong><?= $openConflicts ?></strong> pending item(s).</p>
                    <a href="conflicts.php" class="btn btn-outline-warning btn-sm"<?= app_tooltip_attr($role === 'student' ? 'Opens the staff conflicts review page. If you are a student and see an error, use My Classes to finish pending assessments shown in your dashboard counts.' : 'Opens the conflicts list to approve, reject, or resolve pending schedule coordination items.') ?>>Review conflicts</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</div>

<script>
    (function () {
        const seconds = <?= (int) $autoRefreshSeconds ?>;
        const toggleBtn = document.getElementById('toggleAutoRefresh');
        const label = document.getElementById('autoRefreshLabel');
        const key = 'dashboardAutoRefreshEnabled';
        let enabled = localStorage.getItem(key);
        enabled = enabled === null ? true : enabled === '1';

        function render() {
            if (!toggleBtn || !label) return;
            label.textContent = enabled ? `Auto refresh every ${seconds}s` : 'Auto refresh paused';
            toggleBtn.textContent = enabled ? 'Pause' : 'Resume';
        }

        if (toggleBtn) {
            toggleBtn.addEventListener('click', function () {
                enabled = !enabled;
                localStorage.setItem(key, enabled ? '1' : '0');
                render();
            });
        }

        render();
        setInterval(function () {
            if (!enabled || document.hidden) return;
            window.location.reload();
        }, seconds * 1000);
    })();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

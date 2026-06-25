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
$superStats = [
    'totalSchedulesAllTime' => 0,
    'collegesDepartments' => 0,
    'facultyMembers' => 0,
    'studentEnrollments' => 0,
    'inactivePendingInvites' => 0,
    'activeConflictAlerts' => 0,
    'resolvedConflicts30d' => 0,
    'roomDoubleBookingRisks' => 0,
    'facultyTimeOverlap' => 0,
    'scheduledClassesCurrentTerm' => 0,
    'unassignedTimeSlots' => 0,
    'superAdminLastLogin' => 'N/A',
];

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
} elseif ($role === 'super_admin') {
    $activeFaculty = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    $totalCourses = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
    $totalSchedules = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 0")->fetchColumn();
    $openConflicts = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'super_admin'")->fetchColumn();
    $stat1Label = 'Administrator accounts';
    $stat2Label = 'Active admins';
    $stat3Label = 'Disabled admins';
    $stat4Label = 'Super Admin users';

    $superStats['totalSchedulesAllTime'] = db_table_exists('schedules')
        ? (int) db()->query('SELECT COUNT(*) FROM schedules')->fetchColumn()
        : 0;
    $superStats['facultyMembers'] = db_table_exists('faculty')
        ? (int) db()->query('SELECT COUNT(*) FROM faculty')->fetchColumn()
        : 0;
    $superStats['collegesDepartments'] = db_table_exists('colleges')
        ? (int) db()->query('SELECT COUNT(*) FROM colleges')->fetchColumn()
        : 0;
    $superStats['studentEnrollments'] = db_table_exists('classroom_enrollments')
        ? (int) db()->query('SELECT COUNT(*) FROM classroom_enrollments')->fetchColumn()
        : 0;
    $superStats['inactivePendingInvites'] = db_table_exists('users')
        ? (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 0")->fetchColumn()
        : 0;
    $superStats['activeConflictAlerts'] = db_table_exists('conflict_requests')
        ? (int) db()->query("SELECT COUNT(*) FROM conflict_requests WHERE status = 'pending'")->fetchColumn()
        : 0;
    if (db_table_exists('conflict_requests')) {
        $resolvedDateColumn = db_first_existing_column(
            'conflict_requests',
            ['updated_at', 'reviewed_at', 'created_at'],
            'created_at'
        );
        $superStats['resolvedConflicts30d'] = (int) db()->query(
            "SELECT COUNT(*) FROM conflict_requests WHERE status = 'resolved' AND {$resolvedDateColumn} >= (NOW() - INTERVAL 30 DAY)"
        )->fetchColumn();
    } else {
        $superStats['resolvedConflicts30d'] = 0;
    }
    if (db_table_exists('conflict_requests') && db_column_exists('conflict_requests', 'conflict_type')) {
        $superStats['roomDoubleBookingRisks'] = (int) db()->query(
            "SELECT COUNT(*) FROM conflict_requests WHERE status = 'pending' AND conflict_type LIKE '%room%'"
        )->fetchColumn();
        $superStats['facultyTimeOverlap'] = (int) db()->query(
            "SELECT COUNT(*) FROM conflict_requests WHERE status = 'pending' AND conflict_type LIKE '%faculty%'"
        )->fetchColumn();
    } else {
        $superStats['roomDoubleBookingRisks'] = 0;
        $superStats['facultyTimeOverlap'] = 0;
    }
    $superStats['scheduledClassesCurrentTerm'] = db_table_exists('schedules')
        ? (int) db()->query('SELECT COUNT(*) FROM schedules')->fetchColumn()
        : 0;
    $superStats['unassignedTimeSlots'] = db_table_exists('rooms')
        ? max(0, ((int) db()->query('SELECT COUNT(*) FROM rooms')->fetchColumn() * 60) - $superStats['scheduledClassesCurrentTerm'])
        : 0;
    if (db_table_exists('users')) {
        $lastLoginColumn = db_first_existing_column(
            'users',
            ['last_login', 'last_seen_at', 'updated_at', 'created_at'],
            ''
        );
        if ($lastLoginColumn !== '') {
            $lastLoginRaw = db()->query(
                "SELECT {$lastLoginColumn} FROM users WHERE role = 'super_admin' ORDER BY {$lastLoginColumn} DESC LIMIT 1"
            )->fetchColumn();
            if (is_string($lastLoginRaw) && $lastLoginRaw !== '') {
                $stamp = strtotime($lastLoginRaw);
                if ($stamp !== false) {
                    $superStats['superAdminLastLogin'] = date('M j, Y g:i A', $stamp);
                }
            }
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
$showUpcoming = $role !== 'student' && $role !== 'super_admin';
$upSql .= ' ORDER BY s.start_time ASC LIMIT 15';
$upcoming = [];
if ($showUpcoming) {
    $upStmt = db()->prepare($upSql);
    $upStmt->execute($upParams);
    $upcoming = $upStmt->fetchAll();
}

$dashboardOverviewTitle = $showUpcoming
    ? 'Upcoming classes today (' . htmlspecialchars($today) . ')'
    : ($role === 'super_admin'
        ? 'Super Admin workspace'
        : 'Student overview');

$formatTime12h = static function (?string $time): string {
    $raw = substr((string) $time, 0, 5);
    $dt = DateTime::createFromFormat('H:i', $raw);
    return $dt ? $dt->format('g:i A') : $raw;
};

$roleLabel = match ($role) {
    'super_admin' => 'Super Administrator',
    'admin' => 'Administrator',
    'dean' => 'Dean',
    'program_chair' => 'Program Chair',
    'gened' => 'General Education',
    'faculty' => 'Faculty',
    'student' => 'Student',
    default => 'User',
};

$heroMessage = match ($role) {
    'super_admin' => 'Provision System Administrator accounts and review high-level access. Day-to-day scheduling tools remain on each administrator’s own login.',
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
<?php if ($role === 'super_admin'): ?>
    <style>
        .sa-container {
            background: #f0f2f8;
            border-radius: 28px;
            padding: 28px 24px;
        }

        .sa-header h1 {
            font-size: 28px;
            font-weight: 700;
            background: linear-gradient(135deg, #0b2b3b 0%, #1a4a6f 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            letter-spacing: -0.3px;
            margin-bottom: 8px;
        }

        .sa-sub {
            color: #2c5a6e;
            font-weight: 500;
            font-size: 15px;
            border-left: 3px solid #c9772e;
            padding-left: 12px;
            margin-bottom: 24px;
        }

        .sa-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 18px;
            margin-bottom: 26px;
        }

        .sa-stat-card,
        .sa-insight-card,
        .sa-footer-alerts {
            background: #fff;
            border-radius: 24px;
            border: 1px solid #e9eef5;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.04);
        }

        .sa-stat-card {
            padding: 18px;
        }

        .sa-stat-title {
            font-size: 13px;
            text-transform: uppercase;
            color: #5b6e8c;
            font-weight: 600;
            letter-spacing: 0.4px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .sa-badge {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #eef2fa;
        }

        .sa-stat-number {
            font-size: 34px;
            line-height: 1.1;
            font-weight: 800;
            color: #1e2f41;
        }

        .sa-stat-trend {
            font-size: 13px;
            color: #63809c;
            margin-top: 6px;
        }

        .sa-insight-row {
            display: flex;
            flex-wrap: wrap;
            gap: 18px;
            margin-bottom: 20px;
        }

        .sa-insight-card {
            flex: 1;
            min-width: 260px;
            padding: 20px;
        }

        .sa-insight-card h3 {
            font-size: 17px;
            color: #1e3a4d;
            border-left: 4px solid #c9772e;
            padding-left: 12px;
            margin-bottom: 14px;
        }

        .sa-compact-row {
            display: flex;
            justify-content: space-between;
            border-bottom: 1px solid #edf2f7;
            padding: 10px 0;
            gap: 8px;
        }

        .sa-compact-label {
            color: #436278;
            font-weight: 500;
            font-size: 14px;
        }

        .sa-compact-value {
            color: #173e54;
            font-weight: 700;
            font-size: 18px;
            text-align: right;
        }

        .sa-note {
            font-size: 12px;
            color: #7a8eaa;
            margin-top: 12px;
            background: #f8fafd;
            border-radius: 12px;
            padding: 10px 12px;
        }

        .sa-action-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            margin-bottom: 18px;
        }

        .sa-btn {
            border: 1px solid #cfdfed;
            background: #fff;
            color: #1e4660;
            border-radius: 999px;
            padding: 10px 22px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }

        .sa-btn-dark {
            background: #1c2f3f;
            color: #fff;
            border: 0;
        }

        .sa-btn-super {
            border: 0;
            color: #fff;
            background: linear-gradient(95deg, #2c5a6e 0%, #1e3f50 100%);
        }

        .sa-role-badge {
            background: #e9eff5;
            border-radius: 999px;
            padding: 6px 14px;
            font-size: 12px;
            font-family: monospace;
        }

        .sa-footer-alerts {
            padding: 16px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            justify-content: space-between;
        }

        .sa-conflict-badge {
            background: #fff2e6;
            border-left: 4px solid #e67e22;
            border-radius: 999px;
            padding: 8px 14px;
            color: #b95a0c;
            font-weight: 600;
            font-size: 14px;
        }

        .sa-footer-note {
            color: #4b6f8c;
            font-size: 13px;
            background: #f5f9ff;
            border-radius: 999px;
            padding: 8px 14px;
        }
    </style>
    <div class="sa-container" id="saContainer">
        <div class="sa-header">
            <h1>Dashboard - Western Philippines University</h1>
            <div class="sa-sub">Super Administrator Control Center - High-level analytics and system oversight</div>
        </div>

        <div class="sa-stats-grid">
            <div class="sa-stat-card"><div class="sa-stat-title"><span class="sa-badge">👥</span>Administrator accounts</div><div class="sa-stat-number"><?= number_format($activeFaculty) ?></div><div class="sa-stat-trend">Total registered system admins</div></div>
            <div class="sa-stat-card"><div class="sa-stat-title"><span class="sa-badge">🟢</span>Active admins</div><div class="sa-stat-number"><?= number_format($totalCourses) ?></div><div class="sa-stat-trend">Currently active</div></div>
            <div class="sa-stat-card"><div class="sa-stat-title"><span class="sa-badge">⛔</span>Disabled admins</div><div class="sa-stat-number"><?= number_format($totalSchedules) ?></div><div class="sa-stat-trend">Locked or disabled accounts</div></div>
            <div class="sa-stat-card"><div class="sa-stat-title"><span class="sa-badge">⭐</span>Super Admin users</div><div class="sa-stat-number"><?= number_format($openConflicts) ?></div><div class="sa-stat-trend">Ultimate privilege level</div></div>
            <div class="sa-stat-card"><div class="sa-stat-title"><span class="sa-badge">📅</span>Total schedules</div><div class="sa-stat-number"><?= number_format($superStats['totalSchedulesAllTime']) ?></div><div class="sa-stat-trend">All-time schedule records</div></div>
            <div class="sa-stat-card"><div class="sa-stat-title"><span class="sa-badge">🏫</span>Colleges / Departments</div><div class="sa-stat-number"><?= number_format($superStats['collegesDepartments']) ?></div><div class="sa-stat-trend">Configured academic units</div></div>
            <div class="sa-stat-card"><div class="sa-stat-title"><span class="sa-badge">👩‍🏫</span>Faculty members</div><div class="sa-stat-number"><?= number_format($superStats['facultyMembers']) ?></div><div class="sa-stat-trend">Total faculty records</div></div>
            <div class="sa-stat-card"><div class="sa-stat-title"><span class="sa-badge">🎓</span>Student enrollments</div><div class="sa-stat-number"><?= number_format($superStats['studentEnrollments']) ?></div><div class="sa-stat-trend">Across online classrooms</div></div>
        </div>

        <div class="sa-insight-row">
            <div class="sa-insight-card">
                <h3>Admin and Access Analytics</h3>
                <div class="sa-compact-row"><span class="sa-compact-label">System admin accounts (provisioned)</span><span class="sa-compact-value"><?= number_format($activeFaculty) ?></span></div>
                <div class="sa-compact-row"><span class="sa-compact-label">Super admin power users</span><span class="sa-compact-value"><?= number_format($openConflicts) ?></span></div>
                <div class="sa-compact-row"><span class="sa-compact-label">Inactive or pending invites</span><span class="sa-compact-value"><?= number_format($superStats['inactivePendingInvites']) ?></span></div>
                <div class="sa-compact-row"><span class="sa-compact-label">Last login (Super Admin)</span><span class="sa-compact-value"><?= htmlspecialchars($superStats['superAdminLastLogin']) ?></span></div>
                <div class="sa-note">Super Admin role is strictly for system-wide configuration. Day-to-day scheduling tools require standard admin login.</div>
            </div>
            <div class="sa-insight-card">
                <h3>Conflict and Scheduling Overview</h3>
                <div class="sa-compact-row"><span class="sa-compact-label">Active conflict alerts (pending)</span><span class="sa-compact-value" style="color:#c96b1a;"><?= number_format($superStats['activeConflictAlerts']) ?></span></div>
                <div class="sa-compact-row"><span class="sa-compact-label">Resolved conflicts (last 30d)</span><span class="sa-compact-value"><?= number_format($superStats['resolvedConflicts30d']) ?></span></div>
                <div class="sa-compact-row"><span class="sa-compact-label">Room double-booking risks</span><span class="sa-compact-value"><?= number_format($superStats['roomDoubleBookingRisks']) ?></span></div>
                <div class="sa-compact-row"><span class="sa-compact-label">Faculty time overlap</span><span class="sa-compact-value"><?= number_format($superStats['facultyTimeOverlap']) ?></span></div>
                <div class="sa-note">Super Admin has no conflict queue by design. Switch to an Administrator account to manage conflicts and daily scheduling.</div>
            </div>
            <div class="sa-insight-card">
                <h3>System Utilization</h3>
                <div class="sa-compact-row"><span class="sa-compact-label">Total room usage (weekly avg)</span><span class="sa-compact-value">N/A</span></div>
                <div class="sa-compact-row"><span class="sa-compact-label">Scheduled classes (current term)</span><span class="sa-compact-value"><?= number_format($superStats['scheduledClassesCurrentTerm']) ?></span></div>
                <div class="sa-compact-row"><span class="sa-compact-label">Unassigned time slots</span><span class="sa-compact-value"><?= number_format($superStats['unassignedTimeSlots']) ?></span></div>
                <div class="sa-compact-row"><span class="sa-compact-label">Peak hour load (10 AM - 12 PM)</span><span class="sa-compact-value">N/A</span></div>
                <div class="sa-note">Utilization indicators improve as room, term, and conflict data become more complete.</div>
            </div>
        </div>

        <div class="sa-action-bar">
            <button type="button" class="sa-btn sa-btn-dark" id="darkModeToggleBtn">Dark</button>
            <button type="button" class="sa-btn sa-btn-super" id="superAdminInfoBtn">Super Administrator - SUPER_ADMIN</button>
            <span class="sa-role-badge">Elevated session - Full system audit rights</span>
        </div>

        <div class="sa-footer-alerts">
            <div class="sa-conflict-badge">Conflict alerts - <?= number_format($superStats['activeConflictAlerts']) ?> pending resolution</div>
            <div class="sa-footer-note">Super Admin is only for Administrator accounts (system admins). Not a student account.</div>
            <div class="sa-footer-note">No conflict queue for Super Admin. Use Administrator accounts for day-to-day scheduling tools.</div>
        </div>
    </div>
<?php else: ?>
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
                <a href="student_edutools.php" class="btn btn-outline-primary btn-sm"<?= student_tooltip_attr('Opens EduTools: notebook, ChatGPT, Khan Academy, and other study helpers.') ?>><i class="fa-solid fa-wand-magic-sparkles me-1"></i>Learning tools</a>
                <a href="settings.php" class="btn btn-outline-secondary btn-sm"<?= student_tooltip_attr('Opens account settings to change your password. Use this when your password expires or you want a stronger one.') ?>><i class="fa-solid fa-key me-1"></i>Change Password</a>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card glass-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa-solid fa-clock me-2 text-primary"></i><?= $dashboardOverviewTitle ?></span>
            </div>
            <div class="card-body p-0">
                <?php if ($role === 'student' && !$showUpcoming): ?>
                    <div class="p-3">
                        <p class="text-muted mb-2">Use your student account to open enrolled classrooms, receive announcements, and submit assessment answers.</p>
                        <a href="student_classrooms.php" class="btn btn-outline-primary btn-sm"<?= student_tooltip_attr('Opens your enrolled online classes. Use this shortcut when the overview reminds you to check announcements or assessments.') ?>>Go to My Classes</a>
                    </div>
                <?php elseif ($role === 'super_admin'): ?>
                    <div class="p-3">
                        <p class="text-muted mb-2">Super Admin is only for <strong>Administrator accounts</strong> (system admins). This is not a student account — do not use My Classes.</p>
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
                <?php if ($role === 'super_admin'): ?>
                    <p class="text-muted small mb-0">Super Admin has no conflict queue. Use <strong>Administrator accounts</strong> or sign in as an <code>admin</code> user for day-to-day scheduling tools.</p>
                <?php elseif ($openConflicts === 0): ?>
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
<?php endif; ?>

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

    (function () {
        const darkToggle = document.getElementById('darkModeToggleBtn');
        const superAdminBtn = document.getElementById('superAdminInfoBtn');
        const container = document.getElementById('saContainer');
        if (!darkToggle || !superAdminBtn || !container) return;

        function applyDarkMode(isDark) {
            const cards = container.querySelectorAll('.sa-stat-card, .sa-insight-card, .sa-footer-alerts');
            if (isDark) {
                document.body.style.backgroundColor = '#121826';
                container.style.backgroundColor = '#121826';
                cards.forEach(function (el) {
                    el.style.backgroundColor = '#1e2a36';
                    el.style.borderColor = '#2d3e4e';
                });
            } else {
                document.body.style.backgroundColor = '#f0f2f8';
                container.style.backgroundColor = '#f0f2f8';
                cards.forEach(function (el) {
                    el.style.backgroundColor = '#ffffff';
                    el.style.borderColor = '#e9eef5';
                });
            }
        }

        let darkModeEnabled = localStorage.getItem('wpu_dark_mode') === 'true';
        applyDarkMode(darkModeEnabled);
        darkToggle.addEventListener('click', function () {
            darkModeEnabled = !darkModeEnabled;
            applyDarkMode(darkModeEnabled);
            localStorage.setItem('wpu_dark_mode', darkModeEnabled ? 'true' : 'false');
        });

        superAdminBtn.addEventListener('click', function () {
            const existingToast = document.querySelector('.super-toast-msg');
            if (existingToast) existingToast.remove();
            const toast = document.createElement('div');
            toast.className = 'super-toast-msg';
            toast.style.position = 'fixed';
            toast.style.bottom = '28px';
            toast.style.left = '50%';
            toast.style.transform = 'translateX(-50%)';
            toast.style.zIndex = '9999';
            toast.innerHTML = '<div style="background:#1f3e4b;color:#f9e0b0;padding:12px 20px;border-radius:60px;font-weight:500;box-shadow:0 20px 32px -10px rgba(0,0,0,0.2);">SUPER_ADMIN role: full system audit and account provisioning. Use standard admin login for daily scheduling.</div>';
            document.body.appendChild(toast);
            setTimeout(function () {
                if (toast && toast.remove) toast.remove();
            }, 5000);
        });
    })();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

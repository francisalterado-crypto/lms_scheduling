<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_role(['faculty', 'program_chair', 'dean', 'gened']);

$facultyId = isset($_SESSION['faculty_id']) ? (int) $_SESSION['faculty_id'] : 0;
$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($facultyId < 1) {
    $facultyId = resolve_faculty_id_for_user($userId) ?? 0;
    $_SESSION['faculty_id'] = $facultyId > 0 ? $facultyId : null;
}
if ($facultyId < 1 && in_array($_SESSION['role'] ?? '', ['program_chair', 'dean', 'gened'], true)) {
    $facultyId = ensure_faculty_profile_for_teaching_role($userId) ?? 0;
    if ($facultyId > 0) {
        $_SESSION['faculty_id'] = $facultyId;
    }
}
if ($facultyId < 1) {
    exit('Faculty profile not linked to this account. Ask your dean to create/link your faculty profile.');
}

$classroomId = (int) ($_GET['id'] ?? 0);
$search = trim((string) ($_GET['q'] ?? ''));

$requiredTables = [
    'online_classrooms',
    'classroom_enrollments',
    'classroom_students',
];
$missingTables = array_values(array_filter(
    $requiredTables,
    static fn (string $table): bool => !db_table_exists($table)
));
$hasAttendance = db_table_exists('classroom_attendance_sessions')
    && db_table_exists('classroom_attendance_records');

$classrooms = [];
$classroom = null;
$students = [];
$totals = [
    'enrolled' => 0,
    'present' => 0,
    'absent' => 0,
    'sessions' => 0,
];

if ($missingTables === []) {
    $attendanceSelect = $hasAttendance
        ? ", (SELECT COUNT(*) FROM classroom_attendance_records ar
                INNER JOIN classroom_attendance_sessions cas ON cas.id = ar.session_id
                WHERE cas.classroom_id = oc.id AND ar.status = 'present') AS present_count,
           (SELECT COUNT(*) FROM classroom_attendance_records ar
                INNER JOIN classroom_attendance_sessions cas ON cas.id = ar.session_id
                WHERE cas.classroom_id = oc.id AND ar.status = 'absent') AS absent_count,
           (SELECT COUNT(*) FROM classroom_attendance_sessions cas WHERE cas.classroom_id = oc.id) AS session_count"
        : ', 0 AS present_count, 0 AS absent_count, 0 AS session_count';

    $st = db()->prepare(
        "SELECT oc.id, oc.title, oc.status, s.semester, s.school_year, s.day_of_week, s.start_time, s.end_time,
                c.course_code, c.course_name,
                (SELECT COUNT(*) FROM classroom_enrollments ce WHERE ce.classroom_id = oc.id) AS enrolled_count
                {$attendanceSelect}
         FROM online_classrooms oc
         INNER JOIN schedules s ON s.id = oc.schedule_id
         INNER JOIN courses c ON c.id = oc.course_id
         WHERE oc.faculty_id = ? AND s.faculty_id = ?
         ORDER BY s.school_year DESC, s.semester, c.course_code, oc.title"
    );
    $st->execute([$facultyId, $facultyId]);
    $classrooms = $st->fetchAll();

    foreach ($classrooms as $row) {
        $totals['enrolled'] += (int) ($row['enrolled_count'] ?? 0);
        $totals['present'] += (int) ($row['present_count'] ?? 0);
        $totals['absent'] += (int) ($row['absent_count'] ?? 0);
        $totals['sessions'] += (int) ($row['session_count'] ?? 0);
    }

    if ($classroomId > 0) {
        foreach ($classrooms as $row) {
            if ((int) $row['id'] === $classroomId) {
                $classroom = $row;
                break;
            }
        }

        if ($classroom === null) {
            http_response_code(404);
            exit('Classroom not found or you do not have access to it.');
        }

        if ($hasAttendance) {
            $st = db()->prepare(
                "SELECT cs.id AS student_id, cs.student_number, cs.full_name, cs.email,
                        COALESCE(SUM(ar.status = 'present'), 0) AS present_count,
                        COALESCE(SUM(ar.status = 'absent'), 0) AS absent_count
                 FROM classroom_enrollments ce
                 INNER JOIN classroom_students cs ON cs.id = ce.student_id
                 LEFT JOIN classroom_attendance_sessions cas
                        ON cas.classroom_id = ce.classroom_id
                 LEFT JOIN classroom_attendance_records ar
                        ON ar.session_id = cas.id AND ar.student_id = cs.id
                 WHERE ce.classroom_id = ?
                 GROUP BY cs.id, cs.student_number, cs.full_name, cs.email
                 ORDER BY cs.full_name ASC"
            );
        } else {
            $st = db()->prepare(
                'SELECT cs.id AS student_id, cs.student_number, cs.full_name, cs.email,
                        0 AS present_count, 0 AS absent_count
                 FROM classroom_enrollments ce
                 INNER JOIN classroom_students cs ON cs.id = ce.student_id
                 WHERE ce.classroom_id = ?
                 ORDER BY cs.full_name ASC'
            );
        }
        $st->execute([$classroomId]);
        $students = $st->fetchAll();

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $students = array_values(array_filter(
                $students,
                static function (array $row) use ($needle): bool {
                    $haystack = mb_strtolower(
                        (string) $row['full_name'] . ' ' .
                        (string) $row['student_number'] . ' ' .
                        (string) ($row['email'] ?? '')
                    );
                    return str_contains($haystack, $needle);
                }
            ));
        }
    }
}

$pageTitle = $classroom
    ? ('Student list · ' . (string) $classroom['course_code'])
    : 'Student list';
require_once __DIR__ . '/includes/header.php';
?>
<style>
    .fsl-hub {
        background: #f4f7fc;
        font-family: 'Inter', sans-serif;
        color: #1a2c3e;
        line-height: 1.4;
        padding: 2rem 1.5rem;
        border-radius: 22px;
    }
    .fsl-hub .dashboard-container {
        max-width: 1200px;
        margin: 0 auto;
    }
    .fsl-hub .uni-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        flex-wrap: wrap;
        gap: 1rem;
        margin-bottom: 1.5rem;
        border-bottom: 2px solid #e2e8f0;
        padding-bottom: 1rem;
    }
    .fsl-hub .brand h1 {
        font-size: 1.75rem;
        font-weight: 700;
        background: linear-gradient(135deg, #1e4a6e, #2c7a4d);
        background-clip: text;
        -webkit-background-clip: text;
        color: transparent;
        letter-spacing: -0.3px;
        margin: 0;
    }
    .fsl-hub .brand p {
        font-size: 0.9rem;
        color: #2c5a6e;
        font-weight: 500;
        margin: 0.25rem 0 0;
    }
    .fsl-stats {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }
    .fsl-stat {
        background: #fff;
        border-radius: 16px;
        padding: 1rem 1.15rem;
        box-shadow: 0 8px 18px rgba(0, 0, 0, 0.04), 0 0 0 1px rgba(0, 0, 0, 0.02);
    }
    .fsl-stat .label {
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #64748b;
        margin-bottom: 0.35rem;
    }
    .fsl-stat .value {
        font-size: 1.55rem;
        font-weight: 700;
        color: #0f172a;
        line-height: 1.1;
    }
    .fsl-stat.is-present .value { color: #15803d; }
    .fsl-stat.is-absent .value { color: #b91c1c; }
    .fsl-card {
        background: #fff;
        border-radius: 22px;
        box-shadow: 0 12px 28px rgba(0, 0, 0, 0.05), 0 0 0 1px rgba(0, 0, 0, 0.02);
        overflow: hidden;
        margin-bottom: 1.25rem;
    }
    .fsl-card-header {
        padding: 1.1rem 1.5rem;
        border-bottom: 1px solid #e8eef3;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
        flex-wrap: wrap;
    }
    .fsl-card-header h2 {
        font-size: 1.05rem;
        font-weight: 700;
        margin: 0;
        color: #1e3a4c;
        display: flex;
        align-items: center;
        gap: 0.55rem;
    }
    .fsl-card-body { padding: 0; }
    .fsl-table {
        width: 100%;
        border-collapse: collapse;
    }
    .fsl-table th {
        text-align: left;
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #64748b;
        font-weight: 700;
        padding: 0.85rem 1.25rem;
        border-bottom: 1px solid #e8eef3;
        background: #f8fafc;
        white-space: nowrap;
    }
    .fsl-table td {
        padding: 0.95rem 1.25rem;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
        font-size: 0.9rem;
    }
    .fsl-table tbody tr:hover { background: #f8fbfd; }
    .fsl-table tbody tr:last-child td { border-bottom: none; }
    .fsl-course-code {
        font-weight: 700;
        color: #1e4a6e;
    }
    .fsl-muted {
        color: #64748b;
        font-size: 0.8rem;
    }
    .fsl-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        border-radius: 999px;
        padding: 0.25rem 0.65rem;
        font-size: 0.78rem;
        font-weight: 700;
        white-space: nowrap;
    }
    .fsl-pill.enrolled { background: #e8f0fe; color: #1a56db; }
    .fsl-pill.present { background: #dcfce7; color: #15803d; }
    .fsl-pill.absent { background: #fee2e2; color: #b91c1c; }
    .fsl-actions {
        display: flex;
        gap: 0.45rem;
        flex-wrap: wrap;
    }
    .fsl-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        border-radius: 999px;
        padding: 0.4rem 0.85rem;
        font-size: 0.8rem;
        font-weight: 600;
        text-decoration: none;
        border: 1px solid #cbdde6;
        background: #fff;
        color: #1f5e6e;
    }
    .fsl-btn:hover {
        background: #eef3f7;
        border-color: #9fc1cf;
        color: #1f5e6e;
    }
    .fsl-btn.primary {
        background: #2c7a4d;
        border-color: #2c7a4d;
        color: #fff;
    }
    .fsl-btn.primary:hover {
        background: #246640;
        border-color: #246640;
        color: #fff;
    }
    .fsl-toolbar {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
        align-items: center;
    }
    .fsl-search {
        border: 1px solid #cbdde6;
        border-radius: 999px;
        padding: 0.45rem 0.9rem;
        min-width: 220px;
        font-size: 0.875rem;
    }
    .fsl-search:focus {
        outline: none;
        border-color: #2c7a4d;
        box-shadow: 0 0 0 3px rgba(44, 122, 77, 0.15);
    }
    .fsl-empty {
        text-align: center;
        padding: 2.5rem 1.5rem;
        color: #8ba5b4;
        font-style: italic;
    }
    .fsl-back {
        margin-bottom: 1rem;
    }
    @media (max-width: 900px) {
        .fsl-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .fsl-hub { padding: 1.2rem; }
    }
    @media (max-width: 560px) {
        .fsl-stats { grid-template-columns: 1fr; }
    }
</style>

<div class="fsl-hub">
    <div class="dashboard-container">
        <div class="uni-header">
            <div class="brand">
                <h1><i class="fa-solid fa-users" style="color:#2c7a4d; margin-right: 8px;"></i>Student List</h1>
                <p>
                    <?php if ($classroom): ?>
                        <?= htmlspecialchars((string) $classroom['course_code']) ?> —
                        <?= htmlspecialchars((string) $classroom['course_name']) ?>
                    <?php else: ?>
                        Courses and registered students with attendance totals
                    <?php endif; ?>
                </p>
            </div>
            <div class="fsl-actions">
                <?php if ($classroom): ?>
                    <a href="faculty_student_list.php" class="fsl-btn"<?= app_tooltip_attr('Returns to the course list for all of your online classrooms.') ?>>
                        <i class="fa-solid fa-arrow-left"></i> All courses
                    </a>
                    <a href="faculty_classroom_attendance.php?id=<?= (int) $classroom['id'] ?>" class="fsl-btn"<?= app_tooltip_attr('Opens attendance marking for this classroom.') ?>>
                        <i class="fa-solid fa-clipboard-user"></i> Attendance
                    </a>
                    <a href="faculty_classroom.php?id=<?= (int) $classroom['id'] ?>" class="fsl-btn"<?= app_tooltip_attr('Opens the classroom management page.') ?>>
                        <i class="fa-solid fa-gear"></i> Manage class
                    </a>
                <?php else: ?>
                    <a href="faculty_classrooms.php" class="fsl-btn"<?= app_tooltip_attr('Opens your online classrooms hub.') ?>>
                        <i class="fa-solid fa-chalkboard"></i> My classrooms
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($missingTables !== []): ?>
            <div class="alert alert-warning mb-0">
                Classroom enrollment features are not installed yet. Run
                <a href="upgrade_roles.php">upgrade_roles.php</a> once, then reload this page.
            </div>
        <?php elseif ($classroom): ?>
            <?php
            $detailEnrolled = count($students);
            if ($search === '') {
                $detailEnrolled = (int) ($classroom['enrolled_count'] ?? count($students));
            }
            $detailPresent = (int) ($classroom['present_count'] ?? 0);
            $detailAbsent = (int) ($classroom['absent_count'] ?? 0);
            $detailSessions = (int) ($classroom['session_count'] ?? 0);
            ?>
            <div class="fsl-stats">
                <div class="fsl-stat">
                    <div class="label">Registered</div>
                    <div class="value"><?= (int) $detailEnrolled ?></div>
                </div>
                <div class="fsl-stat is-present">
                    <div class="label">Present marks</div>
                    <div class="value"><?= $detailPresent ?></div>
                </div>
                <div class="fsl-stat is-absent">
                    <div class="label">Absent marks</div>
                    <div class="value"><?= $detailAbsent ?></div>
                </div>
                <div class="fsl-stat">
                    <div class="label">Sessions</div>
                    <div class="value"><?= $detailSessions ?></div>
                </div>
            </div>

            <div class="fsl-card">
                <div class="fsl-card-header">
                    <h2><i class="fa-solid fa-user-graduate" style="color:#2c7a4d;"></i> Registered students</h2>
                    <form method="get" class="fsl-toolbar">
                        <input type="hidden" name="id" value="<?= (int) $classroom['id'] ?>">
                        <input
                            type="search"
                            name="q"
                            class="fsl-search"
                            value="<?= htmlspecialchars($search) ?>"
                            placeholder="Search name or student no."
                            <?= app_tooltip_attr('Filters the roster by student name, number, or email.') ?>
                        >
                        <button type="submit" class="fsl-btn primary">
                            <i class="fa-solid fa-magnifying-glass"></i> Search
                        </button>
                        <?php if ($search !== ''): ?>
                            <a href="faculty_student_list.php?id=<?= (int) $classroom['id'] ?>" class="fsl-btn">Clear</a>
                        <?php endif; ?>
                    </form>
                </div>
                <div class="fsl-card-body">
                    <div class="table-responsive">
                        <table class="fsl-table">
                            <thead>
                            <tr>
                                <th>#</th>
                                <th>Student</th>
                                <th>Student no.</th>
                                <th>Present</th>
                                <th>Absent</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if ($students === []): ?>
                                <tr>
                                    <td colspan="5" class="fsl-empty">
                                        <?= $search !== ''
                                            ? 'No students match your search.'
                                            : 'No students are registered in this course yet. Share the classroom join code so they can enroll.' ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($students as $i => $row): ?>
                                    <tr>
                                        <td><?= $i + 1 ?></td>
                                        <td>
                                            <div style="font-weight:600;"><?= htmlspecialchars((string) $row['full_name']) ?></div>
                                            <?php if (trim((string) ($row['email'] ?? '')) !== ''): ?>
                                                <div class="fsl-muted"><?= htmlspecialchars((string) $row['email']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars((string) ($row['student_number'] !== '' ? $row['student_number'] : '—')) ?></td>
                                        <td>
                                            <span class="fsl-pill present">
                                                <i class="fa-solid fa-check"></i>
                                                <?= (int) $row['present_count'] ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="fsl-pill absent">
                                                <i class="fa-solid fa-xmark"></i>
                                                <?= (int) $row['absent_count'] ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="fsl-stats">
                <div class="fsl-stat">
                    <div class="label">Courses</div>
                    <div class="value"><?= count($classrooms) ?></div>
                </div>
                <div class="fsl-stat">
                    <div class="label">Registered students</div>
                    <div class="value"><?= (int) $totals['enrolled'] ?></div>
                </div>
                <div class="fsl-stat is-present">
                    <div class="label">Present marks</div>
                    <div class="value"><?= (int) $totals['present'] ?></div>
                </div>
                <div class="fsl-stat is-absent">
                    <div class="label">Absent marks</div>
                    <div class="value"><?= (int) $totals['absent'] ?></div>
                </div>
            </div>

            <div class="fsl-card">
                <div class="fsl-card-header">
                    <h2><i class="fa-solid fa-book-open" style="color:#2c7a4d;"></i> Your courses</h2>
                </div>
                <div class="fsl-card-body">
                    <div class="table-responsive">
                        <table class="fsl-table">
                            <thead>
                            <tr>
                                <th>Course</th>
                                <th>Term</th>
                                <th>Registered</th>
                                <th>Present</th>
                                <th>Absent</th>
                                <th></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if ($classrooms === []): ?>
                                <tr>
                                    <td colspan="6" class="fsl-empty">
                                        No online classrooms yet.
                                        <a href="faculty_classrooms.php">Create a classroom</a> for an assigned course first.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($classrooms as $row): ?>
                                    <?php
                                    $term = trim((string) $row['semester'] . ' / ' . (string) $row['school_year'], ' /');
                                    $dayLine = str_replace(',', ', ', (string) $row['day_of_week']);
                                    $timeLine = substr((string) $row['start_time'], 0, 5) . '–' . substr((string) $row['end_time'], 0, 5);
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fsl-course-code"><?= htmlspecialchars((string) $row['course_code']) ?></div>
                                            <div><?= htmlspecialchars((string) $row['course_name']) ?></div>
                                            <div class="fsl-muted"><?= htmlspecialchars((string) $row['title']) ?></div>
                                        </td>
                                        <td>
                                            <div><?= htmlspecialchars($term) ?></div>
                                            <div class="fsl-muted"><?= htmlspecialchars($dayLine) ?></div>
                                            <div class="fsl-muted"><?= htmlspecialchars($timeLine) ?></div>
                                        </td>
                                        <td>
                                            <span class="fsl-pill enrolled">
                                                <i class="fa-solid fa-users"></i>
                                                <?= (int) $row['enrolled_count'] ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="fsl-pill present">
                                                <i class="fa-solid fa-check"></i>
                                                <?= (int) $row['present_count'] ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="fsl-pill absent">
                                                <i class="fa-solid fa-xmark"></i>
                                                <?= (int) $row['absent_count'] ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="fsl-actions">
                                                <a
                                                    href="faculty_student_list.php?id=<?= (int) $row['id'] ?>"
                                                    class="fsl-btn primary"
                                                    <?= app_tooltip_attr('Shows every student registered in this course with present and absent totals.') ?>
                                                >
                                                    <i class="fa-solid fa-list"></i> View students
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

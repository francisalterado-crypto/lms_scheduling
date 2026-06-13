<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_role(['faculty']);

$facultyId = isset($_SESSION['faculty_id']) ? (int) $_SESSION['faculty_id'] : 0;
$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($facultyId < 1) {
    $facultyId = resolve_faculty_id_for_user($userId) ?? 0;
    $_SESSION['faculty_id'] = $facultyId > 0 ? $facultyId : null;
}
if ($facultyId < 1) {
    exit('Faculty profile not linked to this account. Ask your dean to create/link your faculty profile.');
}

$classroomId = (int) ($_GET['id'] ?? $_POST['classroom_id'] ?? 0);
$attendanceDateInput = trim((string) ($_GET['attendance_date'] ?? $_POST['attendance_date'] ?? ''));
$attendanceDateExplicit = $attendanceDateInput !== '';
$attendanceDate = $attendanceDateInput !== '' ? $attendanceDateInput : date('Y-m-d');
$printMode = isset($_GET['print']) && (string) $_GET['print'] === '1';

$requiredTables = [
    'online_classrooms',
    'classroom_enrollments',
    'classroom_students',
    'classroom_attendance_sessions',
    'classroom_attendance_records',
];
$missingTables = array_values(array_filter(
    $requiredTables,
    static fn (string $table): bool => !db_table_exists($table)
));
$hasPresenceColumns = db_column_exists('users', 'last_login_at') && db_column_exists('users', 'last_seen_at');
$hasLogoutColumn = db_column_exists('users', 'last_logout_at');
$hasAttendanceLogoutColumn = db_column_exists('classroom_attendance_records', 'evidence_logout_at');

$classroom = null;
if ($classroomId > 0 && $missingTables === []) {
    $st = db()->prepare(
        'SELECT oc.*, s.day_of_week, s.start_time, s.end_time, s.semester, s.school_year, c.course_code, c.course_name
         FROM online_classrooms oc
         INNER JOIN schedules s ON s.id = oc.schedule_id
         INNER JOIN courses c ON c.id = oc.course_id
         WHERE oc.id = ? AND oc.faculty_id = ?
         LIMIT 1'
    );
    $st->execute([$classroomId, $facultyId]);
    $classroom = $st->fetch() ?: null;
}

if ($missingTables === [] && !$classroom) {
    http_response_code(404);
    exit('Classroom not found or you do not have access to it.');
}

$parseDate = static function (string $value): ?string {
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    if (!$dt || $dt->format('Y-m-d') !== $value) {
        return null;
    }
    return $value;
};
$attendanceDate = $parseDate($attendanceDate) ?? date('Y-m-d');
$normalizeDayToken = static function (string $value): string {
    $lettersOnly = preg_replace('/[^a-z]/i', '', trim($value)) ?? '';
    return strtolower(substr($lettersOnly, 0, 3));
};
$formatTime12h = static function (?string $time): string {
    $raw = substr((string) $time, 0, 5);
    $dt = DateTime::createFromFormat('H:i', $raw);
    return $dt ? $dt->format('g:i A') : $raw;
};
$formatDateTime12h = static function (?string $dateTime): string {
    $raw = trim((string) $dateTime);
    if ($raw === '') {
        return '—';
    }
    $ts = strtotime($raw);
    return $ts === false ? $raw : date('M j, Y g:i A', $ts);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $missingTables === [] && $classroom && $hasPresenceColumns) {
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'run_auto_attendance') {
            $scheduleDays = parse_day_set((string) $classroom['day_of_week']);
            $dayName = date('l', strtotime($attendanceDate));
            $scheduleDayTokens = [];
            foreach ($scheduleDays as $scheduledDay) {
                $token = $normalizeDayToken((string) $scheduledDay);
                if ($token !== '') {
                    $scheduleDayTokens[$token] = true;
                }
            }
            if ($scheduleDayTokens === []) {
                throw new RuntimeException('Class schedule days are not configured yet for this classroom.');
            }
            $selectedToken = $normalizeDayToken($dayName);
            $isOffScheduleDate = !isset($scheduleDayTokens[$selectedToken]);

            $sessionStart = $attendanceDate . ' ' . substr((string) $classroom['start_time'], 0, 8);
            $sessionEnd = $attendanceDate . ' ' . substr((string) $classroom['end_time'], 0, 8);
            $now = date('Y-m-d H:i:s');
            $isWithinWindow = $now >= $sessionStart && $now <= $sessionEnd;
            $onlineRecentThreshold = date('Y-m-d H:i:s', time() - 10 * 60);

            db()->beginTransaction();
            try {
                db()->prepare(
                    'INSERT INTO classroom_attendance_sessions (classroom_id, faculty_id, attendance_date, session_start_at, session_end_at, source)
                     VALUES (?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE
                        session_start_at = VALUES(session_start_at),
                        session_end_at = VALUES(session_end_at),
                        source = VALUES(source),
                        updated_at = CURRENT_TIMESTAMP'
                )->execute([$classroomId, $facultyId, $attendanceDate, $sessionStart, $sessionEnd, 'auto_login_online']);

                $sidStmt = db()->prepare('SELECT id FROM classroom_attendance_sessions WHERE classroom_id = ? AND attendance_date = ? LIMIT 1');
                $sidStmt->execute([$classroomId, $attendanceDate]);
                $sessionId = (int) ($sidStmt->fetchColumn() ?: 0);
                if ($sessionId < 1) {
                    throw new RuntimeException('Unable to initialize attendance session.');
                }

                $studentsSql = $hasLogoutColumn
                    ? 'SELECT cs.id AS student_id, u.last_login_at, u.last_seen_at, u.last_logout_at
                       FROM classroom_enrollments ce
                       INNER JOIN classroom_students cs ON cs.id = ce.student_id
                       LEFT JOIN users u ON u.id = cs.user_id
                       WHERE ce.classroom_id = ?'
                    : 'SELECT cs.id AS student_id, u.last_login_at, u.last_seen_at
                       FROM classroom_enrollments ce
                       INNER JOIN classroom_students cs ON cs.id = ce.student_id
                       LEFT JOIN users u ON u.id = cs.user_id
                       WHERE ce.classroom_id = ?';
                $studentsStmt = db()->prepare($studentsSql);
                $studentsStmt->execute([$classroomId]);
                $students = $studentsStmt->fetchAll();

                $upsert = $hasAttendanceLogoutColumn
                    ? db()->prepare(
                        'INSERT INTO classroom_attendance_records
                         (session_id, student_id, status, source, evidence_login_at, evidence_seen_at, evidence_logout_at, checked_at, notes)
                         VALUES (?,?,?,?,?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE
                            status = VALUES(status),
                            source = VALUES(source),
                            evidence_login_at = VALUES(evidence_login_at),
                            evidence_seen_at = VALUES(evidence_seen_at),
                            evidence_logout_at = VALUES(evidence_logout_at),
                            checked_at = VALUES(checked_at),
                            notes = VALUES(notes),
                            updated_at = CURRENT_TIMESTAMP'
                    )
                    : db()->prepare(
                        'INSERT INTO classroom_attendance_records
                         (session_id, student_id, status, source, evidence_login_at, evidence_seen_at, checked_at, notes)
                         VALUES (?,?,?,?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE
                            status = VALUES(status),
                            source = VALUES(source),
                            evidence_login_at = VALUES(evidence_login_at),
                            evidence_seen_at = VALUES(evidence_seen_at),
                            checked_at = VALUES(checked_at),
                            notes = VALUES(notes),
                            updated_at = CURRENT_TIMESTAMP'
                    );

                foreach ($students as $student) {
                    $loginAt = $student['last_login_at'] !== null ? (string) $student['last_login_at'] : null;
                    $seenAt = $student['last_seen_at'] !== null ? (string) $student['last_seen_at'] : null;
                    $logoutAt = $hasLogoutColumn && ($student['last_logout_at'] ?? null) !== null ? (string) $student['last_logout_at'] : null;

                    $loggedDuringClass = $loginAt !== null && $loginAt >= $sessionStart && $loginAt <= $sessionEnd;
                    $seenDuringClass = $seenAt !== null && $seenAt >= $sessionStart && $seenAt <= $sessionEnd;
                    $currentlyOnlineInClass = $isWithinWindow && $seenAt !== null && $seenAt >= $onlineRecentThreshold;
                    $isPresent = $loggedDuringClass || $seenDuringClass || $currentlyOnlineInClass;

                    $note = ($isPresent
                        ? 'Auto-marked present (login/online activity detected).'
                        : 'Auto-marked absent (no login/online activity in class window).')
                        . ($isOffScheduleDate ? ' Off-schedule date run by faculty.' : '');

                    if ($hasAttendanceLogoutColumn) {
                        $upsert->execute([$sessionId, (int) $student['student_id'], $isPresent ? 'present' : 'absent', 'auto', $loginAt, $seenAt, $logoutAt, $now, $note]);
                    } else {
                        $upsert->execute([$sessionId, (int) $student['student_id'], $isPresent ? 'present' : 'absent', 'auto', $loginAt, $seenAt, $now, $note]);
                    }
                }

                db()->commit();
            } catch (Throwable $e) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                throw $e;
            }

            $_SESSION['flash'] = $isOffScheduleDate
                ? 'Automatic attendance check completed for ' . $attendanceDate . ' (off-schedule date: ' . implode(', ', $scheduleDays) . ').'
                : 'Automatic attendance check completed for ' . $attendanceDate . '.';
        } elseif ($action === 'set_manual_attendance') {
            $recordId = (int) ($_POST['record_id'] ?? 0);
            $status = (string) ($_POST['status'] ?? '');
            if (!in_array($status, ['present', 'absent'], true)) {
                throw new RuntimeException('Invalid attendance status.');
            }
            db()->prepare(
                'UPDATE classroom_attendance_records ar
                 INNER JOIN classroom_attendance_sessions s ON s.id = ar.session_id
                 SET ar.status = ?, ar.source = "manual", ar.checked_at = NOW(), ar.notes = ?
                 WHERE ar.id = ? AND s.classroom_id = ? AND s.faculty_id = ?'
            )->execute([$status, 'Updated manually by faculty.', $recordId, $classroomId, $facultyId]);
            $_SESSION['flash'] = 'Attendance status updated.';
        } elseif ($action === 'delete_attendance_record') {
            $recordId = (int) ($_POST['record_id'] ?? 0);
            if ($recordId < 1) {
                throw new RuntimeException('Invalid attendance record.');
            }
            $st = db()->prepare(
                'DELETE ar
                 FROM classroom_attendance_records ar
                 INNER JOIN classroom_attendance_sessions s ON s.id = ar.session_id
                 WHERE ar.id = ? AND s.classroom_id = ? AND s.faculty_id = ?'
            );
            $st->execute([$recordId, $classroomId, $facultyId]);
            $_SESSION['flash'] = $st->rowCount() > 0 ? 'Attendance record deleted.' : 'Attendance record not found.';
        }
    } catch (Throwable $e) {
        $_SESSION['flash'] = 'Error: ' . $e->getMessage();
    }

    header('Location: faculty_classroom_attendance.php?id=' . $classroomId . '&attendance_date=' . urlencode($attendanceDate));
    exit;
}

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$session = null;
$records = [];
$summary = ['present' => 0, 'absent' => 0, 'total' => 0];
$latestSessionDate = null;
$showDateHint = false;
if ($missingTables === [] && $classroom && $hasPresenceColumns) {
    $st = db()->prepare(
        'SELECT *
         FROM classroom_attendance_sessions
         WHERE classroom_id = ? AND attendance_date = ?
         LIMIT 1'
    );
    $st->execute([$classroomId, $attendanceDate]);
    $session = $st->fetch() ?: null;

    if (!$session) {
        $st = db()->prepare(
            'SELECT attendance_date
             FROM classroom_attendance_sessions
             WHERE classroom_id = ?
             ORDER BY attendance_date DESC, id DESC
             LIMIT 1'
        );
        $st->execute([$classroomId]);
        $latestSessionDate = $st->fetchColumn() ?: null;

        if (!$attendanceDateExplicit && $latestSessionDate !== null) {
            $attendanceDate = (string) $latestSessionDate;
            $st = db()->prepare(
                'SELECT *
                 FROM classroom_attendance_sessions
                 WHERE classroom_id = ? AND attendance_date = ?
                 LIMIT 1'
            );
            $st->execute([$classroomId, $attendanceDate]);
            $session = $st->fetch() ?: null;
        } else {
            $showDateHint = $latestSessionDate !== null;
        }
    }

    if ($session) {
        $st = db()->prepare(
            'SELECT ar.*, cs.full_name, cs.student_number, u.last_logout_at AS user_last_logout_at
             FROM classroom_attendance_records ar
             INNER JOIN classroom_students cs ON cs.id = ar.student_id
             LEFT JOIN users u ON u.id = cs.user_id
             WHERE ar.session_id = ?
             ORDER BY cs.full_name ASC'
        );
        $st->execute([(int) $session['id']]);
        $records = $st->fetchAll();

        foreach ($records as $row) {
            $summary['total']++;
            if ((string) $row['status'] === 'present') {
                $summary['present']++;
            } else {
                $summary['absent']++;
            }
        }
    }
}

$pageTitle = $printMode ? 'Attendance Printout' : 'Class Attendance';
require_once __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="h3 mb-1"><i class="fa-solid fa-user-check me-2 text-primary"></i>Automatic Attendance</h1>
        <?php if ($classroom): ?>
            <div class="text-muted">
                <?= htmlspecialchars((string) $classroom['course_code']) ?> - <?= htmlspecialchars((string) $classroom['course_name']) ?>
                | <?= htmlspecialchars(str_replace(',', ', ', (string) $classroom['day_of_week'])) ?>
                <?= htmlspecialchars($formatTime12h((string) ($classroom['start_time'] ?? ''))) ?> - <?= htmlspecialchars($formatTime12h((string) ($classroom['end_time'] ?? ''))) ?>
                | <?= htmlspecialchars((string) $classroom['semester']) ?> / <?= htmlspecialchars((string) $classroom['school_year']) ?>
            </div>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2 no-print">
        <a href="faculty_classroom.php?id=<?= (int) $classroomId ?>" class="btn btn-outline-secondary btn-sm"<?= app_tooltip_attr('Returns to the class workspace and content tools.') ?>>Back to Classroom</a>
        <a href="faculty_classroom_attendance.php?id=<?= (int) $classroomId ?>&attendance_date=<?= urlencode($attendanceDate) ?>&print=1" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary btn-sm"<?= app_tooltip_attr('Opens a printer-friendly login/logout list for this date.') ?>>
            <i class="fa-solid fa-print me-1"></i>Print login/logout list
        </a>
    </div>
</div>

<?php if ($flash && !$printMode): ?>
    <div class="alert alert-info alert-dismissible fade show">
        <?= htmlspecialchars($flash) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"<?= app_tooltip_attr('Dismisses this alert after you have read it.') ?>></button>
    </div>
<?php endif; ?>

<?php if ($missingTables !== []): ?>
    <div class="alert alert-warning">
        Attendance module needs a database update. Run <a href="upgrade_roles.php">upgrade_roles.php</a> once, then reload this page.
    </div>
<?php elseif (!$hasPresenceColumns): ?>
    <div class="alert alert-warning">
        User activity tracking columns are missing. Run <a href="upgrade_roles.php">upgrade_roles.php</a> once to enable automatic login/online attendance detection.
    </div>
<?php else: ?>
    <?php if ($showDateHint && $latestSessionDate !== null): ?>
        <div class="alert alert-info">
            No attendance records found for <?= htmlspecialchars($attendanceDate) ?>.
            Latest available attendance is on
            <a href="faculty_classroom_attendance.php?id=<?= (int) $classroomId ?>&attendance_date=<?= urlencode((string) $latestSessionDate) ?>">
                <?= htmlspecialchars((string) $latestSessionDate) ?>
            </a>.
        </div>
    <?php endif; ?>

    <?php if ($printMode): ?>
        <style>
            @media print {
                .no-print { display: none !important; }
                .card { box-shadow: none !important; border: 1px solid #ddd !important; }
            }
        </style>
        <script>
            window.addEventListener('load', function () {
                window.print();
            });
        </script>
    <?php endif; ?>

    <?php if (!$printMode): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white"><strong>Run Auto Attendance</strong></div>
        <div class="card-body">
            <form method="post" class="row g-3 align-items-end">
                <input type="hidden" name="action" value="run_auto_attendance">
                <input type="hidden" name="classroom_id" value="<?= (int) $classroomId ?>">
                <div class="col-md-4">
                    <label class="form-label">Attendance date</label>
                    <input type="date" name="attendance_date" class="form-control" value="<?= htmlspecialchars($attendanceDate) ?>" required>
                </div>
                <div class="col-md-8">
                    <button type="submit" class="btn btn-primary"<?= app_tooltip_attr('Marks present/absent from login and activity logs for the selected date. Run before manual overrides.') ?>><i class="fa-solid fa-robot me-1"></i>Run automatic check</button>
                </div>
            </form>
            <div class="small text-muted mt-3">
                Students are marked <strong>present</strong> when login or online activity is detected within class time.
                Faculty can override each record manually below.
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="border rounded p-3 h-100 bg-body-tertiary">
                <div class="small text-muted text-uppercase">Total</div>
                <div class="fs-4 fw-semibold"><?= (int) $summary['total'] ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="border rounded p-3 h-100 bg-body-tertiary">
                <div class="small text-muted text-uppercase">Present</div>
                <div class="fs-4 fw-semibold text-success"><?= (int) $summary['present'] ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="border rounded p-3 h-100 bg-body-tertiary">
                <div class="small text-muted text-uppercase">Absent</div>
                <div class="fs-4 fw-semibold text-danger"><?= (int) $summary['absent'] ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-header bg-white"><strong>Attendance Records</strong></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Student</th>
                        <th>Status</th>
                        <th>Login time</th>
                        <th>Logout time</th>
                        <th>Online activity</th>
                        <th>Source</th>
                        <?php if (!$printMode): ?><th class="text-end">Actions</th><?php endif; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($records === []): ?>
                        <tr><td colspan="<?= $printMode ? '6' : '7' ?>" class="p-3 text-muted">No attendance records yet. Run automatic check first.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($records as $row): ?>
                        <?php $logoutDisplay = (string) (($row['evidence_logout_at'] ?? '') !== '' ? $row['evidence_logout_at'] : ($row['user_last_logout_at'] ?? '')); ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars((string) $row['full_name']) ?></strong><br>
                                <span class="small text-muted"><?= htmlspecialchars((string) $row['student_number']) ?></span>
                            </td>
                            <td>
                                <span class="badge <?= (string) $row['status'] === 'present' ? 'bg-success' : 'bg-danger' ?>">
                                    <?= htmlspecialchars(strtoupper((string) $row['status'])) ?>
                                </span>
                            </td>
                            <td class="small"><?= htmlspecialchars($formatDateTime12h((string) ($row['evidence_login_at'] ?? null))) ?></td>
                            <td class="small"><?= htmlspecialchars($formatDateTime12h($logoutDisplay !== '' ? $logoutDisplay : null)) ?></td>
                            <td class="small"><?= htmlspecialchars($formatDateTime12h((string) ($row['evidence_seen_at'] ?? null))) ?></td>
                            <td class="small"><?= htmlspecialchars((string) $row['source']) ?></td>
                            <?php if (!$printMode): ?>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-1">
                                        <form method="post">
                                            <input type="hidden" name="action" value="set_manual_attendance">
                                            <input type="hidden" name="classroom_id" value="<?= (int) $classroomId ?>">
                                            <input type="hidden" name="attendance_date" value="<?= htmlspecialchars($attendanceDate) ?>">
                                            <input type="hidden" name="record_id" value="<?= (int) $row['id'] ?>">
                                            <input type="hidden" name="status" value="present">
                                            <button type="submit" class="btn btn-sm btn-outline-success"<?= app_tooltip_attr('Overrides this student as present for the selected date.') ?>>Mark present</button>
                                        </form>
                                        <form method="post">
                                            <input type="hidden" name="action" value="set_manual_attendance">
                                            <input type="hidden" name="classroom_id" value="<?= (int) $classroomId ?>">
                                            <input type="hidden" name="attendance_date" value="<?= htmlspecialchars($attendanceDate) ?>">
                                            <input type="hidden" name="record_id" value="<?= (int) $row['id'] ?>">
                                            <input type="hidden" name="status" value="absent">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"<?= app_tooltip_attr('Overrides this student as absent for the selected date.') ?>>Mark absent</button>
                                        </form>
                                        <form method="post" onsubmit="return confirm('Delete this attendance record?');">
                                            <input type="hidden" name="action" value="delete_attendance_record">
                                            <input type="hidden" name="classroom_id" value="<?= (int) $classroomId ?>">
                                            <input type="hidden" name="attendance_date" value="<?= htmlspecialchars($attendanceDate) ?>">
                                            <input type="hidden" name="record_id" value="<?= (int) $row['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete record" aria-label="Delete attendance record"<?= app_tooltip_attr('Deletes this attendance row after confirmation. Use to fix mistaken auto entries.') ?>>
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

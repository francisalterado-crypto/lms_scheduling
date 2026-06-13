<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/classroom_discussion_helpers.php';

require_role(['student']);

$studentId = isset($_SESSION['student_id']) ? (int) $_SESSION['student_id'] : 0;
$userId = (int) ($_SESSION['user_id'] ?? 0);
$hasLiveAt = db_column_exists('schedules', 'online_live_at');
$hasContentAttachments = db_table_exists('classroom_content_attachments');
$hasClassroomDiscussions = classroom_discussions_table_exists();
$hasContentWeeks = db_column_exists('classroom_content', 'weeks');
$hasContentDaysPerTopic = db_column_exists('classroom_content', 'days_per_topic');
$hasContentTopicSchedule = $hasContentWeeks && $hasContentDaysPerTopic;
$hasAttendanceSessionsTable = db_table_exists('classroom_attendance_sessions');
$hasAttendanceRecordsTable = db_table_exists('classroom_attendance_records');
$hasAttendanceTables = $hasAttendanceSessionsTable && $hasAttendanceRecordsTable;
$hasAttendanceLogoutColumn = $hasAttendanceTables && db_column_exists('classroom_attendance_records', 'evidence_logout_at');
$formatDateTime12h = static function (?string $dateTime): string {
    $raw = trim((string) $dateTime);
    if ($raw === '') {
        return '—';
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        return $raw;
    }
    return date('M j, Y g:i A', $ts);
};
$classIsLive = static function (?string $liveAt): bool {
    if ($liveAt === null || $liveAt === '') {
        return false;
    }

    $liveTs = strtotime($liveAt);
    if ($liveTs === false) {
        return false;
    }

    return (time() - $liveTs) <= 2 * 3600;
};

if ($studentId < 1) {
    $studentId = resolve_student_id_for_user($userId) ?? 0;
    $_SESSION['student_id'] = $studentId > 0 ? $studentId : null;
}
if ($studentId < 1) {
    exit('Student profile not linked to this account. Ask your instructor to create or link your student profile.');
}

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$classroomId = (int) ($_GET['id'] ?? $_POST['classroom_id'] ?? 0);
$requiredTables = [
    'online_classrooms',
    'classroom_students',
    'classroom_enrollments',
    'classroom_content',
    'classroom_assessments',
    'classroom_submissions',
    'classroom_scores',
];
$missingTables = array_values(array_filter(
    $requiredTables,
    static fn (string $table): bool => !db_table_exists($table)
));

$classroom = null;
if ($classroomId > 0 && $missingTables === []) {
    $st = db()->prepare(
        'SELECT oc.*, c.course_code, c.course_name, f.full_name AS faculty_name, s.semester, s.school_year, s.start_time, s.end_time, s.online_live_at
         FROM classroom_enrollments ce
         INNER JOIN online_classrooms oc ON oc.id = ce.classroom_id
         INNER JOIN courses c ON c.id = oc.course_id
         INNER JOIN faculty f ON f.id = oc.faculty_id
         INNER JOIN schedules s ON s.id = oc.schedule_id
         WHERE oc.id = ? AND ce.student_id = ?
         LIMIT 1'
    );
    $st->execute([$classroomId, $studentId]);
    $classroom = $st->fetch() ?: null;
}

if ($missingTables === [] && !$classroom) {
    http_response_code(404);
    exit('Classroom not found or you are not enrolled in it.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $missingTables === [] && $classroom) {
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'submit_assessment') {
            $assessmentId = (int) ($_POST['assessment_id'] ?? 0);
            $answerText = classroom_content_prepare_body((string) ($_POST['answer_text'] ?? ''));
            if ($assessmentId < 1 || $answerText === null) {
                throw new RuntimeException('Your answer cannot be empty.');
            }

            $st = db()->prepare(
                'SELECT id FROM classroom_assessments WHERE id = ? AND classroom_id = ? LIMIT 1'
            );
            $st->execute([$assessmentId, $classroomId]);
            if (!$st->fetchColumn()) {
                throw new RuntimeException('Assessment not found.');
            }

            $st = db()->prepare(
                'SELECT COUNT(*) FROM classroom_submissions WHERE assessment_id = ? AND student_id = ?'
            );
            $st->execute([$assessmentId, $studentId]);
            $exists = (int) $st->fetchColumn() > 0;

            db()->prepare(
                'INSERT INTO classroom_submissions (assessment_id, student_id, answer_text, status, submitted_at, updated_at)
                 VALUES (?,?,?,?,NOW(),NOW())
                 ON DUPLICATE KEY UPDATE answer_text = VALUES(answer_text), status = VALUES(status), updated_at = NOW()'
            )->execute([
                $assessmentId,
                $studentId,
                $answerText,
                $exists ? 'resubmitted' : 'submitted',
            ]);

            $_SESSION['flash'] = $exists ? 'Assessment answer updated.' : 'Assessment submitted successfully.';
        } elseif ($action === 'attendance_login' || $action === 'attendance_logout') {
            if (!$hasAttendanceTables) {
                throw new RuntimeException('Attendance module needs a database update. Run upgrade_roles.php once.');
            }

            $today = date('Y-m-d');
            $sessionStart = $today . ' ' . substr((string) ($classroom['start_time'] ?? '00:00:00'), 0, 8);
            $sessionEnd = $today . ' ' . substr((string) ($classroom['end_time'] ?? '23:59:59'), 0, 8);
            $now = date('Y-m-d H:i:s');

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
                )->execute([
                    $classroomId,
                    (int) $classroom['faculty_id'],
                    $today,
                    $sessionStart,
                    $sessionEnd,
                    'auto_login_online',
                ]);

                $sidStmt = db()->prepare(
                    'SELECT id FROM classroom_attendance_sessions WHERE classroom_id = ? AND attendance_date = ? LIMIT 1'
                );
                $sidStmt->execute([$classroomId, $today]);
                $sessionId = (int) ($sidStmt->fetchColumn() ?: 0);
                if ($sessionId < 1) {
                    throw new RuntimeException('Unable to initialize attendance session.');
                }

                if ($action === 'attendance_login') {
                    if ($hasAttendanceLogoutColumn) {
                        db()->prepare(
                            'INSERT INTO classroom_attendance_records
                             (session_id, student_id, status, source, evidence_login_at, evidence_seen_at, evidence_logout_at, checked_at, notes)
                             VALUES (?,?,?,?,?,?,?,?,?)
                             ON DUPLICATE KEY UPDATE
                                status = VALUES(status),
                                source = VALUES(source),
                                evidence_login_at = VALUES(evidence_login_at),
                                evidence_seen_at = VALUES(evidence_seen_at),
                                checked_at = VALUES(checked_at),
                                notes = VALUES(notes),
                                updated_at = CURRENT_TIMESTAMP'
                        )->execute([
                            $sessionId,
                            $studentId,
                            'present',
                            'manual',
                            $now,
                            $now,
                            null,
                            $now,
                            'Student tapped class login.',
                        ]);
                    } else {
                        db()->prepare(
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
                        )->execute([
                            $sessionId,
                            $studentId,
                            'present',
                            'manual',
                            $now,
                            $now,
                            $now,
                            'Student tapped class login.',
                        ]);
                    }
                    $_SESSION['flash'] = 'Attendance login recorded for this class.';
                } else {
                    if ($hasAttendanceLogoutColumn) {
                        db()->prepare(
                            'INSERT INTO classroom_attendance_records
                             (session_id, student_id, status, source, evidence_login_at, evidence_seen_at, evidence_logout_at, checked_at, notes)
                             VALUES (?,?,?,?,?,?,?,?,?)
                             ON DUPLICATE KEY UPDATE
                                source = "manual",
                                evidence_logout_at = VALUES(evidence_logout_at),
                                checked_at = VALUES(checked_at),
                                notes = VALUES(notes),
                                updated_at = CURRENT_TIMESTAMP'
                        )->execute([
                            $sessionId,
                            $studentId,
                            'present',
                            'manual',
                            null,
                            null,
                            $now,
                            $now,
                            'Student tapped class logout.',
                        ]);
                    } else {
                        db()->prepare(
                            'INSERT INTO classroom_attendance_records
                             (session_id, student_id, status, source, evidence_login_at, evidence_seen_at, checked_at, notes)
                             VALUES (?,?,?,?,?,?,?,?)
                             ON DUPLICATE KEY UPDATE
                                source = "manual",
                                checked_at = VALUES(checked_at),
                                notes = VALUES(notes),
                                updated_at = CURRENT_TIMESTAMP'
                        )->execute([
                            $sessionId,
                            $studentId,
                            'present',
                            'manual',
                            null,
                            null,
                            $now,
                            'Student tapped class logout.',
                        ]);
                    }
                    $_SESSION['flash'] = 'Attendance logout recorded for this class.';
                }

                db()->commit();
            } catch (Throwable $e) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                throw $e;
            }
        } elseif ($action === 'post_discussion_message') {
            classroom_discussion_post($classroomId, $userId, (string) ($_POST['message_body'] ?? ''));
            $_SESSION['flash'] = 'Class discussion message posted.';
        }
    } catch (Throwable $e) {
        $_SESSION['flash'] = 'Error: ' . $e->getMessage();
    }

    header('Location: student_classroom.php?id=' . $classroomId);
    exit;
}

$announcements = [];
$materials = [];
$contentAttachmentMap = [];
$discussionMessages = [];
$assessments = [];
$materialWeeks = [];
$attendanceTodayLoginAt = null;
$attendanceTodayLogoutAt = null;

if ($missingTables === []) {
    $st = db()->prepare(
        'SELECT *
         FROM classroom_content
         WHERE classroom_id = ? AND content_type = "announcement"
         ORDER BY created_at DESC'
    );
    $st->execute([$classroomId]);
    $announcements = $st->fetchAll();

    $st = db()->prepare(
        'SELECT *
         FROM classroom_content
         WHERE classroom_id = ? AND content_type <> "announcement"
         ORDER BY created_at DESC'
    );
    $st->execute([$classroomId]);
    $materials = $st->fetchAll();
    $materialWeeks = classroom_content_group_by_week($materials);
    if ($hasContentAttachments) {
        $contentAttachmentMap = classroom_content_attachment_map(array_merge(
            array_column($announcements, 'id'),
            array_column($materials, 'id')
        ));
    }
    if ($hasClassroomDiscussions) {
        $discussionMessages = classroom_discussion_messages($classroomId, $userId);
    }

    $st = db()->prepare(
        'SELECT ca.*, sc.score, sc.feedback, sc.graded_at, sub.answer_text, sub.status AS submission_status, sub.submitted_at
         FROM classroom_assessments ca
         LEFT JOIN classroom_scores sc ON sc.assessment_id = ca.id AND sc.student_id = ?
         LEFT JOIN classroom_submissions sub ON sub.assessment_id = ca.id AND sub.student_id = ?
         WHERE ca.classroom_id = ?
         ORDER BY ca.created_at DESC'
    );
    $st->execute([$studentId, $studentId, $classroomId]);
    $assessments = $st->fetchAll();

    if ($hasAttendanceTables) {
        if ($hasAttendanceLogoutColumn) {
            $st = db()->prepare(
                'SELECT ar.evidence_login_at, ar.evidence_logout_at
                 FROM classroom_attendance_sessions s
                 LEFT JOIN classroom_attendance_records ar ON ar.session_id = s.id AND ar.student_id = ?
                 WHERE s.classroom_id = ? AND s.attendance_date = CURDATE()
                 LIMIT 1'
            );
            $st->execute([$studentId, $classroomId]);
            $attendanceRow = $st->fetch() ?: null;
            if ($attendanceRow) {
                $attendanceTodayLoginAt = $attendanceRow['evidence_login_at'] ?? null;
                $attendanceTodayLogoutAt = $attendanceRow['evidence_logout_at'] ?? null;
            }
        } else {
            $st = db()->prepare(
                'SELECT ar.evidence_login_at
                 FROM classroom_attendance_sessions s
                 LEFT JOIN classroom_attendance_records ar ON ar.session_id = s.id AND ar.student_id = ?
                 WHERE s.classroom_id = ? AND s.attendance_date = CURDATE()
                 LIMIT 1'
            );
            $st->execute([$studentId, $classroomId]);
            $attendanceRow = $st->fetch() ?: null;
            if ($attendanceRow) {
                $attendanceTodayLoginAt = $attendanceRow['evidence_login_at'] ?? null;
            }
        }
    }
}

$pageTitle = 'Classroom';
require_once __DIR__ . '/includes/header.php';
?>
<?php
$classroomLiveAt = $hasLiveAt && $classroom ? (string) ($classroom['online_live_at'] ?? '') : '';
$classroomIsLive = $hasLiveAt && $classIsLive($classroomLiveAt);
$attendanceHasLoginToday = trim((string) ($attendanceTodayLoginAt ?? '')) !== '';
$attendanceHasLogoutToday = $hasAttendanceLogoutColumn && trim((string) ($attendanceTodayLogoutAt ?? '')) !== '';
$attendanceCanLogin = !$attendanceHasLoginToday || $attendanceHasLogoutToday;
$attendanceCanLogout = $hasAttendanceLogoutColumn
    ? ($attendanceHasLoginToday && !$attendanceHasLogoutToday)
    : true;
$attendanceStatusClass = 'text-secondary';
$attendanceStatusLabel = 'Not logged in yet';
if ($attendanceHasLogoutToday) {
    $attendanceStatusClass = 'text-danger';
    $attendanceStatusLabel = 'Logged out';
} elseif ($attendanceHasLoginToday) {
    $attendanceStatusClass = 'text-success';
    $attendanceStatusLabel = $hasAttendanceLogoutColumn ? 'In class' : 'Login recorded';
}
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="h3 mb-1">
            <i class="fa-solid fa-book-open-reader me-2 text-primary"></i><?= htmlspecialchars((string) ($classroom['title'] ?? 'Classroom')) ?>
            <?php if ($classroomIsLive): ?>
                <span class="badge bg-danger live-pulse-badge align-middle ms-2">LIVE</span>
            <?php endif; ?>
        </h1>
        <?php if ($classroom): ?>
            <div class="text-muted">
                <?= htmlspecialchars((string) $classroom['course_code']) ?> - <?= htmlspecialchars((string) $classroom['course_name']) ?>
                | Instructor: <?= htmlspecialchars((string) $classroom['faculty_name']) ?>
            </div>
            <?php if ($classroomIsLive): ?>
                <div class="small text-danger fw-semibold mt-1">
                    <i class="fa-solid fa-circle-play me-1"></i>Your instructor is live now.
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <div class="d-flex flex-wrap align-items-start align-items-md-center gap-2">
        <a href="student_classrooms.php" class="btn btn-outline-secondary btn-sm px-3 text-nowrap"<?= student_tooltip_attr('Returns to the list of all your enrolled classes. Use this when you are done with this class or want to switch subjects.') ?>>Back to My Classes</a>
        <?php if ($hasAttendanceTables): ?>
            <div class="border rounded-3 px-2 py-2 bg-light-subtle d-flex flex-column align-items-start gap-1">
                <div class="small fw-semibold <?= htmlspecialchars($attendanceStatusClass) ?>">
                    <i class="fa-solid fa-user-check me-1"></i><?= htmlspecialchars($attendanceStatusLabel) ?>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <form method="post" class="d-inline">
                        <input type="hidden" name="action" value="attendance_login">
                        <input type="hidden" name="classroom_id" value="<?= (int) $classroomId ?>">
                        <button type="submit" class="btn btn-primary btn-sm" <?= $attendanceCanLogin ? '' : 'disabled' ?> <?= student_tooltip_attr('Records that you joined today’s class session for attendance. Use this when class starts or when your instructor expects a login check.') ?>><i class="fa-solid fa-right-to-bracket me-1"></i>Class Login</button>
                    </form>
                    <form method="post" class="d-inline">
                        <input type="hidden" name="action" value="attendance_logout">
                        <input type="hidden" name="classroom_id" value="<?= (int) $classroomId ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm" <?= $attendanceCanLogout ? '' : 'disabled' ?> <?= student_tooltip_attr('Records that you left the class session. Use this when your instructor tracks end-of-class attendance or when you must leave early.') ?>><i class="fa-solid fa-right-from-bracket me-1"></i>Class Logout</button>
                    </form>
                </div>
                <div class="small text-muted">
                    Last class login: <?= htmlspecialchars($formatDateTime12h($attendanceTodayLoginAt !== null ? (string) $attendanceTodayLoginAt : null)) ?>
                    <?php if ($hasAttendanceLogoutColumn): ?>
                        <br>Last class logout: <?= htmlspecialchars($formatDateTime12h($attendanceTodayLogoutAt !== null ? (string) $attendanceTodayLogoutAt : null)) ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($classroom && trim((string) $classroom['meet_link']) !== ''): ?>
            <a href="<?= htmlspecialchars((string) $classroom['meet_link']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-success btn-sm px-3 text-nowrap"<?= student_tooltip_attr('Opens the live video meeting for this class in a new tab. Use this during class time or when the LIVE badge shows your instructor is online.') ?>><i class="fa-solid fa-video me-1"></i>Join Meet</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-info alert-dismissible fade show">
        <?= htmlspecialchars($flash) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"<?= student_tooltip_attr('Dismisses this notice after you have read it.') ?>></button>
    </div>
<?php endif; ?>

<?php if ($missingTables !== []): ?>
    <div class="alert alert-warning">
        Student classroom features are not installed yet. Run <a href="upgrade_roles.php">upgrade_roles.php</a> once, then reload this page.
    </div>
<?php else: ?>
    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white"><strong>Announcements</strong></div>
                <div class="card-body">
                    <?php if ($announcements === []): ?>
                        <p class="text-muted mb-0">No announcements yet.</p>
                    <?php else: ?>
                        <?php foreach ($announcements as $item): ?>
                            <div class="border-bottom pb-3 mb-3">
                                <div class="fw-semibold"><?= htmlspecialchars((string) $item['title']) ?></div>
                                <?php if (trim((string) ($item['body'] ?? '')) !== ''): ?>
                                    <div class="small mt-1 classroom-content-body"><?= classroom_content_render_body((string) $item['body']) ?></div>
                                <?php endif; ?>
                                <?php if ($hasContentTopicSchedule && (trim((string) ($item['weeks'] ?? '')) !== '' || trim((string) ($item['days_per_topic'] ?? '')) !== '')): ?>
                                    <div class="small text-muted mt-1">
                                        <?php if (trim((string) ($item['weeks'] ?? '')) !== ''): ?>
                                            <span class="me-2"><i class="fa-regular fa-calendar me-1"></i>Weeks: <?= htmlspecialchars((string) $item['weeks']) ?></span>
                                        <?php endif; ?>
                                        <?php if (trim((string) ($item['days_per_topic'] ?? '')) !== ''): ?>
                                            <span><i class="fa-regular fa-clock me-1"></i>Days/topic: <?= htmlspecialchars((string) $item['days_per_topic']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (trim((string) ($item['resource_url'] ?? '')) !== ''): ?>
                                    <?php $resourceUrl = trim((string) $item['resource_url']); ?>
                                    <?php if (classroom_content_is_attachment($resourceUrl)): ?>
                                        <div class="small mt-2">
                                            <a href="<?= htmlspecialchars(classroom_content_resource_href((int) $item['id'], $resourceUrl)) ?>"<?= student_tooltip_attr('Downloads or opens a file your instructor attached to this announcement. Use this to read handouts or slides they shared.') ?>>
                                                <i class="fa-solid fa-paperclip me-1"></i><?= htmlspecialchars(classroom_content_attachment_name($resourceUrl)) ?>
                                            </a>
                                        </div>
                                    <?php else: ?>
                                        <div class="small mt-2"><a href="<?= htmlspecialchars(classroom_content_resource_href((int) $item['id'], $resourceUrl)) ?>" target="_blank" rel="noopener noreferrer"<?= student_tooltip_attr('Opens the linked resource in a new browser tab. Use this for external sites or documents your instructor linked here.') ?>>Open resource</a></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php foreach ($contentAttachmentMap[(int) $item['id']] ?? [] as $attachment): ?>
                                    <div class="small mt-2">
                                        <a href="<?= htmlspecialchars(classroom_content_attachment_href((int) $attachment['id'])) ?>"<?= student_tooltip_attr('Downloads or opens an extra file attached to this post. Use this when your instructor added more than one document.') ?>>
                                            <i class="fa-solid fa-paperclip me-1"></i><?= htmlspecialchars(classroom_content_attachment_download_name((string) $attachment['original_name'], (string) $attachment['stored_name'])) ?>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                                <div class="small text-muted mt-1"><?= htmlspecialchars((string) $item['created_at']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-header bg-white"><strong>Course Materials</strong></div>
                <div class="card-body">
                    <?php if ($materials === []): ?>
                        <p class="text-muted mb-0">No course materials yet.</p>
                    <?php else: ?>
                        <p class="small text-muted">Open a week to view only that week's faculty topics, materials, links, and attachments.</p>
                        <div class="list-group list-group-flush">
                            <?php foreach ($materialWeeks as $group): ?>
                                <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center px-0" href="student_classroom_week.php?id=<?= (int) $classroomId ?>&week=<?= rawurlencode((string) $group['label']) ?>"<?= student_tooltip_attr('Opens materials and topics for this week only. Use this to focus on what your instructor posted for a specific week.') ?>>
                                    <span>
                                        <i class="fa-regular fa-calendar me-2 text-primary"></i>
                                        <strong><?= htmlspecialchars((string) $group['label']) ?></strong>
                                    </span>
                                    <span class="badge text-bg-primary rounded-pill"><?= (int) $group['count'] ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white"><strong>Class Discussion</strong></div>
                <div class="card-body">
                    <?php if (!$hasClassroomDiscussions): ?>
                        <div class="alert alert-warning mb-0">Run <a href="upgrade_roles.php">upgrade_roles.php</a> once to enable classroom discussion.</div>
                    <?php else: ?>
                        <div class="border rounded p-3 bg-light mb-3" style="max-height: 320px; overflow-y: auto;" id="studentClassDiscussionThread">
                            <?php if ($discussionMessages === []): ?>
                                <p class="text-muted mb-0">No discussion messages yet. Start the conversation for this class.</p>
                            <?php else: ?>
                                <?php foreach ($discussionMessages as $message): ?>
                                    <div class="d-flex mb-3 <?= $message['mine'] ? 'justify-content-end' : 'justify-content-start' ?>">
                                        <div class="rounded-3 px-3 py-2 shadow-sm <?= $message['mine'] ? 'bg-primary text-white' : 'bg-white border' ?>" style="max-width: 85%;">
                                            <div class="small <?= $message['mine'] ? 'text-white-50' : 'text-muted' ?> mb-1">
                                                <?= $message['mine'] ? 'You' : htmlspecialchars($message['full_name']) ?>
                                                <span class="badge bg-<?= htmlspecialchars(classroom_discussion_role_badge_class((string) $message['role'])) ?> ms-1"><?= htmlspecialchars(strtoupper((string) $message['role'])) ?></span>
                                                · <?= htmlspecialchars(substr((string) $message['created_at'], 0, 16)) ?>
                                            </div>
                                            <div><?= nl2br(htmlspecialchars((string) $message['body'])) ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <form method="post">
                            <input type="hidden" name="action" value="post_discussion_message">
                            <input type="hidden" name="classroom_id" value="<?= (int) $classroomId ?>">
                            <label class="visually-hidden" for="studentDiscussionBody">Discussion message</label>
                            <textarea name="message_body" id="studentDiscussionBody" class="form-control mb-2" rows="3" maxlength="8000" placeholder="Send a message to your class discussion..." required></textarea>
                            <div class="text-end">
                                <button type="submit" class="btn btn-primary"<?= student_tooltip_attr('Sends your text to the class discussion thread. Use this to ask questions or respond to classmates and your instructor.') ?>><i class="fa-solid fa-paper-plane me-1"></i>Post message</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-header bg-white"><strong>Assessments</strong></div>
                <div class="card-body">
                    <?php if ($assessments === []): ?>
                        <p class="text-muted mb-0">No assessments yet.</p>
                    <?php endif; ?>

                    <?php foreach ($assessments as $assessment): ?>
                        <div class="border rounded p-3 mb-3">
                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                                <div>
                                    <strong><?= htmlspecialchars((string) $assessment['title']) ?></strong>
                                    <span class="badge <?= classroom_assessment_type_badge_class((string) ($assessment['assessment_type'] ?? 'written_work')) ?> ms-1">
                                        <?= htmlspecialchars(classroom_assessment_type_label((string) ($assessment['assessment_type'] ?? 'written_work'))) ?>
                                    </span>
                                    <div class="small text-muted">
                                        <?= number_format((float) $assessment['total_points'], 2) ?> points
                                        <?php if (!empty($assessment['due_at'])): ?>
                                            | Due: <?= htmlspecialchars((string) $assessment['due_at']) ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (trim((string) ($assessment['description'] ?? '')) !== ''): ?>
                                        <div class="small mt-1 classroom-assessment-desc"><?= classroom_content_render_body((string) $assessment['description']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <?php if ($assessment['score'] !== null): ?>
                                    <div class="text-end">
                                        <span class="badge bg-success">Graded</span>
                                        <div class="small mt-1"><?= number_format((float) $assessment['score'], 2) ?> / <?= number_format((float) $assessment['total_points'], 2) ?></div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($assessment['submitted_at'])): ?>
                                <div class="alert alert-light border small">
                                    Submitted: <?= htmlspecialchars((string) $assessment['submitted_at']) ?>
                                    <?php if (!empty($assessment['submission_status'])): ?>
                                        | Status: <?= htmlspecialchars((string) $assessment['submission_status']) ?>
                                    <?php endif; ?>
                                    <?php if (trim((string) ($assessment['feedback'] ?? '')) !== ''): ?>
                                        <br>Feedback: <?= htmlspecialchars((string) $assessment['feedback']) ?>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <form method="post">
                                <input type="hidden" name="action" value="submit_assessment">
                                <input type="hidden" name="classroom_id" value="<?= (int) $classroomId ?>">
                                <input type="hidden" name="assessment_id" value="<?= (int) $assessment['id'] ?>">
                                <div class="mb-2">
                                    <?php $aid = (int) $assessment['id']; ?>
                                    <label class="form-label" for="student-assessment-answer-<?= $aid ?>">Your answer</label>
                                    <div class="wordpad-shell" data-wordpad data-wordpad-name="answer_text">
                                        <div class="wordpad-toolbar d-none" role="toolbar" aria-label="Formatting toolbar">
                                            <select class="form-select form-select-sm wordpad-select" data-wordpad-block aria-label="Text style">
                                                <option value="<p>">Normal text</option>
                                                <option value="<h3>">Heading</option>
                                                <option value="<blockquote>">Quote</option>
                                            </select>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" data-command="bold" title="Bold"<?= student_tooltip_attr('Makes selected text bold in your answer.') ?>><i class="fa-solid fa-bold"></i></button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" data-command="italic" title="Italic"<?= student_tooltip_attr('Italicizes selected text for emphasis.') ?>><i class="fa-solid fa-italic"></i></button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" data-command="underline" title="Underline"<?= student_tooltip_attr('Underlines selected text.') ?>><i class="fa-solid fa-underline"></i></button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" data-command="insertUnorderedList" title="Bulleted list"<?= student_tooltip_attr('Starts or continues a bulleted list.') ?>><i class="fa-solid fa-list-ul"></i></button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" data-command="insertOrderedList" title="Numbered list"<?= student_tooltip_attr('Starts or continues a numbered list.') ?>><i class="fa-solid fa-list-ol"></i></button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" data-command="justifyLeft" title="Align left"<?= student_tooltip_attr('Aligns paragraph text to the left.') ?>><i class="fa-solid fa-align-left"></i></button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" data-command="justifyCenter" title="Align center"<?= student_tooltip_attr('Centers paragraph text.') ?>><i class="fa-solid fa-align-center"></i></button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" data-command="justifyRight" title="Align right"<?= student_tooltip_attr('Aligns paragraph text to the right.') ?>><i class="fa-solid fa-align-right"></i></button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" data-command="createLink" title="Insert link"<?= student_tooltip_attr('Turns the selection into a hyperlink.') ?>><i class="fa-solid fa-link"></i></button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" data-command="unlink" title="Remove link"<?= student_tooltip_attr('Removes the link from the selected text without deleting the text.') ?>><i class="fa-solid fa-link-slash"></i></button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" data-command="removeFormat" title="Clear formatting"<?= student_tooltip_attr('Strips bold, italics, and other formatting from the selection.') ?>><i class="fa-solid fa-eraser"></i></button>
                                        </div>
                                        <div class="wordpad-editor form-control d-none" contenteditable="true" data-placeholder="Type your answer here…"></div>
                                        <textarea id="student-assessment-answer-<?= $aid ?>" name="answer_text" class="form-control" rows="5" placeholder="Plain text if the rich editor is unavailable"><?= htmlspecialchars((string) ($assessment['answer_text'] ?? '')) ?></textarea>
                                    </div>
                                    <div class="form-text">You can use bold, lists, alignment, and links—similar to WordPad.</div>
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm"<?= student_tooltip_attr('Sends your written answer to your instructor for grading. Use this when you are ready to submit or save changes to your response.') ?>>
                                    <?= !empty($assessment['submitted_at']) ? 'Update answer' : 'Submit answer' ?>
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($hasClassroomDiscussions): ?>
<script>
document.getElementById('studentClassDiscussionThread')?.scrollTo(0, document.getElementById('studentClassDiscussionThread').scrollHeight);
</script>
<?php endif; ?>

<script>
document.querySelectorAll('[data-wordpad]').forEach((shell) => {
    const fieldName = shell.getAttribute('data-wordpad-name') || 'body';
    const textarea = shell.querySelector('textarea[name="' + fieldName + '"]');
    const editor = shell.querySelector('.wordpad-editor');
    const toolbar = shell.querySelector('.wordpad-toolbar');
    const blockSelect = shell.querySelector('[data-wordpad-block]');
    const form = shell.closest('form');

    if (!textarea || !editor || !toolbar || !form) {
        return;
    }

    toolbar.classList.remove('d-none');
    editor.classList.remove('d-none');
    editor.innerHTML = textarea.value.trim() !== '' ? textarea.value : '';
    textarea.classList.add('d-none');

    const syncEditor = () => {
        const html = editor.innerHTML
            .replace(/<div><br><\/div>/gi, '')
            .replace(/&nbsp;/gi, ' ')
            .trim();
        textarea.value = html;
    };

    const runCommand = (command, value = null) => {
        editor.focus();
        document.execCommand('styleWithCSS', false, false);
        document.execCommand(command, false, value);
        syncEditor();
    };

    toolbar.addEventListener('click', (event) => {
        const button = event.target.closest('button[data-command]');
        if (!button) {
            return;
        }

        event.preventDefault();
        const command = button.getAttribute('data-command') || '';

        if (command === 'createLink') {
            const url = window.prompt('Enter link URL', 'https://');
            if (url) {
                runCommand(command, url);
            }
            return;
        }

        runCommand(command);
    });

    blockSelect?.addEventListener('change', (event) => {
        const value = event.target.value || '<p>';
        runCommand('formatBlock', value);
    });

    editor.addEventListener('input', syncEditor);
    editor.addEventListener('blur', syncEditor);
    form.addEventListener('submit', syncEditor);
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

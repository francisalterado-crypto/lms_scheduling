<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_role(['faculty']);
$facultyId = isset($_SESSION['faculty_id']) ? (int) $_SESSION['faculty_id'] : 0;
$userId = (int) $_SESSION['user_id'];
if ($facultyId < 1) {
    $facultyId = resolve_faculty_id_for_user($userId) ?? 0;
    $_SESSION['faculty_id'] = $facultyId > 0 ? $facultyId : null;
}
if ($facultyId < 1) {
    exit('Faculty profile not linked to this account. Ask your dean to create/link your faculty profile.');
}

$facultyCollegeId = faculty_college_id($facultyId);
$scheduleCollegeClause = $facultyCollegeId !== null ? ' AND college_id=?' : '';
$scheduleCollegeParam = $facultyCollegeId !== null ? [$facultyCollegeId] : [];

$hasOnlineUrl = db_column_exists('schedules', 'online_class_url');
$hasLiveAt = db_column_exists('schedules', 'online_live_at');
$hasLiveSessions = db_table_exists('classroom_live_sessions');
$hasMakeupSupport = ensure_makeup_schedule_support();

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$makeupWarningPopup = [];
if (!empty($_SESSION['makeup_warning_popup']) && is_array($_SESSION['makeup_warning_popup'])) {
    $makeupWarningPopup = array_values(array_filter(array_map('strval', $_SESSION['makeup_warning_popup'])));
}
unset($_SESSION['makeup_warning_popup']);
$makeupAckDraft = null;
if (!empty($_SESSION['makeup_ack_draft']) && is_array($_SESSION['makeup_ack_draft'])) {
    $makeupAckDraft = $_SESSION['makeup_ack_draft'];
}
unset($_SESSION['makeup_ack_draft']);

$makeupRooms = [];
if ($hasMakeupSupport) {
    $roomSql = "SELECT id, room_code, room_name FROM rooms WHERE status IN ('available','tba')";
    $roomParams = [];
    if ($facultyCollegeId !== null) {
        $roomSql .= ' AND (college_id=? OR college_id IS NULL)';
        $roomParams[] = $facultyCollegeId;
    }
    $roomSql .= ' ORDER BY room_code';
    $stRooms = db()->prepare($roomSql);
    $stRooms->execute($roomParams);
    $makeupRooms = $stRooms->fetchAll(PDO::FETCH_ASSOC);
    if ($makeupRooms === []) {
        // Fallback: any available room so the form can still submit.
        $makeupRooms = db()->query(
            "SELECT id, room_code, room_name FROM rooms WHERE status IN ('available','tba') ORDER BY room_code"
        )->fetchAll(PDO::FETCH_ASSOC);
    }
}

/**
 * @throws RuntimeException
 */
function faculty_schedule_sanitize_online_url(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    $u = filter_var($raw, FILTER_VALIDATE_URL);
    if ($u === false) {
        throw new RuntimeException('Please enter a valid URL (e.g. https://meet.google.com/...).');
    }
    $scheme = strtolower((string) (parse_url($u, PHP_URL_SCHEME) ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new RuntimeException('Only http and https links are allowed.');
    }
    return $u;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_online_link' && $hasOnlineUrl && isset($_POST['schedule_id'])) {
        $scheduleId = (int) $_POST['schedule_id'];
        try {
            $url = !empty($_POST['clear_online'])
                ? ''
                : faculty_schedule_sanitize_online_url((string) ($_POST['online_class_url'] ?? ''));
            $chk = db()->prepare('SELECT COUNT(*) FROM schedules WHERE id=? AND faculty_id=?' . $scheduleCollegeClause);
            $chk->execute(array_merge([$scheduleId, $facultyId], $scheduleCollegeParam));
            if ((int) $chk->fetchColumn() < 1) {
                throw new RuntimeException('Schedule not found.');
            }
            if ($url === '' && $hasLiveAt) {
                db()->prepare('UPDATE schedules SET online_class_url = NULL, online_live_at = NULL WHERE id=? AND faculty_id=?' . $scheduleCollegeClause)
                    ->execute(array_merge([$scheduleId, $facultyId], $scheduleCollegeParam));
            } else {
                db()->prepare('UPDATE schedules SET online_class_url=? WHERE id=? AND faculty_id=?' . $scheduleCollegeClause)
                    ->execute(array_merge([$url, $scheduleId, $facultyId], $scheduleCollegeParam));
            }
            $_SESSION['flash'] = $url === '' ? 'Online class link cleared.' : 'Online class link saved.';
        } catch (Throwable $e) {
            $_SESSION['flash'] = 'Error: ' . $e->getMessage();
        }
        header('Location: faculty_schedule.php');
        exit;
    }

    if (($action === 'go_live' || $action === 'end_live') && $hasLiveAt && $hasOnlineUrl && isset($_POST['schedule_id'])) {
        $scheduleId = (int) $_POST['schedule_id'];
        try {
            $chk = db()->prepare(
                'SELECT id, day_of_week, start_time, end_time, online_class_url
                 FROM schedules
                 WHERE id=? AND faculty_id=?' . $scheduleCollegeClause . '
                 LIMIT 1'
            );
            $chk->execute(array_merge([$scheduleId, $facultyId], $scheduleCollegeParam));
            $scheduleRow = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$scheduleRow) {
                throw new RuntimeException('Schedule not found.');
            }
            $link = trim((string) ($scheduleRow['online_class_url'] ?? ''));
            if ($link === '') {
                throw new RuntimeException('Add an online class link before going live.');
            }
            if ($action === 'go_live') {
                $window = classroom_attendance_login_allowed($scheduleRow);
                if (!$window['allowed']) {
                    throw new RuntimeException(
                        $window['reason'] !== ''
                            ? $window['reason']
                            : 'Go live is only available during the scheduled class time.'
                    );
                }
                db()->prepare('UPDATE schedules SET online_live_at = NOW() WHERE id=? AND faculty_id=?' . $scheduleCollegeClause)
                    ->execute(array_merge([$scheduleId, $facultyId], $scheduleCollegeParam));
                if ($hasLiveSessions) {
                    $st = db()->prepare('SELECT id FROM online_classrooms WHERE schedule_id = ? AND faculty_id = ? LIMIT 1');
                    $st->execute([$scheduleId, $facultyId]);
                    $classroomId = (int) ($st->fetchColumn() ?: 0);
                    if ($classroomId > 0) {
                        $st = db()->prepare(
                            'SELECT id
                             FROM classroom_live_sessions
                             WHERE classroom_id = ? AND schedule_id = ? AND faculty_id = ? AND ended_at IS NULL
                             ORDER BY id DESC
                             LIMIT 1'
                        );
                        $st->execute([$classroomId, $scheduleId, $facultyId]);
                        if ((int) ($st->fetchColumn() ?: 0) < 1) {
                            db()->prepare(
                                'INSERT INTO classroom_live_sessions (classroom_id, schedule_id, faculty_id, started_at)
                                 VALUES (?,?,?,NOW())'
                            )->execute([$classroomId, $scheduleId, $facultyId]);
                        }
                    }
                }
                $_SESSION['flash'] = 'You are now marked as live for this class.';
            } else {
                db()->prepare('UPDATE schedules SET online_live_at = NULL WHERE id=? AND faculty_id=?' . $scheduleCollegeClause)
                    ->execute(array_merge([$scheduleId, $facultyId], $scheduleCollegeParam));
                if ($hasLiveSessions) {
                    $st = db()->prepare('SELECT id FROM online_classrooms WHERE schedule_id = ? AND faculty_id = ? LIMIT 1');
                    $st->execute([$scheduleId, $facultyId]);
                    $classroomId = (int) ($st->fetchColumn() ?: 0);
                    if ($classroomId > 0) {
                        db()->prepare(
                            'UPDATE classroom_live_sessions
                             SET ended_at = NOW()
                             WHERE classroom_id = ? AND schedule_id = ? AND faculty_id = ? AND ended_at IS NULL'
                        )->execute([$classroomId, $scheduleId, $facultyId]);
                    }
                }
                $_SESSION['flash'] = 'Live session ended.';
            }
        } catch (Throwable $e) {
            $_SESSION['flash'] = 'Error: ' . $e->getMessage();
        }
        header('Location: faculty_schedule.php');
        exit;
    }

    if (isset($_POST['schedule_id'])) {
        $scheduleId = (int) $_POST['schedule_id'];
        $message = trim((string) ($_POST['message'] ?? ''));
        $requestType = (string) ($_POST['request_type'] ?? 'change');
        if ($requestType === 'makeup' && $hasMakeupSupport) {
            $day = trim((string) ($_POST['makeup_day'] ?? ''));
            $startRaw = substr((string) ($_POST['makeup_start_time'] ?? ''), 0, 5);
            $endRaw = substr((string) ($_POST['makeup_end_time'] ?? ''), 0, 5);
            $roomId = (int) ($_POST['makeup_room_id'] ?? 0);
            $allowedDays = array_flip(schedule_days_list());
            if ($message === '') {
                $_SESSION['flash'] = 'Please enter a reason for the makeup class.';
            } elseif (!isset($allowedDays[$day]) || $startRaw === '' || $endRaw === '' || $roomId < 1) {
                $_SESSION['flash'] = 'Makeup day, time, and room are required.';
            } else {
                $startTime = $startRaw . ':00';
                $endTime = $endRaw . ':00';
                $ruleCheck = validate_schedule_rules('Custom', [$day], $startTime, $endTime);
                if (empty($ruleCheck['ok'])) {
                    $errs = $ruleCheck['errors'] ?? [];
                    $_SESSION['flash'] = implode(' ', is_array($errs) ? $errs : ['Invalid makeup time.']);
                    $_SESSION['makeup_warning_popup'] = is_array($errs) ? $errs : ['Invalid makeup time.'];
                } else {
                    try {
                        $chk = db()->prepare(
                            'SELECT id, faculty_id, room_id, college_id, semester, school_year
                             FROM schedules WHERE id=? AND faculty_id=?' . $scheduleCollegeClause . ' LIMIT 1'
                        );
                        $chk->execute(array_merge([$scheduleId, $facultyId], $scheduleCollegeParam));
                        $baseRow = $chk->fetch(PDO::FETCH_ASSOC);
                        if (!$baseRow) {
                            $_SESSION['flash'] = 'Schedule not found.';
                        } else {
                            $collegeForCheck = isset($baseRow['college_id']) && $baseRow['college_id'] !== null
                                ? (int) $baseRow['college_id']
                                : $facultyCollegeId;
                            $conflictMsgs = makeup_hard_conflict_messages(
                                (int) $baseRow['faculty_id'],
                                $roomId,
                                [$day],
                                $startTime,
                                $endTime,
                                (string) $baseRow['semester'],
                                (string) $baseRow['school_year'],
                                $collegeForCheck
                            );
                            $ackConflicts = !empty($_POST['ack_conflicts']);
                            if ($conflictMsgs !== [] && !$ackConflicts) {
                                $_SESSION['makeup_warning_popup'] = $conflictMsgs;
                                $_SESSION['makeup_ack_draft'] = [
                                    'schedule_id' => $scheduleId,
                                    'makeup_day' => $day,
                                    'makeup_start_time' => $startRaw,
                                    'makeup_end_time' => $endRaw,
                                    'makeup_room_id' => $roomId,
                                    'message' => $message,
                                ];
                                $_SESSION['flash'] = 'This makeup slot conflicts with an existing faculty or room schedule. See the warning, then confirm to submit anyway.';
                            } else {
                                db()->prepare(
                                    'INSERT INTO schedule_change_requests
                                     (faculty_user_id, schedule_id, request_type, message, proposed_day_of_week, proposed_start_time, proposed_end_time, proposed_room_id)
                                     VALUES (?,?,?,?,?,?,?,?)'
                                )->execute([$userId, $scheduleId, 'makeup', $message, $day, $startTime, $endTime, $roomId]);
                                $_SESSION['flash'] = $conflictMsgs !== []
                                    ? 'Makeup request submitted with known conflicts. Your dean will review them.'
                                    : 'Makeup class request submitted to your dean.';
                            }
                        }
                    } catch (Throwable $e) {
                        $_SESSION['flash'] = 'Could not submit makeup request: ' . $e->getMessage();
                    }
                }
            }
        } elseif ($message !== '') {
            $chk = db()->prepare('SELECT COUNT(*) FROM schedules WHERE id=? AND faculty_id=?' . $scheduleCollegeClause);
            $chk->execute(array_merge([$scheduleId, $facultyId], $scheduleCollegeParam));
            if ((int) $chk->fetchColumn() > 0) {
                if ($hasMakeupSupport) {
                    db()->prepare(
                        'INSERT INTO schedule_change_requests (faculty_user_id, schedule_id, request_type, message) VALUES (?,?,?,?)'
                    )->execute([$userId, $scheduleId, 'change', $message]);
                } else {
                    db()->prepare('INSERT INTO schedule_change_requests (faculty_user_id, schedule_id, message) VALUES (?,?,?)')
                        ->execute([$userId, $scheduleId, $message]);
                }
                $_SESSION['flash'] = 'Change request submitted to your dean.';
            }
        }
        header('Location: faculty_schedule.php');
        exit;
    }
}

$sql = 'SELECT s.*, c.course_code, c.course_name, r.room_code
     FROM schedules s
     INNER JOIN courses c ON c.id = s.course_id
     INNER JOIN rooms r ON r.id = s.room_id
     WHERE s.faculty_id=?';
$params = [$facultyId];
if ($facultyCollegeId !== null) {
    $sql .= ' AND s.college_id=?';
    $params[] = $facultyCollegeId;
}
$sql .= ' ORDER BY s.school_year DESC, s.semester, s.start_time';
$st = db()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$formatTime12h = static function (?string $time): string {
    $raw = substr((string) $time, 0, 5);
    $dt = DateTime::createFromFormat('H:i', $raw);
    return $dt ? $dt->format('g:i A') : $raw;
};

$pageTitle = 'My Schedule';
require_once __DIR__ . '/includes/header.php';
?>
<header class="app-page-header d-flex flex-wrap justify-content-between align-items-start gap-3">
    <div>
        <h1 class="app-page-title mb-2"><i class="fa-solid fa-calendar-check me-2 app-page-title-icon" aria-hidden="true"></i>My schedule</h1>
        <p class="app-page-lead mb-0">Your assigned sections, meeting links, and live status. Request a schedule change or a makeup class slot when needed.</p>
    </div>
    <a href="faculty_classrooms.php" class="btn btn-outline-primary btn-sm rounded-pill align-self-start"<?= app_tooltip_attr('Opens your online class list to manage content, students, and Meet links.') ?>><i class="fa-solid fa-chalkboard me-1" aria-hidden="true"></i>My classrooms</a>
</header>

<?php if ($flash): ?><?php render_information_popup((string) $flash); ?><?php endif; ?>
<?php if (!$hasOnlineUrl): ?>
    <div class="alert alert-warning app-alert">Online class links require a database update. Ask your administrator to run <a href="upgrade_roles.php">upgrade_roles.php</a> once.</div>
<?php endif; ?>

<div class="app-card app-schedule-card mb-0">
    <div class="app-card-header">Weekly assignments</div>
    <div class="table-responsive">
        <table class="table app-table mb-0">
            <thead>
                <tr>
                    <th scope="col">Course</th>
                    <th scope="col">Days</th>
                    <th scope="col">Time</th>
                    <th scope="col">Room</th>
                    <?php if ($hasOnlineUrl): ?><th scope="col">Online class</th><?php endif; ?>
                    <th scope="col">Term</th>
                    <th scope="col">Requests</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="<?= $hasOnlineUrl ? '7' : '6' ?>" class="app-empty-state">No schedule assigned.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td data-label="Course">
                        <span class="fw-medium"><?= htmlspecialchars($r['course_code']) ?></span>
                        <?php if ($hasMakeupSupport && (int) ($r['is_makeup'] ?? 0) === 1): ?>
                            <span class="badge bg-warning text-dark ms-1">Makeup</span>
                        <?php endif; ?>
                        <span class="text-muted d-block small"><?= htmlspecialchars($r['course_name']) ?></span>
                    </td>
                    <td data-label="Days"><?= htmlspecialchars(str_replace(',', ', ', (string) $r['day_of_week'])) ?></td>
                    <td data-label="Time"><?= htmlspecialchars($formatTime12h((string) $r['start_time'])) ?> – <?= htmlspecialchars($formatTime12h((string) $r['end_time'])) ?></td>
                    <td data-label="Room"><?= htmlspecialchars($r['room_code']) ?></td>
                    <?php if ($hasOnlineUrl): ?>
                        <td class="align-top" style="min-width: 220px;" data-label="Online class">
                            <?php
                            $link = trim((string) ($r['online_class_url'] ?? ''));
                            ?>
                            <?php if ($link !== ''): ?>
                                <div class="mb-1">
                                    <a href="<?= htmlspecialchars($link) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-primary"<?= app_tooltip_attr('Opens your saved online class URL (e.g. Meet) in a new tab for this section.') ?>>
                                        <i class="fa-solid fa-video me-1"></i>Join online
                                    </a>
                                </div>
                            <?php endif; ?>
                            <form method="post" class="d-flex flex-column gap-1">
                                <input type="hidden" name="action" value="save_online_link">
                                <input type="hidden" name="schedule_id" value="<?= (int) $r['id'] ?>">
                                <input type="url" name="online_class_url" class="form-control form-control-sm" placeholder="https://..." value="<?= htmlspecialchars($link) ?>" autocomplete="url">
                                <div class="d-flex gap-1 flex-wrap">
                                    <button type="submit" class="btn btn-sm btn-primary"<?= app_tooltip_attr('Saves the online meeting URL for this section so students and deans can use it.') ?>>Save link</button>
                                    <?php if ($link !== ''): ?>
                                        <button type="submit" name="clear_online" value="1" class="btn btn-sm btn-outline-secondary" title="Remove link"<?= app_tooltip_attr('Removes the saved online link for this row. Use this when the meeting URL changes completely.') ?>>Clear</button>
                                    <?php endif; ?>
                                </div>
                            </form>
                            <?php if ($hasLiveAt && $link !== ''): ?>
                                <?php
                                $liveAtRaw = $r['online_live_at'] ?? null;
                                $liveTs = $liveAtRaw ? strtotime((string) $liveAtRaw) : false;
                                $liveWindow = classroom_attendance_login_allowed($r);
                                $isWithinClassTime = !empty($liveWindow['allowed']);
                                $wasMarkedLive = $liveTs !== false && (time() - $liveTs) <= 2 * 3600;
                                $isLiveNow = $wasMarkedLive && $isWithinClassTime;
                                $goLiveDisabledReason = (string) ($liveWindow['reason'] ?? '');
                                if ($goLiveDisabledReason === '') {
                                    $goLiveDisabledReason = 'Go live is only available during the scheduled class time.';
                                }
                                ?>
                                <form method="post" class="mt-2">
                                    <input type="hidden" name="schedule_id" value="<?= (int) $r['id'] ?>">
                                    <?php if ($wasMarkedLive): ?>
                                        <input type="hidden" name="action" value="end_live">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"<?= app_tooltip_attr('Stops the LIVE indicator for this class on the weekly view. Use this when class ends.') ?>><i class="fa-solid fa-stop me-1"></i>End live</button>
                                        <?php if ($isLiveNow): ?>
                                            <span class="badge bg-danger live-pulse-badge ms-1">LIVE</span>
                                        <?php else: ?>
                                            <span class="small text-muted ms-1">Class time ended — clear live status</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <input type="hidden" name="action" value="go_live">
                                        <button type="submit" class="btn btn-sm btn-success"<?= $isWithinClassTime ? '' : ' disabled' ?><?= app_tooltip_attr($isWithinClassTime ? 'Marks this section as live so deans and students see the LIVE badge on the weekly schedule.' : $goLiveDisabledReason) ?>><i class="fa-solid fa-broadcast-tower me-1"></i>Go live</button>
                                        <?php if ($isWithinClassTime): ?>
                                            <span class="small text-muted ms-1">Deans see LIVE on weekly view</span>
                                        <?php else: ?>
                                            <span class="small text-muted ms-1 d-block mt-1"><?= htmlspecialchars($goLiveDisabledReason) ?></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </form>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                    <td data-label="Term"><?= htmlspecialchars($r['semester']) ?> / <?= htmlspecialchars($r['school_year']) ?></td>
                    <td data-label="Requests" style="min-width: 280px;">
                        <form method="post" class="d-flex flex-column gap-2 mb-3">
                            <input type="hidden" name="schedule_id" value="<?= (int) $r['id'] ?>">
                            <input type="hidden" name="request_type" value="change">
                            <label class="visually-hidden" for="sched-req-<?= (int) $r['id'] ?>">Reason for schedule change</label>
                            <input id="sched-req-<?= (int) $r['id'] ?>" type="text" name="message" class="form-control form-control-sm" placeholder="Reason for change" required autocomplete="off">
                            <button type="submit" class="btn btn-sm btn-outline-primary rounded-pill align-self-start"<?= app_tooltip_attr('Sends a schedule change request to your dean with the reason you typed.') ?>>Request change</button>
                        </form>
                        <?php if ($hasMakeupSupport && (int) ($r['is_makeup'] ?? 0) !== 1): ?>
                            <form method="post" class="d-flex flex-column gap-2 border-top pt-2">
                                <input type="hidden" name="schedule_id" value="<?= (int) $r['id'] ?>">
                                <input type="hidden" name="request_type" value="makeup">
                                <div class="small fw-semibold text-muted">Makeup class</div>
                                <label class="visually-hidden" for="makeup-day-<?= (int) $r['id'] ?>">Makeup day</label>
                                <select id="makeup-day-<?= (int) $r['id'] ?>" name="makeup_day" class="form-select form-select-sm" required>
                                    <option value="">Day</option>
                                    <?php foreach (schedule_days_list() as $dayName): ?>
                                        <option value="<?= htmlspecialchars($dayName) ?>"><?= htmlspecialchars($dayName) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="d-flex gap-1">
                                    <label class="visually-hidden" for="makeup-start-<?= (int) $r['id'] ?>">Start time</label>
                                    <input id="makeup-start-<?= (int) $r['id'] ?>" type="time" name="makeup_start_time" class="form-control form-control-sm" value="<?= htmlspecialchars(substr((string) $r['start_time'], 0, 5)) ?>" required>
                                    <label class="visually-hidden" for="makeup-end-<?= (int) $r['id'] ?>">End time</label>
                                    <input id="makeup-end-<?= (int) $r['id'] ?>" type="time" name="makeup_end_time" class="form-control form-control-sm" value="<?= htmlspecialchars(substr((string) $r['end_time'], 0, 5)) ?>" required>
                                </div>
                                <label class="visually-hidden" for="makeup-room-<?= (int) $r['id'] ?>">Room</label>
                                <select id="makeup-room-<?= (int) $r['id'] ?>" name="makeup_room_id" class="form-select form-select-sm" required>
                                    <option value="">Room</option>
                                    <?php foreach ($makeupRooms as $room): ?>
                                        <option value="<?= (int) $room['id'] ?>"<?= (int) $room['id'] === (int) $r['room_id'] ? ' selected' : '' ?>><?= htmlspecialchars((string) $room['room_code']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($makeupRooms === []): ?>
                                    <div class="small text-danger">No rooms available. Ask your dean to add rooms first.</div>
                                <?php endif; ?>
                                <label class="visually-hidden" for="makeup-msg-<?= (int) $r['id'] ?>">Makeup reason</label>
                                <input id="makeup-msg-<?= (int) $r['id'] ?>" type="text" name="message" class="form-control form-control-sm" placeholder="Reason for makeup" required autocomplete="off">
                                <button type="submit" class="btn btn-sm btn-warning rounded-pill align-self-start"<?= $makeupRooms === [] ? ' disabled' : '' ?><?= app_tooltip_attr('Requests a temporary makeup slot. Your dean reviews it and, if approved, adds it to the weekly schedule. Delete the makeup row after the class is held.') ?>>Request makeup</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($makeupAckDraft !== null): ?>
    <?php
    $draftId = (int) ($makeupAckDraft['schedule_id'] ?? 0);
    $draftDay = (string) ($makeupAckDraft['makeup_day'] ?? '');
    $draftStart = (string) ($makeupAckDraft['makeup_start_time'] ?? '');
    $draftEnd = (string) ($makeupAckDraft['makeup_end_time'] ?? '');
    $draftRoom = (int) ($makeupAckDraft['makeup_room_id'] ?? 0);
    $draftMsg = (string) ($makeupAckDraft['message'] ?? '');
    ?>
    <div class="modal fade" id="makeupAckConflictsModal" tabindex="-1" aria-labelledby="makeupAckConflictsModalLabel" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-warning">
                <div class="modal-header bg-warning-subtle">
                    <h5 class="modal-title text-warning-emphasis" id="makeupAckConflictsModalLabel">
                        <i class="fa-solid fa-triangle-exclamation me-2"></i>Warning — Makeup conflicts with faculty or room schedule
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">This makeup slot conflicts with an existing <strong>faculty</strong> or <strong>room</strong> schedule:</p>
                    <ul class="mb-3">
                        <?php foreach ($makeupWarningPopup as $w): ?>
                            <li><?= htmlspecialchars($w) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="mb-0 small text-muted">Your dean will still review the request. Only continue if the overlap is intentional.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="post" class="d-inline">
                        <input type="hidden" name="schedule_id" value="<?= $draftId ?>">
                        <input type="hidden" name="request_type" value="makeup">
                        <input type="hidden" name="makeup_day" value="<?= htmlspecialchars($draftDay) ?>">
                        <input type="hidden" name="makeup_start_time" value="<?= htmlspecialchars($draftStart) ?>">
                        <input type="hidden" name="makeup_end_time" value="<?= htmlspecialchars($draftEnd) ?>">
                        <input type="hidden" name="makeup_room_id" value="<?= $draftRoom ?>">
                        <input type="hidden" name="message" value="<?= htmlspecialchars($draftMsg) ?>">
                        <input type="hidden" name="ack_conflicts" value="1">
                        <button type="submit" class="btn btn-warning">Submit anyway</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var ackEl = document.getElementById('makeupAckConflictsModal');
        if (!ackEl) return;
        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            bootstrap.Modal.getOrCreateInstance(ackEl).show();
            return;
        }
        var items = [];
        ackEl.querySelectorAll('.modal-body li').forEach(function (li) {
            items.push(li.textContent.trim());
        });
        if (window.confirm('Warning — Makeup conflicts with faculty or room schedule:\n\n- ' + items.join('\n- ') + '\n\nSubmit anyway?')) {
            var form = ackEl.querySelector('form');
            if (form) form.submit();
        }
    });
    </script>
<?php elseif ($makeupWarningPopup !== []): ?>
    <?php
    render_schedule_errors_warning_popup(
        $makeupWarningPopup,
        'Warning — Makeup conflicts with faculty or room schedule',
        'OK'
    );
    ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

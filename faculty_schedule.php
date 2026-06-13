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

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

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
            $chk = db()->prepare('SELECT COUNT(*) FROM schedules WHERE id=? AND faculty_id=? AND online_class_url IS NOT NULL AND TRIM(online_class_url) != ""' . $scheduleCollegeClause);
            $chk->execute(array_merge([$scheduleId, $facultyId], $scheduleCollegeParam));
            if ((int) $chk->fetchColumn() < 1) {
                throw new RuntimeException('Add an online class link before going live.');
            }
            if ($action === 'go_live') {
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
        if ($message !== '') {
            $chk = db()->prepare('SELECT COUNT(*) FROM schedules WHERE id=? AND faculty_id=?' . $scheduleCollegeClause);
            $chk->execute(array_merge([$scheduleId, $facultyId], $scheduleCollegeParam));
            if ((int) $chk->fetchColumn() > 0) {
                db()->prepare('INSERT INTO schedule_change_requests (faculty_user_id, schedule_id, message) VALUES (?,?,?)')
                    ->execute([$userId, $scheduleId, $message]);
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
        <p class="app-page-lead mb-0">Your assigned sections, meeting links, and live status. Submit a short note to your dean if you need a schedule change.</p>
    </div>
    <a href="faculty_classrooms.php" class="btn btn-outline-primary btn-sm rounded-pill align-self-start"<?= app_tooltip_attr('Opens your online class list to manage content, students, and Meet links.') ?>><i class="fa-solid fa-chalkboard me-1" aria-hidden="true"></i>My classrooms</a>
</header>

<?php if ($flash): ?>
    <div class="alert alert-success app-alert border-success-subtle" role="status"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>
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
                    <th scope="col">Request change</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="<?= $hasOnlineUrl ? '7' : '6' ?>" class="app-empty-state">No schedule assigned.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td data-label="Course"><span class="fw-medium"><?= htmlspecialchars($r['course_code']) ?></span><span class="text-muted d-block small"><?= htmlspecialchars($r['course_name']) ?></span></td>
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
                                $isLiveNow = $liveTs !== false && (time() - $liveTs) <= 2 * 3600;
                                ?>
                                <form method="post" class="mt-2">
                                    <input type="hidden" name="schedule_id" value="<?= (int) $r['id'] ?>">
                                    <?php if ($isLiveNow): ?>
                                        <input type="hidden" name="action" value="end_live">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"<?= app_tooltip_attr('Stops the LIVE indicator for this class on the weekly view. Use this when class ends.') ?>><i class="fa-solid fa-stop me-1"></i>End live</button>
                                        <span class="badge bg-danger live-pulse-badge ms-1">LIVE</span>
                                    <?php else: ?>
                                        <input type="hidden" name="action" value="go_live">
                                        <button type="submit" class="btn btn-sm btn-success"<?= app_tooltip_attr('Marks this section as live so deans and students see the LIVE badge on the weekly schedule.') ?>><i class="fa-solid fa-broadcast-tower me-1"></i>Go live</button>
                                        <span class="small text-muted ms-1">Deans see LIVE on weekly view</span>
                                    <?php endif; ?>
                                </form>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                    <td data-label="Term"><?= htmlspecialchars($r['semester']) ?> / <?= htmlspecialchars($r['school_year']) ?></td>
                    <td data-label="Request change">
                        <form method="post" class="d-flex flex-column flex-xl-row gap-2 align-items-stretch align-items-xl-center">
                            <input type="hidden" name="schedule_id" value="<?= (int) $r['id'] ?>">
                            <label class="visually-hidden" for="sched-req-<?= (int) $r['id'] ?>">Reason for schedule change</label>
                            <input id="sched-req-<?= (int) $r['id'] ?>" type="text" name="message" class="form-control form-control-sm flex-grow-1" placeholder="Reason for change" required autocomplete="off">
                            <button type="submit" class="btn btn-sm btn-outline-primary rounded-pill flex-shrink-0"<?= app_tooltip_attr('Sends a schedule change request to your dean with the reason you typed. Use this when you cannot meet the assigned time or room.') ?>>Send</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

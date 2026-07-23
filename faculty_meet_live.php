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
    exit('Faculty profile not linked to this account.');
}

$classroomId = (int) ($_GET['classroom_id'] ?? 0);
if ($classroomId < 1) {
    http_response_code(400);
    exit('Missing classroom.');
}

$st = db()->prepare(
    'SELECT oc.id, oc.meet_link, oc.title, oc.schedule_id,
            s.day_of_week, s.start_time, s.end_time, s.online_live_at,
            c.course_code, c.course_name
     FROM online_classrooms oc
     INNER JOIN schedules s ON s.id = oc.schedule_id
     INNER JOIN courses c ON c.id = oc.course_id
     WHERE oc.id = ? AND oc.faculty_id = ? AND s.faculty_id = ?
     LIMIT 1'
);
$st->execute([$classroomId, $facultyId, $facultyId]);
$classroom = $st->fetch(PDO::FETCH_ASSOC);
if (!$classroom) {
    http_response_code(404);
    exit('Classroom not found.');
}

$meetLink = filter_var(trim((string) ($classroom['meet_link'] ?? '')), FILTER_VALIDATE_URL);
if ($meetLink === false) {
    http_response_code(400);
    exit('This classroom does not have a valid Meet link.');
}

$scheduleId = (int) $classroom['schedule_id'];
$window = classroom_attendance_login_allowed($classroom);
$sessionEndTs = strtotime($window['session_end']);
if ($sessionEndTs === false) {
    $sessionEndTs = time() + 7200;
}

$pageTitle = 'Live session — ' . (string) $classroom['course_code'];
require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4" style="max-width: 520px;">
    <div class="card border-0 shadow-sm">
        <div class="card-body p-4 text-center">
            <h1 class="h5 mb-2">
                <span class="badge bg-danger live-pulse-badge me-1">LIVE</span>
                <?= htmlspecialchars((string) $classroom['course_code']) ?>
            </h1>
            <p class="text-muted small mb-3"><?= htmlspecialchars((string) $classroom['course_name']) ?></p>
            <p id="meet-live-status" class="mb-3">Opening Google Meet…</p>
            <p class="small text-muted mb-0">When you leave or close Google Meet, the <strong>LIVE</strong> badge on the weekly schedule turns off automatically.</p>
            <div class="mt-3 d-flex flex-wrap justify-content-center gap-2">
                <button type="button" class="btn btn-success btn-sm" id="meet-live-reopen"><i class="fa-solid fa-video me-1"></i>Open Meet again</button>
                <button type="button" class="btn btn-outline-danger btn-sm" id="meet-live-end"><i class="fa-solid fa-stop me-1"></i>End live now</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var meetUrl = <?= json_encode($meetLink, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var scheduleId = <?= (int) $scheduleId ?>;
    var sessionEndMs = <?= (int) ($sessionEndTs * 1000) ?>;
    var endUrl = <?= json_encode('api/faculty_end_live.php', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var statusEl = document.getElementById('meet-live-status');
    var ended = false;
    var meetWin = null;

    function setStatus(text) {
        if (statusEl) {
            statusEl.textContent = text;
        }
    }

    function endLiveSession() {
        if (ended) {
            return;
        }
        ended = true;
        setStatus('Ending live session…');

        var body = new FormData();
        body.append('schedule_id', String(scheduleId));

        if (navigator.sendBeacon) {
            navigator.sendBeacon(endUrl, body);
        } else {
            fetch(endUrl, { method: 'POST', body: body, credentials: 'same-origin' }).catch(function () {});
        }

        setStatus('Live session ended. You may close this tab.');
        document.querySelectorAll('#meet-live-reopen, #meet-live-end').forEach(function (btn) {
            btn.disabled = true;
        });
    }

    function openMeet() {
        if (ended) {
            return;
        }
        meetWin = window.open(meetUrl, 'classMeetSession');
        if (!meetWin) {
            setStatus('Allow pop-ups for this site, then click “Open Meet again”.');
            return;
        }
        setStatus('Google Meet is open. Close Meet when class ends to turn off LIVE.');
    }

    openMeet();

    var poll = window.setInterval(function () {
        if (ended) {
            window.clearInterval(poll);
            return;
        }
        if (meetWin && meetWin.closed) {
            window.clearInterval(poll);
            endLiveSession();
        }
        if (Date.now() >= sessionEndMs) {
            window.clearInterval(poll);
            endLiveSession();
        }
    }, 1000);

    window.addEventListener('pagehide', endLiveSession);

    var reopenBtn = document.getElementById('meet-live-reopen');
    if (reopenBtn) {
        reopenBtn.addEventListener('click', openMeet);
    }
    var endBtn = document.getElementById('meet-live-end');
    if (endBtn) {
        endBtn.addEventListener('click', function () {
            if (meetWin && !meetWin.closed) {
                meetWin.close();
            }
            endLiveSession();
        });
    }
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

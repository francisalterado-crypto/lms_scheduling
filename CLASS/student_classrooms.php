<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_role(['student']);

$studentId = isset($_SESSION['student_id']) ? (int) $_SESSION['student_id'] : 0;
$userId = (int) ($_SESSION['user_id'] ?? 0);
$hasLiveAt = db_column_exists('schedules', 'online_live_at');
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
$formatTime12h = static function (?string $time): string {
    $raw = substr((string) $time, 0, 5);
    $dt = DateTime::createFromFormat('H:i', $raw);
    return $dt ? $dt->format('g:i A') : $raw;
};

if ($studentId < 1) {
    $studentId = resolve_student_id_for_user($userId) ?? 0;
    $_SESSION['student_id'] = $studentId > 0 ? $studentId : null;
}
if ($studentId < 1) {
    exit('Student profile not linked to this account. Ask your instructor to create or link your student profile.');
}

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
$hasJoinCode = db_table_exists('online_classrooms') && db_column_exists('online_classrooms', 'join_code');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $missingTables === [] && $hasJoinCode) {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'join_with_code') {
        try {
            $code = classroom_normalize_join_code((string) ($_POST['join_code'] ?? ''));
            if (strlen($code) < 4) {
                throw new RuntimeException('Enter the class code your instructor shared.');
            }
            $st = db()->prepare(
                'SELECT oc.id, oc.status, s.college_id AS schedule_college_id
                 FROM online_classrooms oc
                 INNER JOIN schedules s ON s.id = oc.schedule_id
                 WHERE oc.join_code = ? LIMIT 1'
            );
            $st->execute([$code]);
            $row = $st->fetch();
            if (!$row) {
                throw new RuntimeException('No class matches that code. Check with your instructor and try again.');
            }
            if ((string) $row['status'] !== 'active') {
                throw new RuntimeException('That class is not accepting new enrollments.');
            }
            $schedCollege = $row['schedule_college_id'] !== null ? (int) $row['schedule_college_id'] : null;
            $userCollege = current_college_id();
            if ($schedCollege !== null && ($userCollege === null || $userCollege !== $schedCollege)) {
                throw new RuntimeException('That class is for a different college.');
            }
            $joinClassroomId = (int) $row['id'];
            $chk = db()->prepare('SELECT COUNT(*) FROM classroom_enrollments WHERE classroom_id = ? AND student_id = ?');
            $chk->execute([$joinClassroomId, $studentId]);
            if ((int) $chk->fetchColumn() > 0) {
                throw new RuntimeException('You are already enrolled in that class.');
            }
            db()->prepare('INSERT INTO classroom_enrollments (classroom_id, student_id) VALUES (?,?)')
                ->execute([$joinClassroomId, $studentId]);
            $_SESSION['flash'] = 'You joined the class successfully.';
        } catch (Throwable $e) {
            $_SESSION['flash'] = 'Error: ' . $e->getMessage();
        }
        header('Location: student_classrooms.php');
        exit;
    }
}

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$classes = [];
$announcements = [];

if ($missingTables === []) {
    $st = db()->prepare(
        'SELECT oc.id, oc.title, oc.description, oc.meet_link, oc.status,
                c.course_code, c.course_name, f.full_name AS faculty_name,
                s.semester, s.school_year, s.day_of_week, s.start_time, s.end_time, s.online_live_at,
                (SELECT COUNT(*) FROM classroom_content cc WHERE cc.classroom_id = oc.id AND cc.content_type = "announcement") AS announcement_count,
                (SELECT COUNT(*) FROM classroom_assessments ca WHERE ca.classroom_id = oc.id) AS assessment_count,
                (SELECT COUNT(*)
                   FROM classroom_assessments ca
                   LEFT JOIN classroom_submissions sub
                     ON sub.assessment_id = ca.id AND sub.student_id = ?
                  WHERE ca.classroom_id = oc.id AND sub.id IS NULL) AS pending_count
         FROM classroom_enrollments ce
         INNER JOIN online_classrooms oc ON oc.id = ce.classroom_id
         INNER JOIN schedules s ON s.id = oc.schedule_id
         INNER JOIN courses c ON c.id = oc.course_id
         INNER JOIN faculty f ON f.id = oc.faculty_id
         WHERE ce.student_id = ?
         ORDER BY s.school_year DESC, s.semester, c.course_code'
    );
    $st->execute([$studentId, $studentId]);
    $classes = $st->fetchAll();

    $st = db()->prepare(
        'SELECT oc.id AS classroom_id, oc.title AS classroom_title, cc.title, cc.body, cc.created_at
         FROM classroom_content cc
         INNER JOIN online_classrooms oc ON oc.id = cc.classroom_id
         INNER JOIN classroom_enrollments ce ON ce.classroom_id = oc.id
         WHERE ce.student_id = ? AND cc.content_type = "announcement"
         ORDER BY cc.created_at DESC
         LIMIT 10'
    );
    $st->execute([$studentId]);
    $announcements = $st->fetchAll();
}

$pageTitle = 'My Classes';
require_once __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <h1 class="h3 mb-0"><i class="fa-solid fa-user-graduate me-2 text-primary"></i>My Classes</h1>
</div>

<?php if ($flash): ?>
    <div class="alert alert-info alert-dismissible fade show">
        <?= htmlspecialchars($flash) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"<?= student_tooltip_attr('Dismisses this notice. Use this after you have read the message so it stays out of your way.') ?>></button>
    </div>
<?php endif; ?>

<?php if ($missingTables !== []): ?>
    <div class="alert alert-warning">
        Student classroom features are not installed yet. Run <a href="upgrade_roles.php">upgrade_roles.php</a> once, then reload this page.
    </div>
<?php else: ?>
    <?php if ($hasJoinCode): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white"><strong>Join a class with a code</strong></div>
            <div class="card-body">
                <p class="small text-muted mb-3">Your program chair or instructor gives you a code. Enter it here to add the class to your list.</p>
                <form method="post" class="row g-2 align-items-end">
                    <input type="hidden" name="action" value="join_with_code">
                    <div class="col-md-6 col-lg-4">
                        <label class="form-label" for="join-code-input">Class code</label>
                        <input id="join-code-input" type="text" name="join_code" class="form-control font-monospace text-uppercase" maxlength="16" placeholder="e.g. AB12CD34" autocomplete="off" required>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary"<?= student_tooltip_attr('Enrolls you in the class that matches the code you entered. Use this after your instructor or program chair shares a join code with you.') ?>>Join class</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="row g-3">
                <?php if ($classes === []): ?>
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-body text-muted">You are not enrolled in any online classroom yet.</div>
                        </div>
                    </div>
                <?php endif; ?>
                <?php foreach ($classes as $class): ?>
                    <div class="col-md-6">
                        <?php
                        $liveAt = $hasLiveAt ? (string) ($class['online_live_at'] ?? '') : '';
                        $isLive = $hasLiveAt && $classIsLive($liveAt);
                        ?>
                        <div class="card shadow-sm h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <div>
                                        <h2 class="h5 mb-1"><?= htmlspecialchars((string) $class['title']) ?></h2>
                                        <div class="text-muted small"><?= htmlspecialchars((string) $class['course_code']) ?> - <?= htmlspecialchars((string) $class['course_name']) ?></div>
                                    </div>
                                    <div class="d-flex flex-wrap justify-content-end gap-1">
                                        <?php if ($isLive): ?>
                                            <span class="badge bg-danger live-pulse-badge">LIVE</span>
                                        <?php endif; ?>
                                        <span class="badge <?= (string) $class['status'] === 'archived' ? 'bg-secondary' : 'bg-success' ?>"><?= htmlspecialchars((string) $class['status']) ?></span>
                                    </div>
                                </div>
                                <p class="small mt-3 mb-2">Instructor: <?= htmlspecialchars((string) $class['faculty_name']) ?></p>
                                <p class="small text-muted mb-3"><?= htmlspecialchars((string) $class['semester']) ?> / <?= htmlspecialchars((string) $class['school_year']) ?></p>
                                <p class="small text-muted mb-3">
                                    <i class="fa-solid fa-calendar-days me-1"></i><?= htmlspecialchars(str_replace(',', ', ', (string) ($class['day_of_week'] ?? ''))) ?>
                                    <br>
                                    <i class="fa-solid fa-clock me-1"></i><?= htmlspecialchars($formatTime12h((string) ($class['start_time'] ?? ''))) ?> - <?= htmlspecialchars($formatTime12h((string) ($class['end_time'] ?? ''))) ?>
                                </p>
                                <?php if ($isLive): ?>
                                    <div class="small text-danger fw-semibold mb-3">
                                        <i class="fa-solid fa-circle-play me-1"></i>Your instructor is live now.
                                    </div>
                                <?php endif; ?>
                                <div class="small mb-3">
                                    <span class="me-2">Announcements: <strong><?= (int) $class['announcement_count'] ?></strong></span>
                                    <span class="me-2">Assessments: <strong><?= (int) $class['assessment_count'] ?></strong></span>
                                    <span>Pending: <strong><?= (int) $class['pending_count'] ?></strong></span>
                                </div>
                                <div class="d-flex gap-2 flex-wrap">
                                    <a href="student_classroom.php?id=<?= (int) $class['id'] ?>" class="btn btn-primary btn-sm"<?= student_tooltip_attr('Opens this class workspace with announcements, materials, discussion, and assessments. Use this as your main entry for daily class work.') ?>>Open class</a>
                                    <?php if (trim((string) $class['meet_link']) !== ''): ?>
                                        <a href="<?= htmlspecialchars((string) $class['meet_link']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline-success btn-sm"<?= student_tooltip_attr('Opens the live video meeting your instructor linked for this class. Use this during scheduled class time or when the instructor goes live.') ?>>Join Meet</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-header bg-white"><strong>Latest Announcements</strong></div>
                <div class="card-body">
                    <?php if ($announcements === []): ?>
                        <p class="text-muted mb-0">No announcements yet.</p>
                    <?php else: ?>
                        <?php foreach ($announcements as $item): ?>
                            <div class="border-bottom pb-3 mb-3">
                                <div class="small text-muted mb-1"><?= htmlspecialchars((string) $item['classroom_title']) ?></div>
                                <div class="fw-semibold"><?= htmlspecialchars((string) $item['title']) ?></div>
                                <?php if (trim((string) ($item['body'] ?? '')) !== ''): ?>
                                    <div class="small mt-1"><?= nl2br(htmlspecialchars((string) $item['body'])) ?></div>
                                <?php endif; ?>
                                <div class="small text-muted mt-1"><?= htmlspecialchars((string) $item['created_at']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

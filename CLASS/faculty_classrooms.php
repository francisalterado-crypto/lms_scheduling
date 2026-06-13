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

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$hasClassrooms = db_table_exists('online_classrooms');
$hasOnlineUrl = db_column_exists('schedules', 'online_class_url');
$hasJoinCode = $hasClassrooms && db_column_exists('online_classrooms', 'join_code');

/**
 * @throws RuntimeException
 */
function faculty_classroom_sanitize_url(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    $url = filter_var($raw, FILTER_VALIDATE_URL);
    if ($url === false) {
        throw new RuntimeException('Please enter a valid URL such as https://meet.google.com/...');
    }

    $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new RuntimeException('Only http and https meeting links are allowed.');
    }

    return $url;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasClassrooms) {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_classroom') {
        $scheduleId = (int) ($_POST['schedule_id'] ?? 0);

        try {
            if ($scheduleId < 1) {
                throw new RuntimeException('Select an assigned course schedule first.');
            }

            $st = db()->prepare(
                'SELECT s.id, s.course_id, s.online_class_url, s.semester, s.school_year, c.course_code, c.course_name
                 FROM schedules s
                 INNER JOIN courses c ON c.id = s.course_id
                 WHERE s.id = ? AND s.faculty_id = ?
                 LIMIT 1'
            );
            $st->execute([$scheduleId, $facultyId]);
            $schedule = $st->fetch();
            if (!$schedule) {
                throw new RuntimeException('You can only create classrooms for your assigned courses.');
            }

            $chk = db()->prepare('SELECT COUNT(*) FROM online_classrooms WHERE schedule_id = ?');
            $chk->execute([$scheduleId]);
            if ((int) $chk->fetchColumn() > 0) {
                throw new RuntimeException('An online classroom already exists for that assigned course.');
            }

            $meetLink = faculty_classroom_sanitize_url((string) ($_POST['meet_link'] ?? ''));
            if ($meetLink === '') {
                $meetLink = trim((string) ($schedule['online_class_url'] ?? ''));
            }

            $title = trim((string) ($_POST['title'] ?? ''));
            if ($title === '') {
                $title = trim((string) $schedule['course_code']) . ' - ' . trim((string) $schedule['course_name']);
            }

            $description = trim((string) ($_POST['description'] ?? ''));

            if ($hasJoinCode) {
                $joinCode = classroom_alloc_unique_join_code();
                db()->prepare(
                    'INSERT INTO online_classrooms (schedule_id, faculty_id, course_id, title, description, meet_link, join_code)
                     VALUES (?,?,?,?,?,?,?)'
                )->execute([
                    $scheduleId,
                    $facultyId,
                    (int) $schedule['course_id'],
                    $title,
                    $description !== '' ? $description : null,
                    $meetLink,
                    $joinCode,
                ]);
            } else {
                db()->prepare(
                    'INSERT INTO online_classrooms (schedule_id, faculty_id, course_id, title, description, meet_link)
                     VALUES (?,?,?,?,?,?)'
                )->execute([
                    $scheduleId,
                    $facultyId,
                    (int) $schedule['course_id'],
                    $title,
                    $description !== '' ? $description : null,
                    $meetLink,
                ]);
            }

            if ($hasOnlineUrl && $meetLink !== '') {
                db()->prepare('UPDATE schedules SET online_class_url = ? WHERE id = ? AND faculty_id = ?')
                    ->execute([$meetLink, $scheduleId, $facultyId]);
            }

            $_SESSION['flash'] = $hasJoinCode
                ? 'Online classroom created successfully. Share the join code from Manage so students can enroll.'
                : 'Online classroom created successfully.';
        } catch (Throwable $e) {
            $_SESSION['flash'] = 'Error: ' . $e->getMessage();
        }

        header('Location: faculty_classrooms.php');
        exit;
    }
}

$assignedSchedules = [];
$classrooms = [];

if ($hasClassrooms) {
    $st = db()->prepare(
        'SELECT s.id, s.semester, s.school_year, s.day_of_week, s.start_time, s.end_time,
                c.course_code, c.course_name,
                oc.id AS classroom_id
         FROM schedules s
         INNER JOIN courses c ON c.id = s.course_id
         LEFT JOIN online_classrooms oc ON oc.schedule_id = s.id
         WHERE s.faculty_id = ?
         ORDER BY s.school_year DESC, s.semester, c.course_code, s.start_time'
    );
    $st->execute([$facultyId]);
    $assignedSchedules = $st->fetchAll();

    $st = db()->prepare(
        'SELECT oc.*, s.semester, s.school_year, s.day_of_week, s.start_time, s.end_time,
                c.course_code, c.course_name,
                (SELECT COUNT(*) FROM classroom_enrollments ce WHERE ce.classroom_id = oc.id) AS enrolled_count,
                (SELECT COUNT(*) FROM classroom_content cc WHERE cc.classroom_id = oc.id) AS content_count,
                (SELECT COUNT(*) FROM classroom_assessments ca WHERE ca.classroom_id = oc.id) AS assessment_count
         FROM online_classrooms oc
         INNER JOIN schedules s ON s.id = oc.schedule_id
         INNER JOIN courses c ON c.id = oc.course_id
         WHERE oc.faculty_id = ? AND s.faculty_id = ?
         ORDER BY oc.created_at DESC'
    );
    $st->execute([$facultyId, $facultyId]);
    $classrooms = $st->fetchAll();
}

$availableSchedules = array_values(array_filter(
    $assignedSchedules,
    static fn (array $row): bool => empty($row['classroom_id'])
));

$pageTitle = 'My Classrooms';
require_once __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <h1 class="h3 mb-0"><i class="fa-solid fa-chalkboard me-2 text-primary"></i>My Classrooms</h1>
    <a href="faculty_schedule.php" class="btn btn-outline-secondary btn-sm"<?= app_tooltip_attr('Returns to your weekly teaching schedule and online links. Use this when you are done managing class spaces.') ?>><i class="fa-solid fa-calendar-check me-1"></i>Back to My Schedule</a>
</div>

<?php if ($flash): ?>
    <div class="alert alert-info alert-dismissible fade show">
        <?= htmlspecialchars($flash) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"<?= app_tooltip_attr('Dismisses this alert after you have read it.') ?>></button>
    </div>
<?php endif; ?>

<?php if (!$hasClassrooms): ?>
    <div class="alert alert-warning">
        Classroom features are not installed yet. Run <a href="upgrade_roles.php">upgrade_roles.php</a> once, then reload this page.
    </div>
<?php else: ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white"><strong>Create Online Classroom</strong></div>
        <div class="card-body">
            <?php if ($availableSchedules === []): ?>
                <p class="text-muted mb-0">All of your assigned schedules already have online classrooms.</p>
            <?php else: ?>
                <form method="post" class="row g-3">
                    <input type="hidden" name="action" value="create_classroom">
                    <div class="col-md-6">
                        <label class="form-label">Assigned course</label>
                        <select name="schedule_id" class="form-select" required>
                            <option value="">Select assigned course</option>
                            <?php foreach ($availableSchedules as $row): ?>
                                <option value="<?= (int) $row['id'] ?>">
                                    <?= htmlspecialchars($row['course_code'] . ' - ' . $row['course_name'] . ' (' . $row['semester'] . ' / ' . $row['school_year'] . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Only schedules assigned to your faculty account can be turned into classrooms.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Google Meet link</label>
                        <input type="url" name="meet_link" class="form-control" placeholder="https://meet.google.com/..." autocomplete="url">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Classroom title</label>
                        <input type="text" name="title" class="form-control" maxlength="150" placeholder="Optional custom title">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Description</label>
                        <input type="text" name="description" class="form-control" maxlength="255" placeholder="Optional short description">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary"<?= app_tooltip_attr('Creates an online classroom shell for the selected assigned course. Use this before posting content or enrollments.') ?>><i class="fa-solid fa-plus me-1"></i>Create classroom</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-white"><strong>Existing Online Classrooms</strong></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Classroom</th>
                        <?php if ($hasJoinCode): ?><th>Join code</th><?php endif; ?>
                        <th>Schedule</th>
                        <th>Students</th>
                        <th>Content</th>
                        <th>Assessments</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($classrooms === []): ?>
                        <tr><td colspan="<?= $hasJoinCode ? 8 : 7 ?>" class="p-4 text-muted">No online classrooms yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($classrooms as $row): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars((string) $row['title']) ?></strong><br>
                                <span class="text-muted small"><?= htmlspecialchars((string) $row['course_code']) ?> - <?= htmlspecialchars((string) $row['course_name']) ?></span>
                            </td>
                            <?php if ($hasJoinCode): ?>
                                <td><code class="small"><?= htmlspecialchars((string) ($row['join_code'] ?? '') ?: '—') ?></code></td>
                            <?php endif; ?>
                            <td>
                                <div class="small"><?= htmlspecialchars((string) $row['semester']) ?> / <?= htmlspecialchars((string) $row['school_year']) ?></div>
                                <div class="small text-muted"><?= htmlspecialchars(str_replace(',', ', ', (string) $row['day_of_week'])) ?></div>
                                <div class="small text-muted"><?= htmlspecialchars(substr((string) $row['start_time'], 0, 5)) ?> - <?= htmlspecialchars(substr((string) $row['end_time'], 0, 5)) ?></div>
                            </td>
                            <td><?= (int) $row['enrolled_count'] ?></td>
                            <td><?= (int) $row['content_count'] ?></td>
                            <td><?= (int) $row['assessment_count'] ?></td>
                            <td><span class="badge <?= (string) $row['status'] === 'archived' ? 'bg-secondary' : 'bg-success' ?>"><?= htmlspecialchars((string) $row['status']) ?></span></td>
                            <td class="text-nowrap">
                                <a href="faculty_classroom.php?id=<?= (int) $row['id'] ?>" class="btn btn-sm btn-outline-primary"<?= app_tooltip_attr('Opens the full classroom workspace: announcements, materials, discussion, and settings.') ?>>Manage</a>
                                <a href="faculty_classroom_assessments.php?id=<?= (int) $row['id'] ?>" class="btn btn-sm btn-outline-secondary"<?= app_tooltip_attr('Opens quizzes and graded work for this class. Use this to create or score assessments.') ?>>Assessments</a>
                                <?php if (trim((string) $row['meet_link']) !== ''): ?>
                                    <a href="<?= htmlspecialchars((string) $row['meet_link']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-success"<?= app_tooltip_attr('Opens your Google Meet link for this class in a new tab. Use this during scheduled sessions.') ?>>Open Meet</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

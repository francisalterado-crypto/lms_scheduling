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
$defaultAssessmentsLink = 'faculty_classrooms.php';
if ($classrooms !== []) {
    $defaultAssessmentsLink = 'faculty_classroom_assessments.php?id=' . (int) $classrooms[0]['id'];
}

$pageTitle = 'My Classrooms';
require_once __DIR__ . '/includes/header.php';
?>
<style>
    .faculty-hub {
        background: #f4f7fc;
        font-family: 'Inter', sans-serif;
        color: #1a2c3e;
        line-height: 1.4;
        padding: 2rem 1.5rem;
        border-radius: 22px;
    }
    .dashboard-container {
        max-width: 1400px;
        margin: 0 auto;
    }
    .uni-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        flex-wrap: wrap;
        margin-bottom: 2rem;
        border-bottom: 2px solid #e2e8f0;
        padding-bottom: 1rem;
    }
    .brand h1 {
        font-size: 1.9rem;
        font-weight: 700;
        background: linear-gradient(135deg, #1e4a6e, #2c7a4d);
        background-clip: text;
        -webkit-background-clip: text;
        color: transparent;
        letter-spacing: -0.3px;
        margin: 0;
    }
    .brand p {
        font-size: 0.9rem;
        color: #2c5a6e;
        font-weight: 500;
        margin: 0.25rem 0 0;
    }
    .faculty-badge {
        background: #fff;
        padding: 0.55rem 1rem;
        border-radius: 60px;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
        font-size: 0.85rem;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 8px;
        color: #1f5e3a;
    }
    .classroom-grid {
        display: grid;
        grid-template-columns: 1fr 1.6fr;
        gap: 2rem;
        margin-bottom: 2.5rem;
    }
    .hub-card {
        background: #fff;
        border-radius: 28px;
        box-shadow: 0 12px 28px rgba(0, 0, 0, 0.05), 0 0 0 1px rgba(0, 0, 0, 0.02);
        overflow: hidden;
    }
    .hub-card-header {
        padding: 1.25rem 1.75rem;
        border-bottom: 1px solid #ecf3f9;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .hub-card-header i {
        font-size: 1.6rem;
        color: #2c7a4d;
    }
    .hub-card-header h2 {
        font-size: 1.35rem;
        font-weight: 600;
        color: #0f2b3b;
        margin: 0;
    }
    .hub-card-body {
        padding: 1.5rem 1.75rem;
    }
    .form-group {
        margin-bottom: 1.4rem;
    }
    .faculty-hub label {
        font-weight: 600;
        font-size: 0.85rem;
        display: block;
        margin-bottom: 0.5rem;
        color: #2c5a6e;
    }
    .hub-input, .hub-select {
        width: 100%;
        padding: 0.85rem 1rem;
        border: 1.5px solid #e2edf2;
        border-radius: 20px;
        font-family: 'Inter', sans-serif;
        font-size: 0.95rem;
        background: #fefefe;
        transition: .2s;
        outline: none;
    }
    .hub-input:focus, .hub-select:focus {
        border-color: #2c7a4d;
        box-shadow: 0 0 0 3px rgba(44, 122, 77, 0.15);
    }
    .meet-link {
        background: #f8fafc;
        border-radius: 20px;
        padding: 0.6rem 1rem;
        font-size: 0.85rem;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        border: 1px solid #dce5ec;
        width: 100%;
    }
    .meet-link a {
        text-decoration: none;
        font-weight: 500;
        color: #1e6f5c;
        word-break: break-all;
    }
    .info-note {
        font-size: 0.75rem;
        color: #6f8e9e;
        margin-top: 8px;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .btn-create {
        background: linear-gradient(100deg, #1d6f42, #2c8e55);
        border: 0;
        width: 100%;
        padding: 0.9rem;
        border-radius: 40px;
        font-weight: 700;
        font-size: 1rem;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        cursor: pointer;
        transition: all 0.2s;
        margin-top: 1rem;
    }
    .btn-create:hover {
        background: linear-gradient(100deg, #166037, #1f7547);
        transform: translateY(-1px);
        box-shadow: 0 8px 18px rgba(32, 106, 63, 0.2);
    }
    .table-wrapper {
        overflow-x: auto;
        border-radius: 24px;
    }
    .classroom-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.85rem;
    }
    .classroom-table th {
        text-align: left;
        padding: 1rem 0.8rem;
        background-color: #f9fdf9;
        font-weight: 600;
        color: #1a4a5f;
        border-bottom: 2px solid #e2f0e6;
        white-space: nowrap;
    }
    .classroom-table td {
        padding: 1rem 0.8rem;
        border-bottom: 1px solid #edf3f0;
        vertical-align: middle;
    }
    .status-badge {
        background: #e1f7e8;
        color: #1e6a3b;
        font-weight: 600;
        font-size: 0.7rem;
        padding: 0.25rem 0.7rem;
        border-radius: 40px;
        display: inline-block;
        width: fit-content;
        text-transform: capitalize;
    }
    .status-badge.is-archived {
        background: #e2e8f0;
        color: #334155;
    }
    .join-code {
        font-family: monospace;
        font-weight: 700;
        background: #f1f5f9;
        padding: 0.2rem 0.5rem;
        border-radius: 12px;
        letter-spacing: 0.5px;
        display: inline-block;
    }
    .action-icons {
        display: flex;
        gap: 12px;
        align-items: center;
    }
    .action-icons a,
    .action-icons button {
        border: 0;
        background: transparent;
        color: #6c8b9b;
        padding: 0;
        font-size: 1rem;
        transition: .2s;
    }
    .action-icons a:hover,
    .action-icons button:hover {
        color: #2c7a4d;
        transform: scale(1.05);
    }
    .action-icons .text-danger:hover {
        color: #991b1b !important;
    }
    .empty-row td {
        text-align: center;
        padding: 2rem;
        color: #8ba5b4;
        font-style: italic;
    }
    .footer-actions {
        margin-top: 2rem;
        display: flex;
        gap: 1rem;
        justify-content: flex-end;
        border-top: 1px solid #dce9f0;
        padding-top: 1.8rem;
        flex-wrap: wrap;
    }
    .footer-btn {
        background: #fff;
        padding: 0.7rem 1.2rem;
        border-radius: 40px;
        font-weight: 600;
        font-size: 0.9rem;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        border: 1px solid #cbdde6;
        cursor: pointer;
        color: #1f5e6e;
        text-decoration: none;
    }
    .footer-btn:hover {
        background: #eef3f7;
        border-color: #9fc1cf;
    }
    .toast-msg {
        position: fixed;
        bottom: 24px;
        left: 50%;
        transform: translateX(-50%);
        background: #1f2e3a;
        color: #fff;
        padding: 12px 24px;
        border-radius: 60px;
        font-size: 0.85rem;
        font-weight: 500;
        z-index: 1000;
        box-shadow: 0 6px 14px rgba(0, 0, 0, 0.15);
        opacity: 0;
        transition: opacity .2s;
        pointer-events: none;
    }
    @media (max-width: 880px) {
        .faculty-hub {
            padding: 1.2rem;
        }
        .classroom-grid {
            grid-template-columns: 1fr;
            gap: 1.5rem;
        }
        .uni-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 1rem;
        }
    }
</style>

<div class="faculty-hub">
    <div class="dashboard-container">
        <div class="uni-header">
            <div class="brand">
                <h1><i class="fa-solid fa-chalkboard-user" style="color:#2c7a4d; margin-right: 8px;"></i>My Classrooms</h1>
                <p>Western Philippines University · Faculty Teaching Hub</p>
            </div>
            <div class="faculty-badge">
                <i class="fa-solid fa-user-graduate"></i> Faculty Portal
                <i class="fa-solid fa-chevron-down" style="font-size: 10px;"></i>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-info alert-dismissible fade show mb-4">
                <?= htmlspecialchars($flash) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"<?= app_tooltip_attr('Dismisses this alert after you have read it.') ?>></button>
            </div>
        <?php endif; ?>

        <?php if (!$hasClassrooms): ?>
            <div class="alert alert-warning">
                Classroom features are not installed yet. Run <a href="upgrade_roles.php">upgrade_roles.php</a> once, then reload this page.
            </div>
        <?php else: ?>
            <div class="classroom-grid">
                <div class="hub-card">
                    <div class="hub-card-header">
                        <i class="fa-solid fa-plus-circle"></i>
                        <h2>Create Online Classroom</h2>
                    </div>
                    <div class="hub-card-body">
                        <?php if ($availableSchedules === []): ?>
                            <p class="text-muted mb-0">All of your assigned schedules already have online classrooms.</p>
                        <?php else: ?>
                            <form method="post">
                                <input type="hidden" name="action" value="create_classroom">
                                <div class="form-group">
                                    <label for="schedule_id"><i class="fa-regular fa-calendar"></i> Assign course</label>
                                    <select id="schedule_id" name="schedule_id" class="hub-select" required>
                                        <option value="">Select assigned course</option>
                                        <?php foreach ($availableSchedules as $row): ?>
                                            <option value="<?= (int) $row['id'] ?>">
                                                <?= htmlspecialchars($row['course_code'] . ' - ' . $row['course_name'] . ' (' . $row['semester'] . ' / ' . $row['school_year'] . ')') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="info-note"><i class="fa-solid fa-circle-info"></i> Only schedules assigned to your faculty account can be turned into classrooms.</div>
                                </div>

                                <div class="form-group">
                                    <label for="meet_link"><i class="fa-solid fa-video"></i> Google Meet link</label>
                                    <div class="meet-link">
                                        <i class="fa-brands fa-google" style="color:#2c7a4d;"></i>
                                        <a href="https://meet.google.com/" target="_blank" rel="noopener noreferrer">https://meet.google.com/</a>
                                        <i class="fa-solid fa-external-link-alt" style="font-size: 11px; color:#94a3b8;"></i>
                                    </div>
                                    <input id="meet_link" type="url" name="meet_link" class="hub-input mt-2" placeholder="https://meet.google.com/..." autocomplete="url">
                                    <div class="info-note"><i class="fa-solid fa-link"></i> Default meet generator, you can edit after creation.</div>
                                </div>

                                <div class="form-group">
                                    <label for="title"><i class="fa-solid fa-heading"></i> Classroom title <span style="font-weight: normal;">(Optional custom title)</span></label>
                                    <input id="title" type="text" name="title" class="hub-input" maxlength="150" placeholder="e.g., CC101 - Live Lecture (Monday group)">
                                </div>

                                <div class="form-group">
                                    <label for="description"><i class="fa-regular fa-note-sticky"></i> Description <span style="font-weight: normal;">(Optional)</span></label>
                                    <input id="description" type="text" name="description" class="hub-input" maxlength="255" placeholder="Optional short classroom note">
                                </div>

                                <button type="submit" class="btn-create"<?= app_tooltip_attr('Creates an online classroom shell for the selected assigned course. Use this before posting content or enrollments.') ?>>
                                    <i class="fa-solid fa-chalkboard"></i> Create classroom
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="hub-card">
                    <div class="hub-card-header">
                        <i class="fa-solid fa-door-open"></i>
                        <h2>Existing Online Classrooms</h2>
                    </div>
                    <div class="hub-card-body" style="padding: 0 0 1rem 0;">
                        <div class="table-wrapper">
                            <table class="classroom-table">
                                <thead>
                                <tr>
                                    <th>Classroom</th>
                                    <?php if ($hasJoinCode): ?><th>Join code</th><?php endif; ?>
                                    <th>Schedule</th>
                                    <th>Status</th>
                                    <th style="width: 95px;"></th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php if ($classrooms === []): ?>
                                    <tr class="empty-row"><td colspan="<?= $hasJoinCode ? 5 : 4 ?>">No classrooms yet. Create your first online classroom.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($classrooms as $row): ?>
                                    <?php
                                    $status = (string) $row['status'];
                                    $isArchived = $status === 'archived';
                                    $joinCode = (string) ($row['join_code'] ?? '');
                                    $scheduleLine = (string) $row['semester'] . ' / ' . (string) $row['school_year'];
                                    $dayLine = str_replace(',', ', ', (string) $row['day_of_week']);
                                    $timeLine = substr((string) $row['start_time'], 0, 5) . ' - ' . substr((string) $row['end_time'], 0, 5);
                                    ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 500;">
                                                <i class="fa-solid fa-school" style="color:#2c7a4d; margin-right:6px;"></i><?= htmlspecialchars((string) $row['title']) ?>
                                            </div>
                                            <div class="text-muted" style="font-size: 0.8rem;"><?= htmlspecialchars((string) $row['course_code']) ?> - <?= htmlspecialchars((string) $row['course_name']) ?></div>
                                        </td>
                                        <?php if ($hasJoinCode): ?>
                                            <td><span class="join-code"><?= htmlspecialchars($joinCode !== '' ? $joinCode : '—') ?></span></td>
                                        <?php endif; ?>
                                        <td style="font-size: 0.8rem;">
                                            <div><?= htmlspecialchars($scheduleLine) ?></div>
                                            <div class="text-muted"><?= htmlspecialchars($dayLine) ?></div>
                                            <div class="text-muted"><?= htmlspecialchars($timeLine) ?></div>
                                        </td>
                                        <td>
                                            <span class="status-badge<?= $isArchived ? ' is-archived' : '' ?>">
                                                <i class="fa-solid fa-circle" style="font-size: 0.5rem; vertical-align: middle; margin-right: 4px;"></i><?= htmlspecialchars($status) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-icons">
                                                <?php if ($hasJoinCode && $joinCode !== ''): ?>
                                                    <button type="button" class="copy-join-code" data-code="<?= htmlspecialchars($joinCode) ?>" title="Copy join code"><i class="fa-solid fa-copy"></i></button>
                                                <?php endif; ?>
                                                <a href="faculty_classroom.php?id=<?= (int) $row['id'] ?>" title="Manage classroom"><i class="fa-solid fa-gear"></i></a>
                                                <a href="faculty_classroom_assessments.php?id=<?= (int) $row['id'] ?>" title="Manage assessments"><i class="fa-solid fa-clipboard-list"></i></a>
                                                <?php if (trim((string) $row['meet_link']) !== ''): ?>
                                                    <a href="<?= htmlspecialchars((string) $row['meet_link']) ?>" target="_blank" rel="noopener noreferrer" title="Open Meet"><i class="fa-solid fa-video"></i></a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="footer-actions">
                <a href="<?= htmlspecialchars($defaultAssessmentsLink) ?>" class="footer-btn">
                    <i class="fa-solid fa-clipboard-list"></i> Manage Assessments
                </a>
                <a href="https://meet.google.com/" target="_blank" rel="noopener noreferrer" class="footer-btn">
                    <i class="fa-brands fa-google"></i> Open Google Meet
                </a>
                <a href="faculty_schedule.php" class="footer-btn"<?= app_tooltip_attr('Returns to your weekly teaching schedule and online links. Use this when you are done managing class spaces.') ?>>
                    <i class="fa-solid fa-calendar-check"></i> Back to My Schedule
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<div id="toastMsg" class="toast-msg"></div>

<script>
    (function () {
        const toast = document.getElementById('toastMsg');
        let toastTimer = null;

        function showToast(message) {
            if (!toast) return;
            toast.textContent = message;
            toast.style.opacity = '1';
            if (toastTimer) {
                window.clearTimeout(toastTimer);
            }
            toastTimer = window.setTimeout(() => {
                toast.style.opacity = '0';
            }, 2200);
        }

        async function copyText(text) {
            try {
                await navigator.clipboard.writeText(text);
                return true;
            } catch (err) {
                const ta = document.createElement('textarea');
                ta.value = text;
                document.body.appendChild(ta);
                ta.select();
                const ok = document.execCommand('copy');
                document.body.removeChild(ta);
                return ok;
            }
        }

        document.querySelectorAll('.copy-join-code').forEach((btn) => {
            btn.addEventListener('click', async () => {
                const code = btn.getAttribute('data-code') || '';
                if (!code) return;
                const copied = await copyText(code);
                showToast(copied ? `Join code ${code} copied.` : 'Unable to copy join code.');
            });
        });
    })();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

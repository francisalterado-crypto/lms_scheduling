<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Logs older than this many days are removed (FIFO by creation time). */
const ADMIN_ACTIVITY_LOG_RETENTION_DAYS = 7;

/** @var list<string> */
const USER_ACTIVITY_LOG_ACTIONS = ['add', 'edit', 'delete', 'login', 'logout'];

function admin_activity_log_table_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $st = db()->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
        );
        $st->execute([DB_NAME, 'admin_activity_logs']);
        $ready = (int) $st->fetchColumn() > 0;
    } catch (Throwable $e) {
        $ready = false;
    }

    return $ready;
}

function admin_activity_log_column_exists(string $columnName): bool
{
    if (!admin_activity_log_table_ready()) {
        return false;
    }
    try {
        $st = db()->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $st->execute([DB_NAME, 'admin_activity_logs', $columnName]);

        return (int) $st->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Apply lightweight migrations for older installs (new column, wider action_type).
 */
function admin_activity_log_ensure_schema(): void
{
    static $ran = false;
    if ($ran || !admin_activity_log_table_ready()) {
        return;
    }
    $ran = true;
    try {
        if (!admin_activity_log_column_exists('user_role')) {
            db()->exec(
                "ALTER TABLE admin_activity_logs
                 ADD COLUMN user_role VARCHAR(32) NOT NULL DEFAULT 'admin' AFTER admin_username"
            );
        }
    } catch (Throwable $e) {
        // ignore
    }
    try {
        if (!admin_activity_log_column_exists('actor_full_name')) {
            db()->exec(
                "ALTER TABLE admin_activity_logs
                 ADD COLUMN actor_full_name VARCHAR(100) NOT NULL DEFAULT '' AFTER admin_username"
            );
        }
    } catch (Throwable $e) {
        // ignore
    }
    try {
        if (!admin_activity_log_column_exists('actor_log_title')) {
            db()->exec(
                "ALTER TABLE admin_activity_logs
                 ADD COLUMN actor_log_title VARCHAR(120) NOT NULL DEFAULT '' AFTER actor_full_name"
            );
        }
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $st = db()->prepare(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'admin_activity_logs' AND COLUMN_NAME = 'action_type'"
        );
        $st->execute([DB_NAME]);
        $colType = strtolower((string) ($st->fetchColumn() ?: ''));
        if ($colType !== '' && str_contains($colType, 'enum')) {
            db()->exec(
                'ALTER TABLE admin_activity_logs
                 MODIFY COLUMN action_type VARCHAR(20) NOT NULL'
            );
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Remove log rows past the retention window.
 * Safe to call often; runs at most once per HTTP request.
 */
function admin_activity_log_cleanup(): void
{
    static $done = false;
    if ($done || !admin_activity_log_table_ready()) {
        return;
    }
    $done = true;
    admin_activity_log_ensure_schema();
    try {
        $st = db()->prepare(
            'DELETE FROM admin_activity_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)'
        );
        $st->execute([ADMIN_ACTIVITY_LOG_RETENTION_DAYS]);
    } catch (Throwable $e) {
        // Best effort; logging must not break primary operations.
    }
}

/**
 * @param array<string,mixed>|null $row
 * @return array<string,mixed>|null
 */
function admin_activity_log_sanitize_row(?array $row): ?array
{
    if ($row === null) {
        return null;
    }
    $out = [];
    foreach ($row as $k => $v) {
        $key = strtolower((string) $k);
        if (str_contains($key, 'password')) {
            $out[$k] = ($v === null || $v === '') ? $v : '[redacted]';
        } else {
            $out[$k] = $v;
        }
    }

    return $out;
}

/**
 * Record a data or session event for the signed-in user (any role). Admins review in Settings.
 *
 * @param 'add'|'edit'|'delete'|'login'|'logout' $actionType
 * @param array<string,mixed>|null $before
 * @param array<string,mixed>|null $after
 */
function log_user_activity(
    string $actionType,
    string $targetModule,
    string $targetReference,
    ?array $before,
    ?array $after,
    ?int $actorUserId = null,
    ?string $actorUsername = null,
    ?string $actorRole = null
): void {
    if (!in_array($actionType, USER_ACTIVITY_LOG_ACTIONS, true)) {
        $actionType = 'edit';
    }
    if (!admin_activity_log_table_ready()) {
        return;
    }
    admin_activity_log_ensure_schema();

    $uid = $actorUserId ?? (int) ($_SESSION['user_id'] ?? 0);
    if ($uid < 1) {
        return;
    }
    $username = $actorUsername !== null && $actorUsername !== ''
        ? trim($actorUsername)
        : trim((string) ($_SESSION['username'] ?? ''));
    if ($username === '') {
        $username = 'user#' . (string) $uid;
    }
    $role = $actorRole !== null && $actorRole !== ''
        ? trim($actorRole)
        : trim((string) ($_SESSION['role'] ?? ''));
    if ($role === '') {
        $role = 'unknown';
    }
    $actorFullName = trim((string) ($_SESSION['full_name'] ?? ''));
    $actorLogTitle = trim((string) ($_SESSION['admin_log_title'] ?? ''));

    admin_activity_log_cleanup();

    $payload = [
        'before' => admin_activity_log_sanitize_row($before),
        'after' => admin_activity_log_sanitize_row($after),
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        $json = '{"before":null,"after":null}';
    }

    try {
        $hasActorCols = admin_activity_log_column_exists('actor_full_name')
            && admin_activity_log_column_exists('actor_log_title');
        if (admin_activity_log_column_exists('user_role')) {
            if ($hasActorCols) {
                $st = db()->prepare(
                    'INSERT INTO admin_activity_logs (admin_user_id, admin_username, actor_full_name, actor_log_title, user_role, action_type, target_module, target_reference, details_json, created_at)
                     VALUES (?,?,?,?,?,?,?,?,?,NOW())'
                );
                $st->execute([
                    $uid,
                    $username,
                    $actorFullName,
                    $actorLogTitle,
                    $role,
                    $actionType,
                    $targetModule,
                    $targetReference,
                    $json,
                ]);
            } else {
                $st = db()->prepare(
                    'INSERT INTO admin_activity_logs (admin_user_id, admin_username, user_role, action_type, target_module, target_reference, details_json, created_at)
                     VALUES (?,?,?,?,?,?,?,NOW())'
                );
                $st->execute([$uid, $username, $role, $actionType, $targetModule, $targetReference, $json]);
            }
        } else {
            $st = db()->prepare(
                'INSERT INTO admin_activity_logs (admin_user_id, admin_username, action_type, target_module, target_reference, details_json, created_at)
                 VALUES (?,?,?,?,?,?,NOW())'
            );
            $st->execute([$uid, $username, $actionType, $targetModule, $targetReference, $json]);
        }
    } catch (Throwable $e) {
        // Non-fatal
    }
}

/**
 * @param array<string,mixed>|null $before
 * @param array<string,mixed>|null $after
 */
function log_admin_activity(
    string $actionType,
    string $targetModule,
    string $targetReference,
    ?array $before,
    ?array $after
): void {
    log_user_activity($actionType, $targetModule, $targetReference, $before, $after);
}

/**
 * @return list<array<string,mixed>>
 */
function admin_activity_log_list_sorted(string $order): array
{
    if (!admin_activity_log_table_ready()) {
        return [];
    }
    admin_activity_log_ensure_schema();
    $dir = $order === 'oldest' ? 'ASC' : 'DESC';
    $roleCol = admin_activity_log_column_exists('user_role');
    $actorCols = admin_activity_log_column_exists('actor_full_name')
        && admin_activity_log_column_exists('actor_log_title');
    try {
        if ($roleCol && $actorCols) {
            $sql = 'SELECT id, admin_user_id, admin_username, actor_full_name, actor_log_title, user_role, action_type, target_module, target_reference, details_json, created_at
                 FROM admin_activity_logs
                 ORDER BY created_at ' . $dir . ', id ' . $dir . '
                 LIMIT 500';
        } elseif ($roleCol) {
            $sql = 'SELECT id, admin_user_id, admin_username, "" AS actor_full_name, "" AS actor_log_title, user_role, action_type, target_module, target_reference, details_json, created_at
                 FROM admin_activity_logs
                 ORDER BY created_at ' . $dir . ', id ' . $dir . '
                 LIMIT 500';
        } else {
            $sql = 'SELECT id, admin_user_id, admin_username, "" AS actor_full_name, "" AS actor_log_title, "admin" AS user_role, action_type, target_module, target_reference, details_json, created_at
                 FROM admin_activity_logs
                 ORDER BY created_at ' . $dir . ', id ' . $dir . '
                 LIMIT 500';
        }
        $st = db()->query($sql);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Decode details_json for the activity log UI. Supports the standard
 * {"before":...,"after":...} envelope and legacy flat objects.
 *
 * @return array{0: mixed, 1: mixed, 2: bool} Tuple: before, after, invalid_json
 */
function admin_activity_log_decode_details_for_display(string $detailsJson): array
{
    $trim = trim($detailsJson);
    if ($trim === '') {
        return [null, null, false];
    }
    $decoded = json_decode($trim, true);
    if (!is_array($decoded)) {
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [null, null, true];
        }
        // Valid JSON scalar or object decoded without associative array (unlikely with `true`)

        return [null, $decoded, false];
    }
    $hasWrapper = array_key_exists('before', $decoded) || array_key_exists('after', $decoded);
    if ($hasWrapper) {
        return [$decoded['before'] ?? null, $decoded['after'] ?? null, false];
    }

    return [null, $decoded, false];
}

function admin_activity_log_detail_block_nonempty(mixed $value): bool
{
    if ($value === null) {
        return false;
    }
    if (is_array($value) && $value === []) {
        return false;
    }

    return true;
}

/**
 * Pretty JSON for audit blocks (password fields are already redacted at write time).
 */
function admin_activity_log_format_json_pretty(mixed $value): string
{
    if ($value === null) {
        return 'null';
    }
    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
    $out = json_encode($value, $flags);

    return $out !== false ? $out : '';
}

function admin_activity_log_explain_trunc(string $s, int $max = 160): string
{
    $s = trim($s);
    if ($s === '') {
        return '';
    }
    if (strlen($s) <= $max) {
        return $s;
    }

    return substr($s, 0, $max - 1) . '…';
}

/**
 * @param array<string,mixed>|null $row
 */
function admin_activity_log_explain_schedule_row(?array $row): ?string
{
    if ($row === null) {
        return null;
    }
    $cc = trim((string) ($row['course_code'] ?? ''));
    $fn = trim((string) ($row['faculty_name'] ?? ''));
    $rc = trim((string) ($row['room_code'] ?? ''));
    $days = trim(str_replace(',', ', ', (string) ($row['day_of_week'] ?? '')));
    $st = substr((string) ($row['start_time'] ?? ''), 0, 5);
    $en = substr((string) ($row['end_time'] ?? ''), 0, 5);
    $sem = trim((string) ($row['semester'] ?? ''));
    $sy = trim((string) ($row['school_year'] ?? ''));
    $parts = [];
    if ($cc !== '') {
        $parts[] = $cc;
    }
    if ($fn !== '') {
        $parts[] = 'with ' . $fn;
    }
    if ($rc !== '') {
        $parts[] = 'in ' . $rc;
    }
    if ($days !== '') {
        $parts[] = 'on ' . $days;
    }
    if ($st !== '' || $en !== '') {
        $parts[] = $st . '–' . $en;
    }
    if ($sem !== '' || $sy !== '') {
        $parts[] = trim($sem . ' ' . $sy);
    }
    $line = trim(implode(', ', array_filter($parts, static fn (string $p): bool => $p !== '')));

    return $line !== '' ? $line : null;
}

function admin_activity_log_explain_scalar(mixed $v): string
{
    if ($v === null) {
        return '(none)';
    }
    if (is_bool($v)) {
        return $v ? 'Yes' : 'No';
    }
    if (is_int($v) || is_float($v)) {
        return (string) $v;
    }
    if (is_array($v)) {
        $j = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return admin_activity_log_explain_trunc($j !== false ? $j : '', 120);
    }

    return admin_activity_log_explain_trunc(trim((string) $v), 200);
}

function admin_activity_log_label_for_key(string $key): string
{
    static $map = [
        'course_code' => 'Course code',
        'course_name' => 'Course name',
        'room_code' => 'Room',
        'room_name' => 'Room name',
        'faculty_id' => 'Faculty ID',
        'full_name' => 'Full name',
        'username' => 'Username',
        'email' => 'Email',
        'department' => 'Department / program',
        'assigned_program' => 'Assigned program',
        'units' => 'Units',
        'lecture_units' => 'Lecture units',
        'laboratory_units' => 'Laboratory units',
        'is_laboratory' => 'Laboratory course',
        'year_level' => 'Year level',
        'section' => 'Section',
        'capacity' => 'Capacity',
        'type' => 'Type',
        'status' => 'Status',
        'college_code' => 'College code',
        'college_name' => 'College name',
        'day_of_week' => 'Days',
        'start_time' => 'Start time',
        'end_time' => 'End time',
        'semester' => 'Semester',
        'school_year' => 'School year',
        'schedule_type' => 'Schedule type',
        'max_hours_per_day' => 'Max hours per day',
        'is_active' => 'Active',
        'classroom_code' => 'Classroom code',
        'admin_remarks' => 'Admin remarks',
        'admin_log_title' => 'Log title / office',
        'dean_remarks' => 'Dean / chair remarks',
        'message' => 'Message',
    ];
    $k = strtolower($key);

    return $map[$k] ?? ucfirst(str_replace('_', ' ', $key));
}

/**
 * @param array<string,mixed>|null $before
 * @param array<string,mixed>|null $after
 * @return list<string>
 */
function admin_activity_log_collect_diff_lines(?array $before, ?array $after): array
{
    if ($before === null || $after === null) {
        return [];
    }
    /** @var array<string,bool> */
    $ignore = [
        'id' => true,
        'college_id' => true,
        'user_id' => true,
        'faculty_id' => true,
        'course_id' => true,
        'room_id' => true,
        'created_by' => true,
        'academic_year' => true,
    ];
    $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
    sort($keys);
    $out = [];
    foreach ($keys as $k) {
        if (isset($ignore[$k])) {
            continue;
        }
        $vb = $before[$k] ?? null;
        $va = $after[$k] ?? null;
        $encB = json_encode($vb, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $encA = json_encode($va, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encB === $encA) {
            continue;
        }
        $label = admin_activity_log_label_for_key((string) $k);
        if (str_contains(strtolower((string) $k), 'password')) {
            $out[] = $label . ' was updated.';
            continue;
        }
        $out[] = $label . ': ' . admin_activity_log_explain_scalar($vb) . ' → ' . admin_activity_log_explain_scalar($va);
    }

    return array_slice($out, 0, 20);
}

/**
 * @param array<string,mixed> $snap
 * @return list<string>
 */
function admin_activity_log_summarize_entity_snapshot(array $snap): array
{
    $try = [
        ['course_code', 'Course'],
        ['course_name', 'Title'],
        ['room_code', 'Room'],
        ['room_name', 'Room name'],
        ['full_name', 'Name'],
        ['faculty_id', 'Faculty ID'],
        ['username', 'Username'],
        ['email', 'Email'],
        ['department', 'Department'],
        ['college_code', 'College code'],
        ['college_name', 'College name'],
        ['assigned_program', 'Program'],
        ['admin_log_title', 'Log title'],
    ];
    $out = [];
    foreach ($try as [$k, $lab]) {
        if (!array_key_exists($k, $snap)) {
            continue;
        }
        $v = $snap[$k];
        if ($v === null || $v === '') {
            continue;
        }
        $out[] = $lab . ': ' . admin_activity_log_explain_scalar($v);
        if (count($out) >= 6) {
            break;
        }
    }

    return $out;
}

/**
 * Plain-language lines for the activity log Details column.
 *
 * @param array<string,mixed> $log One row from admin_activity_logs
 * @return list<string>
 */
function admin_activity_log_explain_human_lines(array $log, mixed $before, mixed $after): array
{
    $action = strtolower(trim((string) ($log['action_type'] ?? '')));
    $module = trim((string) ($log['target_module'] ?? ''));
    $ref = trim((string) ($log['target_reference'] ?? ''));
    /** @var array<string,mixed>|null $b */
    $b = is_array($before) ? $before : null;
    /** @var array<string,mixed>|null $a */
    $a = is_array($after) ? $after : null;

    if ($action === 'login' && $a !== null) {
        $lines = ['Signed in to the system.'];
        $role = trim((string) ($a['role'] ?? ''));
        $ip = trim((string) ($a['ip'] ?? ''));
        if ($role !== '') {
            $lines[] = 'Role for this session: ' . $role . '.';
        }
        if ($ip !== '') {
            $lines[] = 'Client IP address: ' . $ip . '.';
        }
        $office = trim((string) ($a['log_title'] ?? ''));
        if ($office !== '') {
            $lines[] = 'Log title / office on file: ' . $office . '.';
        }

        return $lines;
    }

    if ($action === 'logout') {
        return ['Signed out and ended the session.'];
    }

    if ($module === 'Account' && $action === 'edit' && $a !== null && array_key_exists('password', $a)) {
        return ['Changed the password on their own account.'];
    }

    if ($a !== null && isset($a['rows']) && is_array($a['rows'])) {
        $ids = $a['created_schedule_ids'] ?? [];
        $n = is_array($ids) ? count($ids) : 0;
        $lines = [
            $n > 0
                ? 'Added ' . $n . ' new class schedule row' . ($n === 1 ? '' : 's') . ' to the timetable.'
                : 'Added new class schedule rows to the timetable.',
        ];
        $shown = 0;
        foreach ($a['rows'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $slug = admin_activity_log_explain_schedule_row($row);
            if ($slug !== null) {
                $lines[] = '• ' . $slug;
                if (++$shown >= 8) {
                    break;
                }
            }
        }
        $totalRows = count($a['rows']);
        if ($shown > 0 && $totalRows > $shown) {
            $lines[] = '• …and ' . ($totalRows - $shown) . ' more row(s).';
        }

        return $lines;
    }

    if ($module === 'Schedules (auto)' && $a !== null) {
        $cc = (int) ($a['created_count'] ?? 0);
        $fc = (int) ($a['failed_courses'] ?? 0);
        $lines = [
            'Ran automatic timetable generation.',
            'Created ' . $cc . ' schedule row(s). ' . $fc . ' course(s) were not fully scheduled.',
        ];
        if (!empty($a['preview']) && is_array($a['preview'])) {
            foreach (array_slice($a['preview'], 0, 5) as $p) {
                $lines[] = '• ' . (string) $p;
            }
            if (count($a['preview']) > 5) {
                $lines[] = '• (Additional tool messages omitted here.)';
            }
        }

        return $lines;
    }

    if ($module === 'Conflict requests' && $b !== null && $a !== null) {
        $st = strtolower(trim((string) ($a['status'] ?? '')));
        if ($st === 'approved') {
            $lines = ['Approved a faculty room/time override request and allowed a new schedule to be created.'];
        } elseif ($st === 'rejected') {
            $lines = ['Rejected a faculty room/time override request.'];
        } else {
            $lines = ['Updated a conflict override request.'];
        }
        $rm = trim((string) ($a['admin_remarks'] ?? ''));
        if ($rm !== '') {
            $lines[] = 'Reviewer notes: ' . admin_activity_log_explain_trunc($rm, 220);
        }

        return $lines;
    }

    if ($module === 'Schedule change requests') {
        $lines = ['Reviewed a faculty request to change an existing class schedule.'];
        if ($a !== null && ($a['status'] ?? '') !== '') {
            $lines[] = 'Decision: ' . strtolower(trim((string) $a['status'])) . '.';
        }
        if ($a !== null) {
            $dr = trim((string) ($a['dean_remarks'] ?? ''));
            if ($dr !== '') {
                $lines[] = 'Remarks to faculty: ' . admin_activity_log_explain_trunc($dr, 220);
            }
        }
        if ($b !== null) {
            $msg = trim((string) ($b['message'] ?? ''));
            if ($msg !== '') {
                $lines[] = 'Faculty request text: ' . admin_activity_log_explain_trunc($msg, 220);
            }
        }

        return $lines;
    }

    $isScheduleModule = $module === 'Schedules'
        || str_starts_with($module, 'Schedules ');

    if ($isScheduleModule && $action === 'delete' && $b !== null) {
        $lines = ['Removed a class schedule from the timetable.'];
        $slug = admin_activity_log_explain_schedule_row($b);
        if ($slug !== null) {
            $lines[] = 'What was removed: ' . $slug;
        }

        return $lines;
    }

    if ($isScheduleModule && $action === 'edit' && $b !== null && $a !== null) {
        $lines = [str_contains($module, 'GEN ED') ? 'Updated a General Education class schedule.' : 'Updated a class schedule.'];
        $diff = admin_activity_log_collect_diff_lines($b, $a);
        if ($diff !== []) {
            $lines = array_merge($lines, $diff);
        } else {
            $slug = admin_activity_log_explain_schedule_row($a);
            if ($slug !== null) {
                $lines[] = 'Saved state: ' . $slug;
            }
        }

        return $lines;
    }

    if ($isScheduleModule && $action === 'add' && $a !== null) {
        $lines = [str_contains($module, 'GEN ED') ? 'Created a new General Education class schedule.' : 'Created a new class schedule row.'];
        $slug = admin_activity_log_explain_schedule_row($a);
        if ($slug !== null) {
            $lines[] = 'Details: ' . $slug;
        }

        return $lines;
    }

    $entityLabel = match ($module) {
        'Courses' => 'course',
        'Rooms' => 'room',
        'Faculty' => 'faculty member',
        'Colleges' => 'college',
        'Deans' => 'dean account',
        'Program chairs' => 'program chair account',
        'GEN ED account' => 'GEN ED coordinator account',
        'Administrator accounts' => 'administrator account',
        default => '',
    };

    if ($entityLabel !== '') {
        if ($action === 'add' && $a !== null) {
            $lines = ['Added a new ' . $entityLabel . '.'];
            $lines = array_merge($lines, admin_activity_log_summarize_entity_snapshot($a));

            return $lines;
        }
        if ($action === 'delete' && $b !== null) {
            $lines = ['Removed a ' . $entityLabel . ' from the system.'];
            $lines = array_merge($lines, admin_activity_log_summarize_entity_snapshot($b));

            return $lines;
        }
        if ($action === 'edit' && ($b !== null || $a !== null)) {
            $lines = ['Updated a ' . $entityLabel . '.'];
            if ($b !== null && $a !== null) {
                $lines = array_merge($lines, admin_activity_log_collect_diff_lines($b, $a));
            } elseif ($a !== null) {
                $lines = array_merge($lines, admin_activity_log_summarize_entity_snapshot($a));
            }

            return $lines;
        }
    }

    $verb = match ($action) {
        'add' => 'Added',
        'edit' => 'Updated',
        'delete' => 'Removed',
        default => 'Recorded',
    };
    $modDisp = $module !== '' ? $module : 'the system';
    $lines = [$verb . ' data in ' . $modDisp . ($ref !== '' ? ' — ' . $ref : '') . '.'];
    if ($action === 'edit' && $b !== null && $a !== null) {
        $diff = admin_activity_log_collect_diff_lines($b, $a);
        if ($diff !== []) {
            $lines = array_merge($lines, $diff);
        }
    } elseif ($action === 'add' && $a !== null) {
        $snap = admin_activity_log_summarize_entity_snapshot($a);
        if ($snap !== []) {
            $lines = array_merge($lines, $snap);
        }
    } elseif ($action === 'delete' && $b !== null) {
        $snap = admin_activity_log_summarize_entity_snapshot($b);
        if ($snap !== []) {
            $lines = array_merge($lines, $snap);
        }
    }

    return $lines;
}

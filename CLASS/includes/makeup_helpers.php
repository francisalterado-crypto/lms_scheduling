<?php
declare(strict_types=1);

/**
 * Makeup-class helpers (shared). Requires db(), db_column_exists(), db_table_exists(),
 * parse_day_set(), days_to_set(), validate_schedule_rules(), checkConflicts().
 */

/**
 * Ensure makeup-class columns exist on schedules and schedule_change_requests.
 */
function ensure_makeup_schedule_support(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $ready = false;
    if (!db_table_exists('schedules') || !db_table_exists('schedule_change_requests')) {
        return false;
    }
    try {
        $pdo = db();
        if (!db_column_exists('schedules', 'is_makeup')) {
            $pdo->exec('ALTER TABLE schedules ADD COLUMN is_makeup TINYINT(1) NOT NULL DEFAULT 0');
        }
        if (!db_column_exists('schedules', 'makeup_for_schedule_id')) {
            $pdo->exec('ALTER TABLE schedules ADD COLUMN makeup_for_schedule_id INT NULL');
        }
        if (!db_column_exists('schedule_change_requests', 'request_type')) {
            $pdo->exec(
                "ALTER TABLE schedule_change_requests
                 ADD COLUMN request_type ENUM('change','makeup') NOT NULL DEFAULT 'change'"
            );
        }
        if (!db_column_exists('schedule_change_requests', 'proposed_day_of_week')) {
            $pdo->exec(
                "ALTER TABLE schedule_change_requests
                 ADD COLUMN proposed_day_of_week SET('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NULL"
            );
        }
        if (!db_column_exists('schedule_change_requests', 'proposed_start_time')) {
            $pdo->exec('ALTER TABLE schedule_change_requests ADD COLUMN proposed_start_time TIME NULL');
        }
        if (!db_column_exists('schedule_change_requests', 'proposed_end_time')) {
            $pdo->exec('ALTER TABLE schedule_change_requests ADD COLUMN proposed_end_time TIME NULL');
        }
        if (!db_column_exists('schedule_change_requests', 'proposed_room_id')) {
            $pdo->exec('ALTER TABLE schedule_change_requests ADD COLUMN proposed_room_id INT NULL');
        }
        $ready = db_column_exists('schedules', 'is_makeup')
            && db_column_exists('schedule_change_requests', 'request_type');
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/**
 * Faculty/room conflict messages for a proposed makeup slot.
 *
 * @param list<string> $days
 * @return list<string>
 */
function makeup_hard_conflict_messages(
    int $facultyId,
    int $roomId,
    array $days,
    string $startTime,
    string $endTime,
    string $semester,
    string $schoolYear,
    ?int $collegeId = null
): array {
    if ($facultyId < 1 || $roomId < 1 || $days === []) {
        return [];
    }
    $conflicts = checkConflicts(
        $facultyId,
        $roomId,
        $days,
        $startTime,
        $endTime,
        $semester,
        $schoolYear,
        null,
        $collegeId,
        true
    );
    $out = [];
    foreach ($conflicts as $c) {
        if (!is_array($c)) {
            continue;
        }
        $type = (string) ($c['type'] ?? '');
        if ($type !== 'faculty' && $type !== 'room') {
            continue;
        }
        $desc = trim((string) ($c['description'] ?? ''));
        if ($desc !== '') {
            $out[] = $desc;
        }
    }
    return array_values(array_unique($out));
}

/**
 * Create a temporary makeup schedule row from an approved makeup request.
 *
 * @param array<string,mixed> $request
 * @param array<string,mixed> $baseSchedule
 * @return array{ok:bool,schedule_id?:int,error?:string,conflicts?:list<array<string,mixed>>}
 */
function create_makeup_schedule_from_request(
    array $request,
    array $baseSchedule,
    int $createdByUserId,
    bool $forceDespiteConflicts = false
): array {
    if (!ensure_makeup_schedule_support()) {
        return ['ok' => false, 'error' => 'Makeup schedule support is not available. Run upgrade_roles.php.'];
    }

    $day = trim((string) ($request['proposed_day_of_week'] ?? ''));
    $startRaw = substr((string) ($request['proposed_start_time'] ?? ''), 0, 5);
    $endRaw = substr((string) ($request['proposed_end_time'] ?? ''), 0, 5);
    if ($startRaw === '' || $startRaw === '0') {
        $startRaw = substr((string) ($baseSchedule['start_time'] ?? ''), 0, 5);
    }
    if ($endRaw === '' || $endRaw === '0') {
        $endRaw = substr((string) ($baseSchedule['end_time'] ?? ''), 0, 5);
    }
    $startTime = $startRaw !== '' ? ($startRaw . ':00') : '';
    $endTime = $endRaw !== '' ? ($endRaw . ':00') : '';
    $roomId = (int) ($request['proposed_room_id'] ?? 0);
    if ($roomId < 1) {
        $roomId = (int) ($baseSchedule['room_id'] ?? 0);
    }

    $days = parse_day_set($day);
    if ($days === [] && $day === '' && !empty($baseSchedule['day_of_week'])) {
        $days = parse_day_set((string) $baseSchedule['day_of_week']);
        if (count($days) > 1) {
            $days = [$days[0]];
        }
    }
    if ($days === [] || $roomId < 1 || $startTime === '' || $endTime === '') {
        return ['ok' => false, 'error' => 'Makeup day, time, and room are required.'];
    }

    $ruleCheck = validate_schedule_rules('Custom', $days, $startTime, $endTime);
    if (empty($ruleCheck['ok'])) {
        $errs = $ruleCheck['errors'] ?? [];
        return ['ok' => false, 'error' => implode(' ', is_array($errs) ? $errs : [])];
    }

    $facultyId = (int) ($baseSchedule['faculty_id'] ?? 0);
    $collegeId = isset($baseSchedule['college_id']) && $baseSchedule['college_id'] !== null
        ? (int) $baseSchedule['college_id']
        : null;
    $semester = (string) ($baseSchedule['semester'] ?? '');
    $schoolYear = (string) ($baseSchedule['school_year'] ?? '');
    $conflicts = checkConflicts(
        $facultyId,
        $roomId,
        $days,
        $startTime,
        $endTime,
        $semester,
        $schoolYear,
        null,
        $collegeId,
        true
    );
    // Makeup slots are temporary — only hard faculty/room overlaps block by default.
    $hardConflicts = [];
    $descriptions = makeup_hard_conflict_messages(
        $facultyId,
        $roomId,
        $days,
        $startTime,
        $endTime,
        $semester,
        $schoolYear,
        $collegeId
    );
    foreach ($conflicts as $c) {
        $type = is_array($c) ? (string) ($c['type'] ?? '') : '';
        if ($type === 'faculty' || $type === 'room') {
            $hardConflicts[] = $c;
        }
    }
    if ($hardConflicts !== [] && !$forceDespiteConflicts) {
        return [
            'ok' => false,
            'error' => 'Conflicts prevent creating the makeup slot.'
                . ($descriptions !== [] ? ' ' . implode(' ', $descriptions) : '')
                . ' Use “Approve anyway” if you still want to create it.',
            'conflicts' => $hardConflicts,
            'conflict_messages' => $descriptions,
        ];
    }

    $daySet = days_to_set($days);
    $cols = [
        'faculty_id', 'course_id', 'room_id', 'college_id', 'schedule_type', 'day_of_week',
        'start_time', 'end_time', 'semester', 'school_year', 'academic_year', 'created_by',
        'is_makeup', 'makeup_for_schedule_id',
    ];
    $vals = [
        $facultyId,
        (int) ($baseSchedule['course_id'] ?? 0),
        $roomId,
        $collegeId,
        'Custom',
        $daySet,
        $startTime,
        $endTime,
        $semester,
        $schoolYear,
        (string) ($baseSchedule['academic_year'] ?? ''),
        $createdByUserId,
        1,
        (int) ($baseSchedule['id'] ?? 0) ?: null,
    ];

    foreach (['program', 'year_level', 'section', 'session_kind'] as $optionalCol) {
        if (db_column_exists('schedules', $optionalCol) && array_key_exists($optionalCol, $baseSchedule)) {
            $cols[] = $optionalCol;
            $vals[] = $baseSchedule[$optionalCol];
        }
    }
    if (db_column_exists('schedules', 'online_class_url') && !empty($baseSchedule['online_class_url'])) {
        $cols[] = 'online_class_url';
        $vals[] = $baseSchedule['online_class_url'];
    }

    try {
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $sql = 'INSERT INTO schedules (' . implode(', ', $cols) . ') VALUES (' . $placeholders . ')';
        db()->prepare($sql)->execute($vals);
        $newId = (int) db()->lastInsertId();
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save makeup schedule: ' . $e->getMessage()];
    }

    if ($newId < 1) {
        return ['ok' => false, 'error' => 'Makeup schedule insert did not return an id.'];
    }

    return ['ok' => true, 'schedule_id' => $newId];
}

<?php
declare(strict_types=1);

/**
 * Inserts sample Thursday schedules for CCAS faculty (college_id 2).
 * Run: c:\xampp\php\php.exe tools/seed_thursday_schedule.php
 */
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$pdo = db();
$hasSessionKind = db_column_exists('schedules', 'session_kind');
$hasJoinCode = db_table_exists('online_classrooms') && db_column_exists('online_classrooms', 'join_code');

$semester = '1st Semester';
$schoolYear = '2026-2027';
$academicYear = '2026-2027';
$createdBy = 2; // dean user
$collegeId = 2;
$roomA = 10; // COLLEG-001
$roomB = 11; // COLLEG-002

/** @var list<array{faculty_id:int,course_id:int,room_id:int,schedule_type:string,days:list<string>,start:string,end:string,meet_link?:string}> */
$samples = [
    ['faculty_id' => 8,  'course_id' => 53, 'room_id' => $roomA, 'schedule_type' => 'TTH', 'days' => ['Tuesday', 'Thursday'], 'start' => '08:00:00', 'end' => '09:30:00'],
    ['faculty_id' => 9,  'course_id' => 54, 'room_id' => $roomB, 'schedule_type' => 'TTH', 'days' => ['Tuesday', 'Thursday'], 'start' => '08:00:00', 'end' => '09:30:00'],
    ['faculty_id' => 10, 'course_id' => 55, 'room_id' => $roomA, 'schedule_type' => 'TTH', 'days' => ['Tuesday', 'Thursday'], 'start' => '10:30:00', 'end' => '12:00:00'],
    ['faculty_id' => 11, 'course_id' => 56, 'room_id' => $roomB, 'schedule_type' => 'TTH', 'days' => ['Tuesday', 'Thursday'], 'start' => '10:30:00', 'end' => '12:00:00'],
    ['faculty_id' => 12, 'course_id' => 57, 'room_id' => $roomA, 'schedule_type' => 'Custom', 'days' => ['Thursday'], 'start' => '13:00:00', 'end' => '14:30:00'],
    ['faculty_id' => 13, 'course_id' => 58, 'room_id' => $roomB, 'schedule_type' => 'Custom', 'days' => ['Thursday'], 'start' => '13:00:00', 'end' => '14:30:00'],
    // Active-now window on Thursdays for go-live / attendance testing (12:00–14:00 PHT)
    ['faculty_id' => 8,  'course_id' => 63, 'room_id' => $roomA, 'schedule_type' => 'Custom', 'days' => ['Thursday'], 'start' => '12:00:00', 'end' => '14:00:00', 'meet_link' => 'https://meet.google.com/sample-thu-elijah'],
    ['faculty_id' => 7,  'course_id' => 52, 'room_id' => $roomB, 'schedule_type' => 'Custom', 'days' => ['Thursday'], 'start' => '15:00:00', 'end' => '16:30:00'],
];

$insertSchedule = $pdo->prepare(
    'INSERT INTO schedules (faculty_id, course_id, room_id, college_id, schedule_type, day_of_week, start_time, end_time, semester, school_year, academic_year, created_by'
    . ($hasSessionKind ? ', session_kind' : '')
    . ', online_class_url) VALUES (?,?,?,?,?,?,?,?,?,?,?,?'
    . ($hasSessionKind ? ',?' : '')
    . ',?)'
);

$existsStmt = $pdo->prepare(
    'SELECT id FROM schedules
     WHERE faculty_id = ? AND course_id = ? AND day_of_week = ? AND start_time = ? AND end_time = ?
       AND semester = ? AND school_year = ?
     LIMIT 1'
);

$inserted = 0;
$skipped = 0;

foreach ($samples as $row) {
    $daySet = days_to_set($row['days']);
    $existsStmt->execute([
        $row['faculty_id'],
        $row['course_id'],
        $daySet,
        $row['start'],
        $row['end'],
        $semester,
        $schoolYear,
    ]);
    if ($existsStmt->fetchColumn()) {
        $skipped++;
        echo "Skip (exists): faculty {$row['faculty_id']} course {$row['course_id']} {$daySet} {$row['start']}-{$row['end']}\n";
        continue;
    }

    $params = [
        $row['faculty_id'],
        $row['course_id'],
        $row['room_id'],
        $collegeId,
        $row['schedule_type'],
        $daySet,
        $row['start'],
        $row['end'],
        $semester,
        $schoolYear,
        $academicYear,
        $createdBy,
    ];
    if ($hasSessionKind) {
        $params[] = 'lecture';
    }
    $params[] = $row['meet_link'] ?? null;

    $insertSchedule->execute($params);
    $scheduleId = (int) $pdo->lastInsertId();
    $inserted++;

    $course = $pdo->prepare('SELECT course_code, course_name FROM courses WHERE id = ?');
    $course->execute([$row['course_id']]);
    $courseRow = $course->fetch(PDO::FETCH_ASSOC) ?: ['course_code' => '?', 'course_name' => '?'];
    $title = trim((string) $courseRow['course_code']) . ' - ' . trim((string) $courseRow['course_name']);

    if (db_table_exists('online_classrooms')) {
        $chk = $pdo->prepare('SELECT COUNT(*) FROM online_classrooms WHERE schedule_id = ?');
        $chk->execute([$scheduleId]);
        if ((int) $chk->fetchColumn() === 0) {
            $meetLink = trim((string) ($row['meet_link'] ?? ''));
            if ($hasJoinCode) {
                $joinCode = classroom_alloc_unique_join_code();
                $pdo->prepare(
                    'INSERT INTO online_classrooms (schedule_id, faculty_id, course_id, title, description, meet_link, join_code)
                     VALUES (?,?,?,?,?,?,?)'
                )->execute([$scheduleId, $row['faculty_id'], $row['course_id'], $title, null, $meetLink, $joinCode]);
            } else {
                $pdo->prepare(
                    'INSERT INTO online_classrooms (schedule_id, faculty_id, course_id, title, description, meet_link)
                     VALUES (?,?,?,?,?,?)'
                )->execute([$scheduleId, $row['faculty_id'], $row['course_id'], $title, null, $meetLink]);
            }
        }
    }

    echo "Inserted schedule #{$scheduleId}: faculty {$row['faculty_id']} {$title} — {$daySet} {$row['start']}-{$row['end']}\n";
}

echo "\nDone. Inserted {$inserted}, skipped {$skipped}.\n";
echo "Open http://localhost/CLASS/faculty_schedule.php (log in as a CCAS faculty user, e.g. Elijah).\n";

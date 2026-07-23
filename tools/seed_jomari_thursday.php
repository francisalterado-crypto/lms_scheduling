<?php
declare(strict_types=1);

/**
 * Adds Thursday sample schedules for Jomari (faculty_id 10).
 * Run: c:\xampp\php\php.exe tools/seed_jomari_thursday.php
 */
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$pdo = db();
$facultyId = 10;
$collegeId = 2;
$createdBy = 2;
$semester = '1st Semester';
$schoolYear = '2026-2027';
$academicYear = '2026-2027';
$hasSessionKind = db_column_exists('schedules', 'session_kind');
$hasJoinCode = db_table_exists('online_classrooms') && db_column_exists('online_classrooms', 'join_code');

/** @var list<array{course_id:int,room_id:int,schedule_type:string,days:list<string>,start:string,end:string,meet_link:string}> */
$samples = [
    [
        'course_id' => 54, // CC104 - already assigned Sunday; separate Thursday section
        'room_id' => 11,
        'schedule_type' => 'Custom',
        'days' => ['Thursday'],
        'start' => '13:00:00',
        'end' => '15:00:00',
        'meet_link' => 'https://meet.google.com/jomari-thu-cc104',
    ],
];

$existsStmt = $pdo->prepare(
    'SELECT id FROM schedules
     WHERE faculty_id = ? AND course_id = ? AND day_of_week = ? AND start_time = ? AND end_time = ?
       AND semester = ? AND school_year = ?
     LIMIT 1'
);

$insertSchedule = $pdo->prepare(
    'INSERT INTO schedules (faculty_id, course_id, room_id, college_id, schedule_type, day_of_week, start_time, end_time, semester, school_year, academic_year, created_by'
    . ($hasSessionKind ? ', session_kind' : '')
    . ', online_class_url) VALUES (?,?,?,?,?,?,?,?,?,?,?,?'
    . ($hasSessionKind ? ',?' : '')
    . ',?)'
);

foreach ($samples as $row) {
    $daySet = days_to_set($row['days']);
    $existsStmt->execute([$facultyId, $row['course_id'], $daySet, $row['start'], $row['end'], $semester, $schoolYear]);
    if ($existsStmt->fetchColumn()) {
        echo "Already exists: {$daySet} {$row['start']}-{$row['end']}\n";
        continue;
    }

    $params = [
        $facultyId, $row['course_id'], $row['room_id'], $collegeId,
        $row['schedule_type'], $daySet, $row['start'], $row['end'],
        $semester, $schoolYear, $academicYear, $createdBy,
    ];
    if ($hasSessionKind) {
        $params[] = 'lecture';
    }
    $params[] = $row['meet_link'];

    $insertSchedule->execute($params);
    $scheduleId = (int) $pdo->lastInsertId();

    $course = $pdo->prepare('SELECT course_code, course_name FROM courses WHERE id = ?');
    $course->execute([$row['course_id']]);
    $c = $course->fetch(PDO::FETCH_ASSOC) ?: ['course_code' => '?', 'course_name' => '?'];
    $title = trim((string) $c['course_code']) . ' - ' . trim((string) $c['course_name']);

    if (db_table_exists('online_classrooms')) {
        $meetLink = $row['meet_link'];
        if ($hasJoinCode) {
            $joinCode = classroom_alloc_unique_join_code();
            $pdo->prepare(
                'INSERT INTO online_classrooms (schedule_id, faculty_id, course_id, title, description, meet_link, join_code)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute([$scheduleId, $facultyId, $row['course_id'], $title, null, $meetLink, $joinCode]);
        } else {
            $pdo->prepare(
                'INSERT INTO online_classrooms (schedule_id, faculty_id, course_id, title, description, meet_link)
                 VALUES (?,?,?,?,?,?)'
            )->execute([$scheduleId, $facultyId, $row['course_id'], $title, null, $meetLink]);
        }
    }

    echo "Inserted #{$scheduleId}: {$title} — {$daySet} {$row['start']}-{$row['end']}\n";
}

echo "\nLog in as Jomari → http://localhost/CLASS/faculty_schedule.php\n";

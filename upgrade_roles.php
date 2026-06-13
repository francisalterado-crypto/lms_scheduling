<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';

function exec_safe(PDO $pdo, string $sql): void
{
    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
        // Ignore idempotent alter errors for already-existing columns/indexes.
        $msg = strtolower($e->getMessage());
        if (
            str_contains($msg, 'duplicate column')
            // Index already exists (do not match 1062 "Duplicate entry … for key …").
            || str_contains($msg, 'duplicate key name')
            // InnoDB: duplicate FK constraint identifier (errno 121) or duplicate FK definition.
            || str_contains($msg, 'errno: 121')
            || str_contains($msg, 'duplicate key on write or update')
            || str_contains($msg, 'duplicate foreign key')
            || str_contains($msg, 'already exists')
            || str_contains($msg, 'check that column/key exists')
            || str_contains($msg, 'check that it exists')
            || str_contains($msg, "can't drop index")
            || str_contains($msg, 'cannot drop')
            || str_contains($msg, 'unknown column')
            || str_contains($msg, "can't drop field")
        ) {
            return;
        }
        throw $e;
    }
}

$ok = '';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET),
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $schema = file_get_contents(__DIR__ . '/install/schema.sql');
        if ($schema === false) {
            throw new RuntimeException('Unable to read install/schema.sql');
        }
        $schema = preg_replace('/--.*$/m', '', $schema);
        foreach (array_filter(array_map('trim', explode(';', (string) $schema))) as $stmt) {
            if ($stmt !== '') {
                exec_safe($pdo, $stmt);
            }
        }

        exec_safe(
            $pdo,
            "ALTER TABLE users MODIFY role ENUM('super_admin','admin','dean','program_chair','faculty','gened','student') NOT NULL DEFAULT 'faculty'"
        );
        exec_safe($pdo, "ALTER TABLE users ADD COLUMN admin_log_title VARCHAR(120) NOT NULL DEFAULT ''");
        exec_safe($pdo, "ALTER TABLE users ADD COLUMN email VARCHAR(190) NOT NULL DEFAULT ''");
        exec_safe($pdo, "ALTER TABLE users ADD COLUMN assigned_program VARCHAR(120) NOT NULL DEFAULT ''");
        exec_safe($pdo, "ALTER TABLE users ADD COLUMN college_id INT NULL");
        exec_safe($pdo, "ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
        exec_safe($pdo, "ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL");
        exec_safe($pdo, "ALTER TABLE users ADD COLUMN last_seen_at DATETIME NULL");
        exec_safe($pdo, "ALTER TABLE users ADD COLUMN last_logout_at DATETIME NULL");
        exec_safe($pdo, 'ALTER TABLE users ADD COLUMN profile_photo VARCHAR(80) NULL');
        // Legacy installs used fk_users_college; that identifier can collide globally (errno 121) on some servers.
        exec_safe($pdo, 'ALTER TABLE users DROP FOREIGN KEY fk_users_college');
        exec_safe($pdo, "ALTER TABLE users ADD CONSTRAINT fk_sched_users_college_id FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE SET NULL");

        exec_safe($pdo, "ALTER TABLE faculty ADD COLUMN user_id INT NULL UNIQUE");
        exec_safe($pdo, "ALTER TABLE faculty ADD COLUMN college_id INT NULL");
        exec_safe($pdo, "ALTER TABLE faculty ADD COLUMN is_gened TINYINT(1) NOT NULL DEFAULT 0");
        exec_safe($pdo, "ALTER TABLE faculty ADD COLUMN employment_status ENUM('Permanent','Contract of Service','Temporary') NOT NULL DEFAULT 'Permanent'");
        exec_safe($pdo, "ALTER TABLE faculty MODIFY COLUMN employment_status ENUM('Permanent','Contract of Service','Temporary') NOT NULL DEFAULT 'Permanent'");
        exec_safe($pdo, "ALTER TABLE faculty ADD CONSTRAINT fk_faculty_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL");
        exec_safe($pdo, "ALTER TABLE faculty ADD CONSTRAINT fk_faculty_college FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE SET NULL");

        exec_safe($pdo, "ALTER TABLE courses ADD COLUMN college_id INT NULL");
        exec_safe($pdo, "ALTER TABLE courses ADD COLUMN is_laboratory TINYINT(1) NOT NULL DEFAULT 0");
        exec_safe($pdo, "ALTER TABLE courses ADD COLUMN is_gened TINYINT(1) NOT NULL DEFAULT 0");
        exec_safe($pdo, "ALTER TABLE courses ADD COLUMN year_level VARCHAR(20) NOT NULL DEFAULT ''");
        exec_safe($pdo, "ALTER TABLE courses ADD COLUMN section VARCHAR(20) NOT NULL DEFAULT ''");
        exec_safe($pdo, "ALTER TABLE courses ADD COLUMN lecture_units DECIMAL(3,1) NOT NULL DEFAULT 3.0");
        exec_safe($pdo, "ALTER TABLE courses ADD COLUMN laboratory_units DECIMAL(3,1) NOT NULL DEFAULT 0.0");
        // Old schema used global UNIQUE(course_code); remove to allow per-scope codes.
        exec_safe($pdo, "ALTER TABLE courses DROP INDEX course_code");
        exec_safe($pdo, "ALTER TABLE courses DROP INDEX uq_course_code");
        exec_safe($pdo, "ALTER TABLE courses DROP INDEX uq_courses_course_code");
        $idxStmt = $pdo->prepare(
            "SELECT DISTINCT INDEX_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME = 'courses'
               AND COLUMN_NAME = 'course_code'
               AND NON_UNIQUE = 0
               AND INDEX_NAME <> 'PRIMARY'"
        );
        $idxStmt->execute([DB_NAME]);
        $uniqueIndexes = $idxStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($uniqueIndexes as $idxName) {
            $safeIdx = str_replace('`', '``', (string) $idxName);
            exec_safe($pdo, "ALTER TABLE courses DROP INDEX `{$safeIdx}`");
        }
        exec_safe($pdo, "ALTER TABLE courses ADD INDEX idx_course_code (course_code)");
        exec_safe($pdo, "UPDATE courses SET lecture_units = units WHERE lecture_units IS NULL OR lecture_units = 0");
        exec_safe($pdo, "UPDATE courses SET laboratory_units = units WHERE is_laboratory = 1 AND (laboratory_units IS NULL OR laboratory_units = 0)");
        exec_safe($pdo, "ALTER TABLE courses ADD CONSTRAINT fk_course_college FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE SET NULL");
        exec_safe($pdo, 'ALTER TABLE courses ADD COLUMN classroom_code VARCHAR(16) NULL');
        exec_safe($pdo, 'ALTER TABLE courses ADD UNIQUE KEY uq_courses_classroom_code (classroom_code)');

        exec_safe($pdo, "ALTER TABLE rooms ADD COLUMN college_id INT NULL");
        exec_safe($pdo, "ALTER TABLE rooms ADD COLUMN is_gened TINYINT(1) NOT NULL DEFAULT 0");
        exec_safe($pdo, "ALTER TABLE rooms MODIFY type ENUM('lecture','laboratory','conference','tba') NOT NULL DEFAULT 'lecture'");
        exec_safe($pdo, "ALTER TABLE rooms MODIFY status ENUM('available','tba','maintenance') NOT NULL DEFAULT 'available'");
        exec_safe($pdo, "ALTER TABLE rooms ADD CONSTRAINT fk_room_college FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE SET NULL");

        // Old schema used global UNIQUE(room_code); use scoped uniqueness (per college / Gen Ed).
        exec_safe($pdo, 'ALTER TABLE rooms DROP INDEX room_code');
        exec_safe($pdo, 'ALTER TABLE rooms DROP INDEX uq_rooms_scope');
        $roomIdxStmt = $pdo->prepare(
            "SELECT DISTINCT INDEX_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME = 'rooms'
               AND COLUMN_NAME = 'room_code'
               AND NON_UNIQUE = 0
               AND INDEX_NAME <> 'PRIMARY'"
        );
        $roomIdxStmt->execute([DB_NAME]);
        $roomUniqueIndexes = $roomIdxStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($roomUniqueIndexes as $roomIdxName) {
            $safeRoomIdx = str_replace('`', '``', (string) $roomIdxName);
            exec_safe($pdo, "ALTER TABLE rooms DROP INDEX `{$safeRoomIdx}`");
        }
        exec_safe($pdo, 'ALTER TABLE rooms ADD INDEX idx_room_code (room_code)');
        exec_safe(
            $pdo,
            "ALTER TABLE rooms ADD COLUMN room_code_scope VARCHAR(64) GENERATED ALWAYS AS (
                IF(COALESCE(is_gened,0) = 1,
                   CONCAT('G|', room_code),
                   CONCAT('C|', IFNULL(college_id, 0), '|', room_code))
            ) STORED"
        );
        exec_safe($pdo, 'ALTER TABLE rooms ADD UNIQUE KEY uq_rooms_scope (room_code_scope)');

        exec_safe($pdo, "ALTER TABLE schedules ADD COLUMN college_id INT NULL");
        exec_safe($pdo, "ALTER TABLE schedules ADD COLUMN online_class_url VARCHAR(512) NULL DEFAULT NULL");
        exec_safe($pdo, "ALTER TABLE schedules ADD COLUMN online_live_at DATETIME NULL DEFAULT NULL");
        exec_safe($pdo, "ALTER TABLE schedules ADD CONSTRAINT fk_sched_college FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE SET NULL");
        exec_safe($pdo, "ALTER TABLE schedules ADD COLUMN program VARCHAR(100) NOT NULL DEFAULT ''");
        exec_safe($pdo, "ALTER TABLE schedules ADD COLUMN year_level VARCHAR(20) NOT NULL DEFAULT ''");
        exec_safe($pdo, "ALTER TABLE schedules ADD COLUMN section VARCHAR(20) NOT NULL DEFAULT ''");

        exec_safe($pdo, "ALTER TABLE conflict_requests ADD COLUMN program VARCHAR(100) NOT NULL DEFAULT ''");
        exec_safe($pdo, "ALTER TABLE conflict_requests ADD COLUMN year_level VARCHAR(20) NOT NULL DEFAULT ''");
        exec_safe($pdo, "ALTER TABLE conflict_requests ADD COLUMN section VARCHAR(20) NOT NULL DEFAULT ''");

        exec_safe(
            $pdo,
            "CREATE TABLE IF NOT EXISTS programs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                college_id INT NOT NULL,
                program_name VARCHAR(120) NOT NULL,
                status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_program_college_name (college_id, program_name),
                CONSTRAINT fk_program_college FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        exec_safe(
            $pdo,
            "CREATE TABLE IF NOT EXISTS programs_year_levels (
                program_id INT NOT NULL,
                year_level VARCHAR(20) NOT NULL,
                PRIMARY KEY (program_id, year_level),
                CONSTRAINT fk_prog_yl_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        exec_safe(
            $pdo,
            "CREATE TABLE IF NOT EXISTS college_programs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                college_id INT NOT NULL,
                program_code VARCHAR(30) NOT NULL,
                program_name VARCHAR(120) NOT NULL DEFAULT '',
                status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_college_program_code (college_id, program_code),
                CONSTRAINT fk_cp_college FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        exec_safe(
            $pdo,
            "CREATE TABLE IF NOT EXISTS program_year_levels (
                id INT AUTO_INCREMENT PRIMARY KEY,
                program_id INT NOT NULL,
                year_level VARCHAR(20) NOT NULL,
                status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_program_year (program_id, year_level),
                CONSTRAINT fk_pyl_program FOREIGN KEY (program_id) REFERENCES college_programs(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        exec_safe(
            $pdo,
            "CREATE TABLE IF NOT EXISTS program_sections (
                id INT AUTO_INCREMENT PRIMARY KEY,
                year_level_id INT NOT NULL,
                section_name VARCHAR(20) NOT NULL,
                status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_year_section (year_level_id, section_name),
                CONSTRAINT fk_ps_year FOREIGN KEY (year_level_id) REFERENCES program_year_levels(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        exec_safe(
            $pdo,
            "CREATE TABLE IF NOT EXISTS faculty_specializations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                faculty_id INT NOT NULL,
                course_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_faculty_course (faculty_id, course_id),
                CONSTRAINT fk_fs_faculty FOREIGN KEY (faculty_id) REFERENCES faculty(id) ON DELETE CASCADE,
                CONSTRAINT fk_fs_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        exec_safe(
            $pdo,
            "CREATE TABLE IF NOT EXISTS ge_course_colleges (
                id INT AUTO_INCREMENT PRIMARY KEY,
                course_id INT NOT NULL,
                college_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_ge_course_college (course_id, college_id),
                CONSTRAINT fk_gecc_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
                CONSTRAINT fk_gecc_college FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        exec_safe(
            $pdo,
            "CREATE TABLE IF NOT EXISTS ge_schedule_targets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                schedule_id INT NOT NULL UNIQUE,
                college_id INT NOT NULL,
                program_name VARCHAR(120) NOT NULL,
                year_level VARCHAR(20) NOT NULL DEFAULT '',
                section VARCHAR(20) NOT NULL DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_ge_target_scope (college_id, program_name, year_level, section),
                CONSTRAINT fk_gst_schedule FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE,
                CONSTRAINT fk_gst_college FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        exec_safe(
            $pdo,
            "CREATE TABLE IF NOT EXISTS internal_messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sender_user_id INT NOT NULL,
                recipient_user_id INT NOT NULL,
                subject VARCHAR(255) NOT NULL DEFAULT '',
                body TEXT NOT NULL,
                is_memo TINYINT(1) NOT NULL DEFAULT 0,
                attachment_original_name VARCHAR(255) NOT NULL DEFAULT '',
                attachment_stored_name VARCHAR(255) NOT NULL DEFAULT '',
                attachment_mime VARCHAR(120) NOT NULL DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                read_at TIMESTAMP NULL DEFAULT NULL,
                KEY idx_im_recipient_unread (recipient_user_id, read_at),
                KEY idx_im_pair_time (sender_user_id, recipient_user_id, created_at),
                CONSTRAINT fk_im_sender FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_im_recipient FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        exec_safe($pdo, "ALTER TABLE internal_messages ADD COLUMN subject VARCHAR(255) NOT NULL DEFAULT ''");
        exec_safe($pdo, "ALTER TABLE internal_messages ADD COLUMN is_memo TINYINT(1) NOT NULL DEFAULT 0");
        exec_safe($pdo, "ALTER TABLE internal_messages ADD COLUMN attachment_original_name VARCHAR(255) NOT NULL DEFAULT ''");
        exec_safe($pdo, "ALTER TABLE internal_messages ADD COLUMN attachment_stored_name VARCHAR(255) NOT NULL DEFAULT ''");
        exec_safe($pdo, "ALTER TABLE internal_messages ADD COLUMN attachment_mime VARCHAR(120) NOT NULL DEFAULT ''");

        exec_safe(
            $pdo,
            "CREATE TABLE IF NOT EXISTS online_classrooms (
                id INT AUTO_INCREMENT PRIMARY KEY,
                schedule_id INT NOT NULL UNIQUE,
                faculty_id INT NOT NULL,
                course_id INT NOT NULL,
                title VARCHAR(150) NOT NULL,
                description TEXT NULL,
                meet_link VARCHAR(512) NOT NULL DEFAULT '',
                join_code VARCHAR(16) NULL,
                status ENUM('active','archived') NOT NULL DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_oc_join_code (join_code),
                CONSTRAINT fk_oc_schedule FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE,
                CONSTRAINT fk_oc_faculty FOREIGN KEY (faculty_id) REFERENCES faculty(id) ON DELETE CASCADE,
                CONSTRAINT fk_oc_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        exec_safe(
            $pdo,
            "CREATE TABLE IF NOT EXISTS classroom_students (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL UNIQUE,
                student_number VARCHAR(30) NOT NULL DEFAULT '',
                full_name VARCHAR(120) NOT NULL,
                email VARCHAR(190) NOT NULL DEFAULT '',
                year_level VARCHAR(20) NOT NULL DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_cls_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        exec_safe($pdo, "ALTER TABLE classroom_students ADD COLUMN user_id INT NULL UNIQUE");
        exec_safe($pdo, "ALTER TABLE classroom_students ADD COLUMN year_level VARCHAR(20) NOT NULL DEFAULT ''");
        exec_safe($pdo, "ALTER TABLE classroom_students ADD CONSTRAINT fk_cls_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL");
        exec_safe(
            $pdo,
            "CREATE TABLE IF NOT EXISTS student_registration_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) NOT NULL,
                password VARCHAR(255) NOT NULL,
                full_name VARCHAR(120) NOT NULL,
                email VARCHAR(190) NOT NULL DEFAULT '',
                student_number VARCHAR(30) NOT NULL DEFAULT '',
                college_id INT NOT NULL,
                program_name VARCHAR(120) NOT NULL,
                year_level VARCHAR(20) NOT NULL DEFAULT '',
                status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                reviewed_by_user_id INT NULL,
                reviewed_at DATETIME NULL,
                rejection_reason VARCHAR(500) NOT NULL DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_srr_pending_scope (status, college_id, program_name),
                INDEX idx_srr_username (username),
                CONSTRAINT fk_srr_college FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE CASCADE,
                CONSTRAINT fk_srr_reviewer FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        exec_safe($pdo, "ALTER TABLE student_registration_requests ADD COLUMN year_level VARCHAR(20) NOT NULL DEFAULT ''");
        exec_safe(
            $pdo,
            "CREATE TABLE IF NOT EXISTS classroom_enrollments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                classroom_id INT NOT NULL,
                student_id INT NOT NULL,
                enrolled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_classroom_student (classroom_id, student_id),
                CONSTRAINT fk_ce_classroom FOREIGN KEY (classroom_id) REFERENCES online_classrooms(id) ON DELETE CASCADE,
                CONSTRAINT fk_ce_student FOREIGN KEY (student_id) REFERENCES classroom_students(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        exec_safe(
            $pdo,
            "CREATE TABLE IF NOT EXISTS classroom_content (
                id INT AUTO_INCREMENT PRIMARY KEY,
                classroom_id INT NOT NULL,
                faculty_id INT NOT NULL,
                content_type ENUM('material','link','announcement') NOT NULL DEFAULT 'material',
                title VARCHAR(150) NOT NULL,
                body TEXT NULL,
                weeks VARCHAR(100) NOT NULL DEFAULT '',
                days_per_topic VARCHAR(100) NOT NULL DEFAULT '',
                resource_url VARCHAR(512) NOT NULL DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_cc_classroom FOREIGN KEY (classroom_id) REFERENCES online_classrooms(id) ON DELETE CASCADE,
                CONSTRAINT fk_cc_faculty FOREIGN KEY (faculty_id) REFERENCES faculty(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        exec_safe($pdo, "ALTER TABLE classroom_content ADD COLUMN weeks VARCHAR(100) NOT NULL DEFAULT ''");
        exec_safe($pdo, "ALTER TABLE classroom_content ADD COLUMN days_per_topic VARCHAR(100) NOT NULL DEFAULT ''");
        exec_safe(
            $pdo,
            "CREATE TABLE IF NOT EXISTS classroom_messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                classroom_id INT NOT NULL,
                sender_user_id INT NOT NULL,
                body TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_cm_classroom_time (classroom_id, created_at),
                CONSTRAINT fk_cm_classroom FOREIGN KEY (classroom_id) REFERENCES online_classrooms(id) ON DELETE CASCADE,
                CONSTRAINT fk_cm_sender FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        exec_safe(
            $pdo,
            "CREATE TABLE IF NOT EXISTS classroom_content_attachments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                content_id INT NOT NULL,
                original_name VARCHAR(255) NOT NULL DEFAULT '',
                stored_name VARCHAR(255) NOT NULL DEFAULT '',
                mime VARCHAR(120) NOT NULL DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_cca_content (content_id),
                CONSTRAINT fk_cca_content FOREIGN KEY (content_id) REFERENCES classroom_content(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        exec_safe(
            $pdo,
            "CREATE TABLE IF NOT EXISTS classroom_assessments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                classroom_id INT NOT NULL,
                faculty_id INT NOT NULL,
                assessment_type ENUM('multiple_choice','true_false','essay','problem_solving') NOT NULL DEFAULT 'essay',
                title VARCHAR(150) NOT NULL,
                description TEXT NULL,
                total_points DECIMAL(8,2) NOT NULL DEFAULT 100.00,
                due_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_ca_classroom FOREIGN KEY (classroom_id) REFERENCES online_classrooms(id) ON DELETE CASCADE,
                CONSTRAINT fk_ca_faculty FOREIGN KEY (faculty_id) REFERENCES faculty(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        exec_safe(
            $pdo,
            "UPDATE classroom_assessments
             SET assessment_type = CASE assessment_type
                WHEN 'written_work' THEN 'essay'
                WHEN 'assignment' THEN 'essay'
                WHEN 'performance_task' THEN 'problem_solving'
                WHEN 'quiz' THEN 'multiple_choice'
                ELSE assessment_type
             END"
        );
        exec_safe($pdo, "ALTER TABLE classroom_assessments MODIFY COLUMN assessment_type ENUM('multiple_choice','true_false','essay','problem_solving') NOT NULL DEFAULT 'essay'");
        exec_safe($pdo, "ALTER TABLE online_classrooms ADD COLUMN written_work_percentage DECIMAL(5,2) NOT NULL DEFAULT 50.00");
        exec_safe($pdo, "ALTER TABLE online_classrooms ADD COLUMN performance_task_percentage DECIMAL(5,2) NOT NULL DEFAULT 50.00");
        exec_safe(
            $pdo,
            "CREATE TABLE IF NOT EXISTS classroom_scores (
                id INT AUTO_INCREMENT PRIMARY KEY,
                assessment_id INT NOT NULL,
                student_id INT NOT NULL,
                score DECIMAL(8,2) NULL,
                feedback TEXT NULL,
                graded_at DATETIME NULL,
                UNIQUE KEY uq_assessment_student (assessment_id, student_id),
                CONSTRAINT fk_cs_assessment FOREIGN KEY (assessment_id) REFERENCES classroom_assessments(id) ON DELETE CASCADE,
                CONSTRAINT fk_cs_student FOREIGN KEY (student_id) REFERENCES classroom_students(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        exec_safe(
            $pdo,
            "CREATE TABLE IF NOT EXISTS classroom_submissions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                assessment_id INT NOT NULL,
                student_id INT NOT NULL,
                answer_text LONGTEXT NULL,
                status ENUM('submitted','resubmitted') NOT NULL DEFAULT 'submitted',
                auto_total_score DECIMAL(8,2) NULL,
                requires_manual_grade TINYINT(1) NOT NULL DEFAULT 1,
                submitted_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_submission_assessment_student (assessment_id, student_id),
                CONSTRAINT fk_csub_assessment FOREIGN KEY (assessment_id) REFERENCES classroom_assessments(id) ON DELETE CASCADE,
                CONSTRAINT fk_csub_student FOREIGN KEY (student_id) REFERENCES classroom_students(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        exec_safe($pdo, "ALTER TABLE classroom_submissions ADD COLUMN auto_total_score DECIMAL(8,2) NULL");
        exec_safe($pdo, "ALTER TABLE classroom_submissions ADD COLUMN requires_manual_grade TINYINT(1) NOT NULL DEFAULT 1");
        exec_safe(
            $pdo,
            "CREATE TABLE IF NOT EXISTS classroom_assessment_questions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                assessment_id INT NOT NULL,
                question_type ENUM('multiple_choice','true_false','essay','problem_solving') NOT NULL DEFAULT 'essay',
                question_text LONGTEXT NOT NULL,
                options_json LONGTEXT NULL,
                answer_key VARCHAR(255) NULL,
                points DECIMAL(8,2) NOT NULL DEFAULT 1.00,
                position INT NOT NULL DEFAULT 1,
                word_limit INT NULL,
                char_limit INT NULL,
                allow_steps TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_caq_assessment_pos (assessment_id, position),
                CONSTRAINT fk_caq_assessment FOREIGN KEY (assessment_id) REFERENCES classroom_assessments(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        exec_safe(
            $pdo,
            "CREATE TABLE IF NOT EXISTS classroom_submission_question_answers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                submission_id INT NOT NULL,
                question_id INT NOT NULL,
                answer_text LONGTEXT NULL,
                answer_steps LONGTEXT NULL,
                is_correct TINYINT(1) NULL,
                auto_score DECIMAL(8,2) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_csqa_submission_question (submission_id, question_id),
                KEY idx_csqa_question (question_id),
                CONSTRAINT fk_csqa_submission FOREIGN KEY (submission_id) REFERENCES classroom_submissions(id) ON DELETE CASCADE,
                CONSTRAINT fk_csqa_question FOREIGN KEY (question_id) REFERENCES classroom_assessment_questions(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        exec_safe(
            $pdo,
            "CREATE TABLE IF NOT EXISTS classroom_attendance_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                classroom_id INT NOT NULL,
                faculty_id INT NOT NULL,
                attendance_date DATE NOT NULL,
                session_start_at DATETIME NOT NULL,
                session_end_at DATETIME NOT NULL,
                source ENUM('auto_login_online') NOT NULL DEFAULT 'auto_login_online',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_classroom_attendance_day (classroom_id, attendance_date),
                CONSTRAINT fk_cas_classroom FOREIGN KEY (classroom_id) REFERENCES online_classrooms(id) ON DELETE CASCADE,
                CONSTRAINT fk_cas_faculty FOREIGN KEY (faculty_id) REFERENCES faculty(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        exec_safe(
            $pdo,
            "CREATE TABLE IF NOT EXISTS classroom_attendance_records (
                id INT AUTO_INCREMENT PRIMARY KEY,
                session_id INT NOT NULL,
                student_id INT NOT NULL,
                status ENUM('present','absent') NOT NULL DEFAULT 'absent',
                source ENUM('auto','manual') NOT NULL DEFAULT 'auto',
                evidence_login_at DATETIME NULL,
                evidence_seen_at DATETIME NULL,
                evidence_logout_at DATETIME NULL,
                checked_at DATETIME NOT NULL,
                notes VARCHAR(255) NOT NULL DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_attendance_session_student (session_id, student_id),
                KEY idx_attendance_status (session_id, status),
                CONSTRAINT fk_car_session FOREIGN KEY (session_id) REFERENCES classroom_attendance_sessions(id) ON DELETE CASCADE,
                CONSTRAINT fk_car_student FOREIGN KEY (student_id) REFERENCES classroom_students(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        exec_safe($pdo, "ALTER TABLE classroom_attendance_records ADD COLUMN evidence_logout_at DATETIME NULL");

        exec_safe($pdo, 'ALTER TABLE online_classrooms ADD COLUMN join_code VARCHAR(16) NULL');
        exec_safe($pdo, 'ALTER TABLE online_classrooms ADD UNIQUE KEY uq_oc_join_code (join_code)');
        exec_safe($pdo, 'ALTER TABLE online_classrooms ADD COLUMN syllabus_stored_name VARCHAR(255) NULL');
        exec_safe($pdo, 'ALTER TABLE online_classrooms ADD COLUMN syllabus_original_name VARCHAR(255) NULL');
        exec_safe($pdo, 'ALTER TABLE online_classrooms ADD COLUMN syllabus_mime VARCHAR(120) NULL');

        $superAdminId = (int) $pdo->query("SELECT id FROM users WHERE role='super_admin' ORDER BY id LIMIT 1")->fetchColumn();
        if ($superAdminId < 1) {
            $st = $pdo->prepare('INSERT INTO users (username,password,full_name,role,is_active) VALUES (?,?,?,?,1)');
            $st->execute([
                DEFAULT_SUPER_ADMIN_USERNAME,
                password_hash(DEFAULT_SUPER_ADMIN_PASSWORD, PASSWORD_DEFAULT),
                DEFAULT_SUPER_ADMIN_FULL_NAME,
                'super_admin',
            ]);
        }

        exec_safe($pdo, "ALTER TABLE admin_activity_logs ADD COLUMN actor_full_name VARCHAR(100) NOT NULL DEFAULT ''");
        exec_safe($pdo, "ALTER TABLE admin_activity_logs ADD COLUMN actor_log_title VARCHAR(120) NOT NULL DEFAULT ''");

        $adminId = (int) $pdo->query("SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1")->fetchColumn();
        if ($adminId < 1) {
            $st = $pdo->prepare('INSERT INTO users (username,password,full_name,role,is_active) VALUES (?,?,?,?,1)');
            $st->execute([DEFAULT_ADMIN_USERNAME, password_hash(DEFAULT_ADMIN_PASSWORD, PASSWORD_DEFAULT), DEFAULT_ADMIN_FULL_NAME, 'admin']);
        }

        $genedId = (int) $pdo->query("SELECT id FROM users WHERE role='gened' ORDER BY id LIMIT 1")->fetchColumn();
        if ($genedId < 1) {
            $st = $pdo->prepare('INSERT INTO users (username,password,full_name,role,is_active) VALUES (?,?,?,?,1)');
            $st->execute([DEFAULT_GENED_USERNAME, password_hash(DEFAULT_GENED_PASSWORD, PASSWORD_DEFAULT), DEFAULT_GENED_FULL_NAME, 'gened']);
        }

        $joinCodeCol = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $joinCodeCol->execute([DB_NAME, 'online_classrooms', 'join_code']);
        if ((int) $joinCodeCol->fetchColumn() > 0) {
            $idsStmt = $pdo->query("SELECT id FROM online_classrooms WHERE join_code IS NULL OR join_code = ''");
            $missingCodes = $idsStmt ? $idsStmt->fetchAll(PDO::FETCH_COLUMN) : [];
            $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            foreach ($missingCodes as $oid) {
                $oid = (int) $oid;
                for ($t = 0; $t < 50; $t++) {
                    $code = '';
                    for ($i = 0; $i < 8; $i++) {
                        $code .= $chars[random_int(0, strlen($chars) - 1)];
                    }
                    $c = $pdo->prepare('SELECT COUNT(*) FROM online_classrooms WHERE join_code = ?');
                    $c->execute([$code]);
                    if ((int) $c->fetchColumn() === 0) {
                        $u = $pdo->prepare('UPDATE online_classrooms SET join_code = ? WHERE id = ?');
                        $u->execute([$code, $oid]);
                        break;
                    }
                }
            }
        }

        // Remove campuses feature: drop FKs/columns/table and restore pre-campus room_code_scope (idempotent).
        exec_safe($pdo, 'ALTER TABLE schedules DROP FOREIGN KEY fk_sched_campus');
        exec_safe($pdo, 'ALTER TABLE schedules DROP COLUMN campus_id');
        exec_safe($pdo, 'ALTER TABLE rooms DROP INDEX uq_rooms_scope');
        exec_safe($pdo, 'ALTER TABLE rooms DROP COLUMN room_code_scope');
        exec_safe($pdo, 'ALTER TABLE rooms DROP FOREIGN KEY fk_room_campus');
        exec_safe($pdo, 'ALTER TABLE rooms DROP INDEX idx_rooms_campus');
        exec_safe($pdo, 'ALTER TABLE rooms DROP COLUMN campus_id');
        exec_safe(
            $pdo,
            "ALTER TABLE rooms ADD COLUMN room_code_scope VARCHAR(64) GENERATED ALWAYS AS (
                IF(COALESCE(is_gened,0) = 1,
                   CONCAT('G|', room_code),
                   CONCAT('C|', IFNULL(college_id, 0), '|', room_code))
            ) STORED"
        );
        exec_safe($pdo, 'ALTER TABLE rooms ADD UNIQUE KEY uq_rooms_scope (room_code_scope)');
        exec_safe($pdo, 'DROP TABLE IF EXISTS campuses');

        $ok = 'Role upgrade complete. You can now use admin/dean/faculty/gened workflows.';
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Upgrade Roles</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width: 700px;">
    <h1 class="h4 mb-3">Database Role Upgrade</h1>
    <p class="text-muted">Runs idempotent schema updates for admin/dean/faculty role support.</p>
    <?php if ($ok): ?>
        <div class="alert alert-success"><?= htmlspecialchars($ok) ?></div>
        <div class="card border-primary shadow-sm mb-4">
            <div class="card-header bg-primary text-white">
                <strong><i class="fa-solid fa-user-secret me-2" aria-hidden="true"></i>Super Admin — first-time sign-in</strong>
            </div>
            <div class="card-body">
                <p class="mb-2">Super Admin uses the <strong>same login page</strong> (<code>login.php</code>). There is no separate URL.</p>
                <p class="mb-2"><strong>Default username:</strong> <code><?= htmlspecialchars(DEFAULT_SUPER_ADMIN_USERNAME) ?></code></p>
                <p class="mb-2"><strong>Default password:</strong> see <code>config/config.php</code> → <code>DEFAULT_SUPER_ADMIN_PASSWORD</code>. Change it in the file and under <strong>Settings</strong> after you sign in.</p>
                <p class="mb-0 small text-muted">After signing in: sidebar → <strong>Administrator accounts</strong> → create or edit <code>admin</code> users for day-to-day scheduling.</p>
            </div>
        </div>
        <a class="btn btn-primary btn-lg" href="login.php">Go to login</a>
    <?php else: ?>
        <?php if ($err): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
        <?php endif; ?>
        <form method="post">
            <button class="btn btn-primary" type="submit">Run upgrade</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>

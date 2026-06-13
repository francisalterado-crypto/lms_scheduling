-- WPU SABLAe Portal — run via install.php or mysql client
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS colleges (
  id INT AUTO_INCREMENT PRIMARY KEY,
  college_code VARCHAR(20) NOT NULL UNIQUE,
  college_name VARCHAR(120) NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  dean_user_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  full_name VARCHAR(100) NOT NULL,
  email VARCHAR(190) NOT NULL DEFAULT '',
  role ENUM('admin','dean','program_chair','faculty','gened','student') NOT NULL DEFAULT 'faculty',
  assigned_program VARCHAR(120) NOT NULL DEFAULT '',
  college_id INT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  last_seen_at DATETIME NULL,
  last_logout_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sched_users_college_id FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS programs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  college_id INT NOT NULL,
  program_name VARCHAR(120) NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_program_college_name (college_id, program_name),
  CONSTRAINT fk_program_college FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS college_programs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  college_id INT NOT NULL,
  program_code VARCHAR(30) NOT NULL,
  program_name VARCHAR(120) NOT NULL DEFAULT '',
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_college_program_code (college_id, program_code),
  CONSTRAINT fk_cp_college FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS program_year_levels (
  id INT AUTO_INCREMENT PRIMARY KEY,
  program_id INT NOT NULL,
  year_level VARCHAR(20) NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_program_year (program_id, year_level),
  CONSTRAINT fk_pyl_program FOREIGN KEY (program_id) REFERENCES college_programs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS program_sections (
  id INT AUTO_INCREMENT PRIMARY KEY,
  year_level_id INT NOT NULL,
  section_name VARCHAR(20) NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_year_section (year_level_id, section_name),
  CONSTRAINT fk_ps_year FOREIGN KEY (year_level_id) REFERENCES program_year_levels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS faculty (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL UNIQUE,
  faculty_id VARCHAR(20) NOT NULL UNIQUE,
  full_name VARCHAR(100) NOT NULL,
  department VARCHAR(100) NOT NULL DEFAULT '',
  email VARCHAR(100) NOT NULL DEFAULT '',
  max_hours_per_day INT NOT NULL DEFAULT 8,
  is_gened TINYINT(1) NOT NULL DEFAULT 0,
  college_id INT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_faculty_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_faculty_college FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS courses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  course_code VARCHAR(20) NOT NULL,
  course_name VARCHAR(100) NOT NULL,
  units DECIMAL(3,1) NOT NULL DEFAULT 3.0,
  lecture_units DECIMAL(3,1) NOT NULL DEFAULT 3.0,
  laboratory_units DECIMAL(3,1) NOT NULL DEFAULT 0.0,
  is_laboratory TINYINT(1) NOT NULL DEFAULT 0,
  is_gened TINYINT(1) NOT NULL DEFAULT 0,
  year_level VARCHAR(20) NOT NULL DEFAULT '',
  section VARCHAR(20) NOT NULL DEFAULT '',
  department VARCHAR(100) NOT NULL DEFAULT '',
  college_id INT NULL,
  classroom_code VARCHAR(16) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_course_code (course_code),
  UNIQUE KEY uq_courses_classroom_code (classroom_code),
  CONSTRAINT fk_course_college FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rooms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  room_code VARCHAR(20) NOT NULL,
  room_name VARCHAR(50) NOT NULL DEFAULT '',
  capacity INT NOT NULL DEFAULT 0,
  type ENUM('lecture','laboratory','conference','tba') NOT NULL DEFAULT 'lecture',
  status ENUM('available','tba','maintenance') NOT NULL DEFAULT 'available',
  is_gened TINYINT(1) NOT NULL DEFAULT 0,
  college_id INT NULL,
  room_code_scope VARCHAR(64) GENERATED ALWAYS AS (
    IF(COALESCE(is_gened,0) = 1,
       CONCAT('G|', room_code),
       CONCAT('C|', IFNULL(college_id, 0), '|', room_code))
  ) STORED,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rooms_scope (room_code_scope),
  KEY idx_room_code (room_code),
  CONSTRAINT fk_room_college FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schedules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  faculty_id INT NOT NULL,
  course_id INT NOT NULL,
  room_id INT NOT NULL,
  college_id INT NULL,
  schedule_type ENUM('MW','TTH','MWF','TTHS','Saturday','Sunday','MW_TTH','Custom') NOT NULL DEFAULT 'Custom',
  day_of_week SET('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  semester VARCHAR(20) NOT NULL,
  school_year VARCHAR(20) NOT NULL,
  academic_year VARCHAR(20) NOT NULL DEFAULT '',
  program VARCHAR(100) NOT NULL DEFAULT '',
  year_level VARCHAR(20) NOT NULL DEFAULT '',
  section VARCHAR(20) NOT NULL DEFAULT '',
  online_class_url VARCHAR(512) NULL DEFAULT NULL,
  online_live_at DATETIME NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  created_by INT NOT NULL,
  CONSTRAINT fk_sched_faculty FOREIGN KEY (faculty_id) REFERENCES faculty(id) ON DELETE CASCADE,
  CONSTRAINT fk_sched_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  CONSTRAINT fk_sched_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
  CONSTRAINT fk_sched_college FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE SET NULL,
  CONSTRAINT fk_sched_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS conflict_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  schedule_id INT NULL,
  conflict_type ENUM('faculty','room','time') NOT NULL,
  conflict_description TEXT NOT NULL,
  resolved TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_conf_sched FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS conflict_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  requested_by INT NOT NULL,
  college_id INT NOT NULL,
  faculty_id INT NOT NULL,
  course_id INT NOT NULL,
  room_id INT NOT NULL,
  schedule_type VARCHAR(20) NOT NULL,
  day_of_week VARCHAR(120) NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  semester VARCHAR(20) NOT NULL,
  school_year VARCHAR(20) NOT NULL,
  academic_year VARCHAR(20) NOT NULL DEFAULT '',
  program VARCHAR(100) NOT NULL DEFAULT '',
  year_level VARCHAR(20) NOT NULL DEFAULT '',
  section VARCHAR(20) NOT NULL DEFAULT '',
  reason TEXT NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  admin_remarks TEXT NULL,
  reviewed_by INT NULL,
  reviewed_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cr_user FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_cr_college FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE CASCADE,
  CONSTRAINT fk_cr_faculty FOREIGN KEY (faculty_id) REFERENCES faculty(id) ON DELETE CASCADE,
  CONSTRAINT fk_cr_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  CONSTRAINT fk_cr_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
  CONSTRAINT fk_cr_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dean_activity_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  dean_user_id INT NOT NULL,
  college_id INT NOT NULL,
  action_type VARCHAR(60) NOT NULL,
  action_details TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_dal_user FOREIGN KEY (dean_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_dal_college FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schedule_change_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  faculty_user_id INT NOT NULL,
  schedule_id INT NOT NULL,
  message TEXT NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  dean_remarks TEXT NULL,
  reviewed_by INT NULL,
  reviewed_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_scr_user FOREIGN KEY (faculty_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_scr_sched FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE,
  CONSTRAINT fk_scr_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS faculty_specializations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  faculty_id INT NOT NULL,
  course_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_faculty_course (faculty_id, course_id),
  CONSTRAINT fk_fs_faculty FOREIGN KEY (faculty_id) REFERENCES faculty(id) ON DELETE CASCADE,
  CONSTRAINT fk_fs_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ge_course_colleges (
  id INT AUTO_INCREMENT PRIMARY KEY,
  course_id INT NOT NULL,
  college_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ge_course_college (course_id, college_id),
  CONSTRAINT fk_gecc_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  CONSTRAINT fk_gecc_college FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ge_schedule_targets (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS internal_messages (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS online_classrooms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  schedule_id INT NOT NULL UNIQUE,
  faculty_id INT NOT NULL,
  course_id INT NOT NULL,
  title VARCHAR(150) NOT NULL,
  description TEXT NULL,
  meet_link VARCHAR(512) NOT NULL DEFAULT '',
  status ENUM('active','archived') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  written_work_percentage DECIMAL(5,2) NOT NULL DEFAULT 50.00,
  performance_task_percentage DECIMAL(5,2) NOT NULL DEFAULT 50.00,
  join_code VARCHAR(16) NULL,
  UNIQUE KEY uq_oc_join_code (join_code),
  CONSTRAINT fk_oc_schedule FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE,
  CONSTRAINT fk_oc_faculty FOREIGN KEY (faculty_id) REFERENCES faculty(id) ON DELETE CASCADE,
  CONSTRAINT fk_oc_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS classroom_students (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL UNIQUE,
  student_number VARCHAR(30) NOT NULL DEFAULT '',
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cls_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS classroom_enrollments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  classroom_id INT NOT NULL,
  student_id INT NOT NULL,
  enrolled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_classroom_student (classroom_id, student_id),
  CONSTRAINT fk_ce_classroom FOREIGN KEY (classroom_id) REFERENCES online_classrooms(id) ON DELETE CASCADE,
  CONSTRAINT fk_ce_student FOREIGN KEY (student_id) REFERENCES classroom_students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS classroom_content (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS classroom_content_attachments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  content_id INT NOT NULL,
  original_name VARCHAR(255) NOT NULL DEFAULT '',
  stored_name VARCHAR(255) NOT NULL DEFAULT '',
  mime VARCHAR(120) NOT NULL DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cca_content (content_id),
  CONSTRAINT fk_cca_content FOREIGN KEY (content_id) REFERENCES classroom_content(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS classroom_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  classroom_id INT NOT NULL,
  sender_user_id INT NOT NULL,
  body TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cm_classroom_time (classroom_id, created_at),
  CONSTRAINT fk_cm_classroom FOREIGN KEY (classroom_id) REFERENCES online_classrooms(id) ON DELETE CASCADE,
  CONSTRAINT fk_cm_sender FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS classroom_assessments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  classroom_id INT NOT NULL,
  faculty_id INT NOT NULL,
  assessment_type ENUM('written_work','performance_task','assignment','quiz') NOT NULL DEFAULT 'written_work',
  title VARCHAR(150) NOT NULL,
  description TEXT NULL,
  total_points DECIMAL(8,2) NOT NULL DEFAULT 100.00,
  due_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ca_classroom FOREIGN KEY (classroom_id) REFERENCES online_classrooms(id) ON DELETE CASCADE,
  CONSTRAINT fk_ca_faculty FOREIGN KEY (faculty_id) REFERENCES faculty(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS classroom_scores (
  id INT AUTO_INCREMENT PRIMARY KEY,
  assessment_id INT NOT NULL,
  student_id INT NOT NULL,
  score DECIMAL(8,2) NULL,
  feedback TEXT NULL,
  graded_at DATETIME NULL,
  UNIQUE KEY uq_assessment_student (assessment_id, student_id),
  CONSTRAINT fk_cs_assessment FOREIGN KEY (assessment_id) REFERENCES classroom_assessments(id) ON DELETE CASCADE,
  CONSTRAINT fk_cs_student FOREIGN KEY (student_id) REFERENCES classroom_students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS classroom_submissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  assessment_id INT NOT NULL,
  student_id INT NOT NULL,
  answer_text LONGTEXT NULL,
  status ENUM('submitted','resubmitted') NOT NULL DEFAULT 'submitted',
  submitted_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_submission_assessment_student (assessment_id, student_id),
  CONSTRAINT fk_csub_assessment FOREIGN KEY (assessment_id) REFERENCES classroom_assessments(id) ON DELETE CASCADE,
  CONSTRAINT fk_csub_student FOREIGN KEY (student_id) REFERENCES classroom_students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS classroom_attendance_sessions (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS classroom_attendance_records (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

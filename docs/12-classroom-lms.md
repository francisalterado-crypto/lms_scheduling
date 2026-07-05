# Module 12: Online Classroom (LMS)

Virtual classroom features tied to schedule entries.

---

## Source Files

| File | Role | Purpose |
|------|------|---------|
| `faculty_classrooms.php` | faculty | Classroom list |
| `faculty_classroom.php` | faculty | Classroom management hub |
| `faculty_classroom_week.php` | faculty | Weekly content organizer |
| `faculty_classroom_attendance.php` | faculty | Attendance tracking |
| `faculty_classroom_assessments.php` | faculty | Assessments & grading |
| `student_classrooms.php` | student | Enrolled classrooms + join |
| `student_classroom.php` | student | Classroom learner view |
| `student_classroom_week.php` | student | Weekly content view |
| `classroom_content_attachment.php` | faculty, student | Secure file download |
| `classroom_syllabus.php` | faculty | Syllabus upload |
| `classroom_materials_monitor.php` | admin, dean, chair, gened | Upload monitoring |
| `includes/classroom_discussion_helpers.php` | — | Discussion threads |

---

## Classroom Lifecycle

1. **Creation** — When a schedule is saved, an `online_classrooms` row may be created linking `schedule_id`, `faculty_id`, `course_id`.
2. **Join code** — `classroom_alloc_unique_join_code()` assigns 8-char code.
3. **Enrollment** — Students join via code → `classroom_enrollments`.
4. **Content** — Faculty posts materials → `classroom_content`.
5. **Assessment** — Faculty creates assessments → students submit → faculty grades.

---

## Tables

### `online_classrooms`
| Field | Purpose |
|-------|---------|
| `schedule_id` | Links to physical schedule |
| `title`, `description` | Display info |
| `meet_link` | Video conference URL |
| `join_code` | Student enrollment code |
| `written_work_percentage`, `performance_task_percentage` | Grade weights |
| `syllabus_*` | Syllabus file metadata |

### `classroom_students` / `classroom_enrollments`
- Student roster independent of user accounts (can link via `user_id`).
- Many-to-many enrollment.

### `classroom_content`
| `content_type` | Use |
|----------------|-----|
| `material` | Files, documents |
| `link` | External URLs |
| `announcement` | Text announcements |

Fields: `title`, `body` (rich HTML), `weeks`, `days_per_topic`, `resource_url`.

### `classroom_content_attachments`
- Multiple files per content item.
- Stored under `uploads/classroom/`.

### `classroom_assessments`
Types: `written_work`, `performance_task`, `assignment`, `quiz`.

### `classroom_submissions` / `classroom_scores`
- Student answers and faculty grades.
- Unique per assessment + student.

### `classroom_attendance_sessions` / `classroom_attendance_records`
- One session per classroom per day.
- Auto-present when student logs in during online window.
- Manual override by faculty.

### `classroom_messages`
- Per-classroom discussion board.
- FIFO limit via `classroom_discussion_enforce_fifo()`.

---

## Content Helpers (`includes/functions.php`)

| Function | Purpose |
|----------|---------|
| `classroom_content_sanitize_html()` | XSS-safe rich text |
| `classroom_content_render_body()` | Display sanitized HTML |
| `classroom_content_group_by_week()` | Group content by week label |
| `classroom_content_store_attachment()` | File upload handler |
| `classroom_content_attachment_href()` | Download URL builder |
| `classroom_syllabus_href()` | Syllabus download URL |

---

## Discussion Helpers (`includes/classroom_discussion_helpers.php`)

| Function | Purpose |
|----------|---------|
| `classroom_discussion_can_access()` | Enrollment check |
| `classroom_discussion_post()` | New message |
| `classroom_discussion_messages()` | Fetch thread |
| `classroom_discussion_threads_for_user()` | Inbox across classrooms |

---

## Assessment Grading

| Function | Purpose |
|----------|---------|
| `classroom_assessment_normalize_type()` | Valid type enum |
| `classroom_grade_question_submission()` | Auto-grade MC/true-false |
| `classroom_problem_answer_matches()` | Fuzzy answer matching |

---

## File Downloads

`classroom_content_attachment.php`:
- Verifies user is faculty of classroom or enrolled student.
- Streams file with correct `Content-Type` and download name.
- Prevents directory traversal.

---

## Materials Monitor

`classroom_materials_monitor.php`:
- Admin/dean/chair/gened view of recent uploads.
- Audit trail for institutional oversight.

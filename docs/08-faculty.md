# Module 8: Faculty

Instructor-facing tools for teaching, classrooms, and schedules.

**Role:** `faculty`  
**Identity:** `users` linked to `faculty` via `user_id` or name match

---

## Source Files

| File | Purpose |
|------|---------|
| `faculty_schedule.php` | Personal teaching schedule |
| `schedule.php` | Schedule list (faculty's classes) |
| `view_schedule.php` | Weekly calendar |
| `conflicts.php` | View conflicts affecting faculty |
| `faculty_classrooms.php` | List online classrooms for faculty |
| `faculty_classroom.php` | Single classroom hub (content, students, settings) |
| `faculty_classroom_week.php` | Week-by-week content view |
| `faculty_classroom_attendance.php` | Attendance sessions and records |
| `faculty_classroom_assessments.php` | Quizzes, assignments, grading |
| `faculty_edutools.php` | EduTools launcher |
| `faculty_teaching_load.php` | Own teaching load summary |
| `classroom_content_attachment.php` | Download content attachments |
| `classroom_syllabus.php` | Upload/view syllabus PDF |
| `messages.php` | Internal messaging |
| `reports.php` | Faculty reports |
| `settings.php` | Profile settings |
| `dashboard.php` | Faculty dashboard |

---

## Identity Resolution

On login, `resolve_faculty_id_for_user()` sets `$_SESSION['faculty_id']`.

`faculty_college_id($facultyId)` scopes schedule queries.

---

## `faculty_classrooms.php`

- Lists `online_classrooms` where `faculty_id` matches session.
- Each classroom links to a `schedules` row / `courses` row.
- Shows join code, enrollment count, status.

---

## `faculty_classroom.php`

Central LMS management page:

| Feature | Tables |
|---------|--------|
| Classroom settings | `online_classrooms` (title, meet link, grade weights) |
| Content (materials, links, announcements) | `classroom_content` |
| Student roster | `classroom_enrollments` + `classroom_students` |
| Discussion | `classroom_messages` via `classroom_discussion_helpers.php` |
| Join code | `online_classrooms.join_code` |

---

## `faculty_classroom_attendance.php`

- Creates `classroom_attendance_sessions` per class day.
- Records `classroom_attendance_records` (present/absent).
- Auto-attendance from online login (`source = auto_login_online`).
- Manual override supported.

---

## `faculty_classroom_assessments.php`

- CRUD `classroom_assessments` (written_work, performance_task, assignment, quiz).
- Grade `classroom_scores` per student.
- Review `classroom_submissions` for assignments.
- Objective question auto-grading via `classroom_grade_question_submission()`.

---

## `faculty_edutools.php`

Launcher for faculty academic tools (links to classrooms, schedule, etc.).

---

## Schedule Change Requests

Faculty can request schedule changes → `schedule_change_requests` reviewed by dean/chair.

---

## Navigation (faculty in `role_nav_sections`)

| Section | Items |
|---------|-------|
| Overview | Dashboard, Messages, Schedules, Weekly view |
| Teaching | Classrooms, EduTools, Teaching load |
| Account | Settings |

---

## Related Tables

`faculty`, `schedules`, `online_classrooms`, `classroom_*`, `schedule_change_requests`, `internal_messages`

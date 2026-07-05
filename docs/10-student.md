# Module 10: Student

Student portal for classrooms, schedules, and wellness.

**Role:** `student`  
**Identity:** `users` linked to `classroom_students` via `user_id`

---

## Source Files

| File | Purpose |
|------|---------|
| `login.php` | Student login + self-registration form |
| `student_classrooms.php` | List enrolled / joinable classrooms |
| `student_classroom.php` | Single classroom view (content, assessments) |
| `student_classroom_week.php` | Week-organized content view |
| `student_edutools.php` | EduTools hub (wellness, etc.) |
| `student_wellness.php` | Wellness check-in UI |
| `classroom_content_attachment.php` | Download attachments |
| `view_schedule.php` | Student schedule view (if applicable) |
| `messages.php` | Messaging with faculty |
| `settings.php` | Profile settings |
| `dashboard.php` | Student dashboard |

---

## Registration Flow

**On `login.php` (mode=register):**

1. Student fills: username, password, full name, email, student number, college, program, year level.
2. `submit_student_registration()` validates via `validate_student_registration_input()`.
3. Inserts `student_registration_requests` (`status = pending`).
4. Program chair approves on `program_chair_student_registrations.php`.
5. `approve_student_registration_request()` creates `users` + `classroom_students`.

**API support:** `api/programs_by_college.php` — dynamic program/year-level dropdowns.

---

## `includes/student_registration_helpers.php`

| Function | Description |
|----------|-------------|
| `student_registration_table_ready()` | Checks table exists |
| `active_colleges_for_registration()` | Active colleges list |
| `active_programs_for_college($collegeId)` | Programs for dropdown |
| `active_year_levels_for_program(...)` | Year levels for program |
| `submit_student_registration(...)` | Create pending request |
| `create_student_account(...)` | Create approved user + student |
| `username_taken_for_registration($username)` | Uniqueness check |

---

## `student_classrooms.php`

- Shows classrooms student is enrolled in (`classroom_enrollments`).
- Join by code: `classroom_normalize_join_code()` + lookup `online_classrooms.join_code`.
- Enrolls via `classroom_enrollments` insert.

---

## `student_classroom.php`

Student view of classroom:
- Read content (`classroom_content`) grouped by week
- View announcements and links
- Submit assessments → `classroom_submissions`
- View scores → `classroom_scores`
- Participate in discussion (`classroom_messages`)

---

## `student_edutools.php`

Hub linking to:
- Wellness check-in (`student_wellness.php`)
- Other student academic tools

---

## `student_wellness.php`

- Renders wellness companion UI via `includes/student_wellness_ui.php`.
- Chat powered by `api/student_wellness_chat.php`.
- See [14-wellness.md](14-wellness.md).

---

## Messaging

Students can message faculty they are enrolled with:
- `messaging_student_can_message_faculty($studentUserId, $facultyUserId)`
- Enforced in `messaging_can_send()`

---

## Navigation (student in `role_nav_sections`)

| Section | Items |
|---------|-------|
| Overview | Dashboard, Messages |
| Learning | Classrooms, EduTools |
| Account | Settings |

---

## Related Tables

`classroom_students`, `student_registration_requests`, `classroom_enrollments`, `online_classrooms`, `classroom_content`, `classroom_assessments`, `classroom_submissions`, `classroom_scores`, `internal_messages`

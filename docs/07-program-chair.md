# Module 7: Program Chair

Program-scoped management within a dean's college.

**Role:** `program_chair`  
**Scope:** `users.college_id` + `users.assigned_program` (department/program name)

---

## Source Files

| File | Purpose |
|------|---------|
| `faculty.php` | Faculty in assigned program (department filter) |
| `courses.php` | Courses for assigned program |
| `add_schedule.php` | Create schedules for program offerings |
| `edit_schedule.php` | Edit program schedules |
| `schedule.php` | Schedule list (program-scoped) |
| `view_schedule.php` | Weekly view |
| `conflicts.php` | Conflict review |
| `program_chair_students.php` | Manage `classroom_students` in program |
| `program_chair_student_registrations.php` | Approve/reject registration requests |
| `faculty_teaching_load.php` | Teaching load for program faculty |
| `reports.php` | Program reports |
| `classroom_materials_monitor.php` | Content monitoring |
| `dashboard.php` | Chair dashboard (pending registrations count) |

---

## Scope Enforcement

```php
$collegeId = dean_or_program_chair_college_id_or_fail();
$programScope = program_scope_or_fail();
```

Queries filter:
- `faculty.department = $programScope`
- `courses.department = $programScope`
- `schedules` joined to courses with matching department

---

## `program_chair_students.php`

- Lists students (`classroom_students`) enrolled in program classrooms.
- Create student accounts linked to `users` (`role = student`).
- Manage student number, year level, email.

---

## `program_chair_student_registrations.php`

Uses `includes/student_registration_helpers.php`:

| Function | Description |
|----------|-------------|
| `pending_registrations_for_program($collegeId, $programScope)` | Pending queue |
| `approve_student_registration_request(...)` | Creates user + student row |
| `reject_student_registration_request(...)` | Sets rejection reason |
| `count_pending_registrations_for_program(...)` | Badge count for nav |

**Workflow:**
1. Student submits registration on `login.php`.
2. Request stored in `student_registration_requests` (`status = pending`).
3. Chair approves → `create_student_account()` or rejects with reason.

---

## Shared Pages with Dean

Program chairs share these pages with deans but see filtered data:

- `faculty.php`, `courses.php`, `rooms.php` (read-only rooms typically)
- All scheduling pages
- `conflicts.php`, `reports.php`

---

## Navigation (program_chair in `role_nav_sections`)

| Section | Items |
|---------|-------|
| Overview | Dashboard, Messages, Schedules, Weekly view |
| Academic | Faculty, Courses, Students, Registrations |
| Scheduling | Add schedule, Conflicts |
| Reports | Reports, Teaching load, Materials monitor |
| Account | Settings |

---

## Related Tables

`faculty`, `courses`, `schedules`, `classroom_students`, `student_registration_requests`, `schedule_change_requests`, `classroom_enrollments`

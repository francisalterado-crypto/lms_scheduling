# Module 6: Dean (College)

College-scoped academic administration.

**Role:** `dean`  
**Scope:** `users.college_id` → one college

---

## Source Files

| File | Purpose |
|------|---------|
| `dean_programs.php` | Manage `college_programs`, year levels, sections |
| `program_chairs.php` | Assign program chair accounts to programs |
| `faculty.php` | Faculty CRUD for the college |
| `courses.php` | Course catalog (college-scoped, non-GE) |
| `rooms.php` | Room management for the college |
| `add_schedule.php` | Create new schedule entries |
| `edit_schedule.php` | Modify existing schedules |
| `schedule.php` | Schedule list view |
| `view_schedule.php` | Weekly calendar view |
| `auto_schedule.php` | Automated schedule generation |
| `conflicts.php` | View scheduling conflicts |
| `dean_gened_chair.php` | Assign GE program chair / CAS dean liaison |
| `faculty_teaching_load.php` | College teaching load report |
| `reports.php` | College reports |
| `classroom_materials_monitor.php` | Monitor uploaded materials |
| `dashboard.php` | Dean dashboard stats |

---

## Scope Enforcement

All dean pages use:

```php
$collegeId = dean_college_id_or_fail();
```

SQL queries append college filter via `sql_scope_for_college()` or explicit `college_id = ?`.

Dean also sees applicable GE schedules via `dean_schedule_scope_sql()`.

---

## `dean_programs.php`

- CRUD on `college_programs` (program code, name, status).
- Manage `program_year_levels` and `program_sections`.
- `parse_dean_program_year_levels_post()` processes form year-level checkboxes.
- `program_year_levels_replace()` syncs year levels.

---

## `program_chairs.php`

- Creates `users` with `role = program_chair`.
- Sets `assigned_program` and `college_id`.
- One chair per program (typical).
- Credential email on create.

---

## `faculty.php`

- CRUD `faculty` rows: faculty_id, name, department, email, max hours, employment status.
- Link to `users` account (`user_id`) for login.
- Manage `faculty_specializations` (courses faculty can teach).
- Suggests usernames via `suggest_available_usernames()`.

---

## `courses.php`

- College course catalog in `courses` where `is_gened = 0`.
- Fields: code, name, units, lecture/lab units, year level, section, department.
- `classroom_code` auto-allocated for LMS integration.
- Shared with program chair (scoped to program department).

---

## `rooms.php`

- College rooms (`is_gened = 0`, `college_id` set).
- Auto room code generation via `next_auto_room_code_for_college()`.
- Types: lecture, laboratory, conference, tba.

---

## Scheduling Pages

| Page | Dean capabilities |
|------|-------------------|
| `add_schedule.php` | New offering with conflict check |
| `edit_schedule.php` | Update times, room, faculty |
| `schedule.php` | Filterable list |
| `view_schedule.php` | Weekly grid |
| `auto_schedule.php` | Batch auto-assign faculty/rooms |
| `conflicts.php` | Faculty/room/time conflicts |

Conflict detection: `checkConflicts()` in `includes/functions.php`.

Schedule change requests from faculty appear on dean dashboard.

---

## `dean_gened_chair.php`

- Configures GE liaison dean for messaging and live sessions.
- Related to `GE_DEAN_USER_ID` / `GE_DEAN_NAME_HINT` config.

---

## Activity Logging

`log_dean_activity($actionType, $details)` → `dean_activity_logs`.

---

## Navigation (dean sections in `role_nav_sections`)

| Section | Items |
|---------|-------|
| Overview | Dashboard, Messages, Schedules, Weekly view |
| Academic | Faculty, Program chairs, Programs, Courses, Rooms |
| Scheduling | Add schedule, Auto schedule, Conflicts |
| Reports | Reports, Teaching load, Materials monitor |
| GE | GE chair assignment |
| Account | Settings |

---

## Related Tables

`college_programs`, `program_year_levels`, `program_sections`, `faculty`, `courses`, `rooms`, `schedules`, `schedule_change_requests`, `dean_activity_logs`

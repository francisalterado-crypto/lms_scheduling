# Module 9: General Education (GEN ED)

Institution-wide General Education scheduling and resources.

**Role:** `gened`  
**Account:** Single coordinator user (`role = gened`), managed via `admin_gened.php`

---

## Source Files

| File | Purpose |
|------|---------|
| `gened_courses.php` | GE course catalog (`is_gened = 1`) |
| `gened_faculty.php` | GE faculty (`faculty.is_gened = 1`) |
| `gened_rooms.php` | GE rooms (`rooms.is_gened = 1`) |
| `gened_schedule.php` | GE schedule list |
| `gened_edit_schedule.php` | Add/edit GE schedule entries |
| `gened_assignments.php` | Map GE courses to colleges (`ge_course_colleges`) |
| `add_schedule.php` / `edit_schedule.php` | Shared schedule forms (GE mode) |
| `schedule.php` | Schedule list (GE scope) |
| `view_schedule.php` | Weekly view |
| `conflicts.php` | GE scheduling conflicts |
| `faculty_teaching_load.php` | GE faculty load |
| `classroom_materials_monitor.php` | Content monitoring |
| `reports.php` | GE reports |
| `messages.php` | Messaging (GE faculty ↔ GE dean rules) |
| `dashboard.php` | GEN ED dashboard |

---

## GE Data Model

### Courses (`courses` where `is_gened = 1`)
- No `college_id` (institution-wide).
- Mapped to colleges via `ge_course_colleges`.

### Faculty (`faculty` where `is_gened = 1`)
- `college_id` may be null.
- Teaches GE offerings only.

### Rooms (`rooms` where `is_gened = 1`)
- `room_code_scope = CONCAT('G|', room_code)`.
- `next_auto_room_code_gened()` for code generation.

### Schedules
- Standard `schedules` row with GE course/faculty/room.
- Target audience stored in `ge_schedule_targets`:
  - `college_id`, `program_name`, `year_level`, `section`

---

## `gened_assignments.php`

- Links GE courses to colleges that offer them.
- CRUD on `ge_course_colleges`.
- Drives which colleges see GE courses in dean schedules.

---

## `gened_edit_schedule.php`

- GE-specific schedule form via `render_schedule_form()`.
- Sets `ge_schedule_targets` for program/year/section targeting.
- Conflict checks include cross-college room sharing.

---

## GE Live Sessions

`weekly_schedule_gened_can_join_online_live()` controls who can join GE online live classes:
- GE faculty teaching the class
- Target college deans/chairs
- Configured GE dean (`GE_DEAN_USER_ID`)

---

## Messaging Rules (`messaging_helpers.php`)

GE-specific messaging constraints:
- `messaging_is_gened_faculty_user()` — identifies GE faculty
- `messaging_ge_dean_user_id()` — resolves GE dean for messaging
- GE faculty may only message the designated GE dean

---

## Navigation (gened in `role_nav_sections`)

| Section | Items |
|---------|-------|
| Overview | Dashboard, Messages, Schedules, Weekly view |
| GE Resources | GE courses, GE faculty, GE rooms, GE assignments |
| Scheduling | Add/edit GE schedule, Conflicts |
| Reports | Reports, Teaching load, Materials monitor |
| Account | Settings |

---

## Related Tables

`courses`, `faculty`, `rooms`, `schedules`, `ge_course_colleges`, `ge_schedule_targets`, `faculty_specializations`

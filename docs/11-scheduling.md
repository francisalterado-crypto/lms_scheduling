# Module 11: Scheduling

Core class scheduling engine shared across dean, program chair, GEN ED, faculty, and admin roles.

---

## Source Files

| File | Roles | Purpose |
|------|-------|---------|
| `schedule.php` | admin, dean, chair, gened, faculty | Filterable schedule list |
| `view_schedule.php` | admin, dean, chair, gened, faculty | Weekly grid calendar |
| `add_schedule.php` | dean, program_chair | Create schedule (college) |
| `edit_schedule.php` | dean, program_chair | Edit schedule (college) |
| `gened_schedule.php` | gened | GE schedule list |
| `gened_edit_schedule.php` | gened | Create/edit GE schedules |
| `auto_schedule.php` | dean | Automated assignment |
| `conflicts.php` | admin, dean, chair, gened, faculty | Conflict viewer |
| `includes/schedule_helpers.php` | — | Shared schedule form renderer |
| `includes/functions.php` | — | Conflict detection, validation rules |

---

## Data Model: `schedules`

| Column | Description |
|--------|-------------|
| `faculty_id` | Instructor |
| `course_id` | Course offering |
| `room_id` | Physical room |
| `college_id` | Owning college (null for some GE) |
| `schedule_type` | MW, TTH, MWF, Custom, etc. |
| `day_of_week` | MySQL SET of weekdays |
| `start_time`, `end_time` | Class period |
| `semester`, `school_year`, `academic_year` | Term identifiers |
| `program`, `year_level`, `section` | Audience |
| `online_class_url`, `online_live_at` | Virtual class links |
| `created_by` | User who created entry |

---

## Schedule Validation (`validate_schedule_rules`)

Enforces config constants:

| Rule | Constant |
|------|----------|
| Earliest start | `TIME_MIN` (06:00) |
| Latest end | `TIME_MAX` (22:00) |
| Minimum duration | `MIN_CLASS_MINUTES` (30) |
| Gap between classes same day | `MIN_GAP_MINUTES` |
| Max block duration | `MAX_CLASS_BLOCK_HOURS` |
| Max consecutive hours | `MAX_CONSECUTIVE_HOURS` |

---

## Conflict Detection (`checkConflicts`)

Checks before save:

1. **Faculty conflict** — Same faculty, overlapping days/times
2. **Room conflict** — Same room, overlapping times (unless room type = tba)
3. **Faculty gaps** — `check_faculty_gaps_and_consecutive()` for gap/consecutive rules
4. **Cross-college room** — `detect_cross_college_room_conflicts()` for shared rooms

Unresolved conflicts:
- Logged to `conflict_logs`
- Or submitted as `conflict_requests` for admin override

---

## `render_schedule_form()` (`schedule_helpers.php`)

Shared HTML form used by add/edit pages:

**Inputs:** faculty, course, room, days, times, term, program, year, section, schedule type.

**Options array controls:**
- College vs GE mode
- Pre-filled defaults for edit
- Available faculty/courses/rooms scoped to role

---

## `auto_schedule.php`

Dean-only automated scheduler:
- Assigns faculty and rooms to unscheduled course offerings
- Respects faculty specializations, max hours, room capacity
- Uses conflict detection iteratively

---

## Weekly View (`view_schedule.php`)

- Renders 7-column week grid
- Color-coded by course/faculty
- Filters: semester, school year, college, program
- Online live session indicators via `weekly_schedule_online_live_mode()`

---

## GE Schedule Targets

GE schedules additionally write `ge_schedule_targets`:
- Which college/program/year/section the GE class serves
- Used by deans to see GE classes in their college view

---

## Schedule Change Workflow

| Step | Actor | Table |
|------|-------|-------|
| 1. Request | Faculty | `schedule_change_requests` |
| 2. Review | Dean / Chair | Same table, status update |
| 3. Apply | Dean / Chair | Update `schedules` row |

---

## Helper Functions Reference

| Function | Purpose |
|----------|---------|
| `fetch_faculty_day_schedules($facultyId, $day, ...)` | Faculty day schedule |
| `fetch_room_day_schedules($roomId, $day, ...)` | Room day schedule |
| `room_status_allows_overlap($roomId)` | TBA rooms skip conflict |
| `parse_day_set()` / `days_to_set()` | Day SET conversion |
| `create_conflict_request($payload)` | Admin escalation |

---

## Related Tables

`schedules`, `faculty`, `courses`, `rooms`, `conflict_logs`, `conflict_requests`, `schedule_change_requests`, `ge_schedule_targets`, `faculty_specializations`

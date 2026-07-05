# Module 1: Core Infrastructure

Shared foundation used by every page in the application.

---

## Source Files

| File | Purpose |
|------|---------|
| `includes/db.php` | PDO singleton (`db()`), MySQL connection with XAMPP-friendly error messages |
| `includes/functions.php` | General utilities: DB introspection, scheduling rules, classroom helpers, room codes, conflict detection |
| `includes/header.php` | HTML `<head>`, Bootstrap/CSS, top navigation shell |
| `includes/footer.php` | Closing markup, script includes |
| `includes/student_tooltip.php` | Tooltip attributes for UI hints (`app_tooltip_attr()`) |
| `index.php` | Redirects to `dashboard.php` (logged in) or `login.php` |
| `logout.php` | Clears session, records `last_logout_at`, redirects to login |

---

## `includes/db.php`

```php
function db(): PDO
```

- Loads `config/config.php`.
- Creates a static PDO connection to `DB_HOST:DB_PORT/DB_NAME`.
- Sets `ERRMODE_EXCEPTION` and `FETCH_ASSOC`.
- Throws a readable `RuntimeException` when MySQL is not running (errno 2002).

---

## `includes/functions.php` — Key Function Groups

### Database introspection
| Function | Description |
|----------|-------------|
| `db_column_exists($table, $column)` | Checks `information_schema.COLUMNS` |
| `db_table_exists($table)` | Checks `information_schema.TABLES` |
| `db_first_existing_column($table, $cols, $fallback)` | Picks first column that exists (migration-safe) |

### Scheduling primitives
| Function | Description |
|----------|-------------|
| `schedule_days_list()` | Returns Mon–Sun array |
| `parse_day_set($value)` / `days_to_set($days)` | MySQL SET ↔ PHP array |
| `time_to_minutes($time)` / `minutes_to_time($min)` | Time arithmetic |
| `intervals_overlap($s1,$e1,$s2,$e2)` | Overlap test |
| `validate_schedule_rules(...)` | Enforces TIME_MIN/MAX, MIN_CLASS_MINUTES, MIN_GAP_MINUTES, MAX_CONSECUTIVE_HOURS |
| `checkConflicts(...)` | Faculty, room, and cross-college room conflict detection |
| `log_conflicts($scheduleId, $conflicts)` | Writes to `conflict_logs` |
| `create_conflict_request($payload)` | Inserts `conflict_requests` for admin review |
| `sql_scope_for_college($alias, $collegeId)` | SQL fragment limiting rows to a college |

### Room management
| Function | Description |
|----------|-------------|
| `room_code_taken_for_college(...)` | Uniqueness within college scope |
| `room_code_taken_for_gened(...)` | Uniqueness for GE rooms |
| `next_auto_room_code_for_college(...)` | Auto-generates room codes |
| `next_auto_room_code_gened()` | GE room code generator |
| `detect_cross_college_room_conflicts(...)` | Shared physical room across colleges |

### Program / year level
| Function | Description |
|----------|-------------|
| `dean_program_year_levels_map($collegeId)` | Map program → year levels |
| `program_defined_year_levels($programId)` | Levels for a `college_programs` row |
| `program_year_levels_replace($programId, $levels)` | Replace year levels |
| `sort_schedule_year_levels($levels)` | Natural sort (1st, 2nd, …) |

### Classroom / LMS helpers
| Function | Description |
|----------|-------------|
| `classroom_alloc_unique_join_code()` | 8-char join code for `online_classrooms` |
| `course_alloc_unique_classroom_code()` | Unique `courses.classroom_code` |
| `classroom_content_sanitize_html($html)` | Safe HTML for rich content |
| `classroom_content_store_attachment($file)` | Saves uploads under `uploads/classroom/` |
| `classroom_assessment_normalize_type($type)` | written_work, performance_task, assignment, quiz |
| `classroom_grade_question_submission(...)` | Auto-grades objective questions |

### General Education (GE)
| Function | Description |
|----------|-------------|
| `is_ge_program_scope($program)` | Detects GE program label |
| `ge_courses_offered_to_college($collegeId)` | GE courses mapped via `ge_course_colleges` |
| `dean_schedule_scope_sql($collegeId, ...)` | Dean sees college + applicable GE schedules |
| `weekly_schedule_gened_can_join_online_live(...)` | GE live session access rules |

### Suggestions
| Function | Description |
|----------|-------------|
| `suggest_available_usernames($preferred)` | Username collision avoidance |
| `suggest_available_course_codes($preferred, $collegeId, $isGened)` | Course code suggestions |

---

## `includes/schedule_helpers.php`

| Function | Description |
|----------|-------------|
| `render_schedule_form($defaults, $options)` | Shared HTML form for add/edit schedule pages |

Used by `add_schedule.php`, `edit_schedule.php`, `gened_edit_schedule.php`.

---

## Session & Config Constants

Defined in `config/config.example.php` (copy to `config/config.php`):

| Constant | Purpose |
|----------|---------|
| `DB_*` | Database connection |
| `SESSION_NAME` | PHP session cookie name (`FSS_SESSION`) |
| `BASE_PATH` | Application root directory |
| `TIME_MIN`, `TIME_MAX` | Allowed class hours (06:00–22:00) |
| `MIN_CLASS_MINUTES`, `MIN_GAP_MINUTES` | Scheduling constraints |
| `MAX_CONSECUTIVE_HOURS` | Faculty consecutive teaching limit |
| `MESSAGING_THREAD_MAX_MESSAGES` | FIFO cap per message thread |
| `MAIL_*` | SMTP / credential email settings |
| `WELLNESS_AI_*` | Groq/Ollama wellness companion |

---

## Dependencies Between Core Files

```
config/config.php
    └── includes/db.php
            └── includes/functions.php
                    └── includes/schedule_helpers.php (optional)
includes/auth.php
    └── includes/db.php
Page (*.php)
    ├── includes/auth.php
    ├── includes/functions.php
    ├── includes/header.php
    └── includes/footer.php
```

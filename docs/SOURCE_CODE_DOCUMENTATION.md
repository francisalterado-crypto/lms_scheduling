---
title: "WPU SABLAe Portal — Source Code Documentation"
author: "CLASS / lms_scheduling"
date: "2026-07-27"
toc: true
toc-depth: 3
numbersections: true
---



\newpage

# WPU SABLAe Portal — Source Code Documentation

**Project:** `lms_scheduling` (Faculty Scheduling & Classroom LMS)  
**Stack:** PHP 8+ (strict types), MySQL/MariaDB, Bootstrap 5, PDO  
**Entry point:** `index.php` → `login.php` or `dashboard.php`

This documentation describes all application source code organized by functional module. Each module page lists source files, database tables, roles, and key behaviors.

---

## Table of Contents

| # | Module | Document |
|---|--------|----------|
| 1 | Core infrastructure | [01-core-infrastructure.md](#) |
| 2 | Authentication & authorization | [02-authentication.md](#) |
| 3 | Installation & configuration | [03-installation.md](#) |
| 4 | Super Admin | [04-super-admin.md](#) |
| 5 | Admin (institution) | [05-admin.md](#) |
| 6 | Dean (college) | [06-dean.md](#) |
| 7 | Program Chair | [07-program-chair.md](#) |
| 8 | Faculty | [08-faculty.md](#) |
| 9 | General Education (GEN ED) | [09-gened.md](#) |
| 10 | Student | [10-student.md](10-student.md) |
| 11 | Scheduling | [11-scheduling.md](11-scheduling.md) |
| 12 | Online classroom (LMS) | [12-classroom-lms.md](12-classroom-lms.md) |
| 13 | Messaging | [13-messaging.md](13-messaging.md) |
| 14 | Student wellness | [14-wellness.md](14-wellness.md) |
| 15 | Reports & analytics | [15-reports.md](15-reports.md) |
| 16 | REST API | [16-api.md](16-api.md) |
| 17 | UI assets & shared layout | [17-ui-assets.md](17-ui-assets.md) |

---

## User Roles

| Role | Scope | Primary responsibilities |
|------|-------|------------------------|
| `super_admin` | Institution | Admin accounts, faculty inventory, teaching-load reports |
| `admin` | Institution | Colleges, deans, GEN ED account, conflict requests, global reports |
| `dean` | One college | Programs, chairs, faculty, rooms, courses, scheduling, auto-schedule |
| `program_chair` | One program (within college) | Faculty, courses, schedules, students, registrations |
| `gened` | Institution (GE only) | GE courses, faculty, rooms, schedules, assignments |
| `faculty` | Assigned classes | Classrooms, attendance, assessments, teaching load |
| `student` | Enrolled classes | Join classrooms, EduTools, wellness check-in |

---

## Directory Layout

```
CLASS/
├── api/                    # JSON REST endpoints
├── assets/css/             # Application stylesheets
├── assets/js/              # Client-side scripts
├── config/                 # config.php (from config.example.php)
├── docs/                   # This documentation set
├── includes/               # Shared PHP libraries & layout partials
├── install/                # schema.sql
├── uploads/                # User-uploaded files (attachments, photos)
├── *.php                   # Role-specific page controllers
├── install.php             # Database installer
└── upgrade_roles.php       # Schema migrations & role upgrades
```

---

## Request Lifecycle

1. **Bootstrap** — Page includes `includes/auth.php` (starts session) and often `includes/functions.php`.
2. **Authorization** — `require_login()` or `require_role([...])` gates access.
3. **Business logic** — Inline in page file or delegated to `includes/*_helpers.php`.
4. **Presentation** — `includes/header.php` + HTML + `includes/footer.php`.
5. **Navigation** — `includes/admin_nav.php` renders role-specific sidebar via `render_role_nav_sections()`.

---

## Database Overview

Core entities: `colleges`, `users`, `programs`, `college_programs`, `faculty`, `courses`, `rooms`, `schedules`.

Supporting: `conflict_logs`, `conflict_requests`, `schedule_change_requests`, `dean_activity_logs`, `admin_activity_logs`.

GE-specific: `ge_course_colleges`, `ge_schedule_targets`, `faculty_specializations`.

LMS: `online_classrooms`, `classroom_students`, `classroom_enrollments`, `classroom_content`, `classroom_assessments`, `classroom_scores`, `classroom_submissions`, `classroom_attendance_*`.

Messaging: `internal_messages`.

Registration: `student_registration_requests`.

Full DDL: `install/schema.sql`.

---

## Maintenance Scripts

| File | Purpose |
|------|---------|
| `install.php` | Initial database creation and default admin/GEN ED accounts |
| `upgrade_roles.php` | Idempotent ALTER TABLE migrations (email, messaging, wellness, etc.) |
| `fix_room_code_scope.php` | Repairs `rooms.room_code_scope` generated column data |

---

*Generated from source analysis of the CLASS codebase.*

---

## Exported documents

All modules combined into single files (with code appendix):

| Format | File |
|--------|------|
| PDF | `WPU_SABLAe_Source_Code_Documentation.pdf` |
| Word | `WPU_SABLAe_Source_Code_Documentation.docx` |
| HTML | `WPU_SABLAe_Source_Code_Documentation.html` |
| Markdown | `SOURCE_CODE_DOCUMENTATION.md` |

Regenerate: `C:\xampp\php\php.exe docs\build_exports.php`


\newpage

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


\newpage

# Module 2: Authentication & Authorization

Session-based login with role-scoped access control.

---

## Source Files

| File | Purpose |
|------|---------|
| `login.php` | Multi-role login UI; student self-registration |
| `login1.php` | Alternate/simplified login page |
| `logout.php` | Session destruction |
| `includes/auth.php` | Session helpers, role checks, ID resolution |
| `profile_photo.php` | Upload/remove user profile photo |
| `includes/profile_photo_helpers.php` | Photo storage under `uploads/profile/` |

---

## Login Flow (`login.php`)

1. If `$_SESSION['user_id']` exists → redirect to `dashboard.php`.
2. User selects role tab: Student, Admin, Program Chair, Dean, Faculty.
3. **Login POST:** Validates username/password against `users` where `is_active = 1`.
4. On success, populates session:
   - `user_id`, `username`, `full_name`, `role`
   - `college_id`, `assigned_program`, `admin_log_title` (when applicable)
   - Resolves `faculty_id` / `student_id` via helper functions
   - Updates `last_login_at`, `last_seen_at`
5. **Student registration POST** (students only): Calls `submit_student_registration()` → `student_registration_requests`.

---

## Session Keys

| Key | Set when |
|-----|----------|
| `user_id` | All authenticated users |
| `username`, `full_name`, `role` | All authenticated users |
| `college_id` | Dean, program chair, college-scoped faculty |
| `assigned_program` | Program chair |
| `admin_log_title` | Admin / super_admin display title |
| `faculty_id` | Faculty user linked to `faculty` row |
| `student_id` | Student linked to `classroom_students` row |

---

## `includes/auth.php` — Functions

### Access control
| Function | Behavior |
|----------|----------|
| `require_login()` | Redirect to `login.php` if no session; touches `last_seen_at` |
| `require_role(array $roles)` | `require_login()` + 403 if role not in list |
| `current_user()` | Returns session user array or `null` |

### Role predicates
| Function | True when `role` is |
|----------|---------------------|
| `is_super_admin()` | `super_admin` |
| `is_admin()` | `admin` |
| `is_dean()` | `dean` |
| `is_program_chair()` | `program_chair` |
| `is_faculty()` | `faculty` |
| `is_gened()` | `gened` |
| `is_student()` | `student` |

### Scope helpers
| Function | Description |
|----------|-------------|
| `current_college_id()` | Session college ID or null |
| `current_program_scope()` | Program chair's `assigned_program` |
| `dean_college_id_or_fail()` | Requires dean + college; exits 403 otherwise |
| `dean_or_program_chair_college_id_or_fail()` | Dean or chair with college |
| `program_scope_or_fail()` | Program chair with assigned program |
| `college_name_by_id($id)` | Lookup from `colleges` |

### Identity resolution
| Function | Description |
|----------|-------------|
| `resolve_faculty_id_for_user($userId)` | Links `users` ↔ `faculty` by `user_id` or name match |
| `resolve_student_id_for_user($userId)` | Links `users` ↔ `classroom_students` |
| `faculty_college_id($facultyId)` | Home college for a faculty row |

### Activity
| Function | Description |
|----------|-------------|
| `auth_touch_user_presence($userId)` | Updates `users.last_seen_at` |
| `auth_user_activity_columns_ready()` | Checks migration for activity columns |
| `log_dean_activity($actionType, $details)` | Inserts `dean_activity_logs` |
| `verify_admin_password($password)` | Re-auth for sensitive admin actions |

---

## Profile Photos

**`includes/profile_photo_helpers.php`**

| Function | Description |
|----------|-------------|
| `profile_photo_store($userId, $file)` | Validates image, saves to `uploads/profile/` |
| `profile_photo_url($userId)` | Public URL for `<img>` |
| `profile_photo_remove($userId)` | Deletes file and clears DB column |

Requires `users.profile_photo` column (added by `upgrade_roles.php`).

---

## Security Notes

- Passwords stored with `password_hash()` / verified with `password_verify()`.
- Role checks happen server-side on every protected page via `require_role()`.
- No JWT; classic PHP server sessions (`SESSION_NAME` cookie).
- Admin password re-verification available via `verify_admin_password()` for destructive operations.


\newpage

# Module 3: Installation & Configuration

Database setup, schema migrations, and environment configuration.

---

## Source Files

| File | Purpose |
|------|---------|
| `install.php` | Browser-based one-time installer |
| `install/schema.sql` | Full database DDL |
| `upgrade_roles.php` | Incremental schema migrations |
| `fix_room_code_scope.php` | Data repair for room scope column |
| `config/config.example.php` | Template configuration |
| `config/config.php` | Live config (gitignored; copy from example) |

---

## Installation (`install.php`)

**Trigger:** POST or `?run=1`

**Steps:**
1. Connect to MySQL without database selected.
2. `CREATE DATABASE IF NOT EXISTS` with utf8mb4.
3. Execute all statements from `install/schema.sql` (split on `;`).
4. Seed default `admin` user if none exists (`DEFAULT_ADMIN_*` constants).
5. Seed default `gened` user if none exists (`DEFAULT_GENED_*` constants).

**Output:** Success message with default credentials or error details.

---

## Schema (`install/schema.sql`)

Creates tables (InnoDB, utf8mb4):

### Organization
- `colleges` — College codes, names, dean link
- `users` — All login accounts (7 roles)
- `programs` / `college_programs` — Program catalog per college
- `programs_year_levels` / `program_year_levels` / `program_sections` — Academic structure

### Academic resources
- `faculty` — Instructor records, max hours, GE flag
- `courses` — Course catalog (college or GE)
- `rooms` — Physical rooms with `room_code_scope` generated uniqueness
- `schedules` — Class offerings (faculty + course + room + time)

### Workflow & audit
- `conflict_logs`, `conflict_requests`
- `schedule_change_requests`
- `dean_activity_logs`, `admin_activity_logs`

### GE extensions
- `ge_course_colleges`, `ge_schedule_targets`
- `faculty_specializations`

### LMS & messaging
- `online_classrooms`, `classroom_students`, `classroom_enrollments`
- `classroom_content`, `classroom_content_attachments`
- `classroom_messages`, `classroom_assessments`, `classroom_scores`, `classroom_submissions`
- `classroom_attendance_sessions`, `classroom_attendance_records`
- `internal_messages`
- `student_registration_requests`

---

## Migrations (`upgrade_roles.php`)

Idempotent migration runner using `exec_safe()` — ignores duplicate column/index errors.

**Typical additions (run after initial install):**
- `users.email`, `last_login_at`, `last_seen_at`, `last_logout_at`, `profile_photo`
- `users.role` enum extensions (`super_admin`, `gened`, `student`)
- Messaging tables and memo columns
- Classroom / assessment / attendance tables
- Wellness-related columns
- Admin activity log schema
- Student registration workflow

**Usage:** Open in browser and POST to apply pending migrations.

---

## Configuration (`config/config.example.php`)

| Section | Key settings |
|---------|--------------|
| Database | `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` |
| Bootstrap accounts | `DEFAULT_ADMIN_*`, `DEFAULT_SUPER_ADMIN_*`, `DEFAULT_GENED_*` |
| GE messaging | `GE_DEAN_USER_ID`, `GE_DEAN_NAME_HINT` |
| Email | `MAIL_ENABLED`, `MAIL_SMTP_*`, `MAIL_LOGIN_URL`, `APP_BASE_URL` |
| Scheduling rules | `TIME_MIN`, `TIME_MAX`, `MIN_GAP_MINUTES`, `MAX_CONSECUTIVE_HOURS` |
| Wellness AI | `WELLNESS_AI_ENABLED`, `WELLNESS_AI_API_KEY`, `WELLNESS_AI_MODEL` |

**Setup:**
```text
cp config/config.example.php config/config.php
# Edit DB credentials and URLs
# Open install.php in browser
# Run upgrade_roles.php for latest schema
```

---

## Room Scope Repair (`fix_room_code_scope.php`)

Maintenance utility for `rooms.room_code_scope` — a generated column ensuring unique room codes per college or GE scope (`G|code` vs `C|college_id|code`).

---

## Web Server

- `web.config` — IIS rewrite rules (if deployed on Windows IIS).
- `uploads/web.config` — Restricts script execution in upload directory.


\newpage

# Module 4: Super Admin

Highest privilege tier for institution-wide administration beyond standard `admin`.

**Role:** `super_admin`

---

## Source Files

| File | Access | Purpose |
|------|--------|---------|
| `super_admin_admins.php` | `super_admin` | Create/manage `admin` user accounts |
| `super_admin_faculty_inventory.php` | `super_admin` | Cross-college faculty roster view |
| `super_admin_teaching_load_report.php` | `super_admin` | Institution-wide teaching load in units |
| `settings.php` | All roles (incl. super_admin) | Profile, password, photo |
| `dashboard.php` | All roles | Super-admin-specific dashboard stats block |

---

## Navigation (`includes/admin_nav.php`)

`render_super_admin_nav_sections()` provides:

| Nav item | File |
|----------|------|
| Dashboard | `dashboard.php` |
| Manage admins | `super_admin_admins.php` |
| Faculty inventory | `super_admin_faculty_inventory.php` |
| Teaching load report | `super_admin_teaching_load_report.php` |
| Messages | `messages.php` |
| Settings | `settings.php` |

---

## `super_admin_admins.php`

- Lists all users with `role = 'admin'`.
- CRUD: username, full name, email, active flag, optional password reset.
- Can generate temporary password and email via `send_account_credentials_mail()`.
- Activity logged via `log_admin_activity()`.

---

## `super_admin_faculty_inventory.php`

- Read-only (or manage) view of `faculty` across all colleges.
- Filters by college, department, employment status.
- Links faculty rows to linked `users` accounts.

---

## `super_admin_teaching_load_report.php`

- Aggregates scheduled units per faculty from `schedules` + `courses`.
- Filters: college, semester, school year.
- Export-friendly tabular report.

---

## Dashboard Metrics (super_admin section in `dashboard.php`)

When `role === 'super_admin'`, additional stats include:

- Total schedules (all time)
- Colleges / departments count
- Faculty members, student enrollments
- Active conflict alerts, resolved conflicts (30 days)
- Room double-booking risks, faculty time overlaps
- Scheduled classes current term

Auto-refreshes every 30 seconds.

---

## Related Tables

| Table | Usage |
|-------|-------|
| `users` | Admin account records (`role = 'admin'`) |
| `faculty` | Inventory source |
| `schedules`, `courses` | Teaching load calculation |
| `conflict_logs`, `conflict_requests` | Conflict metrics |
| `admin_activity_logs` | Audit trail |

---

## Bootstrap

Default super admin credentials from config (created manually or via install extension):

- `DEFAULT_SUPER_ADMIN_USERNAME` / `DEFAULT_SUPER_ADMIN_PASSWORD`

Change password immediately after first login.


\newpage

# Module 5: Admin (Institution)

College-level and institution-wide management for standard administrators.

**Role:** `admin`

---

## Source Files

| File | Purpose |
|------|---------|
| `admin_colleges.php` | CRUD colleges (code, name, status) |
| `admin_deans.php` | Create/manage dean accounts per college |
| `admin_gened.php` | Manage the single GEN ED coordinator account |
| `admin_requests.php` | Review/approve `conflict_requests` |
| `global_reports.php` | Cross-college analytics and exports |
| `faculty_teaching_load.php` | Teaching load report (admin sees all colleges) |
| `classroom_materials_monitor.php` | Monitor classroom content uploads |
| `dashboard.php` | Admin dashboard statistics |
| `settings.php` | Admin profile settings |

---

## Shared Includes

| File | Purpose |
|------|---------|
| `includes/admin_nav.php` | `render_admin_nav_sections()` sidebar |
| `includes/admin_offcanvas.php` | Mobile off-canvas navigation |
| `includes/admin_activity_log.php` | Audit logging for admin actions |
| `includes/mail_helpers.php` | Credential emails for deans/GEN ED |

---

## `admin_colleges.php`

- Lists `colleges` with code, name, status (`active`/`inactive`).
- Add/edit college records.
- Cannot delete colleges with dependent data (FK constraints).
- Logs changes via `log_admin_activity()`.

---

## `admin_deans.php`

- Creates dean `users` rows linked to `colleges.dean_user_id`.
- Fields: username, full name, email, password (manual or generated).
- Email temporary credentials with `send_dean_credentials_mail()`.
- Sets `college_id` on user and updates college dean reference.

---

## `admin_gened.php`

- Manages the single institution-wide GEN ED account (`role = 'gened'`).
- Update username, full name, email, active status.
- Password reset or generate-and-email temporary password.
- Requires `upgrade_roles.php` for `users.email` column.

---

## `admin_requests.php`

- Lists pending `conflict_requests` from deans/chairs/faculty.
- Approve → creates schedule via conflict override path.
- Reject → sets status and `admin_remarks`.
- Uses `verify_admin_password()` for sensitive approvals.

---

## `global_reports.php`

- Institution-wide reporting beyond single-college dean scope.
- Exports and summary charts for schedules, faculty, courses.
- Access: `require_role(['admin'])`.

---

## Activity Logging (`includes/admin_activity_log.php`)

| Function | Description |
|----------|-------------|
| `log_admin_activity($action, $module, $ref, $before, $after)` | Primary audit entry point |
| `log_user_activity(...)` | Generic user activity |
| `admin_activity_log_list_sorted($order)` | Retrieve logs for display |
| `admin_activity_log_collect_diff_lines($before, $after)` | Human-readable change diff |

Stored in `admin_activity_logs` with JSON `details_json` snapshots.

---

## Navigation (`render_admin_nav_sections`)

| Section | Items |
|---------|-------|
| Overview | Dashboard, Messages |
| Institution | Colleges, Deans, GEN ED account |
| Scheduling | Schedules, Weekly view, Conflicts, Admin requests |
| Reports | Faculty teaching load, Global reports |
| Monitoring | Classroom materials monitor |
| Account | Settings |

---

## Related Tables

`colleges`, `users`, `conflict_requests`, `schedules`, `admin_activity_logs`, `online_classrooms`, `classroom_content`


\newpage

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


\newpage

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


\newpage

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
| `faculty_student_list.php` | Courses with registered students and present/absent totals |
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

## `faculty_student_list.php`

- Lists the faculty member’s online classrooms (courses) with registered student counts.
- Present/absent totals come from `classroom_attendance_records` across class sessions.
- Detail view (`?id={classroom_id}`) shows each enrolled student and their present/absent mark counts.

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

### Makeup classes

Faculty can also request a **makeup class** from **My schedule** (day, time, room, reason).  
On approval, dean/chair automatically gets a temporary `schedules` row with `is_makeup = 1`.  
Delete that makeup row after the class is held.

---

## Navigation (faculty in `role_nav_sections`)

| Section | Items |
|---------|-------|
| Overview | Dashboard, Messages, Schedules, Weekly view |
| Academic | Classrooms, Student list, My schedule, EduTools |
| Management | Reports, Teaching load, Conflicts |
| Account | Settings |

---

## Related Tables

`faculty`, `schedules`, `online_classrooms`, `classroom_*`, `schedule_change_requests`, `internal_messages`


\newpage

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


\newpage

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


\newpage

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


\newpage

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


\newpage

# Module 13: Messaging

Internal messaging between users with role-based permission rules.

---

## Source Files

| File | Purpose |
|------|---------|
| `messages.php` | Main messaging UI (inbox, compose, thread view) |
| `message_attachment.php` | Download message attachments |
| `includes/messaging_helpers.php` | All messaging business logic |

**Roles with access:** admin, dean, program_chair, gened, faculty, student

---

## Data Model: `internal_messages`

| Column | Description |
|--------|-------------|
| `sender_user_id` | Author |
| `recipient_user_id` | Recipient |
| `subject` | Thread subject (memos) |
| `body` | Message text |
| `is_memo` | Broadcast memo flag |
| `attachment_*` | Optional file attachment |
| `created_at` | Timestamp |
| `read_at` | Read receipt (null = unread) |

---

## Key Functions (`includes/messaging_helpers.php`)

### Infrastructure
| Function | Description |
|----------|-------------|
| `messaging_table_exists()` | Schema check |
| `messaging_has_memo_columns()` | Memo feature check |
| `messaging_thread_max_messages()` | FIFO limit from config |
| `messaging_enforce_thread_fifo()` | Delete oldest when over limit |

### Permissions
| Function | Description |
|----------|-------------|
| `messaging_can_open_thread($viewerId, $otherId)` | Can view conversation |
| `messaging_can_send($senderId, $recipientId)` | Can send message |
| `messaging_allowed_recipients($forUserId)` | Dropdown recipient list |
| `messaging_faculty_can_message_student(...)` | Enrollment-based rule |
| `messaging_student_can_message_faculty(...)` | Reverse enrollment rule |
| `messaging_is_gened_faculty_user()` | GE faculty detection |
| `messaging_ge_dean_user_id()` | GE dean for GE faculty |

### Operations
| Function | Description |
|----------|-------------|
| `messaging_send($fromId, $toId, $body, $options)` | Send direct message |
| `messaging_send_memo($fromId, $recipientIds, ...)` | Broadcast memo |
| `messaging_thread($userId, $otherId)` | Fetch conversation |
| `messaging_conversation_list($userId)` | Inbox with previews |
| `messaging_unread_count($userId)` | Badge count for nav |
| `messaging_mark_read($viewerId, $otherId)` | Mark thread read |
| `messaging_delete_message($messageId, $userId)` | Delete own message |

### Attachments
| Function | Description |
|----------|-------------|
| `messaging_store_attachment($file)` | Save to uploads |
| `messaging_attachment_path($storedName)` | Filesystem path |
| `messaging_delete_attachment_if_unused()` | Cleanup orphaned files |

---

## Permission Matrix (Summary)

| Sender → Recipient | Allowed when |
|--------------------|--------------|
| Faculty → Student | Student enrolled in faculty's classroom |
| Student → Faculty | Same enrollment relationship |
| Dean → Program chairs | Same college |
| Program chair → Dean | Same college |
| GE faculty → GE dean | Configured GE dean only |
| Admin → Various | Broad access per `messaging_allowed_recipients()` |
| Memo broadcast | Dean/chair/admin to scoped recipients |

---

## FIFO Thread Limit

`MESSAGING_THREAD_MAX_MESSAGES` (default 10):
- When exceeded, oldest messages in a thread are deleted.
- `messaging_enforce_thread_fifo()` runs after each send.

---

## UI (`messages.php`)

Features:
- Conversation list with unread badges
- Thread view with role badges (`messaging_role_badge_class()`)
- Compose to allowed recipients
- Memo mode for broadcast messages
- File attachment upload
- Nav badge via `messaging_unread_count()` in `admin_nav.php`

---

## Attachment Download

`message_attachment.php`:
- Verifies sender or recipient access.
- Streams attachment with original filename.

---

## Config

| Constant | Purpose |
|----------|---------|
| `MESSAGING_THREAD_MAX_MESSAGES` | Per-thread message cap |
| `GE_DEAN_USER_ID` | GE dean user for GE faculty messaging |
| `GE_DEAN_NAME_HINT` | Fallback dean name matching |


\newpage

# Module 14: Student Wellness

Non-diagnostic wellness companion with crisis escalation (Philippines resources).

---

## Source Files

| File | Purpose |
|------|---------|
| `student_wellness.php` | Wellness check-in page (student UI) |
| `student_edutools.php` | Entry point from EduTools |
| `api/student_wellness_chat.php` | REST chat endpoint |
| `api/wellness_chatbot.openapi.yaml` | OpenAPI specification |
| `includes/student_wellness_ui.php` | HTML/JS chat widget |
| `includes/wellness_chatbot.php` | Classification, crisis detection, orchestration |
| `includes/wellness_engine.php` | Template-based empathetic replies |
| `includes/wellness_ai.php` | Groq/OpenAI/Gemini/Ollama integration |

---

## Architecture

```
student_wellness.php (UI)
    └── POST → api/student_wellness_chat.php
            ├── wellness_chatbot.php (crisis check, classify)
            ├── wellness_ai.php (optional LLM enhancement)
            └── wellness_engine.php (fallback templates)
```

---

## API (`api/student_wellness_chat.php`)

| Method | Auth | Description |
|--------|------|-------------|
| GET | Public | Service descriptor / health |
| POST | Student session | Chat completion |

**POST body:**
```json
{
  "message": "user text",
  "lang": "en|tl|optional",
  "history": [{"role":"user|assistant","content":"..."}]
}
```

**Response:**
```json
{
  "reply": "assistant text",
  "crisis": false,
  "lang": "en",
  "disclaimer": "...",
  "provider": "builtin|groq|ollama"
}
```

---

## Crisis Detection (`wellness_chatbot.php`)

| Function | Purpose |
|----------|---------|
| `wellness_is_crisis_message($norm)` | Detects self-harm/suicide keywords |
| `wellness_crisis_response($lang)` | PH hotline resources (NCMH, Hopeline, etc.) |
| `wellness_ph_crises_resources_public()` | Public crisis resource list |

Crisis messages bypass normal chat — immediate escalation response with hotlines.

---

## Message Classification

| Function | Purpose |
|----------|---------|
| `wellness_normalize_for_match($text)` | Lowercase, strip punctuation |
| `wellness_classify($norm)` | Topic + scenario scores |
| `wellness_score_scenarios($norm)` | stress, anxiety, sleep, academics, etc. |
| `wellness_chat_orchestrate($message, $langHint, $history)` | Main entry point |

---

## Template Engine (`wellness_engine.php`)

Fallback when AI is disabled:

| Function | Purpose |
|----------|---------|
| `wellness_parse_student_message()` | Extract intent/context |
| `wellness_empathy_opening()` | Empathetic opener |
| `wellness_core_steps()` | Actionable coping steps |
| `wellness_soft_close()` | Closing encouragement |
| `wellness_engine_reply()` | Compose full reply |

Supports English and Tagalog (`tl`).

---

## AI Provider (`wellness_ai.php`)

| Function | Purpose |
|----------|---------|
| `wellness_ai_is_enabled()` | Checks config |
| `wellness_ai_provider()` | groq, ollama, openai, gemini, auto |
| `wellness_ai_chat_completion(...)` | LLM API call |

**Providers:**
- **Groq** (default cloud) — `WELLNESS_AI_API_KEY` starting with `gsk_`
- **Ollama** (local) — `WELLNESS_OLLAMA_URL`, `WELLNESS_OLLAMA_MODEL`
- **builtin** — Template engine only

---

## Configuration

| Constant | Default | Purpose |
|----------|---------|---------|
| `WELLNESS_AI_ENABLED` | false | Master switch |
| `WELLNESS_AI_PROVIDER` | groq | Provider selection |
| `WELLNESS_AI_API_KEY` | env `GROQ_API_KEY` | API key |
| `WELLNESS_AI_MODEL` | llama-3.3-70b-versatile | Model name |
| `WELLNESS_OLLAMA_URL` | http://127.0.0.1:11434 | Local LLM |

---

## Disclaimers

All responses include non-diagnostic disclaimers:
- `wellness_disclaimer_banner($lang)`
- `wellness_footer_disclaimer($lang)`

Not a substitute for professional mental health care.

---

## OpenAPI

`api/wellness_chatbot.openapi.yaml` documents the REST contract for external integration or testing.

---

## Access Control

- Page: `require_role(['student'])`
- API POST: Validates `$_SESSION['role'] === 'student'`


\newpage

# Module 15: Reports & Analytics

Reporting and monitoring across roles.

---

## Source Files

| File | Roles | Purpose |
|------|-------|---------|
| `reports.php` | admin, dean, chair, gened, faculty | Role-scoped reports hub |
| `global_reports.php` | admin | Institution-wide analytics |
| `faculty_teaching_load.php` | admin, dean, chair, gened | Teaching load in units |
| `super_admin_teaching_load_report.php` | super_admin | Cross-college load report |
| `classroom_materials_monitor.php` | admin, dean, chair, gened | Upload activity monitor |
| `dashboard.php` | all roles | Summary statistics widgets |
| `dashboard1.php` | — | Alternate dashboard layout |

---

## `reports.php`

General reports page scoped by role:

| Role | Scope |
|------|-------|
| admin | All colleges |
| dean | Own college |
| program_chair | Own program |
| gened | GE offerings |
| faculty | Own classes |

Typical report types:
- Schedule summaries by term
- Faculty assignment counts
- Course offering statistics
- Export to printable views (`assets/css/print.css`)

---

## `global_reports.php`

Admin-only cross-college analytics:
- Aggregate schedule counts
- College comparison charts
- Term-over-term summaries
- Institution KPIs

---

## Teaching Load Reports

### `faculty_teaching_load.php`

Calculates load from `schedules` JOIN `courses`:

```
load_units = SUM(courses.units) per faculty per term
```

Filters:
- College (admin sees all; dean/chair scoped)
- Semester, school year
- Department/program
- GE mode for gened role

### `super_admin_teaching_load_report.php`

Super admin variant with institution-wide filters and export.

---

## `classroom_materials_monitor.php`

Monitors `classroom_content` and attachments:
- Recent uploads across classrooms
- Filter by college, faculty, date range
- Oversight for academic integrity / policy compliance

---

## Dashboard Statistics (`dashboard.php`)

Role-specific stat cards:

### Admin
- Active faculty (college-assigned)
- Total courses, schedules
- Open conflict requests

### Dean / Program Chair
- Scoped faculty, courses, schedules
- Pending schedule change requests
- Pending student registrations (chair)

### Faculty
- Today's classes
- Classroom count
- Upcoming assessments

### Student
- Enrolled classrooms
- Pending assessments

### Super Admin
- Extended KPIs (see [04-super-admin.md](#))

Auto-refresh: 30 seconds for admin dashboard.

---

## Print Styles

`assets/css/print.css` — Optimized layouts for printing schedules and reports from browser.

---

## Activity Logs (Reporting Sources)

| Table | Used for |
|-------|----------|
| `admin_activity_logs` | Admin action audit |
| `dean_activity_logs` | Dean/chair action history |
| `conflict_logs` | Scheduling conflict history |

Admin activity log viewer integrated in admin settings/offcanvas.

---

## Related Helpers

| Function | Module | Purpose |
|----------|--------|---------|
| `sql_scope_for_college()` | functions.php | College SQL filter |
| `dean_schedule_scope_sql()` | functions.php | Dean + GE schedule scope |
| `admin_activity_log_list_sorted()` | admin_activity_log.php | Audit log retrieval |


\newpage

# Module 16: REST API

JSON endpoints for AJAX and external clients.

---

## Source Files

| File | Method | Auth | Purpose |
|------|--------|------|---------|
| `api/student_wellness_chat.php` | GET, POST | Student (POST) | Wellness chat companion |
| `api/programs_by_college.php` | GET | Public | Programs/year-levels for registration |
| `api/wellness_chatbot.openapi.yaml` | — | — | OpenAPI 3 spec for wellness API |

---

## `api/programs_by_college.php`

**Purpose:** Powers dynamic dropdowns on student registration form.

**Request:**
```
GET /api/programs_by_college.php?college_id=1
```

**Response:**
```json
{
  "programs": [
    {"id": 1, "program_name": "BSIT", "status": "active"}
  ],
  "year_levels_by_program": {
    "BSIT": ["1st Year", "2nd Year", "3rd Year", "4th Year"]
  }
}
```

**Implementation:**
- Includes `student_registration_helpers.php`
- Calls `active_programs_for_college()` and `active_year_levels_by_program_for_college()`
- No session required (public for registration UX)

---

## `api/student_wellness_chat.php`

See [14-wellness.md](14-wellness.md) for full documentation.

**Summary:**

| Endpoint | Description |
|----------|-------------|
| `GET` | Returns API metadata, disclaimer, crisis resources |
| `POST` | Processes chat message, returns AI/template reply |

**Headers:**
- `Content-Type: application/json`
- `X-Content-Type-Options: nosniff`

**Session:** Uses `SESSION_NAME` cookie; POST requires `role = student`.

**Error responses:**
- 401 — Not authenticated as student
- 400 — Invalid JSON or empty message
- 500 — Internal error

---

## OpenAPI Spec

`api/wellness_chatbot.openapi.yaml` defines:

- `/api/student_wellness_chat` paths
- Request/response schemas
- Crisis response format
- Example payloads

Use with Swagger UI or code generators for client SDKs.

---

## API Design Patterns

1. **Bootstrap:** Each endpoint includes `config/config.php` directly (not full page auth stack).
2. **JSON only:** All responses `Content-Type: application/json; charset=utf-8`.
3. **Session auth:** Wellness API uses PHP sessions, not API keys.
4. **Error handling:** `wellness_api_emit_json($code, $body)` helper with `JSON_THROW_ON_ERROR`.

---

## Future Extension Points

The `api/` directory is the designated location for new JSON endpoints. Follow existing patterns:
- `declare(strict_types=1)`
- Direct config include
- Explicit auth check before business logic
- JSON encode with `JSON_UNESCAPED_UNICODE`


\newpage

# Module 17: UI Assets & Shared Layout

Frontend resources and shared presentation layer.

---

## Source Files

### Stylesheets (`assets/css/`)

| File | Purpose |
|------|---------|
| `style.css` | Main application theme, layout, components |
| `print.css` | Print-optimized styles for schedules/reports |

### JavaScript (`assets/js/`)

| File | Purpose |
|------|---------|
| `app_nav.js` | Sidebar navigation behavior, active states |
| `app_tooltips.js` | Bootstrap tooltip initialization |
| `theme-toggle.js` | Light/dark theme switching |

---

## Layout Partials (`includes/`)

| File | Purpose |
|------|---------|
| `header.php` | `<!DOCTYPE html>`, meta, CSS links, opens `<body>`, top bar |
| `footer.php` | Closes containers, loads JS, `</body></html>` |
| `admin_nav.php` | Role-based sidebar navigation |
| `admin_offcanvas.php` | Mobile off-canvas menu wrapper |
| `student_tooltip.php` | `app_tooltip_attr()` for accessible tooltips |

---

## `includes/header.php`

Responsibilities:
- Sets `$pageTitle` in browser tab
- Loads Bootstrap 5, Font Awesome icons
- Loads `assets/css/style.css`
- Renders top navigation bar with user avatar
- Opens main content container
- Includes role navigation via `render_role_nav_sections()`

---

## `includes/admin_nav.php`

Navigation rendering functions:

| Function | Role |
|----------|------|
| `render_super_admin_nav_sections()` | super_admin sidebar |
| `render_admin_nav_sections()` | admin sidebar |
| `role_nav_sections($role, $unread)` | Returns nav structure array |
| `render_role_nav_sections()` | Dispatches to correct renderer |
| `render_nav_sections_markup()` | HTML output with active highlighting |
| `app_sidebar_brand_meta($role)` | Brand subtitle per role |
| `app_render_topbar_avatar()` | Profile photo in top bar |

Nav items include:
- `file` — PHP filename for active state matching
- `href` — Link target
- `icon` — Font Awesome class
- `label` — Display text
- `badge` — Optional unread count (messages)
- `tooltip` — Help text via `app_tooltip_attr()`

---

## `includes/admin_offcanvas.php`

Mobile-responsive off-canvas sidebar:
- Bootstrap offcanvas component
- Duplicates nav for small screens
- `$dismissOffcanvas` flag closes menu on link click

---

## Tooltips (`includes/student_tooltip.php`)

| Function | Description |
|----------|-------------|
| `app_cursor_tooltips_active()` | Feature flag check |
| `app_tooltip_attr($text)` | Returns `data-bs-toggle="tooltip"` attributes |
| `student_tooltip_attr($text)` | Alias for student-facing pages |

Initialized by `app_tooltips.js` on DOM ready.

---

## Theme Toggle (`assets/js/theme-toggle.js`)

- Persists preference in `localStorage`
- Toggles `data-bs-theme` on `<html>`
- Respects system preference on first visit

---

## Navigation JS (`assets/js/app_nav.js`)

- Highlights active nav item based on current page filename
- Collapsible sidebar sections
- Mobile menu interactions

---

## Common UI Patterns

### Flash messages
```php
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
// ...
<?php if ($flash): ?><div class="alert alert-info"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
```

### Cards
Bootstrap `card shadow-sm` wrappers for forms and data tables.

### Forms
Bootstrap 5 grid (`row g-3`, `col-md-*`), `form-control`, `form-check`.

### Icons
Font Awesome 6 solid (`fa-solid fa-*`) throughout navigation and headings.

### Page titles
```php
$pageTitle = 'Page Name';
require_once __DIR__ . '/includes/header.php';
?>
<h1 class="h3 mb-4"><i class="fa-solid fa-icon me-2 text-primary"></i>Title</h1>
```

---

## Upload Directories

| Path | Contents |
|------|----------|
| `uploads/profile/` | User profile photos |
| `uploads/classroom/` | Classroom content attachments |
| `uploads/messages/` | Message attachments |
| `uploads/syllabus/` | Classroom syllabi |

Protected by `uploads/web.config` (no script execution).

---

## External Dependencies (CDN)

Loaded in `header.php`:
- Bootstrap 5 CSS/JS
- Font Awesome 6
- No npm/build step — plain static assets

---

## `settings.php`

Shared profile page for all roles:
- Update full name, email (if column exists)
- Change password
- Upload profile photo via `profile_photo.php`
- Role-appropriate redirect after save

---

## `profile_photo.php`

POST endpoint for avatar upload/remove:
- Uses `profile_photo_store()` / `profile_photo_remove()`
- Returns to settings with flash message

\newpage

# Appendix A: Key Source Code Excerpts

Representative source files referenced throughout this documentation.

## `index.php` — Application entry point

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit;
```

## `includes/db.php` — Database connection

```php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            // ... XAMPP-friendly connection error ...
            throw $e;
        }
    }
    return $pdo;
}
```

## `includes/auth.php` — Role gate

```php
function require_role(array $roles): void
{
    require_login();
    if (!in_array((string) ($_SESSION['role'] ?? ''), $roles, true)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}
```

## `admin_gened.php` — Admin page pattern

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mail_helpers.php';
require_once __DIR__ . '/includes/admin_activity_log.php';

require_role(['admin']);
// POST handler → flash message → redirect
// GET → load data → header.php → HTML form → footer.php
```

## `api/programs_by_college.php` — JSON API pattern

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/student_registration_helpers.php';

header('Content-Type: application/json; charset=utf-8');

$collegeId = (int) ($_GET['college_id'] ?? 0);
$programs = active_programs_for_college($collegeId);
$yearLevelsByProgram = active_year_levels_by_program_for_college($collegeId);

echo json_encode([
    'programs' => $programs,
    'year_levels_by_program' => $yearLevelsByProgram,
], JSON_UNESCAPED_UNICODE);
```

## `install/schema.sql` — Core tables (excerpt)

```sql
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  full_name VARCHAR(100) NOT NULL,
  role ENUM('super_admin','admin','dean','program_chair','faculty','gened','student') NOT NULL,
  college_id INT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  ...
);

CREATE TABLE IF NOT EXISTS schedules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  faculty_id INT NOT NULL,
  course_id INT NOT NULL,
  room_id INT NOT NULL,
  day_of_week SET('Monday','Tuesday',...) NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  semester VARCHAR(20) NOT NULL,
  school_year VARCHAR(20) NOT NULL,
  ...
);
```

---

*Document generated: 2026-07-27 14:58:30 CEST*

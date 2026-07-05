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

# WPU SABLAe Portal — Source Code Documentation

**Project:** `lms_scheduling` (Faculty Scheduling & Classroom LMS)  
**Stack:** PHP 8+ (strict types), MySQL/MariaDB, Bootstrap 5, PDO  
**Entry point:** `index.php` → `login.php` or `dashboard.php`

This documentation describes all application source code organized by functional module. Each module page lists source files, database tables, roles, and key behaviors.

---

## Table of Contents

| # | Module | Document |
|---|--------|----------|
| 1 | Core infrastructure | [01-core-infrastructure.md](01-core-infrastructure.md) |
| 2 | Authentication & authorization | [02-authentication.md](02-authentication.md) |
| 3 | Installation & configuration | [03-installation.md](03-installation.md) |
| 4 | Super Admin | [04-super-admin.md](04-super-admin.md) |
| 5 | Admin (institution) | [05-admin.md](05-admin.md) |
| 6 | Dean (college) | [06-dean.md](06-dean.md) |
| 7 | Program Chair | [07-program-chair.md](07-program-chair.md) |
| 8 | Faculty | [08-faculty.md](08-faculty.md) |
| 9 | General Education (GEN ED) | [09-gened.md](09-gened.md) |
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

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

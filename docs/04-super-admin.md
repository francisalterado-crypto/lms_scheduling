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

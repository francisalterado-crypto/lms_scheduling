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
- Extended KPIs (see [04-super-admin.md](04-super-admin.md))

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

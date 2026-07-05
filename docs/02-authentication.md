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

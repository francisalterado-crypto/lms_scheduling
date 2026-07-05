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

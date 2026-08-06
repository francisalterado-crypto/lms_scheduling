# lms_scheduling

WPU SABLAe Portal — faculty scheduling, college administration, online classrooms, and student tools.

## Documentation

Full source code documentation organized by module:

**[docs/README.md](docs/README.md)**

| Module | Document |
|--------|----------|
| Core infrastructure | [docs/01-core-infrastructure.md](docs/01-core-infrastructure.md) |
| Authentication | [docs/02-authentication.md](docs/02-authentication.md) |
| Installation | [docs/03-installation.md](docs/03-installation.md) |
| Super Admin | [docs/04-super-admin.md](docs/04-super-admin.md) |
| Admin | [docs/05-admin.md](docs/05-admin.md) |
| Dean | [docs/06-dean.md](docs/06-dean.md) |
| Program Chair | [docs/07-program-chair.md](docs/07-program-chair.md) |
| Faculty | [docs/08-faculty.md](docs/08-faculty.md) |
| GEN ED | [docs/09-gened.md](docs/09-gened.md) |
| Student | [docs/10-student.md](docs/10-student.md) |
| Scheduling | [docs/11-scheduling.md](docs/11-scheduling.md) |
| Classroom LMS | [docs/12-classroom-lms.md](docs/12-classroom-lms.md) |
| Messaging | [docs/13-messaging.md](docs/13-messaging.md) |
| Wellness | [docs/14-wellness.md](docs/14-wellness.md) |
| Reports | [docs/15-reports.md](docs/15-reports.md) |
| API | [docs/16-api.md](docs/16-api.md) |
| UI assets | [docs/17-ui-assets.md](docs/17-ui-assets.md) |
| Deploy over Tailscale | [docs/deploy-tailscale.md](docs/deploy-tailscale.md) |

## Exported documents (single file)

| Format | File |
|--------|------|
| **PDF** | [docs/WPU_SABLAe_Source_Code_Documentation.pdf](docs/WPU_SABLAe_Source_Code_Documentation.pdf) |
| **Word** | [docs/WPU_SABLAe_Source_Code_Documentation.docx](docs/WPU_SABLAe_Source_Code_Documentation.docx) |
| **HTML** | [docs/WPU_SABLAe_Source_Code_Documentation.html](docs/WPU_SABLAe_Source_Code_Documentation.html) |
| **Markdown** | [docs/SOURCE_CODE_DOCUMENTATION.md](docs/SOURCE_CODE_DOCUMENTATION.md) |

Regenerate exports after doc changes:

```text
C:\xampp\php\php.exe docs\build_exports.php
```

## Whole source code (single file)

All application code only (89 files: PHP, SQL, JS, CSS, YAML):

| File | Path |
|------|------|
| Text | [docs/WHOLE_SOURCE_CODE.txt](docs/WHOLE_SOURCE_CODE.txt) (~2 MB) |
| Zip | [docs/WHOLE_SOURCE_CODE.zip](docs/WHOLE_SOURCE_CODE.zip) |

Regenerate:

```text
C:\xampp\php\php.exe docs\build_whole_code.php
```

## Quick start

1. Copy `config/config.example.php` → `config/config.php`
2. Run `install.php` in the browser
3. Run `upgrade_roles.php` for latest schema
4. Log in at `login.php`

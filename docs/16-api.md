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

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

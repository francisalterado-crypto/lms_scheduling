# Module 13: Messaging

Internal messaging between users with role-based permission rules.

---

## Source Files

| File | Purpose |
|------|---------|
| `messages.php` | Main messaging UI (inbox, compose, thread view) |
| `message_attachment.php` | Download message attachments |
| `includes/messaging_helpers.php` | All messaging business logic |

**Roles with access:** admin, dean, program_chair, gened, faculty, student

---

## Data Model: `internal_messages`

| Column | Description |
|--------|-------------|
| `sender_user_id` | Author |
| `recipient_user_id` | Recipient |
| `subject` | Thread subject (memos) |
| `body` | Message text |
| `is_memo` | Broadcast memo flag |
| `attachment_*` | Optional file attachment |
| `created_at` | Timestamp |
| `read_at` | Read receipt (null = unread) |

---

## Key Functions (`includes/messaging_helpers.php`)

### Infrastructure
| Function | Description |
|----------|-------------|
| `messaging_table_exists()` | Schema check |
| `messaging_has_memo_columns()` | Memo feature check |
| `messaging_thread_max_messages()` | FIFO limit from config |
| `messaging_enforce_thread_fifo()` | Delete oldest when over limit |

### Permissions
| Function | Description |
|----------|-------------|
| `messaging_can_open_thread($viewerId, $otherId)` | Can view conversation |
| `messaging_can_send($senderId, $recipientId)` | Can send message |
| `messaging_allowed_recipients($forUserId)` | Dropdown recipient list |
| `messaging_faculty_can_message_student(...)` | Enrollment-based rule |
| `messaging_student_can_message_faculty(...)` | Reverse enrollment rule |
| `messaging_is_gened_faculty_user()` | GE faculty detection |
| `messaging_ge_dean_user_id()` | GE dean for GE faculty |

### Operations
| Function | Description |
|----------|-------------|
| `messaging_send($fromId, $toId, $body, $options)` | Send direct message |
| `messaging_send_memo($fromId, $recipientIds, ...)` | Broadcast memo |
| `messaging_thread($userId, $otherId)` | Fetch conversation |
| `messaging_conversation_list($userId)` | Inbox with previews |
| `messaging_unread_count($userId)` | Badge count for nav |
| `messaging_mark_read($viewerId, $otherId)` | Mark thread read |
| `messaging_delete_message($messageId, $userId)` | Delete own message |

### Attachments
| Function | Description |
|----------|-------------|
| `messaging_store_attachment($file)` | Save to uploads |
| `messaging_attachment_path($storedName)` | Filesystem path |
| `messaging_delete_attachment_if_unused()` | Cleanup orphaned files |

---

## Permission Matrix (Summary)

| Sender → Recipient | Allowed when |
|--------------------|--------------|
| Faculty → Student | Student enrolled in faculty's classroom |
| Student → Faculty | Same enrollment relationship |
| Dean → Program chairs | Same college |
| Program chair → Dean | Same college |
| GE faculty → GE dean | Configured GE dean only |
| Admin → Various | Broad access per `messaging_allowed_recipients()` |
| Memo broadcast | Dean/chair/admin to scoped recipients |

---

## FIFO Thread Limit

`MESSAGING_THREAD_MAX_MESSAGES` (default 10):
- When exceeded, oldest messages in a thread are deleted.
- `messaging_enforce_thread_fifo()` runs after each send.

---

## UI (`messages.php`)

Features:
- Conversation list with unread badges
- Thread view with role badges (`messaging_role_badge_class()`)
- Compose to allowed recipients
- Memo mode for broadcast messages
- File attachment upload
- Nav badge via `messaging_unread_count()` in `admin_nav.php`

---

## Attachment Download

`message_attachment.php`:
- Verifies sender or recipient access.
- Streams attachment with original filename.

---

## Config

| Constant | Purpose |
|----------|---------|
| `MESSAGING_THREAD_MAX_MESSAGES` | Per-thread message cap |
| `GE_DEAN_USER_ID` | GE dean user for GE faculty messaging |
| `GE_DEAN_NAME_HINT` | Fallback dean name matching |

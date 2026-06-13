<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/messaging_helpers.php';
require_once __DIR__ . '/includes/classroom_discussion_helpers.php';

require_role(['admin', 'dean', 'program_chair', 'gened', 'faculty', 'student']);

$usesClassroomDiscussions = in_array((string) ($_SESSION['role'] ?? ''), ['faculty', 'student'], true);

if (!$usesClassroomDiscussions && !messaging_table_exists()) {
    http_response_code(503);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Messages</title></head><body class="p-4">';
    echo '<p>Messaging is not installed. Run <a href="upgrade_roles.php">upgrade_roles.php</a> once.</p>';
    echo '</body></html>';
    exit;
}

$userId = (int) $_SESSION['user_id'];
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($usesClassroomDiscussions && $action === 'post_classroom_message') {
        $classroomId = (int) ($_POST['classroom_id'] ?? 0);
        $body = (string) ($_POST['body'] ?? '');
        try {
            classroom_discussion_post($classroomId, $userId, $body);
            $_SESSION['flash'] = 'Message sent to the class conversation.';
            header('Location: messages.php?classroom=' . $classroomId);
            exit;
        } catch (Throwable $e) {
            $_SESSION['flash'] = 'Error: ' . $e->getMessage();
            header('Location: messages.php' . ($classroomId > 0 ? '?classroom=' . $classroomId : ''));
            exit;
        }
    }
    if (!$usesClassroomDiscussions && $action === 'send') {
        $toId = (int) ($_POST['recipient_id'] ?? 0);
        $body = (string) ($_POST['body'] ?? '');
        try {
            messaging_send($userId, $toId, $body);
            $_SESSION['flash'] = 'Message sent.';
            header('Location: messages.php?with=' . $toId);
            exit;
        } catch (Throwable $e) {
            $_SESSION['flash'] = 'Error: ' . $e->getMessage();
            header('Location: messages.php' . ($toId > 0 ? '?with=' . $toId : ''));
            exit;
        }
    }
    if (!$usesClassroomDiscussions && $action === 'delete_message') {
        $messageId = (int) ($_POST['message_id'] ?? 0);
        $returnWith = (int) ($_POST['with'] ?? 0);
        try {
            if (!messaging_delete_message($messageId, $userId)) {
                throw new RuntimeException('Message not found or you cannot delete it.');
            }
            $_SESSION['flash'] = 'Message deleted.';
        } catch (Throwable $e) {
            $_SESSION['flash'] = 'Error: ' . $e->getMessage();
        }
        header('Location: messages.php' . ($returnWith > 0 ? '?with=' . $returnWith : ''));
        exit;
    }
    if (!$usesClassroomDiscussions && $action === 'send_memo') {
        $recipientIds = $_POST['recipient_ids'] ?? [];
        $subject = (string) ($_POST['subject'] ?? '');
        $body = (string) ($_POST['body'] ?? '');
        $attachment = isset($_FILES['attachment']) && is_array($_FILES['attachment']) ? $_FILES['attachment'] : null;
        try {
            $sentCount = messaging_send_memo($userId, is_array($recipientIds) ? $recipientIds : [], $subject, $body, $attachment);
            $_SESSION['flash'] = $sentCount === 1 ? 'Memo sent to 1 faculty member.' : 'Memo sent to ' . $sentCount . ' faculty members.';
            $firstRecipient = is_array($recipientIds) && $recipientIds !== [] ? (int) $recipientIds[0] : 0;
            header('Location: messages.php' . ($firstRecipient > 0 ? '?with=' . $firstRecipient : ''));
            exit;
        } catch (Throwable $e) {
            $_SESSION['flash'] = 'Error: ' . $e->getMessage();
            header('Location: messages.php');
            exit;
        }
    }
}

$withId = !$usesClassroomDiscussions ? (int) ($_GET['with'] ?? 0) : 0;
$threadUser = !$usesClassroomDiscussions && $withId > 0 ? messaging_user_row($withId) : null;
$canOpen = !$usesClassroomDiscussions && $withId > 0 && $threadUser && messaging_can_open_thread($userId, $withId);
if ($canOpen) {
    messaging_mark_read($userId, $withId);
}

$classroomId = $usesClassroomDiscussions ? (int) ($_GET['classroom'] ?? 0) : 0;
$classroomThreads = $usesClassroomDiscussions ? classroom_discussion_threads_for_user($userId) : [];
$activeClassroom = $usesClassroomDiscussions && $classroomId > 0 ? classroom_discussion_thread_row($classroomId, $userId) : null;
$classroomMessages = $activeClassroom ? classroom_discussion_messages($classroomId, $userId) : [];
$hasClassroomDiscussionTable = classroom_discussions_table_exists();

$conversations = !$usesClassroomDiscussions ? messaging_conversation_list($userId) : [];
$recipients = !$usesClassroomDiscussions ? messaging_allowed_recipients($userId) : [];
$memoRecipients = !$usesClassroomDiscussions ? messaging_memo_recipients($userId) : [];
$thread = $canOpen ? messaging_thread($userId, $withId) : [];
$canSendTo = !$usesClassroomDiscussions && $withId > 0 && $threadUser && messaging_can_send($userId, $withId);
$canSendMemo = !$usesClassroomDiscussions && in_array((string) ($_SESSION['role'] ?? ''), ['admin', 'dean', 'gened', 'program_chair'], true);
$memoReady = !$usesClassroomDiscussions && messaging_has_memo_columns();

$pageTitle = 'Messages';
require_once __DIR__ . '/includes/header.php';

$invalidWith = $withId > 0 && (!$threadUser || !$canOpen);
$invalidClassroom = $usesClassroomDiscussions && $classroomId > 0 && !$activeClassroom;
?>
<h1 class="h3 mb-3"><i class="fa-solid fa-comments me-2 text-primary"></i>Messages</h1>
<p class="text-muted small mb-3">
    <?php if (is_admin()): ?>
        You can message <strong>deans</strong>, <strong>program chairs</strong>, and <strong>GEN ED</strong> coordinators. You can also send memo announcements with attachments to <strong>faculty</strong>.
    <?php elseif (is_dean()): ?>
        You can message <strong>faculty</strong> in your college, <strong>GEN ED</strong>, and <strong>admin</strong>. Memo announcements can be sent to your college faculty.
    <?php elseif (is_program_chair()): ?>
        You can message your college <strong>dean</strong>, <strong>admin</strong>, and <strong>faculty</strong> in your assigned program. Memo announcements can be sent to your program faculty.
    <?php elseif (is_faculty()): ?>
        Choose a <strong>subject</strong> you handle to message all enrolled students in one conversation.
    <?php elseif (is_gened()): ?>
        You can message <strong>deans</strong> and <strong>admin</strong>. Memo announcements can be sent to <strong>GE faculty</strong>.
    <?php elseif (is_student()): ?>
        Choose one of your enrolled <strong>subjects</strong> to reply in the shared class conversation.
    <?php endif; ?>
</p>
<?php if ($flash): ?><div class="alert alert-info py-2"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
<?php if ($invalidWith): ?>
    <div class="alert alert-warning">You cannot open that conversation.</div>
<?php endif; ?>
<?php if ($invalidClassroom): ?>
    <div class="alert alert-warning">You cannot open that subject conversation.</div>
<?php endif; ?>
<?php if ($usesClassroomDiscussions && !$hasClassroomDiscussionTable): ?>
    <div class="alert alert-warning py-2">Classroom discussion is not installed yet. Run <code>upgrade_roles.php</code> once.</div>
<?php endif; ?>
<?php if ($canSendMemo && !$memoReady): ?>
    <div class="alert alert-warning py-2">Memo attachments are not installed yet. Run <code>upgrade_roles.php</code> once to enable memo fields.</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                <strong><?= $usesClassroomDiscussions ? 'Subjects' : 'Conversations' ?></strong>
            </div>
            <div class="list-group list-group-flush" style="max-height: 70vh; overflow-y: auto;">
                <?php if ($usesClassroomDiscussions): ?>
                    <?php if (!$classroomThreads): ?>
                        <div class="list-group-item text-muted small">No subject conversations available yet.</div>
                    <?php endif; ?>
                    <?php foreach ($classroomThreads as $classThread): ?>
                        <a class="list-group-item list-group-item-action <?= $classroomId === (int) $classThread['classroom_id'] ? 'active' : '' ?>"
                           href="messages.php?classroom=<?= (int) $classThread['classroom_id'] ?>"<?= app_tooltip_attr(is_student() ? 'Opens the shared class conversation for this subject. Use this to read and send messages with your instructor and classmates.' : 'Opens the shared class conversation for this subject. Use this to coordinate with enrolled students and track the thread.') ?>>
                            <div class="fw-semibold">
                                <?= htmlspecialchars((string) $classThread['course_code']) ?>
                                <?php if (trim((string) $classThread['title']) !== ''): ?>
                                    <span class="small fw-normal">· <?= htmlspecialchars((string) $classThread['title']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="small opacity-75"><?= htmlspecialchars((string) $classThread['course_name']) ?></div>
                            <div class="small opacity-75 text-truncate" style="max-width: 240px;">
                                <?= htmlspecialchars($classThread['preview'] !== '' ? $classThread['preview'] : 'Open this subject conversation') ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <?php if (!$conversations): ?>
                        <div class="list-group-item text-muted small">No messages yet. Start below.</div>
                    <?php endif; ?>
                    <?php foreach ($conversations as $c): ?>
                        <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-start <?= $withId === (int) $c['partner_id'] ? 'active' : '' ?>"
                           href="messages.php?with=<?= (int) $c['partner_id'] ?>"<?= app_tooltip_attr(is_student() ? 'Opens your private message thread with this person. Use this to read replies and continue the conversation.' : 'Opens your direct message thread with this colleague. Use this to read replies and continue the conversation.') ?>>
                            <div class="me-2">
                                <div class="fw-semibold"><?= htmlspecialchars($c['full_name']) ?></div>
                                <div class="small opacity-75 text-truncate" style="max-width: 220px;"><?= htmlspecialchars($c['preview']) ?></div>
                            </div>
                            <?php if ($c['unread'] > 0): ?>
                                <span class="badge bg-danger rounded-pill"><?= (int) $c['unread'] ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="card-body border-top bg-body-tertiary">
                <?php if ($usesClassroomDiscussions): ?>
                    <label class="form-label small text-muted mb-1">Open subject conversation</label>
                    <form method="get" class="d-flex gap-2 flex-wrap">
                        <select name="classroom" class="form-select form-select-sm" onchange="if(this.value) window.location='messages.php?classroom='+this.value">
                            <option value="">— Select subject —</option>
                            <?php foreach ($classroomThreads as $classThread): ?>
                                <option value="<?= (int) $classThread['classroom_id'] ?>" <?= $classroomId === (int) $classThread['classroom_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) $classThread['course_code']) ?> - <?= htmlspecialchars((string) $classThread['course_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <?php if (!$classroomThreads): ?>
                        <p class="small text-muted mb-0 mt-2">
                            <?= is_faculty() ? 'No subject conversations are available for the classes you handle.' : 'No subject conversations are available for your enrolled classes.' ?>
                        </p>
                    <?php endif; ?>
                <?php else: ?>
                    <label class="form-label small text-muted mb-1">New message to</label>
                    <form method="get" class="d-flex gap-2 flex-wrap">
                        <select name="with" class="form-select form-select-sm" onchange="if(this.value) window.location='messages.php?with='+this.value">
                            <option value="">— Select recipient —</option>
                            <?php foreach ($recipients as $r): ?>
                                <option value="<?= (int) $r['id'] ?>" <?= $withId === (int) $r['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($r['full_name']) ?> (<?= htmlspecialchars(strtoupper((string) $r['role'])) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <?php if (!$recipients): ?>
                        <p class="small text-muted mb-0 mt-2">
                            <?= is_student() ? 'No faculty recipients are available for your enrolled subjects.' : 'No recipients available (check college assignment, classroom enrollment, or active users).' ?>
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                <?php if ($usesClassroomDiscussions && $activeClassroom): ?>
                    <div>
                        <strong><?= htmlspecialchars($activeClassroom['title']) ?></strong>
                        <span class="badge bg-primary ms-1"><?= htmlspecialchars($activeClassroom['course_code']) ?></span>
                        <div class="small text-muted mt-1"><?= htmlspecialchars($activeClassroom['course_name']) ?></div>
                    </div>
                <?php elseif ($canOpen && $threadUser): ?>
                    <div>
                        <strong><?= htmlspecialchars($threadUser['full_name']) ?></strong>
                        <span class="badge bg-<?= htmlspecialchars(messaging_role_badge_class($threadUser['role'])) ?> ms-1"><?= htmlspecialchars(strtoupper($threadUser['role'])) ?></span>
                    </div>
                <?php else: ?>
                    <span class="text-muted"><?= $usesClassroomDiscussions ? 'Select a subject conversation' : 'Select a conversation or recipient' ?></span>
                <?php endif; ?>
            </div>
            <?php if (!$usesClassroomDiscussions && $canOpen && $threadUser && !$canSendTo && $thread): ?>
                <div class="alert alert-secondary border-0 rounded-0 mb-0 small py-2">You can read this thread but cannot send new messages in this direction.</div>
            <?php endif; ?>
            <div class="card-body bg-light" style="min-height: 320px; max-height: 55vh; overflow-y: auto;" id="msgThread">
                <?php if ($usesClassroomDiscussions && $activeClassroom): ?>
                    <?php if ($classroomMessages === []): ?>
                        <p class="text-muted text-center py-5 mb-0">No messages yet in this subject conversation.</p>
                    <?php else: ?>
                        <?php foreach ($classroomMessages as $message): ?>
                            <div class="d-flex mb-3 <?= $message['mine'] ? 'justify-content-end' : 'justify-content-start' ?>">
                                <div class="rounded-3 px-3 py-2 shadow-sm <?= $message['mine'] ? 'bg-primary text-white' : 'bg-white border' ?>" style="max-width: 85%;">
                                    <div class="small <?= $message['mine'] ? 'text-white-50' : 'text-muted' ?> mb-1">
                                        <?= $message['mine'] ? 'You' : htmlspecialchars((string) $message['full_name']) ?>
                                        <span class="badge bg-<?= htmlspecialchars(classroom_discussion_role_badge_class((string) $message['role'])) ?> ms-1"><?= htmlspecialchars(strtoupper((string) $message['role'])) ?></span>
                                        · <?= htmlspecialchars(substr((string) $message['created_at'], 0, 16)) ?>
                                    </div>
                                    <div class="message-body"><?= nl2br(htmlspecialchars((string) $message['body'])) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php elseif ($canOpen && $threadUser): ?>
                    <?php foreach ($thread as $m): ?>
                        <div class="d-flex mb-3 <?= $m['mine'] ? 'justify-content-end' : 'justify-content-start' ?>">
                            <div class="rounded-3 px-3 py-2 shadow-sm <?= $m['mine'] ? 'bg-primary text-white' : 'bg-white border' ?>" style="max-width: 85%;">
                                <div class="small <?= $m['mine'] ? 'text-white-50' : 'text-muted' ?> mb-1">
                                    <?= $m['mine'] ? 'You' : htmlspecialchars($threadUser['full_name']) ?>
                                    · <?= htmlspecialchars(substr($m['created_at'], 0, 16)) ?>
                                </div>
                                <?php if ((int) $m['is_memo'] === 1): ?>
                                    <div class="mb-2">
                                        <span class="badge bg-warning text-dark me-1">MEMO</span>
                                        <?php if ($m['subject'] !== ''): ?>
                                            <strong><?= htmlspecialchars($m['subject']) ?></strong>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (trim((string) $m['body']) !== ''): ?>
                                    <div class="message-body"><?= nl2br(htmlspecialchars($m['body'])) ?></div>
                                <?php endif; ?>
                                <?php if ($m['attachment_original_name'] !== ''): ?>
                                    <div class="mt-2">
                                        <a class="btn btn-sm <?= $m['mine'] ? 'btn-light' : 'btn-outline-secondary' ?>" href="message_attachment.php?id=<?= (int) $m['id'] ?>"<?= app_tooltip_attr('Downloads or opens the file attached to this message. Use this to view documents shared in this conversation.') ?>>
                                            <i class="fa-solid fa-paperclip me-1"></i><?= htmlspecialchars($m['attachment_original_name']) ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                                <?php if ($m['mine']): ?>
                                    <form method="post" class="mt-2 text-end" onsubmit="return confirm('Delete this message?');">
                                        <input type="hidden" name="action" value="delete_message">
                                        <input type="hidden" name="message_id" value="<?= (int) $m['id'] ?>">
                                        <input type="hidden" name="with" value="<?= $withId ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-light"<?= app_tooltip_attr('Removes this message from the thread after you confirm. Use this only for messages you sent by mistake.') ?>>
                                            <i class="fa-solid fa-trash me-1"></i>Delete
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-muted text-center py-5 mb-0"><?= $usesClassroomDiscussions ? 'Choose a subject from the left to open the shared conversation.' : 'Choose someone from the left to read or send messages.' ?></p>
                <?php endif; ?>
            </div>
            <?php if ($usesClassroomDiscussions && $activeClassroom && $hasClassroomDiscussionTable): ?>
                <div class="card-footer bg-white">
                    <form method="post" class="d-flex flex-column gap-2">
                        <input type="hidden" name="action" value="post_classroom_message">
                        <input type="hidden" name="classroom_id" value="<?= $classroomId ?>">
                        <label class="visually-hidden" for="msgBody">Message</label>
                        <textarea name="body" id="msgBody" class="form-control" rows="3" placeholder="Type your message to this subject conversation..." required maxlength="8000"></textarea>
                        <div class="text-end">
                            <button type="submit" class="btn btn-primary"<?= app_tooltip_attr(is_student() ? 'Posts your message to this subject’s shared conversation. Use this to ask questions or reply after choosing the class on the left.' : 'Posts your message to this subject’s shared conversation. Use this to reply to students or share updates for the selected class.') ?>><i class="fa-solid fa-paper-plane me-1"></i>Send</button>
                        </div>
                    </form>
                </div>
            <?php elseif ($canOpen && $threadUser && $canSendTo): ?>
                <div class="card-footer bg-white">
                    <form method="post" class="d-flex flex-column gap-2">
                        <input type="hidden" name="action" value="send">
                        <input type="hidden" name="recipient_id" value="<?= $withId ?>">
                        <label class="visually-hidden" for="msgBody">Message</label>
                        <textarea name="body" id="msgBody" class="form-control" rows="3" placeholder="Type your message…" required maxlength="8000"></textarea>
                        <div class="text-end">
                            <button type="submit" class="btn btn-primary"<?= app_tooltip_attr(is_student() ? 'Sends your private message in this conversation. Use this after you select someone on the left and type your message.' : 'Sends your message in this thread. Use this after you select a recipient on the left and type your note.') ?>><i class="fa-solid fa-paper-plane me-1"></i>Send</button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($canSendMemo): ?>
            <div class="card shadow-sm mt-3">
                <div class="card-header bg-white">
                    <strong>Memo / Announcement</strong>
                </div>
                <div class="card-body">
                    <?php if (!$memoReady): ?>
                        <p class="text-muted mb-0">Run <code>upgrade_roles.php</code> to enable memo subjects and document attachments.</p>
                    <?php elseif (!$memoRecipients): ?>
                        <p class="text-muted mb-0">No faculty recipients are available for your account scope.</p>
                    <?php else: ?>
                        <form method="post" enctype="multipart/form-data" class="row g-3">
                            <input type="hidden" name="action" value="send_memo">
                            <div class="col-12">
                                <label class="form-label">Faculty recipients</label>
                                <select name="recipient_ids[]" class="form-select" size="8" multiple required>
                                    <?php foreach ($memoRecipients as $r): ?>
                                        <option value="<?= (int) $r['id'] ?>">
                                            <?= htmlspecialchars($r['full_name']) ?><?= $r['department'] !== '' ? ' - ' . htmlspecialchars($r['department']) : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Hold Ctrl or Cmd to select multiple faculty members.</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="memoSubject">Memo subject</label>
                                <input type="text" name="subject" id="memoSubject" class="form-control" maxlength="255" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="memoBody">Memo details</label>
                                <textarea name="body" id="memoBody" class="form-control" rows="4" maxlength="8000" placeholder="Write the announcement details here..."></textarea>
                            </div>
                            <div class="col-md-7">
                                <label class="form-label" for="memoAttachment">Attachment</label>
                                <input type="file" name="attachment" id="memoAttachment" class="form-control" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.jpg,.jpeg,.png">
                                <div class="form-text">Allowed: PDF, Office documents, text, CSV, JPG, PNG. Max 10 MB.</div>
                            </div>
                            <div class="col-md-5 d-flex align-items-end justify-content-md-end">
                                <button type="submit" class="btn btn-warning"<?= app_tooltip_attr('Sends a memo announcement to the selected faculty inboxes. Use this for official notices with optional attachments.') ?>>
                                    <i class="fa-solid fa-bullhorn me-1"></i>Send memo
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (($usesClassroomDiscussions && $activeClassroom) || ($canOpen && $threadUser)): ?>
<script>
document.getElementById('msgThread')?.scrollTo(0, document.getElementById('msgThread').scrollHeight);
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

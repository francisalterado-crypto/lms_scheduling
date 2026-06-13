<?php
declare(strict_types=1);
/**
 * Wellness companion chat UI (included by student_wellness.php).
 */
?>
<div class="student-wellness-root">
  <style>
    /* Theme-aware tokens (light defaults; dark overrides below) */
    .student-wellness-root {
      --wellness-accent: #0d9488;
      --wellness-accent-hover: #0f766e;
      --wellness-accent-soft: rgba(13, 148, 136, 0.18);
      --wellness-surface: var(--bs-body-bg, #ffffff);
      --wellness-surface-2: var(--bs-tertiary-bg, #f8fafc);
      --wellness-border: var(--bs-border-color, #e2e8f0);
      --wellness-text: var(--bs-body-color, #1e293b);
      --wellness-text-muted: var(--bs-secondary-color, #64748b);
      --wellness-header-bg: linear-gradient(135deg, #f0fdfa 0%, #ecfeff 50%, #f8fafc 100%);
      --wellness-alert-bg: #fffbeb;
      --wellness-alert-border: #fcd34d;
      --wellness-alert-text: #422006;
      --wellness-bubble-bg: var(--bs-body-bg, #ffffff);
      --wellness-bubble-user-bg: #eef2ff;
      --wellness-bubble-user-border: #c7d2fe;
      --wellness-bubble-user-text: var(--bs-body-color, #1e293b);
      --wellness-crisis-bg: #fef2f2;
      --wellness-crisis-border: #fecaca;
      --wellness-crisis-text: #7f1d1d;
      --wellness-shadow: 0 8px 32px rgba(15, 23, 42, 0.06);
      --wellness-bubble-shadow: 0 4px 14px rgba(15, 23, 42, 0.04);
      --wellness-input-bg: var(--bs-body-bg, #ffffff);
      --wellness-typing-dot: #94a3b8;
      max-width: 820px;
      margin: 0 auto;
      color: var(--wellness-text);
    }

    html[data-bs-theme="dark"] .student-wellness-root {
      --wellness-accent: #2dd4bf;
      --wellness-accent-hover: #14b8a6;
      --wellness-accent-soft: rgba(45, 212, 191, 0.22);
      --wellness-surface: var(--app-surface, #151b24);
      --wellness-surface-2: var(--app-bg, #0f1419);
      --wellness-border: var(--app-border-strong, rgba(255, 255, 255, 0.12));
      --wellness-text: var(--app-text, #e2e8f0);
      --wellness-text-muted: var(--app-text-muted, #94a3b8);
      --wellness-header-bg: linear-gradient(135deg, #134e4a 0%, #1e293b 45%, #151b24 100%);
      --wellness-alert-bg: rgba(251, 191, 36, 0.12);
      --wellness-alert-border: rgba(251, 191, 36, 0.45);
      --wellness-alert-text: #fde68a;
      --wellness-bubble-bg: #1a2230;
      --wellness-bubble-user-bg: rgba(99, 102, 241, 0.22);
      --wellness-bubble-user-border: rgba(129, 140, 248, 0.45);
      --wellness-bubble-user-text: #e0e7ff;
      --wellness-crisis-bg: rgba(239, 68, 68, 0.14);
      --wellness-crisis-border: rgba(248, 113, 113, 0.5);
      --wellness-crisis-text: #fecaca;
      --wellness-shadow: var(--app-shadow-md, 0 8px 24px rgba(0, 0, 0, 0.35));
      --wellness-bubble-shadow: 0 4px 12px rgba(0, 0, 0, 0.25);
      --wellness-input-bg: #101722;
      --wellness-typing-dot: #94a3b8;
    }

    .student-wellness-card {
      border-radius: 16px;
      border: 1px solid var(--wellness-border);
      background: var(--wellness-surface);
      box-shadow: var(--wellness-shadow);
      overflow: hidden;
      display: flex;
      flex-direction: column;
      min-height: min(72vh, 680px);
    }

    .student-wellness-card-header {
      padding: 1rem 1.25rem;
      background: var(--wellness-header-bg);
      border-bottom: 1px solid var(--wellness-border);
      color: var(--wellness-text);
    }

    .student-wellness-card-header .text-muted {
      color: var(--wellness-text-muted) !important;
    }

    .student-wellness-alert {
      font-size: 0.76rem;
      line-height: 1.55;
      background: var(--wellness-alert-bg);
      border: 1px solid var(--wellness-alert-border);
      border-radius: 12px;
      padding: 0.7rem 0.9rem;
      margin-top: 0.75rem;
      color: var(--wellness-alert-text);
    }

    .student-wellness-thread {
      flex: 1;
      display: flex;
      flex-direction: column;
      gap: 12px;
      padding: 1rem 1.25rem;
      overflow-y: auto;
      min-height: 280px;
      max-height: min(48vh, 480px);
      background: var(--wellness-surface-2);
    }

    .student-wellness-bubble {
      max-width: min(92%, 560px);
      align-self: flex-start;
      border-radius: 14px;
      padding: 0.65rem 0.95rem;
      font-size: 0.875rem;
      line-height: 1.55;
      background: var(--wellness-bubble-bg);
      color: var(--wellness-text);
      border: 1px solid var(--wellness-border);
      box-shadow: var(--wellness-bubble-shadow);
    }

    .student-wellness-bubble strong {
      color: var(--wellness-text);
    }

    .student-wellness-bubble.user {
      align-self: flex-end;
      background: var(--wellness-bubble-user-bg);
      border-color: var(--wellness-bubble-user-border);
      color: var(--wellness-bubble-user-text);
    }

    .student-wellness-bubble.user strong {
      color: var(--wellness-bubble-user-text);
    }

    .student-wellness-bubble.crisis {
      align-self: stretch;
      background: var(--wellness-crisis-bg);
      border-color: var(--wellness-crisis-border);
      color: var(--wellness-crisis-text);
    }

    .student-wellness-bubble.crisis strong {
      color: var(--wellness-crisis-text);
    }

    .student-wellness-bubble-meta {
      font-size: 0.65rem;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: var(--wellness-text-muted);
      margin-bottom: 0.25rem;
    }

    .student-wellness-compose {
      padding: 1rem 1.25rem 1.25rem;
      border-top: 1px solid var(--wellness-border);
      background: var(--wellness-surface);
    }

    .student-wellness-compose textarea {
      width: 100%;
      min-height: 88px;
      border-radius: 12px;
      border: 1px solid var(--wellness-border);
      background: var(--wellness-input-bg);
      color: var(--wellness-text);
      padding: 0.7rem 0.9rem;
      font-size: 0.9rem;
      resize: vertical;
    }

    .student-wellness-compose textarea::placeholder {
      color: var(--wellness-text-muted);
      opacity: 0.85;
    }

    .student-wellness-compose textarea:focus {
      border-color: var(--wellness-accent);
      outline: none;
      box-shadow: 0 0 0 3px var(--wellness-accent-soft);
    }

    .student-wellness-actions {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 10px;
      margin-top: 0.6rem;
    }

    .student-wellness-send {
      border: none;
      border-radius: 999px;
      background: var(--wellness-accent);
      color: #0f172a;
      font-weight: 600;
      font-size: 0.875rem;
      padding: 0.5rem 1.4rem;
    }

    html[data-bs-theme="dark"] .student-wellness-send {
      color: #042f2e;
    }

    .student-wellness-send:hover:not(:disabled) {
      background: var(--wellness-accent-hover);
    }

    .student-wellness-send:disabled {
      opacity: 0.55;
    }

    .student-wellness-status {
      font-size: 0.75rem;
      color: var(--wellness-text-muted);
    }

    .student-wellness-status--warn {
      color: #b45309;
    }

    .student-wellness-status--ok {
      color: #15803d;
    }

    html[data-bs-theme="dark"] .student-wellness-status--warn {
      color: #fbbf24;
    }

    html[data-bs-theme="dark"] .student-wellness-status--ok {
      color: #4ade80;
    }

    .student-wellness-hint {
      font-size: 0.72rem;
      color: var(--wellness-text-muted);
      margin: 0.5rem 0 0;
    }

    .student-wellness-typing {
      display: none;
      align-self: flex-start;
      max-width: min(92%, 560px);
    }

    .student-wellness-typing.is-visible {
      display: block;
    }

    .student-wellness-typing .student-wellness-bubble {
      padding: 0.75rem 1rem;
      min-width: 4.5rem;
    }

    .student-wellness-typing-dots {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      height: 1.25rem;
    }

    .student-wellness-typing-dots span {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: var(--wellness-typing-dot);
      animation: wellness-typing-bounce 1.2s ease-in-out infinite;
    }

    .student-wellness-typing-dots span:nth-child(2) {
      animation-delay: 0.15s;
    }

    .student-wellness-typing-dots span:nth-child(3) {
      animation-delay: 0.3s;
    }

    @keyframes wellness-typing-bounce {
      0%, 60%, 100% {
        transform: translateY(0);
        opacity: 0.45;
      }
      30% {
        transform: translateY(-5px);
        opacity: 1;
      }
    }

    /* Page title above card */
    html[data-bs-theme="dark"] .student-wellness-page .text-success {
      color: var(--wellness-accent) !important;
    }
  </style>

  <div class="student-wellness-card" aria-label="Wellness chat">
    <div class="student-wellness-card-header">
      <div class="d-flex align-items-center gap-2">
        <span class="rounded-circle d-inline-flex align-items-center justify-content-center"
              style="width:40px;height:40px;background:var(--wellness-accent-soft);color:var(--wellness-accent);">
          <i class="fa-solid fa-heart-pulse" aria-hidden="true"></i>
        </span>
        <div>
          <div class="fw-semibold">Wellness companion</div>
          <div class="text-muted small">Private support chat · English / Filipino</div>
        </div>
      </div>
      <div class="student-wellness-alert">
        <strong>Not therapy or emergency care.</strong> For crisis or thoughts of self-harm, use the hotlines in any crisis response—not this chat alone.
      </div>
    </div>

    <div class="student-wellness-thread" id="wellnessThread" aria-live="polite"></div>

    <div class="student-wellness-compose">
      <label class="visually-hidden" for="wellnessMessageInput">Your message</label>
      <textarea id="wellnessMessageInput" placeholder="Hal. Nababalisa ako sa exam bukas…&#10;Eg. I can't sleep and feel alone on campus."></textarea>
      <div class="student-wellness-actions">
        <button type="button" class="student-wellness-send" id="wellnessSendBtn">Send</button>
        <span class="student-wellness-status" id="wellnessStatus"></span>
      </div>
      <p class="student-wellness-hint mb-0">Ctrl+Enter to send · Conversation is tied to your student login session.</p>
    </div>
  </div>
</div>

<script>
(function () {
  var thread = document.getElementById('wellnessThread');
  var ta = document.getElementById('wellnessMessageInput');
  var go = document.getElementById('wellnessSendBtn');
  var st = document.getElementById('wellnessStatus');
  if (!thread || !ta || !go) return;

  var apiUrl = String(new URL('api/student_wellness_chat.php', window.location.href)).split(/[?#]/)[0];
  var wellnessHistory = [];
  var typingBubble = null;

  function showTypingBubble() {
    if (!typingBubble) {
      typingBubble = document.createElement('div');
      typingBubble.className = 'student-wellness-typing';
      typingBubble.id = 'wellnessTypingBubble';
      typingBubble.setAttribute('aria-label', 'Wellness companion is typing');
      typingBubble.innerHTML =
        '<article class="student-wellness-bubble">' +
          '<div class="student-wellness-bubble-meta">Wellness companion</div>' +
          '<div class="student-wellness-typing-dots" aria-hidden="true">' +
            '<span></span><span></span><span></span>' +
          '</div>' +
        '</article>';
    }
    typingBubble.classList.add('is-visible');
    typingBubble.setAttribute('aria-hidden', 'false');
    thread.appendChild(typingBubble);
    thread.scrollTop = thread.scrollHeight;
  }

  function hideTypingBubble() {
    if (!typingBubble) return;
    typingBubble.classList.remove('is-visible');
    typingBubble.setAttribute('aria-hidden', 'true');
  }

  function scrollThreadToEnd() {
    thread.scrollTop = thread.scrollHeight;
  }

  function escapeHtml(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function formatMd(s) {
    var r = escapeHtml(String(s).trim());
    r = r.replace(/\*\*([\s\S]+?)\*\*/g, '<strong>$1</strong>');
    r = r.replace(/\n- /g, '<br />\u2022 ');
    r = r.replace(/\n(\d+)\. /g, '<br />$1. ');
    r = r.replace(/\n\r?/g, '<br />');
    return r;
  }

  function appendBubble(side, text, crisis) {
    hideTypingBubble();
    var bub = document.createElement('article');
    var meta = document.createElement('div');
    meta.className = 'student-wellness-bubble-meta';
    meta.textContent = side === 'user' ? 'You' : (crisis ? 'Crisis support — Philippines' : 'Wellness companion');
    var inner = document.createElement('div');
    inner.innerHTML = formatMd(text);
    bub.appendChild(meta);
    bub.appendChild(inner);
    bub.className = 'student-wellness-bubble' + (side === 'user' ? ' user' : '') + (crisis ? ' crisis' : '');
    thread.appendChild(bub);
    scrollThreadToEnd();
  }

  fetch(apiUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
    .then(function (r) { return r.json(); })
    .then(function (info) {
      if (!info || !st) return;
      if (info.setup_hint) {
        st.textContent = info.setup_hint;
        st.className = 'student-wellness-status student-wellness-status--warn';
      } else if (info.groq && info.groq.ready) {
        st.textContent = 'Groq AI ready';
        st.className = 'student-wellness-status student-wellness-status--ok';
      }
    })
    .catch(function () {});

  appendBubble(
    'assistant',
    'Kumusta! Sabihin kung ano ang pinaka-mabigat ngayon—exam, tulog, kalungkutan, pamilya, o relasyon. Sasagutin ko sa Tagalog o English. (Suporta lang—hindi therapy.)',
    false
  );

  function setBusy(busy, msg) {
    go.disabled = busy;
    ta.readOnly = busy;
    if (busy) {
      showTypingBubble();
      if (st && msg != null) st.textContent = msg;
      else if (st) st.textContent = '';
    } else {
      hideTypingBubble();
      if (st && msg != null) {
        st.textContent = msg;
      } else if (st && !st.classList.contains('student-wellness-status--warn') && !st.classList.contains('student-wellness-status--ok')) {
        st.textContent = '';
        st.className = 'student-wellness-status';
      }
    }
  }

  function sendOnce() {
    var body = ta.value.trim();
    if (!body) {
      if (st) {
        st.textContent = 'Type a message first.';
        setTimeout(function () { if (st && st.textContent === 'Type a message first.') st.textContent = ''; }, 3000);
      }
      return;
    }
    ta.value = '';
    appendBubble('user', body, false);
    setBusy(true);

    fetch(apiUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ message: body, language_hint: 'auto', history: wellnessHistory }),
    })
      .then(function (r) {
        return r.json().then(function (j) { return { ok: r.ok, j: j, status: r.status }; });
      })
      .then(function (pack) {
        if (!pack.ok || !pack.j || pack.j.ok === false) {
          appendBubble(
            'assistant',
            pack.status === 401
              ? '**Please log in as a student** and open this page again.'
              : '**Could not get a reply.** ' + (pack.j && pack.j.error ? String(pack.j.error) : 'Try again.'),
            false
          );
          setBusy(false, '');
          return;
        }
        var reply = String(pack.j.reply || '');
        appendBubble('assistant', reply, Boolean(pack.j.crisis));
        if (!pack.j.crisis) {
          wellnessHistory.push({ role: 'user', content: body });
          wellnessHistory.push({ role: 'assistant', content: reply });
          if (wellnessHistory.length > 16) wellnessHistory = wellnessHistory.slice(-16);
        }
        if (st) {
          var bits = [];
          if (pack.j.detected_language === 'tl') bits.push('Filipino');
          else if (pack.j.detected_language === 'en') bits.push('English');
          var src = pack.j.reply_source || 'smart';
          if (src === 'groq' || src === 'ai' || src === 'gemini' || src === 'ollama') bits.push('Groq AI');
          else bits.push('Smart');
          st.textContent = bits.join(' \u00b7 ');
          st.className = 'student-wellness-status';
        }
        setBusy(false, '');
      })
      .catch(function () {
        appendBubble('assistant', '**Network error.** Check your connection and try again.', false);
        setBusy(false, '');
      });
  }

  go.addEventListener('click', sendOnce);
  ta.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
      e.preventDefault();
      sendOnce();
    }
  });
  ta.focus();
})();
</script>

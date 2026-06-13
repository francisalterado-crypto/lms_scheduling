<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_role(['student']);

$userId = (int) ($_SESSION['user_id'] ?? 0);
$storageNs = 'u' . ($userId > 0 ? (string) $userId : '0');

$pageTitle = 'Learning tools (EduTools)';
$mainContainerClass = 'container-fluid px-3 px-lg-4 py-3 py-md-4 flex-grow-1 d-flex flex-column min-w-0 student-edutools-page';
require_once __DIR__ . '/includes/header.php';
?>
<div class="mb-3">
    <h1 class="h4 mb-1"><i class="fa-solid fa-wand-magic-sparkles me-2 text-primary"></i>EduTools</h1>
    <p class="text-muted small mb-0">AI helpers, references, and a personal notebook for your studies. Use the sidebar to pick a tool; <kbd class="small">Ctrl</kbd>+<kbd class="small">E</kbd> toggles the tool menu.</p>
</div>

<div class="student-edutools-root" data-storage-ns="<?= htmlspecialchars($storageNs, ENT_QUOTES, 'UTF-8') ?>">
  <style>
    .student-edutools-root * {
      box-sizing: border-box;
    }

    .student-edutools-root .app-container {
      display: flex;
      width: 100%;
      position: relative;
      min-height: min(720px, calc(100vh - 14rem));
      border-radius: 12px;
      overflow: hidden;
      border: 1px solid #e2e8f0;
      background: #ffffff;
    }

    .student-edutools-root .tools-panel {
      width: 280px;
      max-width: 92vw;
      background: rgba(255, 255, 255, 0.96);
      backdrop-filter: blur(2px);
      border-right: 1px solid #e2e8f0;
      display: flex;
      flex-direction: column;
      transition: transform 0.25s ease, width 0.2s;
      z-index: 30;
      box-shadow: 2px 0 12px rgba(0, 0, 0, 0.03);
      flex-shrink: 0;
      height: auto;
      align-self: stretch;
      min-height: min(680px, calc(100vh - 15rem));
      overflow-y: auto;
    }

    .student-edutools-root .tools-panel.collapsed {
      transform: translateX(-100%);
      position: absolute;
      height: 100%;
      min-height: 100%;
    }

    .student-edutools-root .main-area {
      flex: 1;
      display: flex;
      flex-direction: column;
      overflow: hidden;
      background: #ffffff;
      transition: all 0.2s;
      min-width: 0;
      min-height: min(680px, calc(100vh - 15rem));
    }

    .student-edutools-root .top-bar {
      background: white;
      padding: 12px 16px;
      border-bottom: 1px solid #e9eef3;
      display: flex;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
      box-shadow: 0 1px 2px rgba(0,0,0,0.02);
    }

    .student-edutools-root .menu-toggle-btn {
      background: #f8fafc;
      border: 1px solid #cbd5e1;
      border-radius: 40px;
      padding: 8px 14px;
      font-size: 0.9rem;
      font-weight: 500;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 8px;
      color: #1e293b;
      transition: all 0.2s;
    }

    .student-edutools-root .menu-toggle-btn:hover {
      background: #eef2ff;
      border-color: #94a3b8;
    }

    .student-edutools-root .active-badge {
      background: #eef2ff;
      padding: 6px 14px;
      border-radius: 40px;
      font-size: 0.85rem;
      color: #1e40af;
      font-weight: 500;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .student-edutools-root .active-badge span:first-child {
      font-weight: 400;
      color: #475569;
    }

    .student-edutools-root .key-hint {
      background: #e2e8f0;
      padding: 2px 8px;
      border-radius: 20px;
      font-family: ui-monospace, monospace;
      font-size: 0.75rem;
      color: #0f172a;
    }

    .student-edutools-root .tool-viewport {
      flex: 1;
      position: relative;
      background: #fefefe;
      overflow: auto;
      min-height: 0;
    }

    .student-edutools-root iframe {
      width: 100%;
      height: 100%;
      min-height: 420px;
      border: none;
      display: block;
    }

    .student-edutools-root .notebook-container {
      padding: 20px 22px;
      height: 100%;
      overflow-y: auto;
      background: #fefcf5;
    }

    .student-edutools-root .notebook-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 12px;
      margin-bottom: 20px;
      border-bottom: 2px solid #e2e8f0;
      padding-bottom: 12px;
    }

    .student-edutools-root .notebook-header h2 {
      font-size: 1.35rem;
      font-weight: 600;
      background: linear-gradient(135deg, #0f172a, #334155);
      background-clip: text;
      -webkit-background-clip: text;
      color: transparent;
      margin: 0;
    }

    .student-edutools-root .note-actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }

    .student-edutools-root .note-btn {
      background: white;
      border: 1px solid #cbd5e1;
      padding: 6px 12px;
      border-radius: 30px;
      font-weight: 500;
      font-size: 0.8rem;
      cursor: pointer;
      transition: 0.2s;
    }

    .student-edutools-root .note-btn.primary {
      background: #0f172a;
      color: white;
      border: none;
    }

    .student-edutools-root .note-btn.primary:hover {
      background: #1e293b;
    }

    .student-edutools-root .note-btn:hover {
      background: #f1f5f9;
    }

    .student-edutools-root textarea.notepad-textarea {
      width: 100%;
      min-height: 42vh;
      padding: 16px;
      font-family: ui-monospace, 'Courier New', monospace;
      font-size: 0.95rem;
      line-height: 1.5;
      border: 1px solid #e2e8f0;
      border-radius: 16px;
      background: #ffffff;
      resize: vertical;
      box-shadow: inset 0 1px 3px rgba(0,0,0,0.03);
    }

    .student-edutools-root .status-msg {
      font-size: 0.75rem;
      margin-top: 10px;
      color: #2d6a4f;
    }

    .student-edutools-root .menu-header {
      padding: 18px 16px 6px 16px;
      font-weight: 600;
      font-size: 0.7rem;
      letter-spacing: 0.06em;
      color: #5b6e8c;
      text-transform: uppercase;
    }

    .student-edutools-root .tool-list {
      list-style: none;
      margin: 6px 10px 16px 10px;
      padding: 0;
    }

    .student-edutools-root .tool-item {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 10px 14px;
      margin: 4px 0;
      border-radius: 14px;
      cursor: pointer;
      transition: all 0.15s;
      font-weight: 500;
      font-size: 0.9rem;
      color: #1e293b;
    }

    .student-edutools-root .tool-item:hover {
      background: #f1f5f9;
    }

    .student-edutools-root .tool-item.active {
      background: #eef2ff;
      color: #1e40af;
      border-left: 3px solid #3b82f6;
    }

    .student-edutools-root .tool-icon {
      font-size: 1.25rem;
      line-height: 1;
    }

    .student-edutools-root .tool-name {
      flex: 1;
    }

    .student-edutools-root .badge-external {
      font-size: 0.65rem;
      background: #e2e8f0;
      padding: 2px 8px;
      border-radius: 30px;
      color: #334155;
    }

    .student-edutools-root .edutools-hr {
      margin: 8px 14px;
      border: 0;
      border-top: 1px solid #edf2f7;
    }

    .student-edutools-root .shortcut-toast {
      position: absolute;
      bottom: 12px;
      right: 12px;
      background: #0f172ad9;
      backdrop-filter: blur(8px);
      color: white;
      padding: 6px 12px;
      border-radius: 40px;
      font-size: 0.68rem;
      font-family: ui-monospace, monospace;
      pointer-events: none;
      z-index: 25;
      max-width: 90%;
    }

    .student-edutools-root .badge-google {
      background: #e8eef9;
      color: #1a73e8;
    }

    @media (max-width: 680px) {
      .student-edutools-root .tools-panel {
        width: 260px;
      }
      .student-edutools-root .notebook-container {
        padding: 14px;
      }
      .student-edutools-root .top-bar {
        padding: 8px 12px;
      }
    }

  </style>

  <div class="app-container">
    <aside class="tools-panel" id="eduToolsPanel" aria-label="Learning tools">
      <div style="padding: 16px 16px 0 16px;">
        <h2 style="font-size: 1.35rem; font-weight: 700; margin: 0; background: linear-gradient(145deg, #0f2b3d, #1e4a76); background-clip: text; -webkit-background-clip: text; color: transparent;">EduTools</h2>
        <p style="font-size: 0.75rem; color: #4b5563; margin-top: 6px;">Smart learning panel &amp; education sources</p>
      </div>
      <div class="menu-header">AI &amp; learning tools</div>
      <ul class="tool-list" id="eduToolMenuList"></ul>
      <div class="menu-header" style="margin-top: 6px;">Notebooks</div>
      <ul class="tool-list" id="eduNotebookSectionList"></ul>
      <hr class="edutools-hr" />
      <div style="padding: 10px 16px 16px 16px; font-size: 0.68rem; color: #6c757d;">
        <kbd>Ctrl</kbd> + <kbd>E</kbd> toggles this tool menu
      </div>
    </aside>

    <div class="main-area">
      <div class="top-bar">
        <button type="button" class="menu-toggle-btn" id="eduTogglePanelBtn"<?= app_tooltip_attr('Shows or hides the EduTools sidebar. Same as Ctrl+E.') ?>>
          <span aria-hidden="true">☰</span> Menu
        </button>
        <div class="active-badge" id="eduActiveToolBadge">
          <span>Active tool:</span> <strong id="eduActiveToolName">Notebook</strong>
        </div>
        <div class="ms-auto d-flex gap-2 align-items-center">
          <span class="key-hint">Ctrl+E</span>
        </div>
      </div>
      <div class="tool-viewport position-relative" id="eduToolViewport">
        <div id="eduDynamicContent"></div>
        <div class="shortcut-toast" aria-hidden="true">Ctrl+E toggles sidebar · NotebookLM: AI research assistant</div>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  const root = document.querySelector('.student-edutools-root');
  if (!root) return;
  const ns = root.getAttribute('data-storage-ns') || 'guest';

  const NOTEBOOK_STORAGE_KEY = 'student_edu_' + ns + '_notebook_content';
  const LAST_TOOL_KEY = 'student_edu_' + ns + '_last_tool';
  const PANEL_COLLAPSED_KEY = 'student_edu_' + ns + '_panel_collapsed';

  const toolsConfig = [
    { id: 'notebook', name: 'Built-in notebook', icon: '\uD83D\uDCD8', type: 'notebook', url: null, openNewTab: false, category: 'notebooks' },
    { id: 'notebooklm', name: 'NotebookLM', icon: '\uD83E\uDDE0', type: 'external', url: 'https://notebooklm.google.com', openNewTab: true, description: "Google's AI research assistant & smart notebook", category: 'notebooks' },
    { id: 'chatgpt', name: 'ChatGPT', icon: '\uD83E\uDD16', type: 'external', url: 'https://chat.openai.com', openNewTab: true, description: 'OpenAI assistant', category: 'ai' },
    { id: 'perplexity', name: 'Perplexity AI', icon: '\uD83D\uDD0D', type: 'external', url: 'https://www.perplexity.ai', openNewTab: true, description: 'AI-powered search', category: 'ai' },
    { id: 'wolfram', name: 'Wolfram Alpha', icon: '\uD83E\uDDEE', type: 'external', url: 'https://www.wolframalpha.com', openNewTab: true, description: 'Computational intelligence', category: 'reference' },
    { id: 'khan', name: 'Khan Academy', icon: '\uD83D\uDCDA', type: 'external', url: 'https://www.khanacademy.org', openNewTab: true, description: 'Free online courses', category: 'reference' },
    { id: 'notion', name: 'Notion', icon: '\uD83D\uDCDD', type: 'external', url: 'https://www.notion.so', openNewTab: true, description: 'All-in-one workspace', category: 'reference' }
  ];

  let currentToolId = 'notebook';
  let notebookTextarea = null;
  let notebookContent = '';

  const toolViewport = document.getElementById('eduDynamicContent');
  const toolsPanel = document.getElementById('eduToolsPanel');
  const toggleBtn = document.getElementById('eduTogglePanelBtn');
  const activeToolNameSpan = document.getElementById('eduActiveToolName');
  const toolMenuList = document.getElementById('eduToolMenuList');
  const notebookSectionList = document.getElementById('eduNotebookSectionList');

  if (!toolViewport || !toolsPanel || !toggleBtn || !activeToolNameSpan || !toolMenuList || !notebookSectionList) return;

  function renderMenu() {
    toolMenuList.innerHTML = '';
    notebookSectionList.innerHTML = '';
    const aiTools = toolsConfig.filter(function (t) { return t.category === 'ai'; });
    const refTools = toolsConfig.filter(function (t) { return t.category === 'reference'; });
    const notebookTools = toolsConfig.filter(function (t) { return t.category === 'notebooks'; });
    aiTools.forEach(function (tool) { toolMenuList.appendChild(createMenuItem(tool)); });
    refTools.forEach(function (tool) { toolMenuList.appendChild(createMenuItem(tool)); });
    notebookTools.forEach(function (tool) { notebookSectionList.appendChild(createMenuItem(tool)); });
  }

  function createMenuItem(tool) {
    const li = document.createElement('li');
    li.className = 'tool-item' + (currentToolId === tool.id ? ' active' : '');
    li.setAttribute('data-tool-id', tool.id);
    const isNotebookLM = tool.id === 'notebooklm';
    li.innerHTML =
      '<span class="tool-icon">' + tool.icon + '</span>' +
      '<span class="tool-name">' + escapeHtml(tool.name) + '</span>' +
      '<span class="badge-external' + (isNotebookLM ? ' badge-google' : '') + '">' + (tool.openNewTab ? '\u2197\uFE0F' : '\uD83D\uDCD3') + '</span>';
    li.addEventListener('click', function (e) {
      e.stopPropagation();
      activateTool(tool.id);
    });
    return li;
  }

  function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  function renderNotebook() {
    const saved = localStorage.getItem(NOTEBOOK_STORAGE_KEY) || '# Welcome to your Smart Notebook\n\nTake notes, draft ideas, and save study resources.\n\n## Tips\n- Notes auto-save while you type\n- Export as .md or .txt\n- Open NotebookLM from the menu for AI on your sources';
    notebookContent = saved;
    toolViewport.innerHTML =
      '<div class="notebook-container">' +
        '<div class="notebook-header">' +
          '<h2>Personal learning notebook</h2>' +
          '<div class="note-actions">' +
            '<button type="button" id="eduExportMdBtn" class="note-btn">Export .md</button>' +
            '<button type="button" id="eduExportTxtBtn" class="note-btn">Export .txt</button>' +
            '<button type="button" id="eduClearNotebookBtn" class="note-btn">Clear</button>' +
          '</div>' +
        '</div>' +
        '<textarea id="eduNotebookTextarea" class="notepad-textarea" placeholder="Write your notes here..."></textarea>' +
        '<div class="status-msg" id="eduNoteStatus">Auto-save enabled</div>' +
      '</div>';

    const textarea = document.getElementById('eduNotebookTextarea');
    if (textarea) {
      textarea.value = notebookContent;
      notebookTextarea = textarea;
      var saveTimeout;
      textarea.addEventListener('input', function () {
        if (saveTimeout) clearTimeout(saveTimeout);
        saveTimeout = setTimeout(function () {
          var newContent = textarea.value;
          localStorage.setItem(NOTEBOOK_STORAGE_KEY, newContent);
          notebookContent = newContent;
          var statusDiv = document.getElementById('eduNoteStatus');
          if (statusDiv) {
            statusDiv.textContent = 'Saved at ' + new Date().toLocaleTimeString();
            setTimeout(function () {
              if (statusDiv) statusDiv.textContent = 'Auto-save active';
            }, 1500);
          }
        }, 500);
      });
    }
    var exportMd = document.getElementById('eduExportMdBtn');
    var exportTxt = document.getElementById('eduExportTxtBtn');
    var clearBtn = document.getElementById('eduClearNotebookBtn');
    if (exportMd) exportMd.addEventListener('click', function () { exportNotebook('md'); });
    if (exportTxt) exportTxt.addEventListener('click', function () { exportNotebook('txt'); });
    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        if (confirm('Clear all notebook content? This cannot be undone.')) {
          if (notebookTextarea) notebookTextarea.value = '';
          localStorage.setItem(NOTEBOOK_STORAGE_KEY, '');
          notebookContent = '';
          var statusDiv = document.getElementById('eduNoteStatus');
          if (statusDiv) statusDiv.textContent = 'Notebook cleared.';
        }
      });
    }
  }

  function exportNotebook(format) {
    var content = '';
    if (notebookTextarea) {
      content = notebookTextarea.value;
    } else {
      content = localStorage.getItem(NOTEBOOK_STORAGE_KEY) || '';
    }
    var blob = new Blob([content], { type: format === 'md' ? 'text/markdown' : 'text/plain' });
    var link = document.createElement('a');
    var filename = 'my_learning_notes.' + (format === 'md' ? 'md' : 'txt');
    link.href = URL.createObjectURL(blob);
    link.download = filename;
    link.click();
    URL.revokeObjectURL(link.href);
    var statusDiv = document.getElementById('eduNoteStatus');
    if (statusDiv) statusDiv.textContent = 'Exported as ' + filename;
  }


  function renderExternalHelper(tool) {
    var isNotebookLM = tool.id === 'notebooklm';
    var gradientBg = isNotebookLM ? 'linear-gradient(135deg, #f5f0ff 0%, #e8eefa 100%)' : '#fafcff';
    var accentColor = isNotebookLM ? '#8b5cf6' : '#0f172a';
    var specialNote = isNotebookLM
      ? "NotebookLM is Google's AI notebook. Upload sources (PDFs, docs, web) and ask questions, get summaries, and connect ideas."
      : '';
    toolViewport.innerHTML =
      '<div style="display:flex;align-items:center;justify-content:center;min-height:100%;background:' + gradientBg + ';padding:1.5rem;">' +
        '<div style="max-width:520px;text-align:center;background:white;border-radius:1.5rem;padding:1.75rem;box-shadow:0 12px 30px rgba(0,0,0,0.08);border:1px solid #eef2ff;">' +
          '<div style="font-size:3rem;line-height:1;">' + tool.icon + '</div>' +
          '<h2 style="margin:0.75rem 0 0.35rem;font-size:1.25rem;color:' + accentColor + ';">' + escapeHtml(tool.name) + '</h2>' +
          '<p style="color:#4b5563;margin-bottom:0.75rem;font-size:0.95rem;">' + escapeHtml(tool.description || 'Learning assistant') + '</p>' +
          (specialNote ? '<p style="background:#f3e8ff;padding:8px 12px;border-radius:16px;font-size:0.8rem;color:#6b21a5;margin:12px 0;">' + escapeHtml(specialNote) + '</p>' : '') +
          '<button type="button" id="eduOpenExternalBtn" class="note-btn primary" style="padding:10px 28px;border-radius:60px;font-size:1rem;margin-top:8px;">Launch ' + escapeHtml(tool.name) + ' \u2197</button>' +
          '<p style="margin-top:16px;font-size:0.7rem;color:#6c757d;">Opens in a new tab. ' + (isNotebookLM ? 'Sign in with Google to use NotebookLM.' : 'Best experienced on the official site.') + '</p>' +
        '</div>' +
      '</div>';
    var openBtn = document.getElementById('eduOpenExternalBtn');
    if (openBtn && tool.url) {
      openBtn.addEventListener('click', function () {
        window.open(tool.url, '_blank', 'noopener,noreferrer');
      });
    }
  }

  function activateTool(toolId) {
    var tool = toolsConfig.find(function (t) { return t.id === toolId; });
    if (!tool) return;
    currentToolId = toolId;
    activeToolNameSpan.textContent = tool.name;
    document.querySelectorAll('.student-edutools-root .tool-item').forEach(function (item) {
      if (item.getAttribute('data-tool-id') === toolId) {
        item.classList.add('active');
      } else {
        item.classList.remove('active');
      }
    });
    if (tool.type === 'notebook') {
      renderNotebook();
      setTimeout(function () {
        notebookTextarea = document.getElementById('eduNotebookTextarea');
        if (notebookTextarea && notebookContent) notebookTextarea.value = notebookContent;
      }, 10);
    } else if (tool.type === 'external') {
      renderExternalHelper(tool);
    } else if (tool.type === 'iframe' && tool.url) {
      toolViewport.innerHTML = '<iframe src="' + escapeHtml(tool.url) + '" title="' + escapeHtml(tool.name) + '" sandbox="allow-same-origin allow-scripts allow-popups allow-forms"></iframe>';
    }
    localStorage.setItem(LAST_TOOL_KEY, toolId);
  }

  function togglePanel() {
    toolsPanel.classList.toggle('collapsed');
    localStorage.setItem(PANEL_COLLAPSED_KEY, toolsPanel.classList.contains('collapsed') ? 'true' : 'false');
  }

  function restoreState() {
    var lastTool = localStorage.getItem(LAST_TOOL_KEY);
    var panelCollapsed = localStorage.getItem(PANEL_COLLAPSED_KEY) === 'true';
    if (panelCollapsed) {
      toolsPanel.classList.add('collapsed');
    } else {
      toolsPanel.classList.remove('collapsed');
    }
    if (lastTool && toolsConfig.some(function (t) { return t.id === lastTool; })) {
      activateTool(lastTool);
    } else {
      activateTool('notebook');
    }
  }

  function handleKeydown(e) {
    if ((e.ctrlKey || e.metaKey) && (e.key === 'e' || e.key === 'E')) {
      e.preventDefault();
      togglePanel();
    }
  }

  window.addEventListener('beforeunload', function () {
    if (currentToolId === 'notebook' && notebookTextarea) {
      localStorage.setItem(NOTEBOOK_STORAGE_KEY, notebookTextarea.value);
    }
  });

  renderMenu();
  restoreState();
  toggleBtn.addEventListener('click', togglePanel);
  window.addEventListener('keydown', handleKeydown);

  setInterval(function () {
    if (currentToolId === 'notebook' && notebookTextarea && document.getElementById('eduNotebookTextarea')) {
      var fresh = notebookTextarea.value;
      localStorage.setItem(NOTEBOOK_STORAGE_KEY, fresh);
      notebookContent = fresh;
    }
  }, 2000);
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

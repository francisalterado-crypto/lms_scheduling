<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

require_role(['faculty']);

$userId = (int) ($_SESSION['user_id'] ?? 0);
$storageNs = 'u' . ($userId > 0 ? (string) $userId : '0');

$pageTitle = 'EduTools (writing & research)';
$mainContainerClass = 'container-fluid px-3 px-lg-4 py-3 py-md-4 flex-grow-1 d-flex flex-column min-w-0 faculty-edutools-page';
require_once __DIR__ . '/includes/header.php';
?>
<div class="mb-3">
    <h1 class="h4 mb-1"><i class="fa-solid fa-wand-magic-sparkles me-2 text-primary"></i>EduTools Pro</h1>
    <p class="text-muted small mb-0">Writing, AI detection, research assistants, and a personal notebook. Use the menu to pick a source; <kbd class="small">Ctrl</kbd>+<kbd class="small">E</kbd> toggles the tool panel.</p>
</div>

<div class="faculty-edutools-root" data-storage-ns="<?= htmlspecialchars($storageNs, ENT_QUOTES, 'UTF-8') ?>">
  <style>
    .faculty-edutools-root * {
      box-sizing: border-box;
    }

    .faculty-edutools-root .app-container {
      display: flex;
      width: 100%;
      position: relative;
      min-height: min(720px, calc(100vh - 14rem));
      border-radius: 12px;
      overflow: hidden;
      border: 1px solid #e2e8f0;
      background: #f1f5f9;
    }

    .faculty-edutools-root .tools-panel {
      width: 320px;
      max-width: 92vw;
      background: rgba(255, 255, 255, 0.97);
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

    .faculty-edutools-root .tools-panel.collapsed {
      transform: translateX(-100%);
      position: absolute;
      height: 100%;
      min-height: 100%;
    }

    .faculty-edutools-root .main-area {
      flex: 1;
      display: flex;
      flex-direction: column;
      overflow: hidden;
      background: #ffffff;
      transition: all 0.2s;
      min-width: 0;
      min-height: min(680px, calc(100vh - 15rem));
    }

    .faculty-edutools-root .top-bar {
      background: white;
      padding: 12px 20px;
      border-bottom: 1px solid #e9eef3;
      display: flex;
      align-items: center;
      gap: 16px;
      flex-wrap: wrap;
      box-shadow: 0 1px 2px rgba(0,0,0,0.02);
    }

    .faculty-edutools-root .menu-toggle-btn {
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

    .faculty-edutools-root .menu-toggle-btn:hover {
      background: #eef2ff;
      border-color: #94a3b8;
    }

    .faculty-edutools-root .active-badge {
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

    .faculty-edutools-root .active-badge span:first-child {
      font-weight: 400;
      color: #475569;
    }

    .faculty-edutools-root .key-hint {
      background: #e2e8f0;
      padding: 2px 8px;
      border-radius: 20px;
      font-family: ui-monospace, monospace;
      font-size: 0.75rem;
      color: #0f172a;
    }

    .faculty-edutools-root .tool-viewport {
      flex: 1;
      position: relative;
      background: #fefefe;
      overflow: auto;
      min-height: 0;
    }

    .faculty-edutools-root iframe {
      width: 100%;
      height: 100%;
      min-height: 420px;
      border: none;
      display: block;
    }

    .faculty-edutools-root .notebook-container {
      padding: 24px 28px;
      height: 100%;
      overflow-y: auto;
      background: #fefcf5;
    }

    .faculty-edutools-root .notebook-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 12px;
      margin-bottom: 28px;
      border-bottom: 2px solid #e2e8f0;
      padding-bottom: 16px;
    }

    .faculty-edutools-root .notebook-header h2 {
      font-size: 1.6rem;
      font-weight: 600;
      background: linear-gradient(135deg, #0f172a, #334155);
      background-clip: text;
      -webkit-background-clip: text;
      color: transparent;
      margin: 0;
    }

    .faculty-edutools-root .note-actions {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }

    .faculty-edutools-root .note-btn {
      background: white;
      border: 1px solid #cbd5e1;
      padding: 6px 14px;
      border-radius: 30px;
      font-weight: 500;
      font-size: 0.8rem;
      cursor: pointer;
      transition: 0.2s;
    }

    .faculty-edutools-root .note-btn:hover {
      background: #f1f5f9;
    }

    .faculty-edutools-root textarea.notepad-textarea {
      width: 100%;
      min-height: 55vh;
      padding: 20px;
      font-family: 'Courier New', ui-monospace, monospace;
      font-size: 1rem;
      line-height: 1.5;
      border: 1px solid #e2e8f0;
      border-radius: 20px;
      background: #ffffff;
      resize: vertical;
    }

    .faculty-edutools-root .status-msg {
      font-size: 0.75rem;
      margin-top: 12px;
      color: #2d6a4f;
    }

    .faculty-edutools-root .menu-header {
      padding: 20px 20px 6px 20px;
      font-weight: 700;
      font-size: 0.7rem;
      letter-spacing: 1px;
      color: #5b6e8c;
      text-transform: uppercase;
    }

    .faculty-edutools-root .tool-list {
      list-style: none;
      margin: 4px 12px 12px 12px;
      padding: 0;
    }

    .faculty-edutools-root .tool-item {
      display: flex;
      align-items: center;
      gap: 14px;
      padding: 10px 14px;
      margin: 2px 0;
      border-radius: 14px;
      cursor: pointer;
      transition: all 0.15s;
      font-weight: 500;
      color: #1e293b;
    }

    .faculty-edutools-root .tool-item:hover {
      background: #f1f5f9;
    }

    .faculty-edutools-root .tool-item.active {
      background: #eef2ff;
      color: #1e40af;
      border-left: 3px solid #3b82f6;
    }

    .faculty-edutools-root .tool-icon {
      font-size: 1.3rem;
      line-height: 1;
      min-width: 32px;
    }

    .faculty-edutools-root .tool-name {
      flex: 1;
      font-size: 0.9rem;
    }

    .faculty-edutools-root .badge-external {
      font-size: 0.65rem;
      background: #e2e8f0;
      padding: 2px 8px;
      border-radius: 30px;
      color: #334155;
    }

    .faculty-edutools-root .edutools-hr {
      margin: 8px 16px;
      border: 0;
      border-top: 1px solid #edf2f7;
    }

    .faculty-edutools-root .shortcut-toast {
      position: absolute;
      bottom: 12px;
      right: 12px;
      background: #0f172ad9;
      backdrop-filter: blur(8px);
      color: white;
      padding: 6px 12px;
      border-radius: 40px;
      font-size: 0.7rem;
      font-family: ui-monospace, monospace;
      pointer-events: none;
      z-index: 25;
      max-width: 90%;
    }

    @media (max-width: 680px) {
      .faculty-edutools-root .tools-panel {
        width: 280px;
      }
      .faculty-edutools-root .notebook-container {
        padding: 16px;
      }
    }
  </style>

  <div class="app-container">
    <aside class="tools-panel" id="facToolsPanel" aria-label="Education sources and tools">
      <div style="padding: 20px 20px 0 20px;">
        <h2 style="font-size: 1.5rem; font-weight: 700; margin: 0; background: linear-gradient(145deg, #0f2b3d, #1e4a76); background-clip: text; -webkit-background-clip: text; color: transparent;">EduTools Pro</h2>
        <p style="font-size: 0.7rem; color: #4b5563; margin-top: 4px;">AI detector · Writing · Research · Education sources</p>
      </div>
      <div class="menu-header">Writing &amp; academic</div>
      <ul class="tool-list" id="facWritingToolsList"></ul>
      <div class="menu-header">AI &amp; research</div>
      <ul class="tool-list" id="facAiResearchList"></ul>
      <div class="menu-header">Notebooks</div>
      <ul class="tool-list" id="facNotebooksList"></ul>
      <hr class="edutools-hr" />
      <div style="padding: 12px 20px 20px 20px; font-size: 0.7rem; color: #6c757d;">
        <kbd>Ctrl</kbd> + <kbd>E</kbd> toggles this panel
      </div>
    </aside>

    <div class="main-area">
      <div class="top-bar">
        <button type="button" class="menu-toggle-btn" id="facTogglePanelBtn"<?= app_tooltip_attr('Shows or hides the EduTools sidebar. Same as Ctrl+E.') ?>>
          <span aria-hidden="true">☰</span> Menu
        </button>
        <div class="active-badge" id="facActiveToolBadge">
          <span>Active:</span> <strong id="facActiveToolName">Notebook</strong>
        </div>
        <div class="ms-auto d-flex gap-2 align-items-center">
          <span class="key-hint">Ctrl+E</span>
        </div>
      </div>
      <div class="tool-viewport position-relative" id="facToolViewportOuter">
        <div id="facDynamicContent"></div>
        <div class="shortcut-toast" aria-hidden="true">Ctrl+E sidebar · AI Detector · Grammarly · QuillBot · Turnitin</div>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  const root = document.querySelector('.faculty-edutools-root');
  if (!root) return;
  const ns = root.getAttribute('data-storage-ns') || 'guest';

  const NOTEBOOK_STORAGE_KEY = 'faculty_edu_' + ns + '_notebook_content';
  const LAST_TOOL_KEY = 'faculty_edu_' + ns + '_last_tool';
  const PANEL_COLLAPSED_KEY = 'faculty_edu_' + ns + '_panel_collapsed';

  const toolsConfig = [
    { id: 'turnitin', name: 'Turnitin', icon: '\uD83D\uDD0D', type: 'external', url: 'https://www.turnitin.com', openNewTab: true, description: 'Plagiarism detection & feedback studio', category: 'writing' },
    { id: 'grammarly', name: 'Grammarly', icon: '\u270D\uFE0F', type: 'external', url: 'https://www.grammarly.com', openNewTab: true, description: 'Grammar checker & writing assistant', category: 'writing' },
    { id: 'quillbot', name: 'QuillBot', icon: '\uD83E\uDD9C', type: 'external', url: 'https://quillbot.com', openNewTab: true, description: 'Paraphraser, summarizer & grammar', category: 'writing' },
    { id: 'aidetector', name: 'Free AI Detector', icon: '\uD83E\uDD16', type: 'external', url: 'https://www.aidetector.com', openNewTab: true, description: 'Check if text is AI-generated (ChatGPT, GPT-4)', category: 'writing' },
    { id: 'chatgpt', name: 'ChatGPT', icon: '\uD83D\uDCAC', type: 'external', url: 'https://chat.openai.com', openNewTab: true, description: 'OpenAI conversational AI', category: 'ai' },
    { id: 'perplexity', name: 'Perplexity AI', icon: '\uD83D\uDD0D', type: 'external', url: 'https://www.perplexity.ai', openNewTab: true, description: 'AI-powered search & answers', category: 'ai' },
    { id: 'notebooklm', name: 'NotebookLM', icon: '\uD83E\uDDE0', type: 'external', url: 'https://notebooklm.google.com', openNewTab: true, description: 'Google AI research assistant', category: 'ai' },
    { id: 'wolfram', name: 'Wolfram Alpha', icon: '\uD83E\uDDEE', type: 'external', url: 'https://www.wolframalpha.com', openNewTab: true, description: 'Computational knowledge engine', category: 'ai' },
    { id: 'notebook', name: 'Built-in notebook', icon: '\uD83D\uDCD8', type: 'notebook', url: null, openNewTab: false, category: 'notebooks' },
    { id: 'notion', name: 'Notion', icon: '\uD83D\uDCDD', type: 'external', url: 'https://www.notion.so', openNewTab: true, description: 'All-in-one workspace', category: 'notebooks' },
    { id: 'khan', name: 'Khan Academy', icon: '\uD83D\uDCDA', type: 'external', url: 'https://www.khanacademy.org', openNewTab: true, description: 'Free courses & practice', category: 'notebooks' }
  ];

  let currentToolId = 'notebook';
  let notebookTextarea = null;
  let notebookContent = '';

  const toolViewport = document.getElementById('facDynamicContent');
  const toolsPanel = document.getElementById('facToolsPanel');
  const toggleBtn = document.getElementById('facTogglePanelBtn');
  const activeToolNameSpan = document.getElementById('facActiveToolName');
  const writingToolsList = document.getElementById('facWritingToolsList');
  const aiResearchList = document.getElementById('facAiResearchList');
  const notebooksList = document.getElementById('facNotebooksList');

  if (!toolViewport || !toolsPanel || !toggleBtn || !activeToolNameSpan || !writingToolsList || !aiResearchList || !notebooksList) return;

  function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  function createMenuItem(tool) {
    const li = document.createElement('li');
    li.className = 'tool-item' + (currentToolId === tool.id ? ' active' : '');
    li.setAttribute('data-tool-id', tool.id);
    li.innerHTML =
      '<span class="tool-icon">' + tool.icon + '</span>' +
      '<span class="tool-name">' + escapeHtml(tool.name) + '</span>' +
      '<span class="badge-external">' + (tool.openNewTab ? '\u2197\uFE0F' : '\uD83D\uDCD3') + '</span>';
    li.addEventListener('click', function (e) {
      e.stopPropagation();
      activateTool(tool.id);
    });
    return li;
  }

  function renderMenu() {
    writingToolsList.innerHTML = '';
    aiResearchList.innerHTML = '';
    notebooksList.innerHTML = '';
    toolsConfig.filter(function (t) { return t.category === 'writing'; }).forEach(function (tool) {
      writingToolsList.appendChild(createMenuItem(tool));
    });
    toolsConfig.filter(function (t) { return t.category === 'ai'; }).forEach(function (tool) {
      aiResearchList.appendChild(createMenuItem(tool));
    });
    toolsConfig.filter(function (t) { return t.category === 'notebooks'; }).forEach(function (tool) {
      notebooksList.appendChild(createMenuItem(tool));
    });
  }

  function renderNotebook() {
    const saved = localStorage.getItem(NOTEBOOK_STORAGE_KEY) ||
      '# Faculty notebook\n\nDraft syllabi, track sources, or paste text before checking with external tools.\n\n## Quick links\n- Turnitin, Grammarly, QuillBot, Free AI Detector (menu)\n- ChatGPT, Perplexity, NotebookLM (menu)\n\n---\n### Notes\n';
    notebookContent = saved;
    toolViewport.innerHTML =
      '<div class="notebook-container">' +
        '<div class="notebook-header">' +
          '<h2>Smart notebook</h2>' +
          '<div class="note-actions">' +
            '<button type="button" id="facExportMdBtn" class="note-btn">Export .md</button>' +
            '<button type="button" id="facExportTxtBtn" class="note-btn">Export .txt</button>' +
            '<button type="button" id="facClearNotebookBtn" class="note-btn">Clear</button>' +
          '</div>' +
        '</div>' +
        '<textarea id="facNotebookTextarea" class="notepad-textarea" placeholder="Write notes, paste drafts for checking..."></textarea>' +
        '<div class="status-msg" id="facNoteStatus">Auto-saves locally in this browser</div>' +
      '</div>';

    const textarea = document.getElementById('facNotebookTextarea');
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
          var statusDiv = document.getElementById('facNoteStatus');
          if (statusDiv) {
            statusDiv.textContent = 'Saved at ' + new Date().toLocaleTimeString();
            setTimeout(function () {
              if (statusDiv) statusDiv.textContent = 'Auto-save active';
            }, 1500);
          }
        }, 500);
      });
    }
    var exportMd = document.getElementById('facExportMdBtn');
    var exportTxt = document.getElementById('facExportTxtBtn');
    var clearBtn = document.getElementById('facClearNotebookBtn');
    if (exportMd) exportMd.addEventListener('click', function () { exportNotebook('md'); });
    if (exportTxt) exportTxt.addEventListener('click', function () { exportNotebook('txt'); });
    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        if (confirm('Clear all notebook content?')) {
          if (notebookTextarea) notebookTextarea.value = '';
          localStorage.setItem(NOTEBOOK_STORAGE_KEY, '');
          notebookContent = '';
          var statusDiv = document.getElementById('facNoteStatus');
          if (statusDiv) statusDiv.textContent = 'Cleared.';
        }
      });
    }
  }

  function exportNotebook(format) {
    var content = notebookTextarea ? notebookTextarea.value : (localStorage.getItem(NOTEBOOK_STORAGE_KEY) || '');
    var blob = new Blob([content], { type: format === 'md' ? 'text/markdown' : 'text/plain' });
    var link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'faculty_notebook_export.' + (format === 'md' ? 'md' : 'txt');
    link.click();
    URL.revokeObjectURL(link.href);
  }

  function externalHelperMeta(tool) {
    var specialNote = '';
    var accentColor = '#0f172a';
    var gradientBg = '#fafcff';
    if (tool.id === 'turnitin') {
      specialNote = 'Turnitin is widely used by universities. Check papers for originality before submitting. (Institutional login may be required.)';
      accentColor = '#d32f2f';
      gradientBg = '#fff5f5';
    } else if (tool.id === 'grammarly') {
      specialNote = 'Grammarly catches grammar mistakes, enhances tone, and checks clarity. Free and premium plans available.';
      accentColor = '#24c0b0';
      gradientBg = '#f0fdfa';
    } else if (tool.id === 'quillbot') {
      specialNote = 'QuillBot offers paraphrasing, summarizer, grammar checker, and citation generator. Useful for rewording sentences.';
      accentColor = '#6f42c1';
      gradientBg = '#f8f3ff';
    } else if (tool.id === 'aidetector') {
      specialNote = 'Paste text to see if it may be AI-generated. Useful as one signal among many for academic integrity.';
      accentColor = '#e67e22';
      gradientBg = '#fff8f0';
    } else if (tool.id === 'notebooklm') {
      specialNote = "Google's AI research notebook: upload sources (PDF, docs, websites) and get summaries, Q&A, and study guides.";
      accentColor = '#8b5cf6';
      gradientBg = '#f5f0ff';
    } else if (tool.id === 'chatgpt') {
      specialNote = 'Conversational AI for brainstorming, writing, coding, and research. Sign in with Google, Microsoft, or email.';
      accentColor = '#10a37f';
      gradientBg = '#f0fdf4';
    } else if (tool.id === 'perplexity') {
      specialNote = 'AI search with cited sources. Ask questions and get answers with references.';
      accentColor = '#1a56db';
      gradientBg = '#eff6ff';
    } else if (tool.id === 'wolfram') {
      specialNote = 'Computational answers across math, science, and data. Strong for formulas and facts.';
      accentColor = '#dd1100';
      gradientBg = '#fff8f5';
    } else if (tool.id === 'notion') {
      specialNote = 'Notes, wikis, and project boards in one place. Good for course planning and collaboration.';
      accentColor = '#000000';
      gradientBg = '#f7f7f5';
    } else if (tool.id === 'khan') {
      specialNote = 'Free lessons and practice across many subjects. Useful to recommend or review fundamentals.';
      accentColor = '#14bf96';
      gradientBg = '#f0fdf9';
    }
    return { specialNote: specialNote, accentColor: accentColor, gradientBg: gradientBg };
  }

  function renderExternalHelper(tool) {
    var meta = externalHelperMeta(tool);
    var specialNote = meta.specialNote;
    var accentColor = meta.accentColor;
    var gradientBg = meta.gradientBg;
    toolViewport.innerHTML =
      '<div style="display:flex;align-items:center;justify-content:center;min-height:100%;background:' + gradientBg + ';padding:2rem;">' +
        '<div style="max-width:560px;text-align:center;background:white;border-radius:2rem;padding:2rem;box-shadow:0 20px 35px rgba(0,0,0,0.1);border:1px solid #eef2ff;">' +
          '<div style="font-size:4rem;line-height:1;">' + tool.icon + '</div>' +
          '<h2 style="margin:1rem 0 0.5rem;color:' + accentColor + ';font-size:1.35rem;">' + escapeHtml(tool.name) + '</h2>' +
          '<p style="color:#4b5563;margin-bottom:0.5rem;">' + escapeHtml(tool.description || 'Academic & writing assistant') + '</p>' +
          (specialNote
            ? '<div style="background:' + gradientBg + ';padding:12px;border-radius:16px;margin:16px 0;font-size:0.85rem;color:#2d3e50;border-left:4px solid ' + accentColor + ';text-align:left;">' + escapeHtml(specialNote) + '</div>'
            : '') +
          '<button type="button" id="facOpenExternalBtn" style="background:' + accentColor + ';color:white;border:none;padding:12px 32px;border-radius:60px;font-weight:600;cursor:pointer;font-size:1rem;margin:8px 0;">Open ' + escapeHtml(tool.name) + ' \u2192</button>' +
          '<p style="margin-top:20px;font-size:0.7rem;color:#6c757d;">Opens in a new tab. Some tools require institutional access or a free account.</p>' +
        '</div>' +
      '</div>';
    var openBtn = document.getElementById('facOpenExternalBtn');
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
    document.querySelectorAll('.faculty-edutools-root .tool-item').forEach(function (item) {
      if (item.getAttribute('data-tool-id') === toolId) {
        item.classList.add('active');
      } else {
        item.classList.remove('active');
      }
    });
    if (tool.type === 'notebook') {
      renderNotebook();
      setTimeout(function () {
        notebookTextarea = document.getElementById('facNotebookTextarea');
        if (notebookTextarea && notebookContent) notebookTextarea.value = notebookContent;
      }, 10);
    } else if (tool.type === 'external') {
      renderExternalHelper(tool);
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
    if (currentToolId === 'notebook' && notebookTextarea && document.getElementById('facNotebookTextarea')) {
      var fresh = notebookTextarea.value;
      localStorage.setItem(NOTEBOOK_STORAGE_KEY, fresh);
      notebookContent = fresh;
    }
  }, 2000);
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

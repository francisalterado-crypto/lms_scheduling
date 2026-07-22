(function () {
  'use strict';

  var DB_NAME = 'class_student_offline';
  var DB_VERSION = 1;
  var STORE = 'packs';
  var META_KEY = 'latest';
  var API_URL = 'api/student_offline_pack.php';

  function openDb() {
    return new Promise(function (resolve, reject) {
      if (!window.indexedDB) {
        reject(new Error('This browser does not support offline storage.'));
        return;
      }
      var req = indexedDB.open(DB_NAME, DB_VERSION);
      req.onupgradeneeded = function () {
        var db = req.result;
        if (!db.objectStoreNames.contains(STORE)) {
          db.createObjectStore(STORE, { keyPath: 'key' });
        }
      };
      req.onsuccess = function () {
        resolve(req.result);
      };
      req.onerror = function () {
        reject(req.error || new Error('Could not open offline storage.'));
      };
    });
  }

  function idbPut(record) {
    return openDb().then(function (db) {
      return new Promise(function (resolve, reject) {
        var tx = db.transaction(STORE, 'readwrite');
        tx.objectStore(STORE).put(record);
        tx.oncomplete = function () {
          db.close();
          resolve(record);
        };
        tx.onerror = function () {
          db.close();
          reject(tx.error || new Error('Could not save offline pack.'));
        };
      });
    });
  }

  function idbGet(key) {
    return openDb().then(function (db) {
      return new Promise(function (resolve, reject) {
        var tx = db.transaction(STORE, 'readonly');
        var req = tx.objectStore(STORE).get(key);
        req.onsuccess = function () {
          db.close();
          resolve(req.result || null);
        };
        req.onerror = function () {
          db.close();
          reject(req.error || new Error('Could not read offline pack.'));
        };
      });
    });
  }

  function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) {
      return Promise.resolve(null);
    }
    return navigator.serviceWorker.register('sw-student-offline.js').catch(function () {
      return null;
    });
  }

  function fetchPack(classroomId) {
    var url = API_URL;
    if (classroomId && Number(classroomId) > 0) {
      url += '?classroom_id=' + encodeURIComponent(String(classroomId));
    }
    return fetch(url, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    }).then(function (res) {
      return res.json().then(function (body) {
        if (!res.ok || !body || !body.ok) {
          throw new Error((body && body.error) || 'Could not download offline pack.');
        }
        return body;
      });
    });
  }

  function mergeClassrooms(existing, incoming) {
    var map = {};
    (existing || []).forEach(function (c) {
      map[String(c.id)] = c;
    });
    (incoming || []).forEach(function (c) {
      map[String(c.id)] = c;
    });
    return Object.keys(map)
      .map(function (k) {
        return map[k];
      })
      .sort(function (a, b) {
        return String(a.course_code || '').localeCompare(String(b.course_code || ''));
      });
  }

  function savePack(pack, options) {
    options = options || {};
    var merge = !!options.merge;
    return idbGet(META_KEY).then(function (prev) {
      var classrooms = merge && prev && prev.data
        ? mergeClassrooms(prev.data.classrooms || [], pack.classrooms || [])
        : pack.classrooms || [];
      var itemCount = classrooms.reduce(function (sum, c) {
        return sum + (Number(c.item_count) || 0);
      }, 0);
      var record = {
        key: META_KEY,
        saved_at: pack.saved_at || new Date().toISOString(),
        data: {
          ok: true,
          saved_at: pack.saved_at || new Date().toISOString(),
          student_id: pack.student_id || null,
          classroom_count: classrooms.length,
          item_count: itemCount,
          classrooms: classrooms,
          note: pack.note || '',
        },
      };
      return idbPut(record).then(function () {
        return registerServiceWorker().then(function () {
          return record.data;
        });
      });
    });
  }

  function loadPack() {
    return idbGet(META_KEY).then(function (row) {
      return row && row.data ? row.data : null;
    });
  }

  function downloadAndSave(classroomId, options) {
    return fetchPack(classroomId).then(function (pack) {
      return savePack(pack, options);
    });
  }

  function setStatus(el, message, kind) {
    if (!el) return;
    el.textContent = message || '';
    el.classList.remove('text-success', 'text-danger', 'text-muted', 'text-primary');
    if (kind === 'ok') el.classList.add('text-success');
    else if (kind === 'err') el.classList.add('text-danger');
    else if (kind === 'busy') el.classList.add('text-primary');
    else el.classList.add('text-muted');
  }

  function bindControls(root) {
    root = root || document;
    var statusEl = root.querySelector('[data-offline-status]');
    var buttons = root.querySelectorAll('[data-offline-save]');
    buttons.forEach(function (btn) {
      if (btn.dataset.offlineBound === '1') return;
      btn.dataset.offlineBound = '1';
      btn.addEventListener('click', function () {
        var classroomId = btn.getAttribute('data-offline-save') || '';
        var merge = btn.getAttribute('data-offline-merge') === '1';
        var original = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Saving…';
        setStatus(statusEl, 'Downloading faculty items for offline reading…', 'busy');
        downloadAndSave(classroomId, { merge: merge })
          .then(function (data) {
            var msg =
              'Saved offline: ' +
              (data.classroom_count || 0) +
              ' class(es), ' +
              (data.item_count || 0) +
              ' item(s).';
            setStatus(statusEl, msg, 'ok');
            btn.innerHTML = '<i class="fa-solid fa-check me-1"></i>Saved';
            window.setTimeout(function () {
              btn.disabled = false;
              btn.innerHTML = original;
            }, 1600);
          })
          .catch(function (err) {
            setStatus(statusEl, err && err.message ? err.message : 'Save failed.', 'err');
            btn.disabled = false;
            btn.innerHTML = original;
          });
      });
    });

    loadPack()
      .then(function (data) {
        if (!data || !statusEl) return;
        if (statusEl.getAttribute('data-offline-status') === 'auto') {
          setStatus(
            statusEl,
            'Offline copy ready: ' +
              (data.classroom_count || 0) +
              ' class(es), ' +
              (data.item_count || 0) +
              ' item(s). Last saved ' +
              formatWhen(data.saved_at) +
              '.',
            'ok'
          );
        }
      })
      .catch(function () {});
  }

  function formatWhen(iso) {
    if (!iso) return '—';
    var d = new Date(iso);
    if (Number.isNaN(d.getTime())) return String(iso);
    return d.toLocaleString();
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function renderItem(item) {
    var html = '<article class="border rounded-3 p-3 mb-3 offline-item">';
    html += '<div class="d-flex justify-content-between gap-2 flex-wrap">';
    html += '<h3 class="h6 mb-1">' + escapeHtml(item.title || 'Untitled') + '</h3>';
    html +=
      '<span class="badge text-bg-secondary">' +
      escapeHtml(item.content_type || 'material') +
      '</span>';
    html += '</div>';
    if (item.weeks || item.days_per_topic) {
      html += '<div class="small text-muted mb-2">';
      if (item.weeks) {
        html += '<span class="me-2"><i class="fa-regular fa-calendar me-1"></i>Weeks: ' + escapeHtml(item.weeks) + '</span>';
      }
      if (item.days_per_topic) {
        html +=
          '<span><i class="fa-regular fa-clock me-1"></i>Days/topic: ' +
          escapeHtml(item.days_per_topic) +
          '</span>';
      }
      html += '</div>';
    }
    if (item.body_html) {
      html += '<div class="classroom-content-body small">' + item.body_html + '</div>';
    }
    if (item.resource) {
      if (item.resource.is_attachment) {
        html +=
          '<div class="small mt-2 text-muted"><i class="fa-solid fa-paperclip me-1"></i>' +
          escapeHtml(item.resource.label || 'Attachment') +
          ' <em>(open online to download file)</em></div>';
      } else {
        html +=
          '<div class="small mt-2"><a href="' +
          escapeHtml(item.resource.href || item.resource.url) +
          '" target="_blank" rel="noopener noreferrer">Open resource</a></div>';
      }
    }
    (item.attachments || []).forEach(function (att) {
      html +=
        '<div class="small mt-2 text-muted"><i class="fa-solid fa-paperclip me-1"></i>' +
        escapeHtml(att.original_name || 'Attachment') +
        ' <em>(open online to download file)</em></div>';
    });
    if (item.created_at) {
      html += '<div class="small text-muted mt-2">' + escapeHtml(item.created_at) + '</div>';
    }
    html += '</article>';
    return html;
  }

  function groupMaterialsByWeek(materials) {
    var groups = {};
    var order = [];
    (materials || []).forEach(function (item) {
      var label = item.week_label || item.weeks || 'General resources';
      if (!groups[label]) {
        groups[label] = [];
        order.push(label);
      }
      groups[label].push(item);
    });
    return order.map(function (label) {
      return { label: label, items: groups[label] };
    });
  }

  function courseGroupKey(classroom) {
    var code = String(classroom && classroom.course_code ? classroom.course_code : '').trim();
    var name = String(classroom && classroom.course_name ? classroom.course_name : '').trim();
    if (code || name) {
      return code + '|' + name;
    }
    return 'classroom:' + String(classroom && classroom.id ? classroom.id : 'unknown');
  }

  function courseGroupHeading(classroom) {
    var code = String(classroom && classroom.course_code ? classroom.course_code : '').trim();
    var name = String(classroom && classroom.course_name ? classroom.course_name : '').trim();
    if (code && name) {
      return code + ' — ' + name;
    }
    return code || name || classroom.title || 'Course';
  }

  function groupClassroomsByCourse(classrooms) {
    var groups = {};
    var order = [];
    (classrooms || []).forEach(function (classroom) {
      var key = courseGroupKey(classroom);
      if (!groups[key]) {
        groups[key] = {
          key: key,
          heading: courseGroupHeading(classroom),
          course_code: classroom.course_code || '',
          course_name: classroom.course_name || '',
          classrooms: [],
          item_count: 0,
        };
        order.push(key);
      }
      groups[key].classrooms.push(classroom);
      groups[key].item_count += Number(classroom.item_count) || 0;
      if (!classroom.item_count) {
        groups[key].item_count +=
          (classroom.announcements || []).length + (classroom.materials || []).length;
      }
    });
    return order.map(function (key) {
      return groups[key];
    });
  }

  function collectCourseReviewItems(courseGroup) {
    var entries = [];
    (courseGroup.classrooms || []).forEach(function (classroom) {
      var classTitle = classroom.title || 'Classroom';
      (classroom.announcements || []).forEach(function (item) {
        entries.push({
          classroom_title: classTitle,
          section: 'Announcements',
          item: item,
        });
      });
      groupMaterialsByWeek(classroom.materials || []).forEach(function (week) {
        week.items.forEach(function (item) {
          entries.push({
            classroom_title: classTitle,
            section: week.label,
            item: item,
          });
        });
      });
    });
    return entries;
  }

  function renderCourseBox(courseGroup, index) {
    var entries = collectCourseReviewItems(courseGroup);
    var itemCount = entries.length;
    var classCount = (courseGroup.classrooms || []).length;
    var html = '<div class="card shadow-sm mb-3 offline-course-box" data-course-index="' + escapeHtml(String(index)) + '">';
    html +=
      '<button type="button" class="offline-course-open btn btn-link text-start text-decoration-none text-body w-100 p-0" aria-expanded="false">';
    html += '<div class="card-body d-flex justify-content-between gap-3 align-items-center">';
    html += '<div class="min-w-0">';
    html +=
      '<div class="fw-semibold mb-1"><i class="fa-solid fa-book-open me-2 text-primary"></i>' +
      escapeHtml(courseGroup.heading) +
      '</div>';
    html +=
      '<div class="small text-muted">' +
      escapeHtml(String(classCount)) +
      ' class' +
      (classCount === 1 ? '' : 'es') +
      ' · ' +
      escapeHtml(String(itemCount)) +
      ' item' +
      (itemCount === 1 ? '' : 's') +
      ' · click to open</div>';
    html += '</div>';
    html +=
      '<span class="offline-course-chevron badge text-bg-light border text-muted flex-shrink-0"><i class="fa-solid fa-chevron-down"></i></span>';
    html += '</div></button>';

    html += '<div class="offline-course-panel border-top d-none">';
    html += '<div class="card-body">';
    if (!itemCount) {
      html += '<p class="small text-muted mb-0">No announcements or materials saved for this course.</p>';
    } else {
      html += '<p class="small text-muted mb-2">Choose an item to review:</p>';
      html += '<div class="list-group mb-3 offline-item-chooser">';
      entries.forEach(function (entry, itemIndex) {
        var item = entry.item || {};
        html +=
          '<button type="button" class="list-group-item list-group-item-action offline-choose-item" data-item-index="' +
          escapeHtml(String(itemIndex)) +
          '">';
        html += '<div class="d-flex justify-content-between gap-2 align-items-start">';
        html += '<div class="min-w-0">';
        html += '<div class="fw-semibold">' + escapeHtml(item.title || 'Untitled') + '</div>';
        html +=
          '<div class="small text-muted mt-1">' +
          escapeHtml(entry.classroom_title || '') +
          ' · ' +
          escapeHtml(entry.section || '') +
          (item.content_type ? ' · ' + escapeHtml(item.content_type) : '') +
          '</div>';
        html += '</div>';
        html += '<i class="fa-solid fa-angle-right text-muted flex-shrink-0 mt-1"></i>';
        html += '</div></button>';
      });
      html += '</div>';
      html +=
        '<div class="offline-review-pane border rounded-3 p-3 bg-light d-none" data-review-pane>' +
        '<p class="small text-muted mb-0">Select an item above to review it here.</p>' +
        '</div>';
    }
    html += '</div></div></div>';
    return { html: html, entries: entries };
  }

  function bindOfflineReader(mount, courseEntries) {
    if (!mount) return;

    mount.querySelectorAll('.offline-course-box').forEach(function (box) {
      var openBtn = box.querySelector('.offline-course-open');
      var panel = box.querySelector('.offline-course-panel');
      var chevron = box.querySelector('.offline-course-chevron i');
      var reviewPane = box.querySelector('[data-review-pane]');
      var courseIndex = parseInt(box.getAttribute('data-course-index') || '-1', 10);
      var entries = courseEntries[courseIndex] || [];

      if (openBtn && panel) {
        openBtn.addEventListener('click', function () {
          var willOpen = panel.classList.contains('d-none');
          // Close other course boxes so only one is open at a time.
          mount.querySelectorAll('.offline-course-box').forEach(function (other) {
            if (other === box) return;
            var otherPanel = other.querySelector('.offline-course-panel');
            var otherBtn = other.querySelector('.offline-course-open');
            var otherIcon = other.querySelector('.offline-course-chevron i');
            if (otherPanel) otherPanel.classList.add('d-none');
            if (otherBtn) otherBtn.setAttribute('aria-expanded', 'false');
            if (otherIcon) {
              otherIcon.classList.remove('fa-chevron-up');
              otherIcon.classList.add('fa-chevron-down');
            }
          });

          if (willOpen) {
            panel.classList.remove('d-none');
            openBtn.setAttribute('aria-expanded', 'true');
            if (chevron) {
              chevron.classList.remove('fa-chevron-down');
              chevron.classList.add('fa-chevron-up');
            }
          } else {
            panel.classList.add('d-none');
            openBtn.setAttribute('aria-expanded', 'false');
            if (chevron) {
              chevron.classList.remove('fa-chevron-up');
              chevron.classList.add('fa-chevron-down');
            }
          }
        });
      }

      box.querySelectorAll('.offline-choose-item').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var itemIndex = parseInt(btn.getAttribute('data-item-index') || '-1', 10);
          var entry = entries[itemIndex];
          if (!entry || !reviewPane) return;

          box.querySelectorAll('.offline-choose-item').forEach(function (row) {
            row.classList.remove('active');
          });
          btn.classList.add('active');

          reviewPane.classList.remove('d-none', 'bg-light');
          reviewPane.classList.add('bg-white');
          reviewPane.innerHTML =
            '<div class="d-flex justify-content-between align-items-center gap-2 mb-2">' +
            '<div class="small text-muted mb-0">Reviewing</div>' +
            '<button type="button" class="btn btn-sm btn-outline-secondary offline-clear-review">Close</button>' +
            '</div>' +
            renderItem(entry.item);

          var clearBtn = reviewPane.querySelector('.offline-clear-review');
          if (clearBtn) {
            clearBtn.addEventListener('click', function () {
              btn.classList.remove('active');
              reviewPane.classList.add('d-none', 'bg-light');
              reviewPane.classList.remove('bg-white');
              reviewPane.innerHTML =
                '<p class="small text-muted mb-0">Select an item above to review it here.</p>';
            });
          }

          reviewPane.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });
      });
    });
  }

  function renderOfflineReader(mount) {
    if (!mount) return;
    mount.innerHTML = '<p class="text-muted">Loading offline copy…</p>';
    loadPack()
      .then(function (data) {
        if (!data || !(data.classrooms || []).length) {
          mount.innerHTML =
            '<div class="alert alert-warning mb-0">No offline copy yet. Go online, open <strong>My classes</strong>, and tap <strong>Save for offline</strong>.</div>';
          return;
        }

        var online = navigator.onLine;
        var html = '';
        html += '<div class="alert ' + (online ? 'alert-success' : 'alert-info') + '">';
        html += online
          ? '<i class="fa-solid fa-wifi me-1"></i>You are online. Showing your saved offline copy.'
          : '<i class="fa-solid fa-plane me-1"></i>You are offline. Reading saved faculty items.';
        html +=
          '<div class="small mt-1">Last saved: ' +
          escapeHtml(formatWhen(data.saved_at)) +
          ' · ' +
          escapeHtml(String(data.classroom_count || 0)) +
          ' class(es) · ' +
          escapeHtml(String(data.item_count || 0)) +
          ' item(s)</div>';
        if (data.note) {
          html += '<div class="small mt-1">' + escapeHtml(data.note) + '</div>';
        }
        html += '</div>';

        html +=
          '<p class="small text-muted mb-3">Click a course box to see its items, then choose one to review.</p>';

        var courseEntries = [];
        groupClassroomsByCourse(data.classrooms).forEach(function (courseGroup, index) {
          var built = renderCourseBox(courseGroup, index);
          html += built.html;
          courseEntries[index] = built.entries;
        });

        mount.innerHTML = html;
        bindOfflineReader(mount, courseEntries);
      })
      .catch(function (err) {
        mount.innerHTML =
          '<div class="alert alert-danger mb-0">' +
          escapeHtml(err && err.message ? err.message : 'Could not load offline copy.') +
          '</div>';
      });
  }

  window.StudentOffline = {
    registerServiceWorker: registerServiceWorker,
    downloadAndSave: downloadAndSave,
    loadPack: loadPack,
    bindControls: bindControls,
    renderOfflineReader: renderOfflineReader,
    formatWhen: formatWhen,
  };

  document.addEventListener('DOMContentLoaded', function () {
    registerServiceWorker();
    bindControls(document);
    var mount = document.querySelector('[data-offline-reader]');
    if (mount) {
      renderOfflineReader(mount);
    }
  });
})();

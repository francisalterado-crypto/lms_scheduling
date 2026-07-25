(function () {
  'use strict';

  var DB_NAME = 'class_faculty_offline';
  var DB_VERSION = 1;
  var STORE = 'queue';
  var API_URL = 'api/faculty_offline_upload.php';
  var MAX_FILE_BYTES = 10 * 1024 * 1024;
  var syncing = false;

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
          db.createObjectStore(STORE, { keyPath: 'id' });
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

  function withStore(mode, fn) {
    return openDb().then(function (db) {
      return new Promise(function (resolve, reject) {
        var tx = db.transaction(STORE, mode);
        var store = tx.objectStore(STORE);
        var result;
        try {
          result = fn(store);
        } catch (err) {
          db.close();
          reject(err);
          return;
        }
        tx.oncomplete = function () {
          db.close();
          resolve(result);
        };
        tx.onerror = function () {
          db.close();
          reject(tx.error || new Error('Offline storage error.'));
        };
      });
    });
  }

  function idbPut(record) {
    return withStore('readwrite', function (store) {
      store.put(record);
      return record;
    });
  }

  function idbDelete(id) {
    return withStore('readwrite', function (store) {
      store.delete(id);
    });
  }

  function listQueue() {
    return openDb().then(function (db) {
      return new Promise(function (resolve, reject) {
        var tx = db.transaction(STORE, 'readonly');
        var req = tx.objectStore(STORE).getAll();
        req.onsuccess = function () {
          var rows = req.result || [];
          rows.sort(function (a, b) {
            return String(a.created_at || '').localeCompare(String(b.created_at || ''));
          });
          resolve(rows);
        };
        req.onerror = function () {
          reject(req.error || new Error('Could not read offline queue.'));
        };
        tx.oncomplete = function () {
          db.close();
        };
        tx.onerror = function () {
          db.close();
        };
      });
    });
  }

  function readClassroomOptions() {
    var el = document.getElementById('offline-classroom-options');
    if (!el) {
      return [];
    }
    try {
      var parsed = JSON.parse(el.textContent || '[]');
      return Array.isArray(parsed) ? parsed : [];
    } catch (err) {
      return [];
    }
  }

  function classroomOptionLabel(option) {
    return String(option && option.label ? option.label : 'Classroom');
  }

  function reassignQueueItem(item, targetClassroomId) {
    var options = readClassroomOptions();
    var target = options.find(function (option) {
      return Number(option.id) === Number(targetClassroomId);
    });
    if (!target) {
      return Promise.reject(new Error('Choose a valid course.'));
    }
    if (Number(item.classroom_id) === Number(target.id)) {
      return Promise.resolve(item);
    }
    if (item.status === 'syncing') {
      return Promise.reject(new Error('Wait until this upload finishes syncing.'));
    }

    var code = String(target.course_code || '').trim();
    var name = String(target.course_name || '').trim();
    var classroomTitle = String(target.classroom_title || 'Classroom').trim();
    var label = classroomOptionLabel(target);

    item.classroom_id = Number(target.id);
    item.course_code = code;
    item.course_name = name;
    item.classroom_title = classroomTitle;
    item.classroom_label = label;
    item.status = 'pending';
    item.error = '';
    item.updated_at = new Date().toISOString();

    return idbPut(item);
  }

  function uuid() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
      return window.crypto.randomUUID();
    }
    return 'fou-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
  }

  function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) {
      return Promise.resolve(null);
    }
    return navigator.serviceWorker.register('sw-faculty-offline.js').catch(function () {
      return null;
    });
  }

  function requestBackgroundSync() {
    if (!('serviceWorker' in navigator) || !navigator.serviceWorker.ready) {
      return Promise.resolve();
    }
    return navigator.serviceWorker.ready
      .then(function (reg) {
        if (reg.sync && typeof reg.sync.register === 'function') {
          return reg.sync.register('faculty-offline-upload-sync');
        }
        return null;
      })
      .catch(function () {
        return null;
      });
  }

  function setStatus(el, message, kind) {
    if (!el) return;
    el.textContent = message || '';
    el.classList.remove('text-success', 'text-danger', 'text-muted', 'text-primary', 'text-warning');
    if (kind === 'ok') el.classList.add('text-success');
    else if (kind === 'err') el.classList.add('text-danger');
    else if (kind === 'busy') el.classList.add('text-primary');
    else if (kind === 'warn') el.classList.add('text-warning');
    else el.classList.add('text-muted');
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

  function fileListFromInput(input) {
    var files = [];
    if (!input || !input.files) return Promise.resolve(files);
    var list = Array.prototype.slice.call(input.files || []);
    return Promise.all(
      list.map(function (file) {
        if (file.size > MAX_FILE_BYTES) {
          return Promise.reject(new Error('"' + file.name + '" is larger than 10 MB.'));
        }
        return file.arrayBuffer().then(function (buf) {
          return {
            name: file.name,
            type: file.type || 'application/octet-stream',
            size: file.size,
            buffer: buf,
          };
        });
      })
    );
  }

  function buffersToFiles(fileRecords) {
    return (fileRecords || []).map(function (rec) {
      var blob = new Blob([rec.buffer], { type: rec.type || 'application/octet-stream' });
      return new File([blob], rec.name || 'attachment', {
        type: rec.type || 'application/octet-stream',
        lastModified: Date.now(),
      });
    });
  }

  function offlineMetaFromPage() {
    var el = document.querySelector('[data-offline-classroom-label]');
    var courseCode = '';
    var courseName = '';
    var classroomTitle = '';
    var label = '';
    if (el) {
      courseCode = (el.getAttribute('data-offline-course-code') || '').trim();
      courseName = (el.getAttribute('data-offline-course-name') || '').trim();
      classroomTitle = (el.getAttribute('data-offline-classroom-title') || '').trim();
      label = (el.getAttribute('data-offline-classroom-label') || el.textContent || '').trim();
    }
    if (!label) {
      var h1 = document.querySelector('h1');
      label = h1 ? (h1.textContent || '').trim() : 'Classroom';
    }
    if (!courseCode && label.indexOf(' — ') !== -1) {
      courseCode = label.split(' — ')[0].trim();
    }
    if (!classroomTitle && label.indexOf(' — ') !== -1) {
      classroomTitle = label.split(' — ').slice(1).join(' — ').trim();
    }
    return {
      course_code: courseCode,
      course_name: courseName,
      classroom_title: classroomTitle || 'Classroom',
      classroom_label: label || 'Classroom',
    };
  }

  function courseGroupKey(item) {
    var code = String(item && item.course_code ? item.course_code : '').trim();
    var name = String(item && item.course_name ? item.course_name : '').trim();
    if (code || name) {
      return code + '|' + name;
    }
    var label = String(item && item.classroom_label ? item.classroom_label : '').trim();
    if (label.indexOf(' — ') !== -1) {
      var parsedCode = label.split(' — ')[0].trim();
      if (parsedCode) {
        return parsedCode + '|';
      }
    }
    if (label) {
      return 'label:' + label;
    }
    return 'classroom:' + String(item && item.classroom_id ? item.classroom_id : 'unknown');
  }

  function courseGroupHeading(item) {
    var code = String(item && item.course_code ? item.course_code : '').trim();
    var name = String(item && item.course_name ? item.course_name : '').trim();
    if (code && name) {
      return code + ' — ' + name;
    }
    if (code) {
      return code;
    }
    if (name) {
      return name;
    }
    var label = String(item && item.classroom_label ? item.classroom_label : '').trim();
    if (label.indexOf(' — ') !== -1) {
      return label.split(' — ')[0].trim() || label;
    }
    return label || 'Classroom #' + String(item && item.classroom_id ? item.classroom_id : '');
  }

  function groupQueueByCourse(items) {
    var groups = {};
    var order = [];
    (items || []).forEach(function (item) {
      var key = courseGroupKey(item);
      if (!groups[key]) {
        groups[key] = {
          key: key,
          heading: courseGroupHeading(item),
          items: [],
        };
        order.push(key);
      }
      groups[key].items.push(item);
    });
    order.sort(function (a, b) {
      return String(groups[a].heading).localeCompare(String(groups[b].heading));
    });
    return order.map(function (key) {
      return groups[key];
    });
  }

  function enqueueRecord(record) {
    return idbPut(record).then(function () {
      return registerServiceWorker().then(function () {
        return requestBackgroundSync();
      }).then(function () {
        updatePendingBadges();
        return record;
      });
    });
  }

  function enqueueAddContent(form) {
    var classroomId = parseInt(form.querySelector('[name="classroom_id"]')?.value || '0', 10);
    var title = (form.querySelector('[name="title"]')?.value || '').trim();
    var contentType = form.querySelector('[name="content_type"]')?.value || 'material';
    var body = form.querySelector('[name="body"]')?.value || '';
    var weeks = form.querySelector('[name="weeks"]')?.value || '';
    var daysPerTopic = form.querySelector('[name="days_per_topic"]')?.value || '';
    var resourceUrl = (form.querySelector('[name="resource_url"]')?.value || '').trim();
    var fileInput = form.querySelector('[name="attachments[]"]') || form.querySelector('#fc-attachments');
    var meta = offlineMetaFromPage();

    if (!classroomId) {
      return Promise.reject(new Error('Missing classroom.'));
    }
    if (!title) {
      return Promise.reject(new Error('Content title is required.'));
    }

    return fileListFromInput(fileInput).then(function (files) {
      if (!body.trim() && !resourceUrl && files.length === 0) {
        throw new Error('Add a short description, a resource URL, or at least one attachment.');
      }
      var record = {
        id: uuid(),
        action: 'add_content',
        classroom_id: classroomId,
        classroom_label: meta.classroom_label,
        course_code: meta.course_code,
        course_name: meta.course_name,
        classroom_title: meta.classroom_title,
        content_type: contentType,
        title: title,
        body: body,
        weeks: weeks,
        days_per_topic: daysPerTopic,
        resource_url: resourceUrl,
        files: files,
        status: 'pending',
        error: '',
        created_at: new Date().toISOString(),
        synced_at: null,
      };
      return enqueueRecord(record);
    });
  }

  function enqueueSyllabus(form) {
    var classroomId = parseInt(form.querySelector('[name="classroom_id"]')?.value || '0', 10);
    var fileInput = form.querySelector('[name="syllabus"]') || form.querySelector('#fc-syllabus-file');
    var meta = offlineMetaFromPage();
    if (!classroomId) {
      return Promise.reject(new Error('Missing classroom.'));
    }
    if (!fileInput || !fileInput.files || !fileInput.files.length) {
      return Promise.reject(new Error('Please choose a syllabus file to upload.'));
    }
    return fileListFromInput(fileInput).then(function (files) {
      if (!files.length) {
        throw new Error('Please choose a syllabus file to upload.');
      }
      var record = {
        id: uuid(),
        action: 'upload_syllabus',
        classroom_id: classroomId,
        classroom_label: meta.classroom_label,
        course_code: meta.course_code,
        course_name: meta.course_name,
        classroom_title: meta.classroom_title,
        content_type: 'syllabus',
        title: files[0].name || 'Syllabus',
        body: '',
        weeks: '',
        days_per_topic: '',
        resource_url: '',
        files: files,
        status: 'pending',
        error: '',
        created_at: new Date().toISOString(),
        synced_at: null,
      };
      return enqueueRecord(record);
    });
  }

  function resetAddContentForm(form) {
    form.reset();
    var editor = form.querySelector('.wordpad-editor');
    var textarea = form.querySelector('textarea[name="body"]');
    if (editor) editor.innerHTML = '';
    if (textarea) textarea.value = '';
  }

  function postQueueItem(item) {
    var fd = new FormData();
    fd.append('action', item.action);
    fd.append('classroom_id', String(item.classroom_id));
    fd.append('client_uuid', item.id);

    if (item.action === 'upload_syllabus') {
      var sylFiles = buffersToFiles(item.files);
      if (!sylFiles.length) {
        return Promise.reject(new Error('Missing syllabus file in offline queue.'));
      }
      fd.append('syllabus', sylFiles[0], sylFiles[0].name);
    } else {
      fd.append('content_type', item.content_type || 'material');
      fd.append('title', item.title || '');
      fd.append('body', item.body || '');
      fd.append('weeks', item.weeks || '');
      fd.append('days_per_topic', item.days_per_topic || '');
      fd.append('resource_url', item.resource_url || '');
      buffersToFiles(item.files).forEach(function (file) {
        fd.append('attachments[]', file, file.name);
      });
    }

    return fetch(API_URL, {
      method: 'POST',
      credentials: 'same-origin',
      body: fd,
      headers: { Accept: 'application/json' },
    }).then(function (res) {
      return res.json().then(function (body) {
        if (!res.ok || !body || !body.ok) {
          throw new Error((body && body.error) || 'Sync failed.');
        }
        return body;
      });
    });
  }

  function flushQueue() {
    if (syncing) {
      return Promise.resolve({ synced: 0, failed: 0, skipped: true });
    }
    if (!navigator.onLine) {
      return Promise.resolve({ synced: 0, failed: 0, offline: true });
    }

    syncing = true;
    return listQueue()
      .then(function (items) {
        var pending = items.filter(function (item) {
          return item.status === 'pending' || item.status === 'error';
        });
        // Syllabus first so content posts are not blocked by the syllabus gate.
        pending.sort(function (a, b) {
          var aw = a.action === 'upload_syllabus' ? 0 : 1;
          var bw = b.action === 'upload_syllabus' ? 0 : 1;
          if (aw !== bw) return aw - bw;
          return String(a.created_at || '').localeCompare(String(b.created_at || ''));
        });
        var synced = 0;
        var failed = 0;

        var chain = Promise.resolve();
        pending.forEach(function (item) {
          chain = chain.then(function () {
            item.status = 'syncing';
            item.error = '';
            return idbPut(item)
              .then(function () {
                return postQueueItem(item);
              })
              .then(function () {
                synced += 1;
                return idbDelete(item.id);
              })
              .catch(function (err) {
                failed += 1;
                item.status = 'error';
                item.error = err && err.message ? err.message : 'Sync failed.';
                return idbPut(item);
              });
          });
        });

        return chain.then(function () {
          return { synced: synced, failed: failed, skipped: false, pending: pending.length };
        });
      })
      .finally(function () {
        syncing = false;
        updatePendingBadges();
        var mount = document.querySelector('[data-offline-queue]');
        if (mount) {
          renderQueue(mount);
        }
      });
  }

  function updatePendingBadges() {
    listQueue()
      .then(function (items) {
        var count = items.filter(function (item) {
          return item.status === 'pending' || item.status === 'error' || item.status === 'syncing';
        }).length;
        document.querySelectorAll('[data-offline-pending-count]').forEach(function (el) {
          el.textContent = String(count);
          el.hidden = count < 1;
        });
        document.querySelectorAll('[data-offline-pending-label]').forEach(function (el) {
          if (count < 1) {
            el.textContent = 'No pending offline uploads.';
          } else {
            el.textContent =
              count +
              ' upload' +
              (count === 1 ? '' : 's') +
              ' waiting to sync' +
              (navigator.onLine ? '.' : ' (offline).');
          }
        });
      })
      .catch(function () {});
  }

  function renderQueue(mount) {
    if (!mount) return;
    mount.innerHTML = '<p class="text-muted">Loading offline upload queue…</p>';
    listQueue()
      .then(function (items) {
        var online = navigator.onLine;
        var html = '';
        html += '<div class="alert ' + (online ? 'alert-success' : 'alert-info') + '">';
        html += online
          ? '<i class="fa-solid fa-wifi me-1"></i>You are online. Pending uploads sync automatically.'
          : '<i class="fa-solid fa-plane me-1"></i>You are offline. New posts and files stay on this device until you reconnect.';
        html += '</div>';

        if (!items.length) {
          html +=
            '<div class="alert alert-light border mb-0">No queued uploads. On a classroom page, publish content or save a syllabus while offline — they appear here, then sync when you are back online.</div>';
          mount.innerHTML = html;
          return;
        }

        html += '<p class="small text-muted mb-3">Open a course box to see its queued uploads. You can assign a queued item to another course you teach before it syncs.</p>';
        var classroomOptions = readClassroomOptions();
        groupQueueByCourse(items).forEach(function (courseGroup, index) {
          var collapseId = 'offline-queue-course-' + index;
          html += '<div class="card shadow-sm mb-3 offline-course-box">';
          html +=
            '<button type="button" class="btn btn-link text-start text-decoration-none text-body w-100 p-0" data-bs-toggle="collapse" data-bs-target="#' +
            escapeHtml(collapseId) +
            '" aria-expanded="false" aria-controls="' +
            escapeHtml(collapseId) +
            '">';
          html += '<div class="card-body d-flex justify-content-between gap-3 align-items-center">';
          html += '<div class="min-w-0">';
          html +=
            '<div class="fw-semibold mb-1"><i class="fa-solid fa-book-open me-2 text-primary"></i>' +
            escapeHtml(courseGroup.heading) +
            '</div>';
          html +=
            '<div class="small text-muted">' +
            escapeHtml(String(courseGroup.items.length)) +
            ' upload' +
            (courseGroup.items.length === 1 ? '' : 's') +
            ' waiting</div>';
          html += '</div>';
          html +=
            '<span class="badge text-bg-light border text-muted flex-shrink-0"><i class="fa-solid fa-chevron-down"></i></span>';
          html += '</div></button>';
          html += '<div class="collapse" id="' + escapeHtml(collapseId) + '">';
          html += '<div class="list-group list-group-flush border-top">';
          courseGroup.items.forEach(function (item) {
            var status = item.status || 'pending';
            var badgeClass =
              status === 'error'
                ? 'text-bg-danger'
                : status === 'syncing'
                  ? 'text-bg-primary'
                  : 'text-bg-warning';
            var fileNames = (item.files || [])
              .map(function (f) {
                return f.name;
              })
              .filter(Boolean);
            var classroomBit =
              item.classroom_title ||
              item.classroom_label ||
              'Classroom #' + item.classroom_id;
            html += '<div class="list-group-item" data-queue-id="' + escapeHtml(item.id) + '">';
            html += '<div class="d-flex justify-content-between gap-2 flex-wrap align-items-start">';
            html += '<div class="min-w-0">';
            html += '<div class="fw-semibold">' + escapeHtml(item.title || 'Untitled') + '</div>';
            html +=
              '<div class="small text-muted">' +
              escapeHtml(classroomBit) +
              ' · ' +
              escapeHtml(item.action === 'upload_syllabus' ? 'Syllabus' : item.content_type || 'material') +
              '</div>';
            if (fileNames.length) {
              html +=
                '<div class="small mt-1"><i class="fa-solid fa-paperclip me-1"></i>' +
                escapeHtml(fileNames.join(', ')) +
                '</div>';
            }
            if (item.error) {
              html += '<div class="small text-danger mt-1">' + escapeHtml(item.error) + '</div>';
            }
            html += '<div class="small text-muted mt-1">Queued ' + escapeHtml(formatWhen(item.created_at)) + '</div>';
            html += '</div>';
            html += '<span class="badge ' + badgeClass + '">' + escapeHtml(status) + '</span>';
            html += '</div>';
            html +=
              '<div class="mt-2 d-flex flex-wrap gap-2 align-items-center"><button type="button" class="btn btn-sm btn-outline-danger" data-offline-discard="' +
              escapeHtml(item.id) +
              '">Discard</button>';
            if (classroomOptions.length > 1 && status !== 'syncing') {
              html +=
                '<label class="small text-muted mb-0 me-1">Assign to</label><select class="form-select form-select-sm w-auto" data-offline-reassign="' +
                escapeHtml(item.id) +
                '">';
              classroomOptions.forEach(function (option) {
                var selected = Number(option.id) === Number(item.classroom_id) ? ' selected' : '';
                html +=
                  '<option value="' +
                  escapeHtml(String(option.id)) +
                  '"' +
                  selected +
                  '>' +
                  escapeHtml(classroomOptionLabel(option)) +
                  '</option>';
              });
              html += '</select>';
            }
            html += '</div>';
            html += '</div>';
          });
          html += '</div></div></div>';
        });
        mount.innerHTML = html;

        mount.querySelectorAll('[data-offline-discard]').forEach(function (btn) {
          btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-offline-discard');
            if (!id || !window.confirm('Discard this queued upload from this device?')) return;
            idbDelete(id).then(function () {
              updatePendingBadges();
              renderQueue(mount);
            });
          });
        });

        mount.querySelectorAll('[data-offline-reassign]').forEach(function (selectEl) {
          selectEl.setAttribute('data-previous-value', selectEl.value || '');
          selectEl.addEventListener('change', function () {
            var id = selectEl.getAttribute('data-offline-reassign');
            var targetId = parseInt(selectEl.value || '0', 10);
            var previousValue = selectEl.getAttribute('data-previous-value') || '';
            if (!id || targetId < 1) {
              return;
            }
            listQueue()
              .then(function (rows) {
                var item = rows.find(function (row) {
                  return String(row.id) === String(id);
                });
                if (!item) {
                  throw new Error('Queued upload not found.');
                }
                return reassignQueueItem(item, targetId);
              })
              .then(function () {
                updatePendingBadges();
                renderQueue(mount);
              })
              .catch(function (err) {
                selectEl.value = previousValue;
                window.alert(err && err.message ? err.message : 'Could not assign queued upload.');
              });
          });
        });
      })
      .catch(function (err) {
        mount.innerHTML =
          '<div class="alert alert-danger mb-0">' +
          escapeHtml(err && err.message ? err.message : 'Could not load offline queue.') +
          '</div>';
      });
  }

  function bindAddContentForm(form) {
    if (!form || form.dataset.offlineBound === '1') return;
    form.dataset.offlineBound = '1';
    form.addEventListener(
      'submit',
      function (event) {
        if (navigator.onLine) {
          return;
        }
        event.preventDefault();
        event.stopPropagation();

        var shell = form.querySelector('[data-wordpad]');
        if (shell) {
          var editor = shell.querySelector('.wordpad-editor');
          var textarea = shell.querySelector('textarea[name="body"]');
          if (editor && textarea) {
            textarea.value = editor.innerHTML
              .replace(/<div><br><\/div>/gi, '')
              .replace(/&nbsp;/gi, ' ')
              .trim();
          }
        }

        var statusEl = document.querySelector('[data-offline-status]');
        var submitBtn = form.querySelector('[type="submit"]');
        var original = submitBtn ? submitBtn.innerHTML : '';
        if (submitBtn) {
          submitBtn.disabled = true;
          submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Saving offline…';
        }
        setStatus(statusEl, 'Saving post and files on this device…', 'busy');

        enqueueAddContent(form)
          .then(function () {
            resetAddContentForm(form);
            setStatus(
              statusEl,
              'Saved offline. Open Offline uploads or reconnect to sync to the class.',
              'ok'
            );
            if (submitBtn) {
              submitBtn.innerHTML = '<i class="fa-solid fa-check me-1"></i>Saved offline';
              window.setTimeout(function () {
                submitBtn.disabled = false;
                submitBtn.innerHTML = original;
              }, 1800);
            }
          })
          .catch(function (err) {
            setStatus(statusEl, err && err.message ? err.message : 'Could not save offline.', 'err');
            if (submitBtn) {
              submitBtn.disabled = false;
              submitBtn.innerHTML = original;
            }
          });
      },
      true
    );
  }

  function bindSyllabusForm(form) {
    if (!form || form.dataset.offlineBound === '1') return;
    form.dataset.offlineBound = '1';
    form.addEventListener(
      'submit',
      function (event) {
        if (navigator.onLine) {
          return;
        }
        event.preventDefault();
        event.stopPropagation();

        var statusEl = document.querySelector('[data-offline-status]');
        var submitBtn = form.querySelector('[type="submit"]');
        var original = submitBtn ? submitBtn.innerHTML : '';
        if (submitBtn) {
          submitBtn.disabled = true;
          submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Saving offline…';
        }
        setStatus(statusEl, 'Saving syllabus on this device…', 'busy');

        enqueueSyllabus(form)
          .then(function () {
            form.reset();
            setStatus(
              statusEl,
              'Syllabus saved offline. It will upload when you are back online.',
              'ok'
            );
            if (submitBtn) {
              submitBtn.innerHTML = '<i class="fa-solid fa-check me-1"></i>Saved offline';
              window.setTimeout(function () {
                submitBtn.disabled = false;
                submitBtn.innerHTML = original;
              }, 1800);
            }
          })
          .catch(function (err) {
            setStatus(statusEl, err && err.message ? err.message : 'Could not save offline.', 'err');
            if (submitBtn) {
              submitBtn.disabled = false;
              submitBtn.innerHTML = original;
            }
          });
      },
      true
    );
  }

  function bindControls(root) {
    root = root || document;
    var addForm = root.querySelector('#fc-add-content-form');
    if (addForm) {
      bindAddContentForm(addForm);
    }
    root.querySelectorAll('form').forEach(function (form) {
      var action = form.querySelector('input[name="action"]');
      if (action && action.value === 'upload_syllabus') {
        bindSyllabusForm(form);
      }
    });

    root.querySelectorAll('[data-offline-sync-now]').forEach(function (btn) {
      if (btn.dataset.offlineBound === '1') return;
      btn.dataset.offlineBound = '1';
      btn.addEventListener('click', function () {
        var statusEl = root.querySelector('[data-offline-status]');
        var original = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Syncing…';
        setStatus(statusEl, 'Uploading queued files…', 'busy');
        flushQueue()
          .then(function (result) {
            if (result.skipped && !navigator.onLine) {
              setStatus(statusEl, 'Still offline — sync will run when you reconnect.', 'warn');
            } else if (result.failed > 0) {
              setStatus(
                statusEl,
                'Synced ' + result.synced + ', failed ' + result.failed + '. Check errors below.',
                'err'
              );
            } else if (result.synced > 0) {
              setStatus(statusEl, 'Synced ' + result.synced + ' upload(s).', 'ok');
            } else {
              setStatus(statusEl, 'Nothing to sync.', 'muted');
            }
          })
          .catch(function (err) {
            setStatus(statusEl, err && err.message ? err.message : 'Sync failed.', 'err');
          })
          .finally(function () {
            btn.disabled = false;
            btn.innerHTML = original;
          });
      });
    });

    updatePendingBadges();
  }

  window.FacultyOffline = {
    registerServiceWorker: registerServiceWorker,
    flushQueue: flushQueue,
    listQueue: listQueue,
    bindControls: bindControls,
    renderQueue: renderQueue,
    formatWhen: formatWhen,
  };

  document.addEventListener('DOMContentLoaded', function () {
    registerServiceWorker();
    bindControls(document);
    var mount = document.querySelector('[data-offline-queue]');
    if (mount) {
      renderQueue(mount);
    }
    flushQueue().catch(function () {});
  });

  window.addEventListener('online', function () {
    flushQueue().catch(function () {});
  });

  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', function (event) {
      if (event.data && event.data.type === 'faculty-offline-flush') {
        flushQueue().catch(function () {});
      }
    });
  }
})();

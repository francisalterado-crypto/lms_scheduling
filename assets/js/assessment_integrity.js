/**
 * Ends an in-progress assessment when the student leaves the window
 * (tab switch, minimize, blur, or close).
 */
(function () {
    if (window.__assessmentIntegrityLoaded) {
        return;
    }
    window.__assessmentIntegrityLoaded = true;

    var INTEGRITY_WARNING =
        'Warning: Your assessment has ended because you left or minimized the assessment window, closed it, or switched to another tab or application.\n\nYour answers will be submitted now.';
    var activeController = null;

    function storageKey(assessmentId, studentId) {
        return 'class_assessment_timer_' + studentId + '_' + assessmentId;
    }

    function formatDigits(totalSeconds) {
        var s = Math.max(0, Math.floor(totalSeconds));
        var hh = String(Math.floor(s / 3600)).padStart(2, '0');
        var mm = String(Math.floor((s % 3600) / 60)).padStart(2, '0');
        var ss = String(s % 60).padStart(2, '0');
        return hh + ':' + mm + ':' + ss;
    }

    function readStart(key) {
        try {
            var raw = localStorage.getItem(key);
            if (!raw) return null;
            var n = parseInt(raw, 10);
            return isNaN(n) ? null : n;
        } catch (e) {
            return null;
        }
    }

    function writeStart(key, ts) {
        try {
            localStorage.setItem(key, String(ts));
        } catch (e) {}
    }

    function clearStart(key) {
        try {
            localStorage.removeItem(key);
        } catch (e) {}
    }

    function showIntegrityWarning(onDone) {
        var warnEl = document.getElementById('assessmentIntegrityWarningModal');
        var finished = false;
        function finish() {
            if (finished) return;
            finished = true;
            onDone();
        }
        if (!warnEl || typeof bootstrap === 'undefined') {
            try {
                window.alert(INTEGRITY_WARNING);
            } catch (e) {}
            finish();
            return;
        }
        var warnModal = bootstrap.Modal.getOrCreateInstance(warnEl);
        var ackBtn = warnEl.querySelector('[data-integrity-ack]');
        function onAck() {
            if (ackBtn) ackBtn.removeEventListener('click', onAck);
            warnEl.removeEventListener('hidden.bs.modal', onHidden);
            try {
                warnModal.hide();
            } catch (e) {}
            finish();
        }
        function onHidden() {
            onAck();
        }
        if (ackBtn) ackBtn.addEventListener('click', onAck);
        warnEl.addEventListener('hidden.bs.modal', onHidden);
        warnModal.show();
        setTimeout(onAck, 2500);
    }

    function setupModal(modal) {
        var timed = modal.getAttribute('data-timed-attempt') === '1';
        var alreadySubmitted = modal.getAttribute('data-already-submitted') === '1';
        var integrityLocked = modal.getAttribute('data-integrity-locked') === '1';
        var assessmentId = modal.getAttribute('data-assessment-id');
        var studentId = modal.getAttribute('data-student-id');
        var limitMinutes = parseInt(modal.getAttribute('data-time-limit-minutes') || '0', 10) || 0;
        var key = storageKey(assessmentId, studentId);
        var limitMs = limitMinutes * 60 * 1000;
        var gate = modal.querySelector('[data-timer-gate]');
        var body = modal.querySelector('[data-timer-body]');
        var display = modal.querySelector('[data-timer-display]');
        var digits = modal.querySelector('[data-timer-digits]');
        var startBtn = modal.querySelector('[data-timer-start]');
        var form = modal.querySelector('[data-assessment-form]');
        var integrityField = form ? form.querySelector('[data-integrity-field]') : null;
        var closeBtn = modal.querySelector('.btn-close');
        var tickTimer = null;
        var submitting = false;
        var monitoring = false;
        var blurTimer = null;
        var allowModalHide = false;

        var controller = {
            isMonitoring: function () {
                return monitoring && !submitting;
            },
            endForIntegrity: endForIntegrity
        };

        function stopTick() {
            if (tickTimer) {
                clearInterval(tickTimer);
                tickTimer = null;
            }
        }

        function isAttemptActive() {
            if (!form || submitting || alreadySubmitted || integrityLocked) return false;
            if (body && body.hidden) return false;
            return modal.classList.contains('show');
        }

        function stopMonitoring() {
            monitoring = false;
            if (activeController === controller) {
                activeController = null;
            }
            if (blurTimer) {
                clearTimeout(blurTimer);
                blurTimer = null;
            }
        }

        function startMonitoring() {
            if (monitoring || submitting || !form || alreadySubmitted || integrityLocked) return;
            monitoring = true;
            activeController = controller;
            modal.setAttribute('data-integrity-monitoring', '1');
        }

        function prepareForcedSubmit(asIntegrity) {
            if (!form) return;
            if (integrityField) {
                integrityField.value = asIntegrity ? '1' : '0';
            }
            form.querySelectorAll('[required]').forEach(function (el) {
                el.removeAttribute('required');
            });
            form.setAttribute('novalidate', 'novalidate');
        }

        function doFormSubmit(asIntegrity) {
            if (!form) return;
            var isIntegrity = !!asIntegrity;

            var classroomId = '';
            var classroomInput = form.querySelector('input[name="classroom_id"]');
            if (classroomInput) {
                classroomId = String(classroomInput.value || '');
            }

            var redirectTo = 'student_classroom.php' + (classroomId !== '' ? ('?id=' + encodeURIComponent(classroomId)) : '');
            var postUrl = form.getAttribute('action') || window.location.pathname + window.location.search;

            try {
                if (integrityField) {
                    integrityField.value = isIntegrity ? '1' : '0';
                }
                var fd = new FormData(form);
                fd.set('action', 'submit_assessment');
                fd.set('integrity_violation', isIntegrity ? '1' : '0');
                if (classroomId !== '') {
                    fd.set('classroom_id', classroomId);
                }
                // keepalive allows the request to finish even when the tab is hidden/closing.
                fetch(postUrl, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                    keepalive: true,
                    redirect: 'follow'
                })
                    .finally(function () {
                        window.location.href = redirectTo;
                    });
            } catch (e) {
                try {
                    HTMLFormElement.prototype.submit.call(form);
                } catch (e2) {
                    form.submit();
                }
            }
        }

        function forceSubmit(asIntegrity) {
            if (submitting || !form) return;
            submitting = true;
            stopTick();
            stopMonitoring();
            clearStart(key);
            prepareForcedSubmit(!!asIntegrity);
            allowModalHide = true;
            doFormSubmit(!!asIntegrity);
        }

        function endForIntegrity() {
            if (submitting || !form) return;
            if (!monitoring && !isAttemptActive()) return;
            submitting = true;
            stopTick();
            stopMonitoring();
            clearStart(key);
            prepareForcedSubmit(true);
            allowModalHide = true;
            modal.removeAttribute('data-integrity-monitoring');

            // Submit immediately in background — alert/modals are deferred when the tab is hidden.
            if (document.visibilityState === 'hidden' || !document.hasFocus()) {
                doFormSubmit(true);
                return;
            }

            showIntegrityWarning(function () {
                doFormSubmit(true);
            });
        }

        function revealBody() {
            if (gate) gate.classList.add('d-none');
            if (body) body.hidden = false;
            if (display) display.classList.remove('d-none');
        }

        function updateDisplay(remainingSec) {
            if (!digits || !display) return;
            digits.textContent = formatDigits(remainingSec);
            display.classList.toggle('is-warning', remainingSec <= 300 && remainingSec > 60);
            display.classList.toggle('is-danger', remainingSec <= 60);
        }

        function autoSubmit() {
            if (digits) digits.textContent = '00:00:00';
            if (display) display.classList.add('is-danger');
            forceSubmit(false);
        }

        function beginCountdown(startedAt) {
            revealBody();
            startMonitoring();
            var endAt = startedAt + limitMs;

            function tick() {
                var remainingMs = endAt - Date.now();
                var remainingSec = Math.ceil(remainingMs / 1000);
                updateDisplay(remainingSec);
                if (remainingMs <= 0) autoSubmit();
            }

            stopTick();
            tick();
            tickTimer = setInterval(tick, 250);
        }

        function startAttempt() {
            var now = Date.now();
            writeStart(key, now);
            beginCountdown(now);
        }

        if (startBtn) {
            startBtn.addEventListener('click', function () {
                startAttempt();
            });
        }

        if (closeBtn) {
            closeBtn.addEventListener(
                'click',
                function (e) {
                    if (!isAttemptActive() || submitting) return;
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    endForIntegrity();
                },
                true
            );
        }

        if (form) {
            form.addEventListener('submit', function () {
                submitting = true;
                stopMonitoring();
                clearStart(key);
                stopTick();
                allowModalHide = true;
            });
        }

        modal.addEventListener('hide.bs.modal', function (e) {
            if (allowModalHide || submitting) return;
            if (isAttemptActive()) {
                e.preventDefault();
                endForIntegrity();
            }
        });

        modal.addEventListener('shown.bs.modal', function () {
            if (modal.getAttribute('data-clear-timer') === '1') {
                clearStart(key);
            }
            if (timed && limitMinutes >= 1) {
                var startedAt = readStart(key);
                if (startedAt) {
                    var remaining = startedAt + limitMs - Date.now();
                    if (remaining <= 0) {
                        revealBody();
                        updateDisplay(0);
                        autoSubmit();
                    } else {
                        beginCountdown(startedAt);
                    }
                }
                return;
            }

            if (body && !body.hidden) {
                startMonitoring();
            }
        });

        modal.addEventListener('hidden.bs.modal', function () {
            stopTick();
            stopMonitoring();
            allowModalHide = false;
            modal.removeAttribute('data-integrity-monitoring');
        });
    }

    function boot() {
        var hash = window.location.hash;
        if (hash && hash.indexOf('#assessment-') === 0) {
            var id = hash.substring('#assessment-'.length);
            var modalEl = document.getElementById('assessmentModal' + id);
            if (modalEl && typeof bootstrap !== 'undefined') {
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }
        }

        document.querySelectorAll('.assessment-take-modal').forEach(setupModal);

        document.addEventListener('visibilitychange', function () {
            if (activeController && activeController.isMonitoring() && document.visibilityState === 'hidden') {
                activeController.endForIntegrity();
            }
        });

        window.addEventListener('blur', function () {
            if (!activeController || !activeController.isMonitoring()) return;
            if (document.visibilityState === 'hidden') {
                activeController.endForIntegrity();
                return;
            }
            setTimeout(function () {
                if (!activeController || !activeController.isMonitoring()) return;
                if (document.visibilityState === 'hidden' || !document.hasFocus()) {
                    activeController.endForIntegrity();
                }
            }, 50);
        });

        window.addEventListener('pagehide', function () {
            if (activeController && activeController.isMonitoring()) {
                activeController.endForIntegrity();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();

(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    ready(function () {
        var panel = document.getElementById('adminNavOffcanvas');
        var openBtn = document.getElementById('adminNavOpenBtn');
        if (!panel) {
            return;
        }

        var backdrop = null;

        function ensureBackdrop() {
            if (backdrop) {
                return backdrop;
            }
            backdrop = document.createElement('div');
            backdrop.className = 'admin-offcanvas-backdrop no-print';
            document.body.appendChild(backdrop);
            backdrop.addEventListener('click', closePanel);
            return backdrop;
        }

        function openPanel() {
            ensureBackdrop();
            // Force a reflow so the transition runs from the off-screen state.
            void panel.offsetWidth;
            panel.classList.add('show');
            panel.removeAttribute('aria-hidden');
            backdrop.classList.add('show');
            document.body.classList.add('admin-offcanvas-open', 'overflow-hidden');
            if (openBtn) {
                openBtn.setAttribute('aria-expanded', 'true');
            }
        }

        function closePanel() {
            panel.classList.remove('show');
            panel.setAttribute('aria-hidden', 'true');
            if (backdrop) {
                backdrop.classList.remove('show');
            }
            document.body.classList.remove('admin-offcanvas-open', 'overflow-hidden');
            if (openBtn) {
                openBtn.setAttribute('aria-expanded', 'false');
            }
        }

        function togglePanel() {
            if (panel.classList.contains('show')) {
                closePanel();
            } else {
                openPanel();
            }
        }

        if (openBtn) {
            openBtn.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                togglePanel();
            });
        }

        panel.addEventListener('click', function (event) {
            if (event.target.closest('[data-offcanvas-dismiss]')) {
                closePanel();
                return;
            }
            var link = event.target.closest('.admin-nav-link, .admin-sidebar-foot-link');
            if (link && link.getAttribute('href') && link.getAttribute('href') !== '#') {
                closePanel();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && panel.classList.contains('show')) {
                closePanel();
            }
        });

        window.addEventListener('resize', function () {
            if (window.innerWidth >= 992 && panel.classList.contains('show')) {
                closePanel();
            }
        });
    });
})();

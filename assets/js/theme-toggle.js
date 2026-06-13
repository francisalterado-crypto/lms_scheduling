(function () {
    'use strict';

    var STORAGE_KEY = 'app_theme';
    var LEGACY_KEY = 'schedule_theme';

    function readStored() {
        try {
            var t = localStorage.getItem(STORAGE_KEY);
            if (t === 'dark' || t === 'light') {
                return t;
            }
        } catch (e) {}
        return null;
    }

    function effectiveTheme() {
        var s = readStored();
        if (s) {
            return s;
        }
        try {
            if (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches) {
                return 'dark';
            }
        } catch (e) {}
        return 'light';
    }

    function applyTheme(theme, persist) {
        var t = theme === 'dark' ? 'dark' : 'light';
        document.documentElement.setAttribute('data-bs-theme', t);
        if (persist) {
            try {
                localStorage.setItem(STORAGE_KEY, t);
                localStorage.setItem(LEGACY_KEY, t);
            } catch (e) {}
        }
        updateToggleUI(t);
    }

    function updateToggleUI(theme) {
        var btn = document.getElementById('appThemeToggle');
        if (!btn) {
            return;
        }
        var isDark = theme === 'dark';
        btn.setAttribute('aria-pressed', isDark ? 'true' : 'false');
        var label = document.getElementById('appThemeToggleLabel');
        if (label) {
            label.textContent = isDark ? 'Light' : 'Dark';
        }
        var moon = btn.querySelector('.app-theme-icon-dark');
        var sun = btn.querySelector('.app-theme-icon-light');
        if (moon) {
            moon.classList.toggle('d-none', isDark);
        }
        if (sun) {
            sun.classList.toggle('d-none', !isDark);
        }
        btn.title = isDark ? 'Switch to light mode' : 'Switch to dark mode';
        btn.setAttribute('aria-label', isDark ? 'Switch to light mode' : 'Switch to dark mode');
    }

    function init() {
        try {
            if (!localStorage.getItem(STORAGE_KEY)) {
                var leg = localStorage.getItem(LEGACY_KEY);
                if (leg === 'dark' || leg === 'light') {
                    localStorage.setItem(STORAGE_KEY, leg);
                }
            }
        } catch (e) {}

        var attr = document.documentElement.getAttribute('data-bs-theme');
        var t = attr === 'dark' || attr === 'light' ? attr : effectiveTheme();
        document.documentElement.setAttribute('data-bs-theme', t);
        updateToggleUI(t);

        var btn = document.getElementById('appThemeToggle');
        if (btn) {
            btn.addEventListener('click', function () {
                var cur = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light';
                applyTheme(cur === 'dark' ? 'light' : 'dark', true);
            });
        }

        try {
            matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
                if (readStored()) {
                    return;
                }
                applyTheme(e.matches ? 'dark' : 'light', false);
            });
        } catch (e) {}
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

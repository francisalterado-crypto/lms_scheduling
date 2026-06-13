(function () {
    'use strict';

    function shellHasTooltipRole() {
        var c = document.body.classList;
        return (
            c.contains('app-shell-sidebar--student') ||
            c.contains('app-shell-sidebar--admin') ||
            c.contains('app-shell-sidebar--dean') ||
            c.contains('app-shell-sidebar--gened') ||
            c.contains('app-shell-sidebar--faculty')
        );
    }

    if (!shellHasTooltipRole()) {
        return;
    }

    var DELAY = 300;
    var tip = null;
    var timer = null;
    var hovered = null;
    var lastX = 0;
    var lastY = 0;

    function getTip() {
        if (!tip) {
            tip = document.createElement('div');
            tip.id = 'app-cursor-tooltip';
            tip.className = 'app-cursor-tooltip';
            tip.setAttribute('role', 'tooltip');
            tip.setAttribute('aria-hidden', 'true');
            document.body.appendChild(tip);
            tip.style.display = 'none';
        }
        return tip;
    }

    function place() {
        var el = getTip();
        if (el.style.display === 'none' || el.style.display === '') {
            return;
        }
        var pad = 14;
        var m = 10;
        el.style.left = '0px';
        el.style.top = '0px';
        el.style.visibility = 'hidden';
        el.style.display = 'block';
        var w = el.offsetWidth;
        var h = el.offsetHeight;
        var left = lastX + pad;
        var top = lastY + pad;
        if (left + w > window.innerWidth - m) {
            left = Math.max(m, window.innerWidth - w - m);
        }
        if (top + h > window.innerHeight - m) {
            top = Math.max(m, window.innerHeight - h - m);
        }
        el.style.left = left + 'px';
        el.style.top = top + 'px';
        el.style.visibility = 'visible';
    }

    function hideTip() {
        if (tip) {
            tip.style.display = 'none';
            tip.setAttribute('aria-hidden', 'true');
        }
    }

    document.addEventListener(
        'mouseover',
        function (e) {
            var el = e.target.closest('[data-app-tooltip]');
            if (!el) {
                return;
            }
            if (hovered === el) {
                return;
            }
            hovered = el;
            if (timer) {
                clearTimeout(timer);
                timer = null;
            }
            lastX = e.clientX;
            lastY = e.clientY;
            timer = setTimeout(function () {
                timer = null;
                if (hovered !== el) {
                    return;
                }
                var text = el.getAttribute('data-app-tooltip');
                if (!text) {
                    return;
                }
                var node = getTip();
                node.textContent = text;
                node.style.display = 'block';
                node.setAttribute('aria-hidden', 'false');
                place();
                requestAnimationFrame(place);
            }, DELAY);
        },
        true
    );

    document.addEventListener(
        'mousemove',
        function (e) {
            lastX = e.clientX;
            lastY = e.clientY;
            if (!tip || tip.style.display === 'none') {
                return;
            }
            place();
        },
        true
    );

    document.addEventListener(
        'mouseout',
        function (e) {
            var el = e.target.closest('[data-app-tooltip]');
            if (!el) {
                return;
            }
            var rel = e.relatedTarget;
            if (rel && el.contains(rel)) {
                return;
            }
            if (hovered === el) {
                hovered = null;
            }
            if (timer) {
                clearTimeout(timer);
                timer = null;
            }
            hideTip();
        },
        true
    );

    document.addEventListener('scroll', hideTip, true);
})();

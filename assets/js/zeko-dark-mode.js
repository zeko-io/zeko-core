/* ===== Zeko Dark Mode Toggle ===== */
(function() {
    'use strict';

    var currentTheme = document.documentElement.getAttribute('data-theme') || 'auto';

    function setTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        currentTheme = theme;
        updateActiveButton();
        savePreference(theme);
    }

    function updateActiveButton() {
        var buttons = document.querySelectorAll('.zeko-theme-btn');
        for (var i = 0; i < buttons.length; i++) {
            var btn = buttons[i];
            if (btn.getAttribute('data-theme') === currentTheme) {
                btn.classList.add('active');
                btn.setAttribute('aria-pressed', 'true');
            } else {
                btn.classList.remove('active');
                btn.setAttribute('aria-pressed', 'false');
            }
        }
    }

    function savePreference(theme) {
        if (typeof zekoDarkMode !== 'undefined' && zekoDarkMode.ajaxUrl) {
            var data = new FormData();
            data.append('action', 'zeko_save_theme_preference');
            data.append('nonce', zekoDarkMode.nonce);
            data.append('theme', theme);
            fetch(zekoDarkMode.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' });
        }
        document.cookie = 'zeko_theme_preference=' + theme + ';path=/;max-age=' + (365 * 86400);
    }

    document.addEventListener('DOMContentLoaded', function() {
        var container = document.getElementById('zeko-theme-toggle');
        if (container) {
            container.addEventListener('click', function(e) {
                var btn = e.target.closest('.zeko-theme-btn');
                if (btn) {
                    setTheme(btn.getAttribute('data-theme'));
                }
            });
        }
        updateActiveButton();
    });

    window.ZekoDarkMode = { setTheme: setTheme, getTheme: function() { return currentTheme; } };
})();

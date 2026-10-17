(function () {
    var key = 'jpcs-theme';

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        try { localStorage.setItem(key, theme); } catch (error) {}

        document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
            var next = theme === 'dark' ? 'light' : 'dark';
            button.innerHTML = theme === 'dark'
                ? '<span class="theme-icon" aria-hidden="true">&#9790;</span>'
                : '<span class="theme-icon" aria-hidden="true">&#9728;</span>';
            button.setAttribute('data-theme-state', theme);
            button.setAttribute('aria-label', 'Switch to ' + next + ' theme');
            button.setAttribute('title', 'Switch to ' + next + ' theme');
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        applyTheme(document.documentElement.getAttribute('data-theme') || 'dark');
        document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
            button.addEventListener('click', function () {
                applyTheme(document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
            });
        });
    });
})();

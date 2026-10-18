(function () {
    var key = 'jpcs-theme';

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        try { localStorage.setItem(key, theme); } catch (error) {}

        document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
            var next = theme === 'dark' ? 'light' : 'dark';
            button.innerHTML = theme === 'dark'
                ? '<span class="theme-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M20 15.4A8 8 0 0 1 8.6 4 8.1 8.1 0 1 0 20 15.4Z"></path></svg></span>'
                : '<span class="theme-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"></path></svg></span>';
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

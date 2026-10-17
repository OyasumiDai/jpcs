(function () {
    var key = 'jpcs-theme';
    var saved = null;
    try { saved = localStorage.getItem(key); } catch (error) {}
    var theme = saved === 'light' || saved === 'dark'
        ? saved
        : (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
    document.documentElement.setAttribute('data-theme', theme);
})();

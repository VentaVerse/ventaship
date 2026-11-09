<script>
(function () {
    var KEY = 'ventaship-theme';
    function resolve(mode) {
        if (mode === 'dark' || mode === 'light') return mode;
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    function apply(mode) {
        document.documentElement.setAttribute('data-theme', resolve(mode));
        document.documentElement.setAttribute('data-theme-mode', mode);
    }
    function get() {
        try { return localStorage.getItem(KEY) || 'system'; } catch (e) { return 'system'; }
    }
    apply(get());
    window.ventashipTheme = {
        get: get,
        set: function (mode) {
            try { localStorage.setItem(KEY, mode); } catch (e) {}
            apply(mode);
        }
    };
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
        if (get() === 'system') apply('system');
    });
})();
</script>

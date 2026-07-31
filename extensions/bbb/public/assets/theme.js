/* Extension SE5 « Visioconférences » — bascule de thème, sans dépendance.
 *
 * Chargé SANS `defer` depuis le <head> : il pose `data-theme` avant le premier
 * rendu, ce qui évite le flash de thème clair sur un poste réglé en sombre.
 * Sans JavaScript, la page reste parfaitement lisible — `prefers-color-scheme`
 * fait le travail par défaut (voir app.css).
 */
(function () {
    var KEY = 'se5-ext-bbb-theme';

    function apply(theme) {
        if (theme === 'light' || theme === 'dark') {
            document.documentElement.setAttribute('data-theme', theme);
        } else {
            document.documentElement.removeAttribute('data-theme');
        }
    }

    var stored = null;
    try {
        stored = window.localStorage.getItem(KEY);
    } catch (e) {
        stored = null;
    }
    apply(stored);

    document.addEventListener('DOMContentLoaded', function () {
        var button = document.querySelector('[data-theme-toggle]');
        if (!button) {
            return;
        }

        button.hidden = false;
        button.addEventListener('click', function () {
            var current = document.documentElement.getAttribute('data-theme');
            if (!current) {
                current = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
            var next = current === 'dark' ? 'light' : 'dark';
            apply(next);
            try {
                window.localStorage.setItem(KEY, next);
            } catch (e) {
                /* stockage indisponible : la bascule reste valable pour la page courante */
            }
        });
    });
})();

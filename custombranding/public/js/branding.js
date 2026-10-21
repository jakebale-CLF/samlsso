/**
 * customBranding plugin for GLPI.
 *
 * Applies the parts of the branding that CSS cannot reach: the browser tab
 * title and the favicon. Settings come from the <meta name="custombranding">
 * tag injected by the plugin.
 */
(() => {
    const meta = document.querySelector('meta[name="custombranding"]');
    if (!meta) {
        return;
    }

    const title   = meta.dataset.title || '';
    const appName = meta.dataset.appName || 'GLPI';
    const favicon = meta.dataset.favicon || '';

    const applyTitle = () => {
        if (title === '' || title === appName) {
            return;
        }
        const current = document.title;
        let next = current;
        if (current === appName) {
            next = title;
        } else if (current.endsWith(' - ' + appName)) {
            next = current.slice(0, -appName.length) + title;
        }
        if (next !== current) {
            document.title = next;
        }
    };

    const applyFavicon = () => {
        if (favicon === '') {
            return;
        }
        // GLPI renders its own "shortcut icon" after plugin tags: point every icon link at ours.
        document.querySelectorAll('link[rel~="icon"]').forEach((link) => {
            if (link.getAttribute('href') !== favicon) {
                link.setAttribute('href', favicon);
                link.removeAttribute('type');
            }
        });
    };

    applyTitle();
    applyFavicon();
    document.addEventListener('DOMContentLoaded', () => {
        applyTitle();
        applyFavicon();
        // Some GLPI views rewrite the title client side (tabs, kanban...).
        const titleNode = document.querySelector('title');
        if (titleNode) {
            new MutationObserver(applyTitle).observe(titleNode, { childList: true, characterData: true, subtree: true });
        }
    });
})();

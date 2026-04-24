// Promeut tout `<details class="dropdown">` ouvert dans le top-layer du navigateur
// via l'API Popover. Nécessaire pour que les dropdowns affichés dans une modale
// (`<dialog>`) ou n'importe quel conteneur `overflow:hidden`/`overflow-y-auto`
// ne soient pas clippés.
//
// Fonctionne sans toucher aux templates : on réagit à l'event `toggle` natif
// de `<details>` et on positionne `.dropdown-content` en `position: fixed`
// à partir de la bounding box du `<summary>`. Les variantes DaisyUI
// (`dropdown-end`, `dropdown-top`, `dropdown-left`, `dropdown-right`) sont
// honorées.

const openDropdowns = new Set();

function readPlacement(details) {
    const c = details.classList;
    return {
        end: c.contains('dropdown-end'),
        top: c.contains('dropdown-top'),
        right: c.contains('dropdown-right'),
        left: c.contains('dropdown-left'),
    };
}

function positionContent(content, anchor, placement) {
    const rect = anchor.getBoundingClientRect();
    // `w-full` sur le dropdown-content vaut "largeur du details parent" en flux
    // normal. En top-layer (position:fixed), 100% devient la largeur viewport —
    // on réaligne explicitement sur l'ancre.
    if (content.classList.contains('w-full')) {
        content.style.width = `${rect.width}px`;
    }
    const cw = content.offsetWidth;
    const ch = content.offsetHeight;
    const gap = 4;
    let top, left;

    if (placement.top) {
        top = rect.top - ch - gap;
        left = placement.end ? rect.right - cw : rect.left;
    } else if (placement.right) {
        top = rect.top;
        left = rect.right + gap;
    } else if (placement.left) {
        top = rect.top;
        left = rect.left - cw - gap;
    } else {
        top = rect.bottom + gap;
        left = placement.end ? rect.right - cw : rect.left;
    }

    const pad = 4;
    left = Math.max(pad, Math.min(left, window.innerWidth - cw - pad));
    top = Math.max(pad, Math.min(top, window.innerHeight - ch - pad));

    content.style.position = 'fixed';
    content.style.top = `${top}px`;
    content.style.left = `${left}px`;
    content.style.right = 'auto';
    content.style.bottom = 'auto';
    content.style.margin = '0';
}

function openDropdown(details) {
    const content = details.querySelector(':scope > .dropdown-content');
    if (!content) return;
    const anchor = details.querySelector(':scope > summary') || details;

    if (!content.hasAttribute('popover')) {
        content.setAttribute('popover', 'manual');
    }
    try {
        if (typeof content.showPopover === 'function') content.showPopover();
    } catch (_) { /* déjà ouvert */ }

    positionContent(content, anchor, readPlacement(details));
    openDropdowns.add(details);
}

function closeDropdown(details) {
    const content = details.querySelector(':scope > .dropdown-content');
    if (content) {
        try {
            if (typeof content.hidePopover === 'function') content.hidePopover();
        } catch (_) { /* déjà fermé */ }
        // Reset de la largeur forcée pour `w-full` afin que la prochaine mesure
        // (si la modale a été redimensionnée) reparte propre.
        if (content.classList.contains('w-full')) content.style.width = '';
    }
    openDropdowns.delete(details);
}

document.addEventListener('toggle', (e) => {
    const details = e.target;
    if (!(details instanceof HTMLDetailsElement)) return;
    if (!details.classList.contains('dropdown')) return;
    if (details.open) openDropdown(details);
    else closeDropdown(details);
}, true);

// Un scroll (viewport ou ancêtre scrollable) rend la position obsolète.
// On ferme plutôt que de recalculer en continu — comportement proche du
// `details.open = false` natif et cohérent avec le pattern existant.
document.addEventListener('scroll', (e) => {
    if (openDropdowns.size === 0) return;
    // Ignorer le scroll interne de la dropdown-content ouverte (liste défilable).
    const target = e.target;
    if (target && target.nodeType === 1) {
        for (const d of openDropdowns) {
            const content = d.querySelector(':scope > .dropdown-content');
            if (content && (target === content || content.contains(target))) return;
        }
    }
    for (const d of Array.from(openDropdowns)) d.removeAttribute('open');
}, true);

window.addEventListener('resize', () => {
    if (openDropdowns.size === 0) return;
    for (const d of Array.from(openDropdowns)) d.removeAttribute('open');
});

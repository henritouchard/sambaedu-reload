import './bootstrap';
import './theme';
import './dropdown-top-layer';

window.copyToClipboard = function (text, btn = null) {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    document.execCommand('copy');
    document.body.removeChild(textarea);

    if (btn) {
        const icon = btn.querySelector('i');
        if (icon) {
            icon.classList.replace('fa-copy', 'fa-check');
            setTimeout(() => icon.classList.replace('fa-check', 'fa-copy'), 1500);
        }
    }
};

// Tooltip positioning system
document.addEventListener('DOMContentLoaded', () => {
    document.addEventListener('mouseenter', (e) => {
        const trigger = e.target.closest('.tooltip-trigger');
        if (!trigger) return;

        const tooltipId = trigger.dataset.tooltipId;
        const position = trigger.dataset.tooltipPosition || 'top';
        const tooltip = document.getElementById(tooltipId);

        if (!tooltip) return;

        // Move tooltip to body if not already there
        if (tooltip.parentElement !== document.body) {
            document.body.appendChild(tooltip);
        }

        // Show tooltip first to get dimensions
        tooltip.classList.remove('opacity-0', 'invisible');
        tooltip.classList.add('opacity-100', 'visible');

        // Position tooltip
        const triggerRect = trigger.getBoundingClientRect();
        const tooltipRect = tooltip.getBoundingClientRect();
        const gap = 8;
        let top, left;

        switch (position) {
            case 'bottom':
                top = triggerRect.bottom + gap;
                left = triggerRect.left + (triggerRect.width / 2) - (tooltipRect.width / 2);
                break;
            case 'left':
                top = triggerRect.top + (triggerRect.height / 2) - (tooltipRect.height / 2);
                left = triggerRect.left - tooltipRect.width - gap;
                break;
            case 'right':
                top = triggerRect.top + (triggerRect.height / 2) - (tooltipRect.height / 2);
                left = triggerRect.right + gap;
                break;
            case 'top':
            default:
                top = triggerRect.top - tooltipRect.height - gap;
                left = triggerRect.left + (triggerRect.width / 2) - (tooltipRect.width / 2);
                break;
        }

        // Keep tooltip within viewport
        const padding = 8;
        left = Math.max(padding, Math.min(left, window.innerWidth - tooltipRect.width - padding));
        top = Math.max(padding, Math.min(top, window.innerHeight - tooltipRect.height - padding));

        tooltip.style.top = `${top}px`;
        tooltip.style.left = `${left}px`;
    }, true);

    document.addEventListener('mouseleave', (e) => {
        const trigger = e.target.closest('.tooltip-trigger');
        if (!trigger) return;

        const tooltipId = trigger.dataset.tooltipId;
        const tooltip = document.getElementById(tooltipId);

        if (!tooltip) return;

        tooltip.classList.remove('opacity-100', 'visible');
        tooltip.classList.add('opacity-0', 'invisible');
    }, true);
});

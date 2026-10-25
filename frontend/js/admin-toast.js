/**
 * Admin pop-up messages: showNotification('Saved.', 'success' | 'error' | 'danger' | 'warning' | 'info').
 * Shared by Member boards, Pending Applications and Set Venue. Colours follow the Site Colours
 * palette; the values after the commas are the defaults when no palette is saved.
 */
(function () {
    const c = (name, rgb) => `rgb(var(${name}, ${rgb}))`;
    const STYLES = {
        success: [c('--c-accent2-28a745', '40 167 69'), c('--c-fixed-ffffff', '255 255 255'), c('--c-accent2-1e7e34', '30 126 52'), 'check-circle', true],
        error:   [c('--c-danger-dc3545', '220 53 69'), c('--c-fixed-ffffff', '255 255 255'), c('--c-danger-bd2130', '189 33 48'), 'times-circle', true],
        warning: [c('--c-highlight-ffc107', '255 193 7'), c('--c-fixed-212529', '33 37 41'), c('--c-highlight-d39e00', '211 158 0'), 'exclamation-triangle', false],
        info:    [c('--c-accent-17a2b8', '23 162 184'), c('--c-fixed-ffffff', '255 255 255'), c('--c-accent-138496', '19 132 150'), 'info-circle', true],
    };
    STYLES.danger = STYLES.error;

    window.showNotification = function (message, type = 'success') {
        document.querySelectorAll('.notification-toast').forEach(n => n.remove());
        const [bg, fg, border, icon, lightText] = STYLES[type] || STYLES.success;

        const notification = document.createElement('div');
        notification.className = 'notification-toast alert alert-dismissible fade show position-fixed';
        notification.setAttribute('role', type === 'error' || type === 'danger' ? 'alert' : 'status');
        notification.style.cssText = `top: 20px; right: 20px; z-index: 1050; max-width: 350px;
            box-shadow: 0 8px 15px rgb(var(--c-fixed-000000, 0 0 0) / 0.2); background-color: ${bg} !important; color: ${fg} !important;
            border-left: 4px solid ${border} !important; border-radius: 8px; padding: 12px 16px;`;
        notification.innerHTML = `<i class="fas fa-${icon} me-2"></i><span></span>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close" style="filter: brightness(0) invert(${lightText ? '1' : '0'});"></button>`;
        notification.querySelector('span').textContent = message;   // never parsed as HTML
        document.body.appendChild(notification);

        setTimeout(() => notification.remove(), 5000);
    };
})();

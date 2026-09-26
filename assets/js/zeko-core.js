/* ===== Zeko Core JS Utilities ===== */

window.ZekoCore = window.ZekoCore || {};

/**
 * Show a toast notification.
 *
 * @param {string} message  - Toast message text.
 * @param {string} type     - 'success' | 'error' | 'warning' | 'info' (default 'info').
 * @param {number} duration - Auto-dismiss in ms (default 4000). Pass 0 to keep until close.
 */
ZekoCore.toast = function(message, type, duration) {
    type     = type || 'info';
    duration = typeof duration === 'number' ? duration : 4000;

    // Ensure container exists.
    var container = document.getElementById('zeko-toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'zeko-toast-container';
        container.setAttribute('aria-live', 'polite');
        container.setAttribute('aria-atomic', 'true');
        container.style.cssText = 'position:fixed;top:20px;right:20px;z-index:1000000;display:flex;flex-direction:column;gap:8px;pointer-events:none;max-width:380px;';
        document.body.appendChild(container);
    }

    var icons = {
        success: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
        error:   '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
        warning: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        info:    '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'
    };

    var colors = {
success: 'var(--color-success, #1e7e34)',
  error:   'var(--color-danger, #dc3545)',
  warning: 'var(--color-warning, #a8620a)',
  info:    'var(--color-info, #117a8b)'
    };

    var bgColors = {
        success: 'var(--color-success-light, #d4edda)',
        error:   'var(--color-danger-light, #f8d7da)',
        warning: 'var(--color-warning-light, #fff3cd)',
        info:    'var(--color-info-light, #d1ecf1)'
    };

    var toast = document.createElement('div');
    toast.className = 'zeko-toast zeko-toast--' + type;
    toast.setAttribute('role', 'alert');
    toast.style.cssText = 'display:flex;align-items:flex-start;gap:10px;padding:12px 16px;border-radius:var(--radius-md, 6px);background:' + bgColors[type] + ';color:' + colors[type] + ';box-shadow:0 4px 12px rgba(0,0,0,0.15);pointer-events:auto;font-size:14px;line-height:1.4;animation:zeko-toast-in 0.3s ease;transition:opacity 0.3s ease,transform 0.3s ease;';

    toast.innerHTML = '<span style="flex-shrink:0;margin-top:1px;">' + (icons[type] || icons.info) + '</span>'
        + '<span style="flex:1;">' + message + '</span>'
        + '<button type="button" aria-label="Dismiss" style="flex-shrink:0;background:none;border:none;color:inherit;cursor:pointer;padding:0;font-size:18px;line-height:1;opacity:0.7;" onclick="this.closest(\'.zeko-toast\').remove();">&times;</button>';

    container.appendChild(toast);

    // Inject keyframes if not yet present.
    if (!document.getElementById('zeko-toast-keyframes')) {
        var style = document.createElement('style');
        style.id = 'zeko-toast-keyframes';
        style.textContent = '@keyframes zeko-toast-in{from{opacity:0;transform:translateX(20px);}to{opacity:1;transform:translateX(0);}}';
        document.head.appendChild(style);
    }

    if (duration > 0) {
        setTimeout(function() {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(20px)';
            setTimeout(function() { toast.remove(); }, 300);
        }, duration);
    }

    return toast;
};

// Convenience wrappers.
ZekoCore.toastSuccess = function(msg, dur) { return ZekoCore.toast(msg, 'success', dur); };
ZekoCore.toastError   = function(msg, dur) { return ZekoCore.toast(msg, 'error', dur); };
ZekoCore.toastWarning = function(msg, dur) { return ZekoCore.toast(msg, 'warning', dur); };
ZekoCore.toastInfo    = function(msg, dur) { return ZekoCore.toast(msg, 'info', dur); };

/**
 * Debounce a function call.
 */
ZekoCore.debounce = function(func, wait) {
    var timeout;
    return function() {
        var context = this, args = arguments;
        clearTimeout(timeout);
        timeout = setTimeout(function() { func.apply(context, args); }, wait);
    };
};

/**
 * Open a modal by ID.
 *
 * The modal element should have role="dialog" and aria-modal="true".
 * A backdrop overlay is created automatically if one doesn't exist.
 *
 * @param {string} modalId - The ID of the modal wrapper element.
 */
ZekoCore.openModal = function(modalId) {
    var modal = document.getElementById(modalId);
    if (!modal) return;

    // Create backdrop if needed.
    var backdrop = document.getElementById(modalId + '-backdrop');
    if (!backdrop) {
        backdrop = document.createElement('div');
        backdrop.id = modalId + '-backdrop';
        backdrop.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:999998;animation:zeko-fade-in 0.2s ease;';
        backdrop.addEventListener('click', function() { ZekoCore.closeModal(modalId); });
        document.body.appendChild(backdrop);
    }

    modal.setAttribute('aria-hidden', 'false');
    modal.style.display = 'flex';
    modal.style.zIndex = '999999';
    document.body.style.overflow = 'hidden';

    // ESC key handler.
    modal._zekoEscHandler = function(e) {
        if (e.key === 'Escape') { ZekoCore.closeModal(modalId); }
    };
    document.addEventListener('keydown', modal._zekoEscHandler);

    // Focus the first focusable element.
    var focusable = modal.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
    if (focusable) { focusable.focus(); }

    // Inject animation keyframes if not present.
    if (!document.getElementById('zeko-modal-keyframes')) {
        var style = document.createElement('style');
        style.id = 'zeko-modal-keyframes';
        style.textContent = '@keyframes zeko-fade-in{from{opacity:0;}to{opacity:1;}}';
        document.head.appendChild(style);
    }
};

/**
 * Close a modal by ID.
 *
 * @param {string} modalId - The ID of the modal wrapper element.
 */
ZekoCore.closeModal = function(modalId) {
    var modal = document.getElementById(modalId);
    if (!modal) return;

    modal.setAttribute('aria-hidden', 'true');
    modal.style.display = 'none';
    document.body.style.overflow = '';

    // Remove backdrop.
    var backdrop = document.getElementById(modalId + '-backdrop');
    if (backdrop) { backdrop.remove(); }

    // Remove ESC handler.
    if (modal._zekoEscHandler) {
        document.removeEventListener('keydown', modal._zekoEscHandler);
        modal._zekoEscHandler = null;
    }
};

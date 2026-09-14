/**
 * assets/js/confirm-modal.js
 * --------------------------------------------------------------------
 * Global reusable confirmation modal + toast notification system.
 *
 *   confirmAction({ type, title, message, confirmText, cancelText, onConfirm })
 *   showToast(type, message)
 *
 * No dependencies. Works with existing CSS variables + dark mode.
 * --------------------------------------------------------------------
 */
(function () {
  'use strict';

  // ---- TOAST SYSTEM (reuse existing .toast classes from style.css) ----
  window.showToast = function (type, message, duration) {
    var stack = document.getElementById('toastStack');
    if (!stack) {
      stack = document.createElement('div');
      stack.id = 'toastStack';
      stack.className = 'toast-stack';
      stack.setAttribute('aria-live', 'polite');
      stack.setAttribute('aria-atomic', 'true');
      document.body.appendChild(stack);
    }
    var icons = {
      success: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
      error:   '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>',
      warning: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>',
      info:    '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>',
    };
    var toast = document.createElement('div');
    toast.className = 'toast toast--' + (type || 'info');
    toast.setAttribute('role', 'alert');
    toast.innerHTML =
      '<span class="toast__icon">' + (icons[type] || icons.info) + '</span>' +
      '<span class="toast__text">' + escapeHtml(message) + '</span>' +
      '<button class="toast__close" aria-label="Dismiss" type="button">' +
      '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button>' +
      '<span class="toast__progress"></span>';
    stack.appendChild(toast);

    var ms = duration || 3000;
    var timer = setTimeout(function () { dismissToast(toast); }, ms);
    toast.addEventListener('mouseenter', function () { clearTimeout(timer); });
    toast.addEventListener('mouseleave', function () { timer = setTimeout(function () { dismissToast(toast); }, 1500); });
    var closeBtn = toast.querySelector('.toast__close');
    if (closeBtn) closeBtn.addEventListener('click', function () { clearTimeout(timer); dismissToast(toast); });
  };

  function dismissToast(toast) {
    if (!toast || toast.classList.contains('is-leaving')) return;
    toast.classList.add('is-leaving');
    setTimeout(function () { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 280);
  }

  // ---- CONFIRMATION MODAL ----
  var modalOverlay = null;
  var modalBox = null;
  var focusBefore = null;

  function ensureModal() {
    if (modalOverlay) return;
    modalOverlay = document.createElement('div');
    modalOverlay.className = 'confirm-overlay';
    modalOverlay.setAttribute('role', 'dialog');
    modalOverlay.setAttribute('aria-modal', 'true');
    modalOverlay.style.display = 'none';
    modalOverlay.innerHTML =
      '<div class="confirm-modal" tabindex="-1">' +
        '<div class="confirm-modal__icon"></div>' +
        '<h3 class="confirm-modal__title"></h3>' +
        '<p class="confirm-modal__message"></p>' +
        '<div class="confirm-modal__buttons">' +
          '<button class="confirm-modal__cancel" type="button"></button>' +
          '<button class="confirm-modal__confirm" type="button"></button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(modalOverlay);
    modalBox = modalOverlay.querySelector('.confirm-modal');
  }

  window.confirmAction = function (opts) {
    opts = opts || {};
    ensureModal();

    var type = opts.type || 'danger';
    var typeConfig = {
      danger:  { icon: '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/></svg>', iconBg: '#fef3f2', iconColor: '#d9534a', confirmClass: 'confirm-modal__confirm--danger' },
      warning: { icon: '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/></svg>', iconBg: '#fff7e9', iconColor: '#c4730a', confirmClass: 'confirm-modal__confirm--warning' },
      info:    { icon: '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>', iconBg: '#eff5ff', iconColor: '#2563eb', confirmClass: 'confirm-modal__confirm--info' },
      success: { icon: '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>', iconBg: '#ecfdf3', iconColor: '#058555', confirmClass: 'confirm-modal__confirm--success' },
    };
    var cfg = typeConfig[type] || typeConfig.danger;

    // Dark mode colors
    var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    if (isDark) {
      cfg.iconBg = type === 'danger' ? 'rgba(217,83,74,.12)' : type === 'warning' ? 'rgba(196,115,10,.12)' : type === 'success' ? 'rgba(5,133,85,.12)' : 'rgba(37,99,235,.12)';
    }

    // Set content
    modalOverlay.querySelector('.confirm-modal__icon').innerHTML = cfg.icon;
    modalOverlay.querySelector('.confirm-modal__icon').style.background = cfg.iconBg;
    modalOverlay.querySelector('.confirm-modal__icon').style.color = cfg.iconColor;
    modalOverlay.querySelector('.confirm-modal__title').textContent = opts.title || 'Are you sure?';
    modalOverlay.querySelector('.confirm-modal__message').textContent = opts.message || '';
    var cancelBtn = modalOverlay.querySelector('.confirm-modal__cancel');
    var confirmBtn = modalOverlay.querySelector('.confirm-modal__confirm');
    cancelBtn.textContent = opts.cancelText || 'Cancel';
    confirmBtn.textContent = opts.confirmText || 'Confirm';
    confirmBtn.className = 'confirm-modal__confirm ' + cfg.confirmClass;

    // Show
    focusBefore = document.activeElement;
    modalOverlay.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    setTimeout(function () { confirmBtn.focus(); }, 50);

    // Handlers
    var processing = false;
    function close() {
      modalOverlay.style.display = 'none';
      document.body.style.overflow = '';
      if (focusBefore) focusBefore.focus();
    }
    cancelBtn.onclick = function () { if (!processing) close(); };
    confirmBtn.onclick = function () {
      if (processing) return;
      processing = true;
      confirmBtn.disabled = true;
      confirmBtn.textContent = (opts.loadingText || 'Processing...');
      confirmBtn.classList.add('is-loading');
      if (typeof opts.onConfirm === 'function') {
        var result = opts.onConfirm(function () {
          // success callback
          close();
          processing = false;
          confirmBtn.disabled = false;
          confirmBtn.classList.remove('is-loading');
        }, function () {
          // error callback
          processing = false;
          confirmBtn.disabled = false;
          confirmBtn.classList.remove('is-loading');
          confirmBtn.textContent = opts.confirmText || 'Confirm';
        });
        // If onConfirm returns a promise
        if (result && typeof result.then === 'function') {
          result.then(function () { close(); processing = false; confirmBtn.disabled = false; confirmBtn.classList.remove('is-loading'); }).catch(function () { processing = false; confirmBtn.disabled = false; confirmBtn.classList.remove('is-loading'); confirmBtn.textContent = opts.confirmText || 'Confirm'; });
        } else if (result !== false) {
          // Synchronous success
          close();
          processing = false;
          confirmBtn.disabled = false;
          confirmBtn.classList.remove('is-loading');
        } else {
          // Synchronous error — re-enable
          processing = false;
          confirmBtn.disabled = false;
          confirmBtn.classList.remove('is-loading');
          confirmBtn.textContent = opts.confirmText || 'Confirm';
        }
      } else {
        close();
        processing = false;
      }
    };

    // Keyboard
    modalOverlay.onkeydown = function (e) {
      if (e.key === 'Escape' && !processing) { close(); }
      if (e.key === 'Enter' && !processing) { confirmBtn.click(); }
    };
    // Click outside to close
    modalOverlay.onclick = function (e) {
      if (e.target === modalOverlay && !processing) close();
    };
  };

  // ---- Auto-wire: replace browser confirm() on forms with data-confirm ----
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (form.dataset.confirmWired) return;
    var msg = form.getAttribute('data-confirm');
    if (!msg) {
      // Check for onsubmit="return confirm('...')" pattern
      var onsub = form.getAttribute('onsubmit') || '';
      var match = onsub.match(/confirm\(['"](.+?)['"]\)/);
      if (match) {
        msg = match[1];
        form.removeAttribute('onsubmit');
      }
    }
    if (!msg) return;
    e.preventDefault();
    form.dataset.confirmWired = '1';
    confirmAction({
      type: form.dataset.confirmType || 'danger',
      title: msg,
      message: form.dataset.confirmMessage || 'This action cannot be undone.',
      confirmText: form.dataset.confirmText || 'Confirm',
      cancelText: 'Cancel',
      onConfirm: function () { form.submit(); return true; }
    });
  });

  // ---- Auto-wire: replace confirm() on buttons/links ----
  document.addEventListener('click', function (e) {
    var el = e.target.closest('[onclick*="confirm("]');
    if (!el || el.dataset.confirmWired) return;
    var onclick = el.getAttribute('onclick') || '';
    var match = onclick.match(/confirm\(['"](.+?)['"]\)/);
    if (!match) return;
    e.preventDefault();
    e.stopPropagation();
    el.dataset.confirmWired = '1';
    var msg = match[1];
    // Remove the onclick confirm
    el.removeAttribute('onclick');
    confirmAction({
      type: el.dataset.confirmType || 'danger',
      title: msg,
      message: el.dataset.confirmMessage || 'This action cannot be undone.',
      confirmText: el.dataset.confirmText || 'Confirm',
      cancelText: 'Cancel',
      onConfirm: function () {
        // If it's a form submit button, submit the form
        if (el.tagName === 'BUTTON' && el.type === 'submit') {
          var f = el.closest('form');
          if (f) { f.submit(); return true; }
        }
        // If it's a link, navigate
        if (el.tagName === 'A' && el.href) {
          window.location.href = el.href;
          return true;
        }
        return true;
      }
    });
  }, true); // capture phase to intercept before inline onclick

  // ---- Helper: escape HTML ----
  function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }
})();

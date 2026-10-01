/* =====================================================================
   ISOKO RYACU — favorites.js
   AJAX favorites toggle (no page reload).
   Any element with [data-fav-listing="<id>"] becomes a favorite button.
   On click, posts to /api/v1/favorites and updates the icon state.
   ===================================================================== */
(function () {
  'use strict';

  function getAppUrl() {
    return document.documentElement.getAttribute('data-app-url') || '';
  }

  function getCsrfToken() {
    // Read the CSRF token from the meta tag set by header.php
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  function isFav(el) { return el.classList.contains('is-saved'); }

  function toggleIcon(el) {
    var svg = el.querySelector('svg path');
    if (!svg) return;
    if (isFav(el)) {
      svg.setAttribute('fill', 'currentColor');
    } else {
      svg.setAttribute('fill', 'none');
    }
  }

  document.querySelectorAll('[data-fav-listing]').forEach(function (btn) {
    if (btn.dataset.favBound === '1') return;
    btn.dataset.favBound = '1';
    btn.setAttribute('role', 'button');
    btn.setAttribute('tabindex', '0');
    btn.addEventListener('click', function (ev) {
      ev.preventDefault();
      ev.stopPropagation();
      var id = btn.getAttribute('data-fav-listing');
      if (!id) return;
      // Require login — read meta tag
      var loggedIn = document.querySelector('meta[name="user-logged-in"]');
      if (!loggedIn || loggedIn.getAttribute('content') !== '1') {
        window.location.href = getAppUrl() + '/pages/login.php?next=' + encodeURIComponent(window.location.pathname + window.location.search);
        return;
      }
      var action = isFav(btn) ? 'remove' : 'add';
      var url = getAppUrl() + '/api/v1/favorites/?id=' + encodeURIComponent(id) + '&action=' + action;
      fetch(url, {
        method: 'POST',
        headers: {
          'X-CSRF-Token': getCsrfToken(),
          'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin'
      }).then(function (r) { return r.json(); }).then(function (data) {
        if (data && data.success) {
          btn.classList.toggle('is-saved', data.state === 'saved');
          toggleIcon(btn);
          // Update count badges if present
          if (data.favorites_count !== undefined) {
            document.querySelectorAll('[data-fav-count="' + id + '"]').forEach(function (e) {
              e.textContent = data.favorites_count;
            });
          }
          // Show a brief flash message
          if (data.message) {
            window.dispatchEvent(new CustomEvent('isoko:notify', { detail: { message: data.message, type: 'success' } }));
          }
        } else if (data && data.error) {
          window.dispatchEvent(new CustomEvent('isoko:notify', { detail: { message: data.error, type: 'error' } }));
        }
      }).catch(function (e) {
        // Silent — don't bother the user with network errors
        console.warn('Favorite toggle failed:', e);
      });
    });
    // Keyboard support
    btn.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); btn.click(); }
    });
    toggleIcon(btn);
  });

  // Listen for inline notifications
  window.addEventListener('isoko:notify', function (ev) {
    var n = document.createElement('div');
    n.className = 'flash flash--' + (ev.detail.type || 'info');
    n.style.position = 'fixed';
    n.style.bottom = '20px';
    n.style.left = '50%';
    n.style.transform = 'translateX(-50%)';
    n.style.zIndex = '9999';
    n.style.maxWidth = '400px';
    n.textContent = ev.detail.message;
    document.body.appendChild(n);
    setTimeout(function () { n.style.opacity = '0'; n.style.transition = 'opacity 0.4s'; }, 2200);
    setTimeout(function () { if (n.parentNode) n.parentNode.removeChild(n); }, 2700);
  });
})();

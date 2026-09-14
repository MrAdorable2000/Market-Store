/* =====================================================================
   ISOKO RYACU — messages-poll.js
   Keeps the navbar "Messages" unread badge live without a full page
   reload, by polling api/v1/messages-poll?action=unread_count.
   Loaded on every page for logged-in users (see includes/footer.php).
   ===================================================================== */
(function () {
  'use strict';

  function getAppUrl() {
    return document.documentElement.getAttribute('data-app-url') || '';
  }

  function updateBadge(count) {
    var bell = document.querySelector('a[href$="/pages/messages.php"].nav__bell');
    if (!bell) return;
    var dot = bell.querySelector('.nav__bell-dot');
    if (count > 0 && !dot) {
      dot = document.createElement('span');
      dot.className = 'nav__bell-dot';
      dot.setAttribute('aria-hidden', 'true');
      bell.appendChild(dot);
    } else if (count <= 0 && dot) {
      dot.remove();
    }
  }

  function poll() {
    fetch(getAppUrl() + '/api/v1/messages-poll/?action=unread_count', {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data && typeof data.count === 'number') updateBadge(data.count);
      })
      .catch(function () { /* silent — polling should never disrupt the page */ });
  }

  // First check shortly after load, then every 20s.
  setTimeout(poll, 3000);
  setInterval(poll, 20000);
})();

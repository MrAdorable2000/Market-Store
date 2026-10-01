/* =====================================================================
   ISOKO RYACU — main.js
   Theme toggle · Mobile drawer · Dropdown · Lazy load · Image fallback
   Flash dismiss · Active nav link
   Plain vanilla JS — no dependencies.
   ===================================================================== */
(function () {
  'use strict';

  // ---- Automatic logout after inactivity ----------------------------
  // Server-side enforcement lives in includes/auth.php. This browser timer
  // handles the case where a protected page stays open without navigation.
  (function () {
    var loggedMeta = document.querySelector('meta[name="user-logged-in"]');
    var timeoutMeta = document.querySelector('meta[name="session-idle-timeout"]');
    var heartbeatMeta = document.querySelector('meta[name="session-idle-heartbeat"]');
    var appRoot = document.documentElement.getAttribute('data-app-url') || '';
    if (!loggedMeta || loggedMeta.getAttribute('content') !== '1' || !timeoutMeta) return;

    var timeoutMs = parseInt(timeoutMeta.getAttribute('content') || '0', 10) * 1000;
    var heartbeatMs = parseInt(heartbeatMeta ? heartbeatMeta.getAttribute('content') : '60', 10) * 1000;
    if (!timeoutMs || timeoutMs < 1000) return;
    if (!heartbeatMs || heartbeatMs < 10000) heartbeatMs = 60000;

    var lastActivity = Date.now();
    var lastHeartbeat = 0;
    var timer = null;
    var activityThrottle = 0;
    var loggingOut = false;

    function logoutForIdle() {
      if (loggingOut) return;
      loggingOut = true;
      var url = appRoot + '/pages/logout.php?reason=idle';
      window.location.replace(url);
    }

    function schedule() {
      if (timer) window.clearTimeout(timer);
      var remaining = timeoutMs - (Date.now() - lastActivity);
      if (remaining <= 0) {
        logoutForIdle();
        return;
      }
      timer = window.setTimeout(logoutForIdle, remaining);
    }

    function heartbeat() {
      var now = Date.now();
      if (now - lastHeartbeat < heartbeatMs) return;
      lastHeartbeat = now;
      var csrf = document.querySelector('meta[name="csrf-token"]');
      fetch(appRoot + '/api/v1/auth/activity.php', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'X-CSRF-Token': csrf ? csrf.getAttribute('content') : '',
          'Accept': 'application/json'
        }
      }).then(function (res) {
        if (res.status === 401) logoutForIdle();
      }).catch(function () {
        // Temporary network failure must not force a logout. The server-side
        // timeout remains the final authority on the next authenticated request.
      });
    }

    function markActivity() {
      var now = Date.now();
      if (now - activityThrottle < 1000) return;
      activityThrottle = now;
      lastActivity = now;
      schedule();
      heartbeat();
    }

    ['click', 'keydown', 'pointerdown', 'touchstart', 'input', 'change', 'scroll', 'mousemove'].forEach(function (eventName) {
      document.addEventListener(eventName, markActivity, { passive: true });
    });

    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'visible') {
        var now = Date.now();
        if (now - lastActivity >= timeoutMs) logoutForIdle();
        else { schedule(); heartbeat(); }
      }
    });

    schedule();
  })();

  // ---- Theme toggle --------------------------------------------------
  var themeBtn = document.getElementById('themeToggle');
  if (themeBtn) {
    themeBtn.addEventListener('click', function () {
      var html = document.documentElement;
      var next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      html.setAttribute('data-theme', next);
      try { localStorage.setItem('isoko-theme', next); } catch (e) {}
    });
  }

  // ---- Mobile nav drawer ---------------------------------------------
  var navToggle = document.getElementById('navToggle');
  var mobileNav = document.getElementById('mobileNav');
  if (navToggle && mobileNav) {
    navToggle.addEventListener('click', function () {
      var open = mobileNav.classList.toggle('open');
      navToggle.classList.toggle('active', open);
      mobileNav.hidden = false;
      document.body.style.overflow = open ? 'hidden' : '';
    });
    // Close when clicking a link
    mobileNav.querySelectorAll('a').forEach(function (a) {
      a.addEventListener('click', function () {
        mobileNav.classList.remove('open');
        navToggle.classList.remove('active');
        document.body.style.overflow = '';
      });
    });
    // Close on outside click
    document.addEventListener('click', function (ev) {
      if (!mobileNav.contains(ev.target) && !navToggle.contains(ev.target) && mobileNav.classList.contains('open')) {
        mobileNav.classList.remove('open');
        navToggle.classList.remove('active');
        document.body.style.overflow = '';
      }
    });
  }

  // ---- User dropdown -------------------------------------------------
  document.querySelectorAll('.dropdown').forEach(function (dd) {
    var trigger = dd.querySelector('.dropdown__trigger');
    if (!trigger) return;
    trigger.addEventListener('click', function (ev) {
      ev.stopPropagation();
      var open = dd.classList.toggle('open');
      trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.addEventListener('click', function () {
      dd.classList.remove('open');
      trigger.setAttribute('aria-expanded', 'false');
    });
  });

  // ---- Language selector dropdown ------------------------------------
  // (Same pattern as the user dropdown, but with the .lang-switcher class)
  document.querySelectorAll('.lang-switcher').forEach(function (ls) {
    var btn = ls.querySelector('.lang-switcher__btn');
    if (!btn) return;
    btn.addEventListener('click', function (ev) {
      ev.stopPropagation();
      var open = ls.classList.toggle('open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      // Close other open dropdowns / language switchers
      document.querySelectorAll('.lang-switcher.open, .dropdown.open').forEach(function (other) {
        if (other !== ls) {
          other.classList.remove('open');
          var otherBtn = other.querySelector('.lang-switcher__btn, .dropdown__trigger');
          if (otherBtn) otherBtn.setAttribute('aria-expanded', 'false');
        }
      });
    });
  });
  // Close language switcher on outside click
  document.addEventListener('click', function () {
    document.querySelectorAll('.lang-switcher.open').forEach(function (ls) {
      ls.classList.remove('open');
      var btn = ls.querySelector('.lang-switcher__btn');
      if (btn) btn.setAttribute('aria-expanded', 'false');
    });
  });

  // ---- Toast auto-dismiss (2 seconds) + manual close ----------------
  function dismissToast(toast) {
    if (!toast || toast.classList.contains('is-leaving')) return;
    toast.classList.add('is-leaving');
    setTimeout(function () {
      if (toast.parentNode) toast.parentNode.removeChild(toast);
    }, 280);
  }
  document.querySelectorAll('.toast').forEach(function (toast) {
    // Auto-dismiss after 2 seconds (2000ms)
    var timer = setTimeout(function () { dismissToast(toast); }, toast.classList.contains('toast--welcome') ? 1800 : 2000);
    // Pause auto-dismiss on hover so the user can read long messages
    if (!toast.classList.contains('toast--welcome')) toast.addEventListener('mouseenter', function () { clearTimeout(timer); });
    if (!toast.classList.contains('toast--welcome')) toast.addEventListener('mouseleave', function () {
      timer = setTimeout(function () { dismissToast(toast); }, 1000);
    });
    // Manual close button
    var closeBtn = toast.querySelector('.toast__close');
    if (closeBtn) {
      closeBtn.addEventListener('click', function () {
        clearTimeout(timer);
        dismissToast(toast);
      });
    }
  });

  // ---- Lazy loading + image fallback --------------------------------
  // <img loading="lazy" data-src="..."> defers the request until on-screen.
  // On error, falls back to a placeholder so the UI never shows a broken image.
  // The fallback path is auto-detected from the <html data-app-url> attribute
  // that header.php injects, so it works regardless of the folder name.
  function getFallback() {
    var root = document.documentElement.getAttribute('data-app-url') || '';
    return root + '/assets/images/placeholders/default.svg';
  }
  function applyFallback(img) {
    if (!img) return;
    var fallback = img.dataset.fallback || getFallback();
    if (img.src !== fallback) { img.src = fallback; }
  }
  document.querySelectorAll('img').forEach(function (img) {
    img.addEventListener('error', function () { applyFallback(img); });
  });

  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          var img = entry.target;
          if (img.dataset.src) { img.src = img.dataset.src; img.removeAttribute('data-src'); }
          io.unobserve(img);
        }
      });
    }, { rootMargin: '200px' });
    document.querySelectorAll('img[data-src]').forEach(function (img) { io.observe(img); });
  } else {
    document.querySelectorAll('img[data-src]').forEach(function (img) { img.src = img.dataset.src; });
  }

  // ---- Active nav link (by current path) ----------------------------
  // Compares the link's pathname against the current page's pathname.
  // Active link = path begins with the link's path, AND the link isn't
  // just the project root (which would match every page).
  var appRoot = (document.documentElement.getAttribute('data-app-url') || '').replace(/^https?:\/\/[^\/]+/, '');
  var here = window.location.pathname.replace(/\/$/, '');
  document.querySelectorAll('.nav--primary .nav__link').forEach(function (a) {
    var href = a.getAttribute('href');
    if (!href) return;
    var path;
    try { path = new URL(href, window.location.origin).pathname.replace(/\/$/, ''); }
    catch (e) { return; }
    if (path === here || (path !== '' && path !== appRoot && here.indexOf(path) === 0)) {
      a.classList.add('is-active');
      a.style.color = 'var(--brand-700)';
      a.style.background = 'var(--bg-soft)';
    }
  });

  // ---- Save-to-favorites (UI only; Phase 3 wires to backend) ---------
  document.querySelectorAll('.listing-card__fav').forEach(function (btn) {
    btn.addEventListener('click', function (ev) {
      ev.preventDefault();
      ev.stopPropagation();
      btn.classList.toggle('is-saved');
    });
  });
})();

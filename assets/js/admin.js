/* =====================================================================
   ISOKO RYACU — admin.js
   --------------------------------------------------------------------
   Admin-area interactions only (loaded via includes/admin_footer.php):
     0. Theme toggle (light / dark, shared with the public website)
     1. Sidebar: desktop collapse (persisted) + mobile off-canvas
     2. Dropdown panels (notifications + profile) with outside-click
     3. Confirmation modals for destructive actions (no browser alerts)
     4. Flash messages -> animated toasts with auto-dismiss
     5. Button loading states on form submit
     6. Image fallbacks (never show broken images)
     7. 3D card tilt + pointer glare for KPI cards (fine pointers only)
   Plain vanilla JS — no dependencies, respects reduced motion.
   ===================================================================== */
(function () {
  'use strict';

  var shell = document.getElementById('aShell');
  var sidebar = document.getElementById('aSidebar');
  var backdrop = document.getElementById('aBackdrop');
  var toggle = document.getElementById('aSidebarToggle');

  function isMobile() { return window.matchMedia('(max-width: 900px)').matches; }

  /* 0. Theme toggle ----------------------------------------------------
     Uses the SAME 'isoko-theme' localStorage key + html[data-theme]
     switch as the public website, so one choice follows the user
     across the whole platform. The visible icon swaps via CSS.      */
  var themeBtn = document.getElementById('aThemeToggle');

  function syncThemeUI() {
    if (!themeBtn) return;
    var dark = document.documentElement.getAttribute('data-theme') === 'dark';
    themeBtn.setAttribute('aria-pressed', dark ? 'true' : 'false');
    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', dark ? '#0b1517' : '#0ea5a4');
  }

  if (themeBtn) {
    syncThemeUI();
    themeBtn.addEventListener('click', function () {
      var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', next);
      try { localStorage.setItem('isoko-theme', next); } catch (e) { /* storage unavailable */ }
      syncThemeUI();
    });
  }

  /* 1. Sidebar toggle -------------------------------------------------
     Desktop (>900px): collapses to an icon rail, choice remembered.
     Mobile (<=900px): opens the off-canvas drawer + backdrop.        */
  if (toggle && shell && sidebar) {
    // Restore remembered collapse state on desktop only
    try {
      if (!isMobile() && localStorage.getItem('isoko-admin-collapsed') === '1') {
        shell.classList.add('is-collapsed');
      }
    } catch (e) { /* storage unavailable — fine */ }

    toggle.addEventListener('click', function () {
      if (isMobile()) {
        var open = sidebar.classList.toggle('is-open');
        if (backdrop) backdrop.classList.toggle('is-open', open);
        document.body.style.overflow = open ? 'hidden' : '';
      } else {
        var collapsed = shell.classList.toggle('is-collapsed');
        try { localStorage.setItem('isoko-admin-collapsed', collapsed ? '1' : '0'); } catch (e) {}
      }
    });

    // Close drawer when backdrop clicked
    if (backdrop) {
      backdrop.addEventListener('click', function () {
        sidebar.classList.remove('is-open');
        backdrop.classList.remove('is-open');
        document.body.style.overflow = '';
      });
    }
    // Close drawer after following a link (nice return experience)
    sidebar.querySelectorAll('a').forEach(function (a) {
      a.addEventListener('click', function () {
        if (isMobile() && sidebar.classList.contains('is-open')) {
          sidebar.classList.remove('is-open');
          if (backdrop) backdrop.classList.remove('is-open');
          document.body.style.overflow = '';
        }
      });
    });
    // Reset drawer state when crossing back to desktop
    window.addEventListener('resize', function () {
      if (!isMobile()) {
        sidebar.classList.remove('is-open');
        if (backdrop) backdrop.classList.remove('is-open');
        document.body.style.overflow = '';
      }
    });
  }

  /* 2. Dropdown panels ------------------------------------------------ */
  document.querySelectorAll('.a-drop').forEach(function (drop) {
    var trigger = drop.querySelector('.a-drop__trigger');
    if (!trigger) return;
    trigger.addEventListener('click', function (ev) {
      ev.stopPropagation();
      var willOpen = !drop.classList.contains('is-open');
      document.querySelectorAll('.a-drop.is-open').forEach(function (d) {
        d.classList.remove('is-open');
      });
      drop.classList.toggle('is-open', willOpen);
      trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    });
  });
  document.addEventListener('click', function () {
    document.querySelectorAll('.a-drop.is-open').forEach(function (d) { d.classList.remove('is-open'); });
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') {
      document.querySelectorAll('.a-drop.is-open').forEach(function (d) { d.classList.remove('is-open'); });
      closeModal();
    }
  });

  /* 3. Confirmation modals ----------------------------------------------
     Any form with [data-confirm] opens a styled modal instead of the
     ugly browser confirm(). The form submits only after "Confirm".    */
  var modalRoot = null;

  function ensureModal() {
    if (modalRoot) return modalRoot;
    modalRoot = document.createElement('div');
    modalRoot.className = 'a-modal';
    modalRoot.setAttribute('role', 'dialog');
    modalRoot.setAttribute('aria-modal', 'true');
    modalRoot.innerHTML =
      '<div class="a-modal__backdrop" data-close></div>' +
      '<div class="a-modal__box">' +
      '  <div class="a-modal__head">' +
      '    <span class="a-modal__icon">' +
      '      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
      '    </span>' +
      '    <div><div class="a-modal__title" data-mtitle></div><p class="a-modal__sub" data-msub></p></div>' +
      '    <button type="button" class="a-modal__close" data-close aria-label="Close">' +
      '      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M18 6 6 18M6 6l12 12" stroke-linecap="round"/></svg>' +
      '    </button>' +
      '  </div>' +
      '  <div class="a-modal__body" data-mbody></div>' +
      '  <div class="a-modal__foot">' +
      '    <button type="button" class="a-btn" data-close>Cancel</button>' +
      '    <button type="button" class="a-btn a-btn--danger" data-mconfirm>Confirm</button>' +
      '  </div>' +
      '</div>';
    document.body.appendChild(modalRoot);
    modalRoot.addEventListener('click', function (ev) {
      if (ev.target.closest('[data-close]')) closeModal();
    });
    return modalRoot;
  }

  function openModal(opts) {
    var m = ensureModal();
    m.querySelector('[data-mtitle]').textContent = opts.title || 'Are you sure?';
    m.querySelector('[data-msub]').textContent = opts.sub || '';
    m.querySelector('[data-mbody]').textContent = opts.body || '';
    var confirmBtn = m.querySelector('[data-mconfirm]');
    confirmBtn.textContent = opts.confirmLabel || 'Confirm';
    confirmBtn.className = 'a-btn ' + (opts.danger === false ? 'a-btn--primary' : 'a-btn--danger');
    confirmBtn.onclick = function () {
      closeModal();
      if (typeof opts.onConfirm === 'function') opts.onConfirm();
    };
    m.classList.add('is-open');
    document.body.style.overflow = 'hidden';
  }
  function closeModal() {
    if (modalRoot && modalRoot.classList.contains('is-open')) {
      modalRoot.classList.remove('is-open');
      document.body.style.overflow = '';
    }
  }

  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      if (form.dataset.confirmed === '1') { delete form.dataset.confirmed; return; }
      ev.preventDefault();
      openModal({
        title: form.getAttribute('data-confirm-title') || 'Please confirm',
        sub: form.getAttribute('data-confirm-sub') || '',
        body: form.getAttribute('data-confirm') || '',
        confirmLabel: form.getAttribute('data-confirm-label') || 'Confirm',
        onConfirm: function () {
          form.dataset.confirmed = '1';
          form.submit();
        }
      });
    });
  });

  /* 4. Flash messages -> toasts ---------------------------------------- */
  document.querySelectorAll('.flash').forEach(function (el) {
    // auto-dismiss (non-error stay a bit longer)
    var ms = el.classList.contains('toast--welcome') ? 1800 : (el.classList.contains('flash--error') ? 7000 : 4200);
    var t = setTimeout(function () { dismiss(el); }, ms);
    if (!el.classList.contains('toast--welcome')) el.addEventListener('mouseenter', function () { clearTimeout(t); });
    if (!el.classList.contains('toast--welcome')) el.addEventListener('mouseleave', function () { t = setTimeout(function () { dismiss(el); }, 1600); });
    var close = el.querySelector('.flash__close');
    if (close) close.addEventListener('click', function () { clearTimeout(t); dismiss(el); });
  });
  function dismiss(el) {
    if (el.classList.contains('is-hiding')) return;
    el.classList.add('is-hiding');
    setTimeout(function () { el.remove(); }, 320);
  }

  /* 5. Button loading state on submit ---------------------------------- */
  document.querySelectorAll('form').forEach(function (form) {
    form.addEventListener('submit', function () {
      // only decorate the button that was actually pressed
      var btn = form.querySelector('.a-btn.is-submitting');
      if (btn) return;
      var active = document.activeElement;
      if (active && active.classList && active.classList.contains('a-btn')) {
        active.classList.add('is-submitting');
      } else if (form.querySelector('button[type="submit"]')) {
        // keyboard submit (Enter) — mark the first submit button
        form.querySelector('button[type="submit"]').classList.add('is-submitting');
      }
    });
  });

  /* 6. Image fallbacks (never broken images) --------------------------- */
  function fallback(img) {
    var root = document.documentElement.getAttribute('data-app-url') || '';
    var fb = img.dataset.fallback || (root + '/assets/images/placeholders/default.svg');
    if (img.src !== fb) img.src = fb;
  }
  document.querySelectorAll('img').forEach(function (img) {
    img.addEventListener('error', function () { fallback(img); });
  });

  /* 7. 3D card tilt + pointer glare -------------------------------------
     KPI cards get a subtle perspective tilt and a light reflection that
     follows the cursor (CSS reads --gx / --gy on .a-kpi::after).
     Only runs on hover-capable fine pointers (desktop) and is skipped
     entirely when the user prefers reduced motion — the plain CSS hover
     lift remains as the graceful fallback in both cases.               */
  (function () {
    if (!window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    var MAX_TILT = 5; // degrees — perceptible but still tasteful

    document.querySelectorAll('.a-kpi').forEach(function (card) {
      var raf = null;

      card.addEventListener('mouseenter', function () {
        card.classList.add('is-tilting');
      });

      card.addEventListener('mousemove', function (ev) {
        if (raf) return; // throttle to one update per frame
        raf = requestAnimationFrame(function () {
          raf = null;
          var r = card.getBoundingClientRect();
          if (r.width < 40 || r.height < 40) return;
          var px = Math.min(1, Math.max(0, (ev.clientX - r.left) / r.width));
          var py = Math.min(1, Math.max(0, (ev.clientY - r.top) / r.height));
          var ry = ((px - 0.5) * 2 * MAX_TILT).toFixed(2);   // rotateY follows X
          var rx = (-(py - 0.5) * 2 * MAX_TILT).toFixed(2);  // rotateX follows Y
          card.style.setProperty('--gx', (px * 100).toFixed(1) + '%');
          card.style.setProperty('--gy', (py * 100).toFixed(1) + '%');
          card.style.transform =
            'perspective(900px) rotateX(' + rx + 'deg) rotateY(' + ry + 'deg) translateY(-5px)';
        });
      });

      card.addEventListener('mouseleave', function () {
        if (raf) { cancelAnimationFrame(raf); raf = null; }
        card.classList.remove('is-tilting');
        card.style.transform = ''; // back to the CSS hover/off states
      });
    });
  })();

  /* =====================================================================
     8. ADMIN PULSE — lightweight real-time activity (Phase 10)
     =====================================================================
     Polls /api/v1/admin-pulse every 30 s (only while the tab is visible):
       - refreshes the sidebar + topbar live badges,
       - raises a toast when something NEW happened since the previous
         poll (new user / listing / rental request / report / review /
         message — all REAL database events).
     Silent-failure tolerant: stops after repeated errors, never throws. */
  (function () {
    if (!document.querySelector('.a-shell')) return;

    var POLL_MS = 30000;
    var root = document.documentElement.getAttribute('data-app-url') || '';
    var endpoint = root + '/api/v1/admin-pulse';
    var lastCheck = new Date(Date.now() - 60000).toISOString(); // start with the last minute
    var knownTotals = null;   // events sum from the previous poll
    var failures = 0;

    function badgeElFor(key) {
      var map = {
        pending: '/pages/admin/listings.php',
        reports: '/pages/admin/reports.php',
        rentals: '/pages/admin/rentals.php',
        messages: '/pages/admin/messages.php'
      };
      var href = map[key];
      if (!href) return null;
      var links = document.querySelectorAll('.a-sidebar .a-nav__link, .a-sidefoot .a-nav__link');
      for (var i = 0; i < links.length; i++) {
        if ((links[i].getAttribute('href') || '').indexOf(href) !== -1) {
          return links[i].querySelector('.a-nav__badge');
        }
      }
      return null;
    }

    function setBadge(el, n) {
      if (!el) return;
      if (n > 0) {
        el.textContent = n > 99 ? '99+' : String(n);
        el.style.display = '';
      } else {
        el.style.display = 'none';
      }
    }

    function toast(message) {
      var host = document.querySelector('.a-content') || document.body;
      var el = document.createElement('div');
      el.className = 'flash flash--success a-pulse-toast';
      el.setAttribute('role', 'status');
      el.textContent = message;
      host.insertBefore(el, host.firstChild);
      setTimeout(function () {
        el.classList.add('is-leaving');
        setTimeout(function () { el.remove(); }, 320);
      }, 5000);
    }

    function apply(data) {
      if (!data || data.ok !== true) return;

      // 1. Live badges (sidebar + topbar)
      ['pending', 'reports', 'rentals', 'messages'].forEach(function (k) {
        setBadge(badgeElFor(k), data.badges[k]);
      });
      var msgCount = document.querySelector('.a-iconbtn__count');
      if (msgCount) {
        if (data.badges.messages > 0) {
          msgCount.textContent = data.badges.messages > 99 ? '99+' : String(data.badges.messages);
          msgCount.style.display = '';
        } else { msgCount.style.display = 'none'; }
      }

      // 2. Announce NEW events since the previous poll
      var sum = 0, parts = [];
      var names = { users: 'users', listings: 'listings', rentals: 'rental requests', reports: 'reports', reviews: 'reviews', messages: 'messages' };
      Object.keys(data.events).forEach(function (k) {
        sum += data.events[k];
        if (data.events[k] > 0) parts.push(data.events[k] + ' ' + names[k]);
      });
      if (knownTotals !== null && sum > knownTotals && parts.length) {
        toast('⟳ ' + parts.slice(0, 3).join(' · '));
      }
      knownTotals = sum;
      lastCheck = data.now || new Date().toISOString();
    }

    function tick() {
      if (document.visibilityState === 'hidden') return; // save requests
      fetch(endpoint + '?since=' + encodeURIComponent(lastCheck), {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
        .then(function (r) { if (!r.ok) throw new Error(String(r.status)); return r.json(); })
        .then(function (data) { failures = 0; apply(data); })
        .catch(function () {
          failures += 1;
          if (failures >= 3) clearInterval(timer); // stop quietly if the endpoint is unreachable
        });
    }

    var timer = setInterval(tick, POLL_MS);
    setTimeout(tick, 1500); // first refresh shortly after load
  })();
})();

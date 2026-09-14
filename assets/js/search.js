/* =====================================================================
   ISOKO RYACU — search.js
   Smart Search Autocomplete · Search tabs · Filter chips · Gallery
   =====================================================================
   - Debounced live search from the real database (api/v1/search/)
   - Product suggestions with image, name, category, price
   - Category suggestions
   - Seller suggestions
   - "Did you mean?" for typos
   - Keyboard navigation (↑↓ Enter Escape)
   - Mobile responsive · Dark mode compatible
   ===================================================================== */
(function () {
  'use strict';

  var APP_URL = document.documentElement.getAttribute('data-app-url') || '';
  var CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';

  // ---- Search tabs (Buy / Rent / All) --------------------------------
  document.querySelectorAll('.searchbar').forEach(function (bar) {
    var tabs = bar.querySelectorAll('.searchbar__tab');
    var input = bar.querySelector('input[name="q"]');
    var typeField = bar.querySelector('input[name="type"]');
    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        tabs.forEach(function (t) { t.classList.remove('active'); });
        tab.classList.add('active');
        if (typeField) typeField.value = tab.dataset.type || '';
        if (input) input.focus();
      });
    });
  });

  // ---- Smart Autocomplete -------------------------------------------
  var debounceTimer = null;
  var currentResults = [];
  var selectedIdx = -1;
  var searchSequence = 0;

  document.querySelectorAll('.searchbar__input input').forEach(function (input) {
    var wrap = input.closest('.searchbar') || input.parentElement.parentElement;
    var suggest = wrap.querySelector('.searchbar__suggest');
    if (!suggest) return;

    // Keep the autocomplete panel in normal page flow visually: when it
    // extends below the compact hero, reserve exactly the required space
    // so it never covers Browse by Category or other content.
    function syncSuggestionSpace() {
      var hero = suggest.closest('.hero--bleed');
      if (!hero) return;
      if (!suggest.classList.contains('is-open')) {
        hero.style.marginBottom = '';
        return;
      }
      requestAnimationFrame(function () {
        var hb = hero.getBoundingClientRect().bottom;
        var sb = suggest.getBoundingClientRect().bottom;
        var extra = Math.max(0, Math.ceil(sb - hb) + 14);
        hero.style.marginBottom = extra ? extra + 'px' : '';
      });
    }

    function closeSuggestions() {
      suggest.classList.remove('is-open');
      suggest.innerHTML = '';
      var hero = suggest.closest('.hero--bleed');
      if (hero) hero.style.marginBottom = '';
    }

    function buildUrl(query) {
      return APP_URL + '/api/v1/search/?q=' + encodeURIComponent(query) + '&limit=5';
    }

    function escapeHtml(s) {
      var d = document.createElement('div');
      d.textContent = s || '';
      return d.innerHTML;
    }

    function formatPrice(price, currency) {
      return Number(price || 0).toLocaleString() + ' ' + (currency || 'RWF');
    }

    function imageOrDefault(path) {
      if (path && path.indexOf('http') === 0) return path;
      // The API normally returns a fully resolved URL, but keep this client
      // fallback for older API responses and DB-backed listing photos.
      if (path && path.indexOf('db-blob:') === 0) {
        var id = parseInt(path.slice(8), 10);
        if (id > 0) return APP_URL + '/api/v1/listing-image/?id=' + id;
      }
      if (path) return APP_URL + '/' + path.replace(/^\/+/, '');
      return APP_URL + '/assets/images/placeholders/default.svg';
    }

    function renderResults(data) {
      currentResults = [];
      selectedIdx = -1;
      var html = '';

      // Products
      if (data.products && data.products.length) {
        html += '<div class="ss-section">Products</div>';
        data.products.forEach(function (p) {
          currentResults.push({ type: 'product', url: APP_URL + '/pages/listing-details.php?id=' + p.id });
          html += '<a href="' + APP_URL + '/pages/listing-details.php?id=' + p.id + '" class="ss-item" data-idx="' + (currentResults.length - 1) + '">'
                + '<img src="' + imageOrDefault(p.image) + '" alt="" class="ss-thumb" loading="eager" decoding="async" onerror="this.onerror=null;this.src=\'' + APP_URL + '/assets/images/placeholders/default.svg\'">'
                + '<div class="ss-info"><strong class="ss-title">' + escapeHtml(p.title) + '</strong>'
                + '<span class="ss-meta">' + escapeHtml(p.category_name) + ' · ' + formatPrice(p.price, p.currency) + '</span></div>'
                + '<span class="ss-type ss-type--' + p.listing_type + '">' + (p.listing_type === 'rent' ? 'Rent' : 'Sale') + '</span>'
                + '</a>';
        });
      }

      // Categories
      if (data.categories && data.categories.length) {
        html += '<div class="ss-section">Categories</div>';
        data.categories.forEach(function (c) {
          currentResults.push({ type: 'category', url: APP_URL + '/pages/category.php?slug=' + encodeURIComponent(c.slug) });
          html += '<a href="' + APP_URL + '/pages/category.php?slug=' + encodeURIComponent(c.slug) + '" class="ss-item" data-idx="' + (currentResults.length - 1) + '">'
                + '<span class="ss-icon">📁</span>'
                + '<div class="ss-info"><strong class="ss-title">' + escapeHtml(c.name) + '</strong>'
                + '<span class="ss-meta">Browse all in this category</span></div></a>';
        });
      }

      // Sellers
      if (data.sellers && data.sellers.length) {
        html += '<div class="ss-section">Sellers</div>';
        data.sellers.forEach(function (s) {
          currentResults.push({ type: 'seller', url: APP_URL + '/pages/seller-profile.php?id=' + s.id });
          html += '<a href="' + APP_URL + '/pages/seller-profile.php?id=' + s.id + '" class="ss-item" data-idx="' + (currentResults.length - 1) + '">'
                + '<img src="' + imageOrDefault(s.avatar_path) + '" alt="" class="ss-thumb ss-thumb--avatar" loading="lazy">'
                + '<div class="ss-info"><strong class="ss-title">' + escapeHtml(s.full_name) + '</strong>'
                + '<span class="ss-meta">' + s.listing_count + ' listing(s)</span></div></a>';
        });
      }

      // Did you mean? — clickable smart correction
      if (data.suggestion && !data.products.length && !data.categories.length) {
        html += '<button type="button" class="ss-suggestion" data-suggestion="' + escapeHtml(data.suggestion) + '">'
             + '<span class="ss-suggestion__label">Did you mean?</span>'
             + '<strong>“' + escapeHtml(data.suggestion) + '”</strong>'
             + '<span class="ss-suggestion__apply">Use this</span>'
             + '</button>';
      }

      // No results
      if (!data.products.length && !data.categories.length && !data.sellers.length && !data.suggestion) {
        html += '<div class="ss-empty">No results found. Try different keywords.</div>';
      }

      // View all
      if (data.products.length || data.categories.length || data.sellers.length) {
        html += '<a href="' + APP_URL + '/pages/explore.php?q=' + encodeURIComponent(input.value.trim()) + '" class="ss-viewall">View all results →</a>';
      }

      suggest.innerHTML = html;

      var suggestionBtn = suggest.querySelector('.ss-suggestion[data-suggestion]');
      if (suggestionBtn) {
        suggestionBtn.addEventListener('click', function () {
          var corrected = suggestionBtn.getAttribute('data-suggestion') || '';
          input.value = corrected;
          selectedIdx = -1;
          input.focus();
          doSearch(corrected);
        });
      }

      suggest.classList.add('is-open');
      syncSuggestionSpace();
    }

    function showLoading() {
      suggest.innerHTML = '<div class="ss-loading"><span class="ss-spinner"></span>Searching...</div>';
      suggest.classList.add('is-open');
      syncSuggestionSpace();
    }

    function doSearch(query) {
      if (query.length < 2) {
        closeSuggestions();
        return;
      }

      var requestId = ++searchSequence;
      showLoading();
      var finished = false;
      var timeoutId = setTimeout(function () {
        if (requestId !== searchSequence || finished) return;
        finished = true;
        suggest.innerHTML = '<div class=\"ss-error\">Search took too long. Press Enter to search.</div>';
        syncSuggestionSpace();
      }, 8000);

      fetch(buildUrl(query))
        .then(function (r) {
          if (!r.ok) throw new Error('HTTP ' + r.status);
          return r.json();
        })
        .then(function (data) {
          if (requestId === searchSequence && !finished) renderResults(data);
        })
        .catch(function () {
          if (requestId !== searchSequence || finished) return;
          finished = true;
          suggest.innerHTML = '<div class=\"ss-error\">Search unavailable. Press Enter to search.</div>';
          syncSuggestionSpace();
        })
        .finally(function () {
          clearTimeout(timeoutId);
          finished = true;
        });
    }

    // Debounced input
    input.addEventListener('input', function () {
      clearTimeout(debounceTimer);
      var val = input.value.trim();
      if (val.length < 2) {
        closeSuggestions();
        return;
      }
      debounceTimer = setTimeout(function () { doSearch(val); }, 300);
    });

    // Focus — don't auto-search on focus, wait for typing
    input.addEventListener('focus', function () {
      if (input.value.trim().length >= 2) doSearch(input.value.trim());
    });

    // Blur — close after delay
    input.addEventListener('blur', function () {
      setTimeout(function () { closeSuggestions(); }, 200);
    });

    // Keyboard navigation
    input.addEventListener('keydown', function (e) {
      var items = suggest.querySelectorAll('.ss-item');
      if (!suggest.classList.contains('is-open') || !items.length) {
        if (e.key === 'ArrowDown' && input.value.trim().length >= 2) {
          doSearch(input.value.trim());
        }
        return;
      }
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        selectedIdx = Math.min(selectedIdx + 1, items.length - 1);
        items.forEach(function (el, i) { el.classList.toggle('is-selected', i === selectedIdx); });
        items[selectedIdx]?.scrollIntoView({ block: 'nearest' });
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        selectedIdx = Math.max(selectedIdx - 1, 0);
        items.forEach(function (el, i) { el.classList.toggle('is-selected', i === selectedIdx); });
        items[selectedIdx]?.scrollIntoView({ block: 'nearest' });
      } else if (e.key === 'Enter' && selectedIdx >= 0 && currentResults[selectedIdx]) {
        e.preventDefault();
        window.location.href = currentResults[selectedIdx].url;
      } else if (e.key === 'Escape') {
        closeSuggestions();
        selectedIdx = -1;
      }
    });
  });

  // ---- Explore filter chips ------------------------------------------
  document.querySelectorAll('[data-filter-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var target = document.querySelector(btn.dataset.filterToggle);
      if (target) target.classList.toggle('hidden');
    });
  });

  // ---- Listing details gallery ---------------------------------------
  document.querySelectorAll('.gallery__thumb').forEach(function (thumb) {
    thumb.addEventListener('click', function () {
      var gallery = thumb.closest('.gallery');
      if (!gallery) return;
      var main = gallery.querySelector('.gallery__main img');
      if (main && thumb.dataset.src) { main.src = thumb.dataset.src; }
      gallery.querySelectorAll('.gallery__thumb').forEach(function (t) { t.classList.remove('active'); });
      thumb.classList.add('active');
    });
  });
})();

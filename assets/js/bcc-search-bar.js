/**
 * BCC Search Bar
 * - Search icon at rest → arrow (go) icon while typing
 * - Spinner replaces search icon while loading, reverts to arrow after
 * - Single icon slot (no duplicate clear button)
 * - Recent searches (localStorage, max 5)
 * - Trending searches (from REST /bcc/v1/search/trending)
 * - Click outside closes dropdown
 */
(function () {
  'use strict';

  const cfg       = window.bccSearchBar || {};
  const REST      = cfg.restUrl    || '/wp-json/bcc/v1';
  const RESULTS   = cfg.resultsUrl || '/search/';
  const NONCE     = cfg.nonce      || '';
  const DEBOUNCE  = 320;
  const MAX_RECENT = 5;
  const STORAGE_KEY = 'bcc_recent_searches';

  const TABS = [
    { key: 'all',      label: 'All'      },
    { key: 'projects', label: 'Projects' },
    { key: 'users',    label: 'Users'    },
    { key: 'groups',   label: 'Groups'   },
  ];

  /* ── SVGs ─────────────────────────────────────────────────── */
  const SVG = {
    search: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
               stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
               <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
             </svg>`,
    spinner: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 2a10 10 0 1 0 10 10"/>
              </svg>`,
    go: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
           stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
           <line x1="5" y1="12" x2="19" y2="12"/>
           <polyline points="12 5 19 12 12 19"/>
         </svg>`,
    clear: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
              stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
            </svg>`,
    clock: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
              stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
            </svg>`,
    trend: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
              stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/>
              <polyline points="17 6 23 6 23 12"/>
            </svg>`,
  };

  /* ── Recent searches (localStorage) ──────────────────────── */
  function getRecent() {
    try {
      return JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]');
    } catch { return []; }
  }

  function saveRecent(q) {
    if (!q || q.length < 2) return;
    let list = getRecent().filter(s => s.toLowerCase() !== q.toLowerCase());
    list.unshift(q);
    list = list.slice(0, MAX_RECENT);
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(list)); } catch {}
  }

  function removeRecent(q) {
    const list = getRecent().filter(s => s !== q);
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(list)); } catch {}
  }

  /* ── Init ─────────────────────────────────────────────────── */
  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.bcc-search-wrap[data-bcc-bar]').forEach(initBar);
  });

  function initBar(wrap) {
    const input      = wrap.querySelector('.bcc-search-input');
    const dropdown   = wrap.querySelector('.bcc-dropdown');
    const filterRow  = wrap.querySelector('.bcc-filter-tabs');
    const resultEl   = wrap.querySelector('.bcc-results-list');
    const footerEl   = wrap.querySelector('.bcc-dropdown-footer');
    // Double dynamic icon button
    const iconBtn    = wrap.querySelector('.bcc-icon-btn-main');
    const loaderBtn  = wrap.querySelector('.bcc-btn-loader');

    if (!input || !dropdown || !iconBtn) return;

    let timer      = null;
    let lastQ      = '';
    let activeTab  = 'all';
    let allResults = { projects: [], users: [], groups: [] };
    let focusIndex = -1;
    let isLoading  = false;
    let hasTyped   = false;
    let trendingCache = null;

    /* ── Icon state machine ───────────────────────────────── */
    // States: 'search' | 'spinner' | 'go'
    function setIcon(state) {
      iconBtn.innerHTML = SVG[state] || SVG.search;
      iconBtn.classList.toggle('is-spinner', state === 'spinner');
      iconBtn.classList.toggle('bcc-btn-go', state === 'go');
      iconBtn.classList.toggle('bcc-btn-search', state === 'search');
      iconBtn.title = state === 'go' ? 'View all results'
                    : state === 'spinner' ? 'Searching…'
                    : 'Search';
    }

    function setLoading(on) {
      isLoading = on;
      if (!loaderBtn) return;
      if (on) {
        // Show spinner on left button, disable clicking
        loaderBtn.classList.remove('bcc-hidden');
        loaderBtn.innerHTML = SVG.spinner;
        loaderBtn.classList.add('is-spinner');
        loaderBtn.disabled = true;
      } else {
        // Flip to clear button after loading, if there's a query
        loaderBtn.classList.remove('is-spinner');
        loaderBtn.disabled = false;
        if (input.value.trim().length >= 2) {
          loaderBtn.innerHTML = SVG.clear;
          loaderBtn.classList.remove('bcc-hidden');
          loaderBtn.title = 'Clear search';
        } else {
          loaderBtn.classList.add('bcc-hidden');
        }
      }
    }

    /* ── Dropdown open/close ──────────────────────────────── */
    function openDropdown() { dropdown.classList.add('is-open'); }
    function closeDropdown() {
      dropdown.classList.remove('is-open');
      focusIndex = -1;
    }

    function goToResults() {
      const q = input.value.trim();
      if (!q) return;
      saveRecent(q);
      const tab = activeTab !== 'all' ? `&type=${encodeURIComponent(activeTab)}` : '';
      window.location.href = `${RESULTS}?q=${encodeURIComponent(q)}${tab}`;
    }

    function buildResultsUrl(q) {
      const tab = activeTab !== 'all' ? `&type=${encodeURIComponent(activeTab)}` : '';
      return `${RESULTS}?q=${encodeURIComponent(q)}${tab}`;
    }

    /* ── Render filter tabs ───────────────────────────────── */
    function renderTabs() {
      if (!filterRow) return;
      filterRow.innerHTML = TABS.map(t =>
        `<button class="bcc-filter-tab${t.key === activeTab ? ' is-active' : ''}"
                 data-tab="${t.key}" type="button">${t.label}</button>`
      ).join('');
      filterRow.querySelectorAll('.bcc-filter-tab').forEach(btn => {
        btn.addEventListener('click', () => {
          activeTab = btn.dataset.tab;
          renderTabs();
          renderResults();
        });
      });
    }

    /* ── Pre-search screen: recent + trending ─────────────── */
    async function showPreSearch() {
      if (!resultEl) return;
      openDropdown();
      // Hide filter tabs and footer in pre-search
      if (filterRow) filterRow.innerHTML = '';
      if (footerEl)  footerEl.classList.add('bcc-hidden');

      const recent = getRecent();
      let html = '';

      if (recent.length) {
        html += `<div class="bcc-section-label">
                   ${SVG.clock} Recent searches
                   <button class="bcc-clear-recent" type="button">Clear all</button>
                 </div>`;
        html += recent.map(q =>
          `<div class="bcc-pre-item bcc-recent-item" data-q="${escAttr(q)}">
             <span class="bcc-pre-icon">${SVG.clock}</span>
             <span class="bcc-pre-text">${escHtml(q)}</span>
             <button class="bcc-remove-recent" data-q="${escAttr(q)}" type="button"
                     title="Remove">${SVG.clear}</button>
           </div>`
        ).join('');
      }

      // Trending placeholder while loading
      html += `<div class="bcc-section-label">${SVG.trend} Trending</div>`;
      html += `<div class="bcc-trending-list"><div class="bcc-state-msg">Loading…</div></div>`;

      resultEl.innerHTML = html;
      bindPreSearchEvents();

      // Fetch trending (cached per page load)
      if (!trendingCache) {
        try {
          const headers = NONCE ? { 'X-WP-Nonce': NONCE } : {};
          const res = await fetch(`${REST}/search?trending=1`, { headers });
          trendingCache = res.ok ? ((await res.json()).results || []) : [];
        } catch { trendingCache = []; }
      }

      renderTrending();
    }

    function renderTrending() {
      const trendEl = resultEl && resultEl.querySelector('.bcc-trending-list');
      if (!trendEl) return;

      const visible  = trendingCache ? trendingCache.slice(0, 5)  : [];
      const overflow = trendingCache ? trendingCache.slice(5, 10) : [];

      if (!visible.length) {
        trendEl.innerHTML = `<div class="bcc-state-msg">No trending searches right now.</div>`;
        return;
      }

      trendEl.innerHTML =
        visible.map(item =>
          `<div class="bcc-pre-item bcc-trend-item" data-q="${escAttr(item.query || item.page_name || '')}">
             <span class="bcc-pre-icon">${SVG.trend}</span>
             <span class="bcc-pre-text">${escHtml(item.query || item.page_name || item.title || '')}</span>
           </div>`
        ).join('') +
        (overflow.length
          ? `<button class="bcc-show-more-trend" type="button">View more</button>
             <div class="bcc-trend-overflow bcc-hidden">
               ${overflow.map(item =>
                 `<div class="bcc-pre-item bcc-trend-item" data-q="${escAttr(item.query || item.page_name || '')}">
                    <span class="bcc-pre-icon">${SVG.trend}</span>
                    <span class="bcc-pre-text">${escHtml(item.query || item.page_name || '')}</span>
                  </div>`
               ).join('')}
             </div>`
          : '');

      bindPreSearchEvents();
    }

    function bindPreSearchEvents() {
      if (!resultEl) return;

      // Click recent/trending item → fill input and search
      resultEl.querySelectorAll('.bcc-pre-item').forEach(el => {
        el.addEventListener('click', e => {
          if (e.target.closest('.bcc-remove-recent')) return;
          const q = el.dataset.q;
          if (!q) return;
          input.value = q;
          hasTyped = true;
          lastQ = q;
          fetchAll(q);
        });
      });

      // Remove single recent
      resultEl.querySelectorAll('.bcc-remove-recent').forEach(btn => {
        btn.addEventListener('click', e => {
          e.stopPropagation();
          removeRecent(btn.dataset.q);
          showPreSearch();
        });
      });

      // Clear all recent
      const clearAll = resultEl.querySelector('.bcc-clear-recent');
      if (clearAll) {
        clearAll.addEventListener('click', () => {
          try { localStorage.removeItem(STORAGE_KEY); } catch {}
          showPreSearch();
        });
      }

      // View more trending
      const showMore = resultEl.querySelector('.bcc-show-more-trend');
      if (showMore) {
        showMore.addEventListener('click', () => {
          const overflow = resultEl.querySelector('.bcc-trend-overflow');
          if (overflow) overflow.classList.toggle('bcc-hidden');
          showMore.textContent = overflow && overflow.classList.contains('bcc-hidden')
            ? 'View more' : 'View less';
          bindPreSearchEvents();
        });
      }
    }

    /* ── Search results render ────────────────────────────── */
    function renderResults() {
      if (!resultEl) return;
      const q = lastQ;
      let items = [];

      if (activeTab === 'all') {
        const p = allResults.projects.slice();
        const u = allResults.users.slice();
        const g = allResults.groups.slice();
        while (p.length || u.length || g.length) {
          if (p.length) items.push({ ...p.shift(), _kind: 'project' });
          if (u.length) items.push({ ...u.shift(), _kind: 'user' });
          if (g.length) items.push({ ...g.shift(), _kind: 'group' });
        }
        items = items.slice(0, 8);
      } else if (activeTab === 'projects') {
        items = allResults.projects.map(r => ({ ...r, _kind: 'project' }));
      } else if (activeTab === 'users') {
        items = allResults.users.map(r => ({ ...r, _kind: 'user' }));
      } else {
        items = allResults.groups.map(r => ({ ...r, _kind: 'group' }));
      }

      if (!items.length) {
        resultEl.innerHTML = `<div class="bcc-state-msg">No results for <strong>${escHtml(q)}</strong></div>`;
        if (footerEl) footerEl.classList.add('bcc-hidden');
        return;
      }

      resultEl.innerHTML = items.map((item, i) => buildItemHtml(item, i)).join('');
      resultEl.querySelectorAll('.bcc-result-item').forEach(el => {
        el.addEventListener('click', () => { window.location.href = el.href; });
      });

      if (footerEl) {
        footerEl.classList.remove('bcc-hidden');
        const a = footerEl.querySelector('.bcc-view-all-btn');
        if (a) a.href = buildResultsUrl(q);
      }
    }

    function buildItemHtml(item, i) {
      let avatar, name, meta, badge, url;
      if (item._kind === 'project') {
        avatar = item.avatar_url || '';
        name   = item.page_name  || '';
        meta   = item.category   || '';
        badge  = item.tier ? tierBadge(item.tier) : '';
        url    = item.page_url   || '#';
      } else if (item._kind === 'user') {
        avatar = item.avatar_url   || '';
        name   = item.display_name || item.username || '';
        meta   = '@' + (item.username || '');
        badge  = '';
        url    = item.profile_url  || '#';
      } else {
        avatar = item.avatar_url  || '';
        name   = item.name        || '';
        meta   = item.description || '';
        badge  = '';
        url    = item.group_url   || '#';
      }

      const imgHtml = avatar
        ? `<img class="bcc-result-avatar" src="${escAttr(avatar)}" alt="" loading="lazy">`
        : `<span class="bcc-result-avatar" style="display:flex;align-items:center;
               justify-content:center;font-size:1rem;font-weight:700;
               color:var(--bcc-primary);background:var(--bcc-overlay-light)">
             ${escHtml(name.charAt(0).toUpperCase())}
           </span>`;

      return `<a href="${escAttr(url)}" class="bcc-result-item" role="option" data-index="${i}">
        ${imgHtml}
        <div class="bcc-result-info">
          <div class="bcc-result-name">${escHtml(name)}</div>
          ${meta ? `<div class="bcc-result-meta">${escHtml(meta)}</div>` : ''}
        </div>
        ${badge}
      </a>`;
    }

    function tierBadge(tier) {
      return `<span class="bcc-result-badge bcc-tier-${escHtml(tier.toLowerCase())}">${escHtml(tier)}</span>`;
    }

    /* ── Fetch all verticals ──────────────────────────────── */
    async function fetchAll(q) {
      setLoading(true);
      openDropdown();
      renderTabs();
      if (resultEl) resultEl.innerHTML = `<div class="bcc-state-msg">Searching…</div>`;
      if (footerEl) footerEl.classList.add('bcc-hidden');

      const headers = NONCE ? { 'X-WP-Nonce': NONCE } : {};
      const enc     = encodeURIComponent(q);

      try {
        const [pRes, uRes, gRes] = await Promise.allSettled([
          fetch(`${REST}/search?q=${enc}`,        { headers }),
          fetch(`${REST}/search/users?q=${enc}`,  { headers }),
          fetch(`${REST}/search/groups?q=${enc}`, { headers }),
        ]);
        allResults.projects = (pRes.status === 'fulfilled' && pRes.value.ok)
          ? ((await pRes.value.json()).results || []) : [];
        allResults.users = (uRes.status === 'fulfilled' && uRes.value.ok)
          ? ((await uRes.value.json()).results || []) : [];
        allResults.groups = (gRes.status === 'fulfilled' && gRes.value.ok)
          ? ((await gRes.value.json()).results || []) : [];
      } catch {
        allResults = { projects: [], users: [], groups: [] };
      }

      setLoading(false);
      renderResults();
    }

    /* ── Keyboard navigation ──────────────────────────────── */
    function getFocusableItems() {
      return Array.from(resultEl ? resultEl.querySelectorAll('.bcc-result-item, .bcc-pre-item') : []);
    }

    function moveFocus(dir) {
      const items = getFocusableItems();
      if (!items.length) return;
      focusIndex = Math.max(0, Math.min(items.length - 1, focusIndex + dir));
      items.forEach((el, i) => el.classList.toggle('is-focused', i === focusIndex));
      items[focusIndex].scrollIntoView({ block: 'nearest' });
    }

    /* ── Event listeners ──────────────────────────────────── */
    input.addEventListener('focus', () => {
      if (!input.value.trim()) {
        showPreSearch();
      }
    });

    input.addEventListener('input', () => {
      const q = input.value.trim();
      hasTyped = q.length > 0;

      if (q.length < 2) {
        clearTimeout(timer);
        if (q.length === 0) {
          setIcon('search');
          if (loaderBtn) loaderBtn.classList.add('bcc-hidden');
          showPreSearch();
        }
        return;
      }

      setIcon('go'); // optimistic — switches to spinner on fetch start
      clearTimeout(timer);
      timer = setTimeout(() => {
        if (q !== lastQ) {
          lastQ = q;
          fetchAll(q);
        }
      }, DEBOUNCE);
    });

    input.addEventListener('keydown', e => {
      if (!dropdown.classList.contains('is-open')) return;
      if (e.key === 'ArrowDown')  { e.preventDefault(); moveFocus(1); }
      if (e.key === 'ArrowUp')    { e.preventDefault(); moveFocus(-1); }
      if (e.key === 'Escape')     { closeDropdown(); input.blur(); }
      if (e.key === 'Enter') {
        e.preventDefault();
        const focused = resultEl && resultEl.querySelector('.bcc-result-item.is-focused');
        if (focused) { window.location.href = focused.href; }
        else { goToResults(); }
      }
    });

    // Left button — clears search when in clear state
    if (loaderBtn) {
      loaderBtn.addEventListener('click', () => {
        if (!loaderBtn.classList.contains('is-spinner')) {
          input.value = '';
          loaderBtn.classList.add('bcc-hidden');
          setIcon('search');
          hasTyped = false;
          lastQ = '';
          allResults = { projects: [], users: [], groups: [] };
          showPreSearch();
          input.focus();
        }
      });
    }

    // Single icon button — acts as go when in 'go' state, search otherwise
    iconBtn.addEventListener('click', () => {
      if (iconBtn.classList.contains('bcc-btn-go')) {
        goToResults();
      } else {
        input.focus();
      }
    });

    // Close on outside click
    document.addEventListener('click', e => {
      if (!wrap.contains(e.target)) closeDropdown();
    });

    /* ── Initial state ────────────────────────────────────── */
    setIcon('search');
  }

  function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;')
      .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
  function escAttr(s) { return escHtml(s); }
})();
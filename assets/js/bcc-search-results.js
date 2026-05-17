/**
 * BCC Search Results Page
 *
 * Reads ?q= and ?type= from the URL, fetches all three verticals,
 * renders a tab-based results page with skeleton loaders.
 */
(function () {
  'use strict';

  const cfg     = window.bccSearchBar || {};
  const REST    = cfg.restUrl   || '/wp-json/bcc/v1';
  const NONCE   = cfg.nonce     || '';

  const TABS = [
    { key: 'all',      label: 'All'      },
    { key: 'projects', label: 'Projects' },
    { key: 'users',    label: 'Users'    },
    { key: 'groups',   label: 'Groups'   },
  ];

  document.addEventListener('DOMContentLoaded', () => {
    const page = document.querySelector('.bcc-results-page[data-bcc-results]');
    if (!page) return;
    initResultsPage(page);
  });

  function initResultsPage(page) {
    const params  = new URLSearchParams(window.location.search);
    const q       = params.get('q') || '';
    const initTab = params.get('type') || 'all';

    /* ── Query label ─────────────────────────────────────────── */
    const label = page.querySelector('.bcc-rp-query-label');
    if (label) {
      label.innerHTML = q
        ? `Results for <strong>"${escHtml(q)}"</strong>`
        : 'Enter a search term above.';
    }

    if (!q) {
      // No query — show trending searches as default page state
      if (label) label.innerHTML = 'Trending searches';
      showTrending();
      return;
    }

    let activeTab = TABS.find(t => t.key === initTab) ? initTab : 'all';
    let data = { projects: null, users: null, groups: null }; // null = not loaded

    /* ── Render tab bar ──────────────────────────────────────── */
    const tabBar = page.querySelector('.bcc-rp-tabs');

    function renderTabBar() {
      if (!tabBar) return;
      tabBar.innerHTML = TABS.map(t => {
        const count = countForTab(t.key);
        const badge = count !== null
          ? `<span class="bcc-rp-tab-count">${count}</span>` : '';
        return `<button class="bcc-rp-tab${t.key === activeTab ? ' is-active' : ''}"
                        data-tab="${t.key}" type="button">
                  ${t.label}${badge}
                </button>`;
      }).join('');

      tabBar.querySelectorAll('.bcc-rp-tab').forEach(btn => {
        btn.addEventListener('click', () => {
          activeTab = btn.dataset.tab;
          renderTabBar();
          renderActivePanel();
          // Update URL without reload
          const url = new URL(window.location.href);
          if (activeTab === 'all') url.searchParams.delete('type');
          else url.searchParams.set('type', activeTab);
          history.replaceState(null, '', url.toString());
        });
      });
    }

    function countForTab(key) {
      if (key === 'all') {
        if (data.projects === null || data.users === null || data.groups === null) return null;
        return (data.projects.length || 0) + (data.users.length || 0) + (data.groups.length || 0);
      }
      if (key === 'projects') return data.projects !== null ? data.projects.length : null;
      if (key === 'users')    return data.users    !== null ? data.users.length    : null;
      if (key === 'groups')   return data.groups   !== null ? data.groups.length   : null;
      return null;
    }

    /* ── Panels ──────────────────────────────────────────────── */
    const panelWrap = page.querySelector('.bcc-rp-panels');

    function getOrCreatePanel(key) {
      let panel = panelWrap && panelWrap.querySelector(`.bcc-rp-panel[data-panel="${key}"]`);
      if (!panel && panelWrap) {
        panel = document.createElement('div');
        panel.className = 'bcc-rp-panel';
        panel.dataset.panel = key;
        panelWrap.appendChild(panel);
      }
      return panel;
    }

    function renderActivePanel() {
      if (!panelWrap) return;
      panelWrap.querySelectorAll('.bcc-rp-panel').forEach(p => p.classList.remove('is-active'));
      const panel = getOrCreatePanel(activeTab);
      panel.classList.add('is-active');
      renderPanel(panel, activeTab);
    }

    function renderPanel(panel, key) {
      if (key === 'all') {
        const loaded = data.projects !== null && data.users !== null && data.groups !== null;
        if (!loaded) { panel.innerHTML = skeletonHtml(5); return; }

        const items = [
          ...(data.projects || []).map(r => ({ ...r, _kind: 'project' })),
          ...(data.users    || []).map(r => ({ ...r, _kind: 'user' })),
          ...(data.groups   || []).map(r => ({ ...r, _kind: 'group' })),
        ];

        if (!items.length) {
          panel.innerHTML = emptyHtml(q);
          return;
        }
        panel.innerHTML = items.map(buildCard).join('');
        return;
      }

      const vertical = key === 'projects' ? data.projects
                     : key === 'users'    ? data.users
                     :                     data.groups;

      if (vertical === null) { panel.innerHTML = skeletonHtml(5); return; }
      if (!vertical.length)  { panel.innerHTML = emptyHtml(q);    return; }

      const kind = key === 'projects' ? 'project' : key === 'users' ? 'user' : 'group';
      panel.innerHTML = vertical.map(r => buildCard({ ...r, _kind: kind })).join('');
    }

    /* ── Card builder ────────────────────────────────────────── */
    function buildCard(item) {
      let avatar, name, meta, desc, badge, url;

      if (item._kind === 'project') {
        avatar = item.avatar_url  || '';
        name   = item.page_name   || '';
        meta   = [item.category, item.tier ? `Tier: ${item.tier}` : ''].filter(Boolean).join(' · ');
        desc   = '';
        badge  = item.tier        ? `<span class="bcc-rp-badge bcc-tier-${escHtml((item.tier||'').toLowerCase())}">${escHtml(item.tier)}</span>` : '';
        url    = item.page_url    || '#';
      } else if (item._kind === 'user') {
        avatar = item.avatar_url   || '';
        name   = item.display_name || item.username || '';
        meta   = '@' + (item.username || '');
        desc   = '';
        badge  = '';
        url    = item.profile_url  || '#';
      } else {
        avatar = item.avatar_url  || '';
        name   = item.name        || '';
        meta   = item.slug        ? `/${item.slug}` : '';
        desc   = item.description || '';
        badge  = '';
        url    = item.group_url   || '#';
      }

      const avatarCls = item._kind === 'group' ? 'bcc-rp-avatar is-group' : 'bcc-rp-avatar';
      const imgHtml   = avatar
        ? `<img class="${avatarCls}" src="${escAttr(avatar)}" alt="" loading="lazy">`
        : `<span class="${avatarCls}"
               style="display:flex;align-items:center;justify-content:center;
                      font-size:1.2rem;font-weight:700;
                      color:var(--bcc-primary);background:var(--bcc-overlay-light)">
             ${escHtml(name.charAt(0).toUpperCase())}
           </span>`;

      return `<a href="${escAttr(url)}" class="bcc-rp-card">
        ${imgHtml}
        <div class="bcc-rp-card-info">
          <div class="bcc-rp-card-name">${escHtml(name)}</div>
          ${meta ? `<div class="bcc-rp-card-meta">${escHtml(meta)}</div>` : ''}
          ${desc ? `<div class="bcc-rp-card-desc">${escHtml(desc)}</div>` : ''}
        </div>
        ${badge}
      </a>`;
    }

    /* ── Skeletons / empty ───────────────────────────────────── */
    function skeletonHtml(n) {
      return Array(n).fill(0).map(() => `
        <div class="bcc-skeleton-card">
          <div class="bcc-skeleton bcc-skeleton-avatar"></div>
          <div class="bcc-skeleton-lines">
            <div class="bcc-skeleton bcc-skeleton-line long"></div>
            <div class="bcc-skeleton bcc-skeleton-line short"></div>
          </div>
        </div>
      `).join('');
    }

    function emptyHtml(q) {
      return `<div class="bcc-rp-empty">
        <strong>No results</strong>
        Nothing found for "${escHtml(q)}". Try a different keyword.
      </div>`;
    }

    async function showTrending() {
      const panelWrap = page.querySelector('.bcc-rp-panels');
      if (!panelWrap) return;

      // Hide tabs, show a single panel
      const tabBar = page.querySelector('.bcc-rp-tabs');
      if (tabBar) tabBar.innerHTML = '';

      panelWrap.innerHTML = skeletonHtml(8);

      try {
        const headers = NONCE ? { 'X-WP-Nonce': NONCE } : {};
        const res = await fetch(`${REST}/search?trending=1`, { headers });
        const json = res.ok ? await res.json() : {};
        const items = (json.results || []).slice(0, 20);

        if (!items.length) {
          panelWrap.innerHTML = emptyHtml('trending');
          return;
        }

        panelWrap.innerHTML = items
          .map(r => buildCard({ ...r, _kind: 'project' }))
          .join('');
      } catch {
        panelWrap.innerHTML = emptyHtml('trending');
      }
    }

    /* ── Fetch ───────────────────────────────────────────────── */
    async function fetchAll() {
      const headers = NONCE ? { 'X-WP-Nonce': NONCE } : {};
      const enc     = encodeURIComponent(q);

      renderTabBar();
      renderActivePanel(); // shows skeleton first

      const [pRes, uRes, gRes] = await Promise.allSettled([
        fetch(`${REST}/search?q=${enc}`,             { headers }),
        fetch(`${REST}/search/users?q=${enc}`,       { headers }),
        fetch(`${REST}/search/groups?q=${enc}`,      { headers }),
      ]);

      // Update data object as each resolves
      data.projects = (pRes.status === 'fulfilled' && pRes.value.ok)
        ? ((await pRes.value.json()).results || []) : [];
      data.users = (uRes.status === 'fulfilled' && uRes.value.ok)
        ? ((await uRes.value.json()).results || []) : [];
      data.groups = (gRes.status === 'fulfilled' && gRes.value.ok)
        ? ((await gRes.value.json()).results || []) : [];

      renderTabBar();   // update counts
      renderActivePanel();
    }

    /* ── Boot ────────────────────────────────────────────────── */
    renderTabBar();
    renderActivePanel(); // show skeleton immediately
    fetchAll();
  }

  function escHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function escAttr(s) { return escHtml(s); }
})();
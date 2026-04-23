/* global bccSearch */
(function () {
    'use strict';

    // ─── Config ────────────────────────────────────────────────────────────────
    const DEBOUNCE_MS       = 260;
    const MIN_CHARS         = 2;
    const MAX_PER_GROUP     = 3;
    const MAX_RECENT        = 5;
    const MAX_TRENDING      = 6;
    const RECENT_KEY        = 'bcc_search_recent';
    const RESULT_CLASS      = 'bcc-search__result';
    const ACTIVE_CLASS      = `${RESULT_CLASS}--active`;

    // ─── Recent searches (localStorage) ───────────────────────────────────────
    function getRecentSearches() {
        try {
            var data = JSON.parse(localStorage.getItem(RECENT_KEY));
            return Array.isArray(data) ? data.slice(0, MAX_RECENT) : [];
        } catch (e) {
            return [];
        }
    }

    function saveRecentSearch(query) {
        var q = String(query).trim();
        if (q.length < MIN_CHARS) return;
        try {
            var recent = getRecentSearches().filter(function (item) {
                return item.toLowerCase() !== q.toLowerCase();
            });
            recent.unshift(q);
            localStorage.setItem(RECENT_KEY, JSON.stringify(recent.slice(0, MAX_RECENT)));
        } catch (e) { /* localStorage unavailable */ }
    }

    // ─── Highlight query in name ────────────────────────────────────────────────
    function highlight(text, query) {
        if (!query) return escHtml(text);
        const safe  = escHtml(text);
        const safeQ = escHtml(query).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        return safe.replace(new RegExp(`(${safeQ})`, 'gi'), '<em>$1</em>');
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // ─── URL safety ──────────────────────────────────────────────────────────────
    function safeUrl(url) {
        if (!url) return '#';
        return /^https?:\/\//i.test(url) ? url : '#';
    }

    // ─── Tier helpers (server-provided via wp_localize_script) ────────────────
    const TIER_CSS = (typeof bccSearch !== 'undefined' && bccSearch.tierCss) ? bccSearch.tierCss : {};
    const TIER_LABEL = (typeof bccSearch !== 'undefined' && bccSearch.tierLabel) ? bccSearch.tierLabel : {};

    function tierClass(tier) {
        if (!tier) return '';
        return TIER_CSS[tier.toLowerCase()] ? `bcc-search__score--${TIER_CSS[tier.toLowerCase()]}` : '';
    }

    function tierLabel(tier) {
        if (!tier) return '';
        return TIER_LABEL[tier.toLowerCase()] || '';
    }

    // ─── Build a single result <li> ─────────────────────────────────────────────
    function buildItem(item, query) {
        const li   = document.createElement('li');
        const link = document.createElement('a');
        link.href      = safeUrl(item.page_url);
        link.className = RESULT_CLASS;
        link.setAttribute('role', 'option');
        link.setAttribute('tabindex', '-1');

        const label = tierLabel(item.tier);
        const scoreHtml = item.trust_score !== null && item.trust_score !== undefined
            ? `<span class="bcc-search__score ${tierClass(item.tier)}">${label ? escHtml(label) + ' \u00B7 ' : ''}${item.trust_score}</span>`
            : '';

        const catHtml = item.category
            ? `<span class="bcc-search__cat">${escHtml(item.category)}</span>`
            : '';

        link.innerHTML = `
            <img class="bcc-search__avatar" src="${safeUrl(item.avatar_url || '')}" alt="" loading="lazy">
            <span class="bcc-search__meta">
                <span class="bcc-search__name">${highlight(item.page_name, query)}</span>
                <span class="bcc-search__sub">${catHtml}</span>
            </span>
            ${scoreHtml}
        `;

        li.appendChild(link);
        return li;
    }

    // ─── Group results by category, sorted by best score per group ─────────────
    function groupByCategory(results) {
        const groups = new Map();
        for (const item of results) {
            const slug = item.category_slug || '_uncategorized';
            const name = item.category || 'Other';
            if (!groups.has(slug)) {
                groups.set(slug, { slug, name, items: [], topScore: -1 });
            }
            var g = groups.get(slug);
            g.items.push(item);
            var s = (item.trust_score !== null && item.trust_score !== undefined) ? item.trust_score : -1;
            if (s > g.topScore) g.topScore = s;
        }
        // Sort groups: highest top-score first
        var sorted = Array.from(groups.values());
        sorted.sort(function (a, b) { return b.topScore - a.topScore; });
        return sorted;
    }

    // ─── Build a group <div> with header + nested <ul> + optional "see more" ──
    function buildGroup(slug, name, items, query, onSeeMore) {
        const visible  = items.slice(0, MAX_PER_GROUP);
        const overflow = items.length - visible.length;

        const group = document.createElement('div');
        group.className = 'bcc-search__group';

        const header = document.createElement('div');
        header.className = 'bcc-search__group-header';
        header.innerHTML = `
            <span class="bcc-search__group-label">${escHtml(name)}</span>
            <span class="bcc-search__group-count">${items.length}</span>
        `;

        const list = document.createElement('ul');
        list.className = 'bcc-search__group-list';
        visible.forEach(function (item) { list.appendChild(buildItem(item, query)); });

        group.appendChild(header);
        group.appendChild(list);

        if (overflow > 0) {
            const more = document.createElement('div');
            more.className = 'bcc-search__see-more';
            more.setAttribute('data-category', slug);
            more.textContent = 'View ' + overflow + ' more \u2192';
            more.addEventListener('click', function () { onSeeMore(slug); });
            group.appendChild(more);
        }

        return group;
    }

    // ─── Count results per category slug ─────────────────────────────────────────
    function countByCategory(results) {
        var counts = {};
        for (var i = 0; i < results.length; i++) {
            var slug = results[i].category_slug || '';
            counts[slug] = (counts[slug] || 0) + 1;
        }
        return counts;
    }

    // ─── Debounce ───────────────────────────────────────────────────────────────
    function debounce(fn, delay) {
        let t;
        return function (...args) {
            clearTimeout(t);
            t = setTimeout(() => fn.apply(this, args), delay);
        };
    }

    // ─── Users vertical: DOM-agnostic result renderer ──────────────────────
    //
    // Kept outside initWidget so the code path is clearly separate from
    // project-result rendering; there is no shared mutable state.
    function buildUserItem(user, query) {
        const li   = document.createElement('li');
        const link = document.createElement('a');
        link.href      = safeUrl(user.profile_url);
        link.className = RESULT_CLASS + ' bcc-search__user-result';
        link.setAttribute('role', 'option');
        link.setAttribute('tabindex', '-1');

        const avatarHtml = user.avatar_url
            ? `<img class="bcc-search__avatar" src="${safeUrl(user.avatar_url)}" alt="" loading="lazy">`
            : '<span class="bcc-search__avatar bcc-search__avatar--empty" aria-hidden="true"></span>';

        link.innerHTML = `
            ${avatarHtml}
            <span class="bcc-search__meta">
                <span class="bcc-search__name">${highlight(user.display_name || user.username, query)}</span>
                <span class="bcc-search__sub"><span class="bcc-search__handle">@${escHtml(user.username)}</span></span>
            </span>
        `;

        li.appendChild(link);
        return li;
    }

    // ─── Groups vertical: DOM-agnostic result renderer ─────────────────────
    //
    // Sibling to buildUserItem. Separate function kept deliberately so
    // each vertical's row shape can evolve independently without one
    // affecting the other.
    function buildGroupItem(group, query) {
        const li   = document.createElement('li');
        const link = document.createElement('a');
        link.href      = safeUrl(group.group_url);
        link.className = RESULT_CLASS + ' bcc-search__group-result';
        link.setAttribute('role', 'option');
        link.setAttribute('tabindex', '-1');

        const avatarHtml = group.avatar_url
            ? `<img class="bcc-search__avatar" src="${safeUrl(group.avatar_url)}" alt="" loading="lazy">`
            : '<span class="bcc-search__avatar bcc-search__avatar--empty" aria-hidden="true"></span>';

        const descHtml = group.description
            ? `<span class="bcc-search__sub"><span class="bcc-search__desc">${escHtml(group.description)}</span></span>`
            : '';

        link.innerHTML = `
            ${avatarHtml}
            <span class="bcc-search__meta">
                <span class="bcc-search__name">${highlight(group.name, query)}</span>
                ${descHtml}
            </span>
        `;

        li.appendChild(link);
        return li;
    }

    // ─── Init each .bcc-search widget on the page ───────────────────────────────
    function initWidget(widget) {
        const input      = widget.querySelector('.bcc-search__input');
        const chipsEl    = widget.querySelector('.bcc-search__chips');
        const tabsEl     = widget.querySelector('.bcc-search__tabs');
        const dropdown   = widget.querySelector('.bcc-search__dropdown');
        const listEl     = widget.querySelector('.bcc-search__results');
        const emptyEl    = widget.querySelector('.bcc-search__empty');
        const clearBtn   = widget.querySelector('.bcc-search__clear');
        const userListEl  = widget.querySelector('.bcc-search__user-results');
        const userEmpty   = widget.querySelector('.bcc-search__user-empty');
        const groupListEl = widget.querySelector('.bcc-search__group-results');
        const groupEmpty  = widget.querySelector('.bcc-search__group-empty');
        const projPane    = widget.querySelector('.bcc-search__pane--projects');
        const userPane    = widget.querySelector('.bcc-search__pane--users');
        const groupPane   = widget.querySelector('.bcc-search__pane--groups');

        if (!input || !dropdown || !listEl) return;

        let controller      = null;          // projects AbortController
        let userController  = null;          // users AbortController (independent)
        let groupController = null;          // groups AbortController (independent)
        let activeIdx       = -1;
        let currentType     = '';
        // Active vertical: 'projects' (default), 'users', or 'groups'.
        // The Projects pipeline never observes this value; it's only
        // checked at input time to decide which fetch path to run.
        let activeVertical  = (widget.getAttribute('data-vertical') || 'projects');
        // Per-widget in-memory cache of the last query + results per
        // vertical. Saves a roundtrip when the tab is re-activated
        // with the same query, without reusing the server-side cache
        // key. Sibling state to lastUserQuery / lastUserResults.
        let lastUserQuery    = '';
        let lastUserResults  = null;
        let lastGroupQuery   = '';
        let lastGroupResults = null;

        // ── Chips: render from API categories ─────────────────────────────────
        // counts is optional — when provided, chips show (N) and hide zeros.
        function renderChips(categories, counts) {
            if (!chipsEl || !Array.isArray(categories) || categories.length === 0) return;
            var hasCounts = counts && typeof counts === 'object';
            var total = 0;

            // Build display list: "All" first, then others sorted by count desc
            var allChip = null;
            var others  = [];
            categories.forEach(function (cat) {
                if (cat.slug === '') {
                    allChip = cat;
                } else {
                    var count = hasCounts ? (counts[cat.slug] || 0) : -1;
                    if (hasCounts && count === 0) return; // hide empty
                    if (hasCounts) total += count;
                    others.push({ slug: cat.slug, name: cat.name, count: count });
                }
            });

            // Sort non-All chips by count descending (only when counts available)
            if (hasCounts) {
                others.sort(function (a, b) { return b.count - a.count; });
            }

            // Validate current selection still exists in visible chips
            if (currentType !== '' && !others.find(function (o) { return o.slug === currentType; })) {
                currentType = '';
            }

            chipsEl.innerHTML = '';

            // "All" chip (always first)
            if (allChip) {
                var allBtn = document.createElement('button');
                allBtn.type = 'button';
                allBtn.className = 'bcc-search__chip';
                allBtn.setAttribute('data-type', '');
                allBtn.textContent = hasCounts ? allChip.name + ' (' + total + ')' : allChip.name;
                if (currentType === '') allBtn.classList.add('bcc-search__chip--active');
                allBtn.addEventListener('click', function () { selectChip(''); });
                chipsEl.appendChild(allBtn);
            }

            // Category chips
            others.forEach(function (o) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'bcc-search__chip';
                btn.setAttribute('data-type', o.slug);
                btn.textContent = hasCounts ? o.name + ' (' + o.count + ')' : o.name;
                if (o.slug === currentType) btn.classList.add('bcc-search__chip--active');
                btn.addEventListener('click', function () { selectChip(o.slug); });
                chipsEl.appendChild(btn);
            });
        }

        function selectChip(slug) {
            currentType = slug;
            // Update active styling
            if (chipsEl) {
                Array.from(chipsEl.querySelectorAll('.bcc-search__chip')).forEach(function (btn) {
                    btn.classList.toggle('bcc-search__chip--active', btn.getAttribute('data-type') === slug);
                });
            }
            // Re-search with new filter (debounced to prevent rapid-click flooding)
            var q = input.value.trim();
            if (q.length >= MIN_CHARS) {
                debouncedSearch(q, slug);
            }
        }

        function getActiveChipLabel() {
            if (!chipsEl) return '';
            var active = chipsEl.querySelector('.bcc-search__chip--active');
            return active ? active.textContent : '';
        }

        // ── Open / Close (class-based for CSS transitions) ────────────────────
        // Remove hidden attr on init so CSS transitions take over
        dropdown.removeAttribute('hidden');

        function openDropdown() {
            widget.classList.add('bcc-search--open');
            input.setAttribute('aria-expanded', 'true');
        }

        function closeDropdown() {
            widget.classList.remove('bcc-search--open');
            input.setAttribute('aria-expanded', 'false');
            activeIdx = -1;
        }

        // Expose for global click-outside handler
        widget.__closeDropdown = closeDropdown;

        // ── Preload + Suggestions ─────────────────────────────────────────────
        var preloadCache = null;
        var preloadFetching = false;

        function fetchPreload() {
            if (preloadCache || preloadFetching) return;
            preloadFetching = true;
            var url = new URL(bccSearch.restUrl);
            url.searchParams.set('trending', '1');
            fetch(url.toString())
                .then(function (res) { return res.ok ? res.json() : null; })
                .then(function (json) {
                    if (json && Array.isArray(json.results)) {
                        preloadCache = json.results.slice(0, MAX_TRENDING);
                        if (json.categories) renderChips(json.categories);
                        // If input is still empty and focused, show suggestions now
                        if (document.activeElement === input && input.value.trim().length < MIN_CHARS) {
                            showSuggestions();
                        }
                    }
                })
                .catch(function () {})
                .finally(function () { preloadFetching = false; });
        }

        function buildSection(title, children) {
            var section = document.createElement('div');
            section.className = 'bcc-search__section';
            var heading = document.createElement('div');
            heading.className = 'bcc-search__section-title';
            heading.textContent = title;
            section.appendChild(heading);
            var list = document.createElement('ul');
            list.className = 'bcc-search__group-list';
            children.forEach(function (child) { list.appendChild(child); });
            section.appendChild(list);
            return section;
        }

        function buildRecentItem(query) {
            var li = document.createElement('li');
            var link = document.createElement('a');
            link.href = '#';
            link.className = RESULT_CLASS + ' bcc-search__recent-item';
            link.setAttribute('role', 'option');
            link.setAttribute('tabindex', '-1');
            link.innerHTML = '<span class="bcc-search__recent-icon" aria-hidden="true">\u29D6</span>'
                + '<span class="bcc-search__recent-text">' + escHtml(query) + '</span>';
            link.addEventListener('click', function (e) {
                e.preventDefault();
                input.value = query;
                if (clearBtn) clearBtn.hidden = false;
                doSearch(query, currentType);
            });
            li.appendChild(link);
            return li;
        }

        function showSuggestions() {
            listEl.innerHTML = '';
            activeIdx = -1;
            if (emptyEl) emptyEl.hidden = true;

            var hasContent = false;

            // Recent searches
            var recent = getRecentSearches();
            if (recent.length > 0) {
                var recentItems = recent.map(function (q) { return buildRecentItem(q); });
                listEl.appendChild(buildSection('Recent Searches', recentItems));
                hasContent = true;
            }

            // Trending
            if (preloadCache && preloadCache.length > 0) {
                var trendingItems = preloadCache.map(function (item) { return buildItem(item, ''); });
                listEl.appendChild(buildSection('Trending', trendingItems));
                hasContent = true;
            }

            if (hasContent) {
                // Stagger animation
                var allItems = listEl.querySelectorAll('.' + RESULT_CLASS);
                allItems.forEach(function (el, i) {
                    el.style.animationDelay = (i * 15) + 'ms';
                });
                openDropdown();
            }
        }

        // ── Keyboard: move active item ────────────────────────────────────────
        function getItems() {
            return Array.from(listEl.querySelectorAll(`.${RESULT_CLASS}`));
        }

        function setActive(idx) {
            const items = getItems();
            items.forEach(el => el.classList.remove(ACTIVE_CLASS));
            if (idx >= 0 && idx < items.length) {
                items[idx].classList.add(ACTIVE_CLASS);
                items[idx].scrollIntoView({ block: 'nearest' });
            }
            activeIdx = idx;
        }

        // ── Render results (flat when filtered, grouped when "All") ──────────
        function renderResults(results, query) {
            listEl.innerHTML = '';
            activeIdx = -1;

            if (results.length === 0) {
                if (emptyEl) {
                    var suffix = query ? ' for \u201C' + query + '\u201D' : '';
                    if (currentType !== '') {
                        var label = getActiveChipLabel();
                        emptyEl.textContent = 'No ' + (label || 'projects')
                            + ' found' + suffix + ". Try \u2018All Types\u2019.";
                    } else {
                        emptyEl.textContent = 'No projects found' + suffix + '.';
                    }
                    emptyEl.hidden = false;
                }
                return;
            }

            emptyEl && (emptyEl.hidden = true);

            if (currentType !== '') {
                // Flat list — single category
                var flatList = document.createElement('ul');
                flatList.className = 'bcc-search__group-list';
                results.forEach(function (item) { flatList.appendChild(buildItem(item, query)); });
                listEl.appendChild(flatList);
            } else {
                // Grouped by category
                var groups = groupByCategory(results);
                groups.forEach(function (g) {
                    listEl.appendChild(buildGroup(g.slug, g.name, g.items, query, selectChip));
                });
            }

            // Stagger entrance animation per result item
            var allItems = listEl.querySelectorAll('.' + RESULT_CLASS);
            allItems.forEach(function (el, i) {
                el.style.animationDelay = (i * 15) + 'ms';
            });
        }

        // ── Fetch ─────────────────────────────────────────────────────────────
        async function doSearch(q, type) {
            q = String(q).trim();
            if (q.length < MIN_CHARS) return;

            const safeType = (typeof type === 'string' && /^[a-z0-9-]*$/i.test(type)) ? type : '';

            if (controller) controller.abort();
            controller = new AbortController();

            widget.classList.add('bcc-search--loading');

            const url = new URL(bccSearch.restUrl);
            url.searchParams.set('q', q);
            if (safeType) url.searchParams.set('type', safeType);

            try {
                const res = await fetch(url.toString(), {
                    signal: controller.signal,
                });

                widget.classList.remove('bcc-search--loading');

                if (!res.ok) {
                    renderResults([], q);
                    if (emptyEl) {
                        emptyEl.textContent = 'Search unavailable. Please try again.';
                        emptyEl.hidden = false;
                    }
                    openDropdown();
                    return;
                }

                const json = await res.json();
                var results = json.results || [];
                renderChips(json.categories || [], countByCategory(results));
                renderResults(results, q);
                saveRecentSearch(q);
                openDropdown();
            } catch (e) {
                widget.classList.remove('bcc-search--loading');
                if (e.name !== 'AbortError') {
                    console.error('[BCC Search]', e);
                }
            }
        }

        const debouncedSearch = debounce(function (q, type) {
            if (q.length < MIN_CHARS) {
                closeDropdown();
                return;
            }
            doSearch(q, type);
        }, DEBOUNCE_MS);

        // ── Users vertical: fetch + render ────────────────────────────────
        //
        // Runs on its own AbortController and its own empty/results
        // elements. Never touches listEl / emptyEl / chipsEl / the
        // project cache. Same debounce window as projects so typing
        // feels identical on both tabs.
        function renderUserResults(users, query) {
            if (!userListEl) return;
            userListEl.innerHTML = '';
            if (!users || users.length === 0) {
                if (userEmpty) {
                    var suffix = query ? ' for “' + query + '”' : '';
                    userEmpty.textContent = 'No users found' + suffix + '.';
                    userEmpty.hidden = false;
                }
                return;
            }
            if (userEmpty) userEmpty.hidden = true;

            var ul = document.createElement('ul');
            ul.className = 'bcc-search__group-list';
            users.forEach(function (u) { ul.appendChild(buildUserItem(u, query)); });
            userListEl.appendChild(ul);

            // Same entrance stagger as projects for visual consistency.
            var allItems = userListEl.querySelectorAll('.' + RESULT_CLASS);
            allItems.forEach(function (el, i) {
                el.style.animationDelay = (i * 15) + 'ms';
            });
        }

        async function doUserSearch(q) {
            q = String(q).trim();
            if (q.length < MIN_CHARS) return;

            // Serve from the in-memory last-query cache when the tab
            // is re-activated with the same query — avoids a server
            // roundtrip on common UX flows (click Users → click
            // Projects → click Users).
            if (q === lastUserQuery && lastUserResults !== null) {
                renderUserResults(lastUserResults, q);
                openDropdown();
                return;
            }

            if (userController) userController.abort();
            userController = new AbortController();

            widget.classList.add('bcc-search--loading');

            // bccSearch.userSearchUrl is injected via wp_localize_script
            // from render.php / the shortcode handler. Guard anyway so
            // a missing localization doesn't wedge the tab.
            var base = (typeof bccSearch !== 'undefined' && bccSearch.userSearchUrl)
                ? bccSearch.userSearchUrl
                : '';
            if (!base) {
                renderUserResults([], q);
                openDropdown();
                widget.classList.remove('bcc-search--loading');
                return;
            }

            var url = new URL(base);
            url.searchParams.set('q', q);

            try {
                const res = await fetch(url.toString(), { signal: userController.signal });
                widget.classList.remove('bcc-search--loading');

                if (!res.ok) {
                    renderUserResults([], q);
                    openDropdown();
                    return;
                }
                const json = await res.json();
                var users = Array.isArray(json.results) ? json.results : [];
                lastUserQuery   = q;
                lastUserResults = users;
                renderUserResults(users, q);
                openDropdown();
            } catch (e) {
                widget.classList.remove('bcc-search--loading');
                if (e.name !== 'AbortError') {
                    console.error('[BCC Search users]', e);
                }
            }
        }

        const debouncedUserSearch = debounce(function (q) {
            if (q.length < MIN_CHARS) {
                closeDropdown();
                return;
            }
            doUserSearch(q);
        }, DEBOUNCE_MS);

        // ── Groups vertical: fetch + render ───────────────────────────────
        //
        // Structurally mirrors the Users vertical: own AbortController,
        // own DOM targets, own last-query memory cache. No state
        // touches projects or users.
        function renderGroupResults(groups, query) {
            if (!groupListEl) return;
            groupListEl.innerHTML = '';
            if (!groups || groups.length === 0) {
                if (groupEmpty) {
                    var suffix = query ? ' for “' + query + '”' : '';
                    groupEmpty.textContent = 'No groups found' + suffix + '.';
                    groupEmpty.hidden = false;
                }
                return;
            }
            if (groupEmpty) groupEmpty.hidden = true;

            var ul = document.createElement('ul');
            ul.className = 'bcc-search__group-list';
            groups.forEach(function (g) { ul.appendChild(buildGroupItem(g, query)); });
            groupListEl.appendChild(ul);

            var allItems = groupListEl.querySelectorAll('.' + RESULT_CLASS);
            allItems.forEach(function (el, i) {
                el.style.animationDelay = (i * 15) + 'ms';
            });
        }

        async function doGroupSearch(q) {
            q = String(q).trim();
            if (q.length < MIN_CHARS) return;

            if (q === lastGroupQuery && lastGroupResults !== null) {
                renderGroupResults(lastGroupResults, q);
                openDropdown();
                return;
            }

            if (groupController) groupController.abort();
            groupController = new AbortController();

            widget.classList.add('bcc-search--loading');

            var base = (typeof bccSearch !== 'undefined' && bccSearch.groupSearchUrl)
                ? bccSearch.groupSearchUrl
                : '';
            if (!base) {
                renderGroupResults([], q);
                openDropdown();
                widget.classList.remove('bcc-search--loading');
                return;
            }

            var url = new URL(base);
            url.searchParams.set('q', q);

            try {
                const res = await fetch(url.toString(), { signal: groupController.signal });
                widget.classList.remove('bcc-search--loading');

                if (!res.ok) {
                    renderGroupResults([], q);
                    openDropdown();
                    return;
                }
                const json = await res.json();
                var groups = Array.isArray(json.results) ? json.results : [];
                lastGroupQuery   = q;
                lastGroupResults = groups;
                renderGroupResults(groups, q);
                openDropdown();
            } catch (e) {
                widget.classList.remove('bcc-search--loading');
                if (e.name !== 'AbortError') {
                    console.error('[BCC Search groups]', e);
                }
            }
        }

        const debouncedGroupSearch = debounce(function (q) {
            if (q.length < MIN_CHARS) {
                closeDropdown();
                return;
            }
            doGroupSearch(q);
        }, DEBOUNCE_MS);

        // ── Tabs: switch vertical ────────────────────────────────────────
        function setVertical(next) {
            if (next !== 'projects' && next !== 'users' && next !== 'groups') return;
            if (next === activeVertical) return;
            activeVertical = next;
            widget.setAttribute('data-vertical', next);

            // Update tab visual + ARIA state.
            if (tabsEl) {
                Array.from(tabsEl.querySelectorAll('.bcc-search__tab')).forEach(function (btn) {
                    var isActive = btn.getAttribute('data-vertical') === next;
                    btn.classList.toggle('bcc-search__tab--active', isActive);
                    btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
                });
            }

            // Swap panes. Category chips are projects-only — hide
            // them under non-project verticals (CSS also enforces
            // this, defence-in-depth).
            if (projPane)  projPane.hidden  = (next !== 'projects');
            if (userPane)  userPane.hidden  = (next !== 'users');
            if (groupPane) groupPane.hidden = (next !== 'groups');
            if (chipsEl)   chipsEl.hidden   = (next !== 'projects');

            // Abort any in-flight fetch from the OTHER verticals so
            // their response can't race into the newly-visible pane.
            if (next !== 'projects' && controller)      controller.abort();
            if (next !== 'users'    && userController)  userController.abort();
            if (next !== 'groups'   && groupController) groupController.abort();

            var q = input.value.trim();
            if (next === 'projects') {
                if (q.length >= MIN_CHARS) {
                    debouncedSearch(q, currentType);
                } else {
                    showSuggestions();
                }
            } else if (next === 'users') {
                if (q.length >= MIN_CHARS) {
                    // Lazy-load: first tab click triggers the first fetch.
                    debouncedUserSearch(q);
                } else {
                    renderUserResults([], '');
                    openDropdown();
                }
            } else { // 'groups'
                if (q.length >= MIN_CHARS) {
                    debouncedGroupSearch(q);
                } else {
                    renderGroupResults([], '');
                    openDropdown();
                }
            }
        }

        if (tabsEl) {
            Array.from(tabsEl.querySelectorAll('.bcc-search__tab')).forEach(function (btn) {
                btn.addEventListener('click', function () {
                    setVertical(btn.getAttribute('data-vertical') || 'projects');
                });
            });
        }

        // ── Event: input (single handler for search + clear button visibility) ─
        input.addEventListener('input', function () {
            if (clearBtn) clearBtn.hidden = this.value.length === 0;
            var q = this.value.trim();
            if (q.length < MIN_CHARS) {
                if (activeVertical === 'users') {
                    renderUserResults([], '');
                } else if (activeVertical === 'groups') {
                    renderGroupResults([], '');
                } else {
                    // Projects keeps its trending/recent overlay.
                    showSuggestions();
                }
                return;
            }
            if (activeVertical === 'users') {
                debouncedUserSearch(q);
            } else if (activeVertical === 'groups') {
                debouncedGroupSearch(q);
            } else {
                debouncedSearch(q, currentType);
            }
        });

        // ── Event: clear button ─────────────────────────────────────────────
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                input.value = '';
                clearBtn.hidden = true;
                currentType = '';
                if (chipsEl) {
                    Array.from(chipsEl.querySelectorAll('.bcc-search__chip')).forEach(function (btn) {
                        btn.classList.toggle('bcc-search__chip--active', btn.getAttribute('data-type') === '');
                    });
                }
                showSuggestions();
                input.focus();
            });
        }

        // ── Event: keyboard navigation ────────────────────────────────────────
        input.addEventListener('keydown', function (e) {
            const items = getItems();

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                setActive(Math.min(activeIdx + 1, items.length - 1));
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                setActive(Math.max(activeIdx - 1, -1));
                if (activeIdx === -1) input.focus();
            } else if (e.key === 'Enter') {
                if (activeIdx >= 0 && items[activeIdx]) {
                    items[activeIdx].click();
                }
            } else if (e.key === 'Escape') {
                closeDropdown();
                input.blur();
            }
        });

        // ── Event: focus input ───────────────────────────────────────────────
        input.addEventListener('focus', function () {
            fetchPreload();
            var q = input.value.trim();
            if (q.length >= MIN_CHARS && listEl.children.length > 0) {
                openDropdown();
            } else {
                showSuggestions();
            }
        });
    }

    // ─── Boot ────────────────────────────────────────────────────────────────────
    function boot() {
        if (typeof bccSearch === 'undefined') return;
        document.querySelectorAll('.bcc-search').forEach(initWidget);

        // Single global click-outside handler for all widgets
        document.addEventListener('click', function (e) {
            document.querySelectorAll('.bcc-search').forEach(function (widget) {
                if (!widget.contains(e.target) && typeof widget.__closeDropdown === 'function') {
                    widget.__closeDropdown();
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();

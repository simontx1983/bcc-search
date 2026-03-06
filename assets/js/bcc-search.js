/* global bccSearch */
(function () {
    'use strict';

    // ─── Config ────────────────────────────────────────────────────────────────
    const DEBOUNCE_MS   = 260;
    const MIN_CHARS     = 2;
    const RESULT_CLASS  = 'bcc-search__result';
    const ACTIVE_CLASS  = `${RESULT_CLASS}--active`;

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

    // ─── Tier CSS class ─────────────────────────────────────────────────────────
    function tierClass(tier) {
        if (!tier) return '';
        const map = { platinum: 'platinum', gold: 'gold', silver: 'silver', bronze: 'bronze', verified: 'verified' };
        return map[tier.toLowerCase()] ? `bcc-search__score--${map[tier.toLowerCase()]}` : '';
    }

    // ─── Build a single result <li> ─────────────────────────────────────────────
    function buildItem(item, query) {
        const li   = document.createElement('li');
        const link = document.createElement('a');
        link.href      = item.url || '#';
        link.className = RESULT_CLASS;
        link.setAttribute('role', 'option');
        link.setAttribute('tabindex', '-1');

        const scoreHtml = item.score !== null && item.score !== undefined
            ? `<span class="bcc-search__score ${tierClass(item.tier)}">${item.score}</span>`
            : '';

        const catHtml = item.category
            ? `<span class="bcc-search__cat">${escHtml(item.category)}</span>`
            : '';

        link.innerHTML = `
            <img class="bcc-search__avatar" src="${escHtml(item.avatar || '')}" alt="" loading="lazy">
            <span class="bcc-search__meta">
                <span class="bcc-search__name">${highlight(item.title, query)}</span>
                <span class="bcc-search__sub">${catHtml}</span>
            </span>
            ${scoreHtml}
        `;

        li.appendChild(link);
        return li;
    }

    // ─── Debounce ───────────────────────────────────────────────────────────────
    function debounce(fn, delay) {
        let t;
        return function (...args) {
            clearTimeout(t);
            t = setTimeout(() => fn.apply(this, args), delay);
        };
    }

    // ─── Init each .bcc-search widget on the page ───────────────────────────────
    function initWidget(widget) {
        const input    = widget.querySelector('.bcc-search__input');
        const typeEl   = widget.querySelector('.bcc-search__type');
        const dropdown = widget.querySelector('.bcc-search__dropdown');
        const listEl   = widget.querySelector('.bcc-search__results');
        const emptyEl  = widget.querySelector('.bcc-search__empty');

        if (!input || !dropdown || !listEl) return;

        let controller   = null; // AbortController for in-flight requests
        let activeIdx    = -1;
        let lastQ        = '';
        let lastType     = '';

        // ── Open / Close ──────────────────────────────────────────────────────
        function openDropdown() {
            dropdown.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        }

        function closeDropdown() {
            dropdown.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            activeIdx = -1;
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

        // ── Render results ────────────────────────────────────────────────────
        function renderResults(results, query) {
            listEl.innerHTML = '';
            activeIdx = -1;

            if (results.length === 0) {
                emptyEl && (emptyEl.hidden = false);
                return;
            }

            emptyEl && (emptyEl.hidden = true);
            results.forEach(item => listEl.appendChild(buildItem(item, query)));
        }

        // ── Fetch ─────────────────────────────────────────────────────────────
        async function doSearch(q, type) {
            // Guard: never fire a request with an invalid query
            q = String(q).trim();
            if (q.length < MIN_CHARS) return;

            // Guard: type must be a safe slug (letters, digits, hyphens only)
            const safeType = (typeof type === 'string' && /^[a-z0-9-]*$/i.test(type)) ? type : '';

            if (controller) controller.abort();
            controller = new AbortController();

            widget.classList.add('bcc-search--loading');

            const url = new URL(bccSearch.restUrl);
            url.searchParams.set('q', q);
            if (safeType) url.searchParams.set('type', safeType);

            try {
                const res = await fetch(url.toString(), {
                    signal:  controller.signal,
                    headers: { 'X-WP-Nonce': bccSearch.nonce },
                });

                widget.classList.remove('bcc-search--loading');

                if (!res.ok) {
                    // Server error — show empty state rather than stale results
                    renderResults([], q);
                    if (emptyEl) {
                        emptyEl.textContent = 'Search unavailable. Please try again.';
                        emptyEl.hidden = false;
                    }
                    openDropdown();
                    return;
                }

                const json = await res.json();
                // Restore default empty message in case it was changed by an error
                if (emptyEl) emptyEl.textContent = 'No projects found.';
                renderResults(json.results || [], q);
                openDropdown();
            } catch (e) {
                widget.classList.remove('bcc-search--loading');
                if (e.name !== 'AbortError') {
                    console.error('[BCC Search]', e);
                }
            }
        }

        const debouncedSearch = debounce(function (q, type) {
            lastQ    = q;
            lastType = type;
            if (q.length < MIN_CHARS) {
                closeDropdown();
                return;
            }
            doSearch(q, type);
        }, DEBOUNCE_MS);

        // ── Event: input ──────────────────────────────────────────────────────
        input.addEventListener('input', function () {
            debouncedSearch(this.value.trim(), typeEl ? typeEl.value : '');
        });

        // ── Event: type dropdown change ───────────────────────────────────────
        if (typeEl) {
            typeEl.addEventListener('change', function () {
                const q = input.value.trim();
                if (q.length >= MIN_CHARS) {
                    debouncedSearch(q, this.value);
                }
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

        // ── Event: click outside ──────────────────────────────────────────────
        document.addEventListener('click', function (e) {
            if (!widget.contains(e.target)) {
                closeDropdown();
            }
        });

        // ── Event: focus input to reopen if results exist ─────────────────────
        input.addEventListener('focus', function () {
            if (listEl.children.length > 0 && input.value.trim().length >= MIN_CHARS) {
                openDropdown();
            }
        });
    }

    // ─── Boot ────────────────────────────────────────────────────────────────────
    function boot() {
        if (typeof bccSearch === 'undefined') return;
        document.querySelectorAll('.bcc-search').forEach(initWidget);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();

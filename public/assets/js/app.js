// Client-side sortable tables.
// Any <th data-sort="text|num|date"> becomes a clickable sort header.
// <td data-sort-value="..."> overrides cell text for sorting (used for date columns).
(function () {
    // Apply dynamic badge colors via JS (CSP-safe: el.style.setProperty is not
    // blocked by style-src 'self', unlike inline style="" attributes).
    document.querySelectorAll('[data-badge-color]').forEach(function (el) {
        el.style.setProperty('--badge-color', el.dataset.badgeColor);
    });
    function cellValue(cell, type) {
        if (type === 'date') {
            return cell.getAttribute('data-sort-value') || '';
        }
        if (type === 'num') {
            return parseFloat(cell.textContent.trim()) || 0;
        }
        return cell.textContent.trim().toLowerCase();
    }

    function sortTable(table, colIndex, type, dir) {
        var tbody = table.querySelector('tbody');
        if (!tbody) return;
        var rows = Array.from(tbody.rows).filter(function (r) {
            return !r.querySelector('td[colspan]');
        });
        rows.sort(function (a, b) {
            var va = cellValue(a.cells[colIndex], type);
            var vb = cellValue(b.cells[colIndex], type);
            if (type === 'date') {
                if (va === '' && vb === '') return 0;
                if (va === '') return 1;
                if (vb === '') return -1;
            }
            if (va < vb) return -dir;
            if (va > vb) return dir;
            return 0;
        });
        rows.forEach(function (r) { tbody.appendChild(r); });
    }

    function initSortableTable(table) {
        var headers = Array.from(table.querySelectorAll('thead th[data-sort]'));
        if (!headers.length) return;

        var activeCol = -1;
        var activeDir = 1;

        // Reflect any data-sort-dir pre-set in HTML (server-rendered default order).
        headers.forEach(function (th, i) {
            if (th.hasAttribute('data-sort-dir')) {
                activeCol = i;
                activeDir = th.getAttribute('data-sort-dir') === 'desc' ? -1 : 1;
            }
        });

        headers.forEach(function (th, i) {
            th.setAttribute('tabindex', '0');
            th.setAttribute('aria-sort', 'none');

            function doSort() {
                var type = th.getAttribute('data-sort');
                if (activeCol === i) {
                    activeDir = -activeDir;
                } else {
                    activeCol = i;
                    activeDir = (type === 'num' || type === 'date') ? -1 : 1;
                }
                sortTable(table, i, type, activeDir);
                headers.forEach(function (h) {
                    h.removeAttribute('data-sort-dir');
                    h.setAttribute('aria-sort', 'none');
                });
                th.setAttribute('data-sort-dir', activeDir === 1 ? 'asc' : 'desc');
                th.setAttribute('aria-sort', activeDir === 1 ? 'ascending' : 'descending');
            }

            th.addEventListener('click', doSort);
            th.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); doSort(); }
            });
        });

        if (activeCol >= 0 && headers[activeCol]) {
            headers[activeCol].setAttribute('aria-sort', activeDir === 1 ? 'ascending' : 'descending');
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.admin-table').forEach(initSortableTable);
    });
})();

document.addEventListener('DOMContentLoaded', function () {
    // Auto-submit time-window dropdown on change.
    var sel = document.getElementById('window-days');
    if (sel) {
        sel.addEventListener('change', function () { this.form.submit(); });
    }
    var langSel = document.getElementById('window-lang');
    if (langSel) {
        langSel.addEventListener('change', function () { this.form.submit(); });
    }

    // Sources page: select-all / deselect-all buttons.
    var selectAll = document.getElementById('select-all');
    var deselectAll = document.getElementById('deselect-all');
    function setAll(checked) {
        document.querySelectorAll('input[name="sources[]"]').forEach(function (cb) {
            cb.checked = checked;
        });
    }
    if (selectAll) { selectAll.addEventListener('click', function () { setAll(true); }); }
    if (deselectAll) { deselectAll.addEventListener('click', function () { setAll(false); }); }

    // Ensure horizontally scrollable tables can be focused and scrolled via keyboard.
    document.querySelectorAll('.table-wrap').forEach(function (el) {
        if (el.scrollWidth > el.clientWidth) {
            if (!el.hasAttribute('tabindex')) {
                el.setAttribute('tabindex', '0');
            }
            if (!el.hasAttribute('role')) {
                el.setAttribute('role', 'region');
            }
            if (!el.hasAttribute('aria-label')) {
                el.setAttribute('aria-label', 'Scrollable table');
            }
        }
    });

    // CSP-safe confirm dialogs: forms with data-confirm="message" prompt before submit.
    // Replaces inline onsubmit handlers which are blocked by script-src 'self'.
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            var msg = form.getAttribute('data-confirm');
            if (msg && !window.confirm(msg)) {
                e.preventDefault();
            }
        });
    });

    // Theme management (toggle button + settings radios).
    function updateThemeToggleA11y(toggle) {
        if (!toggle) return;
        var appliedTheme = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
        toggle.setAttribute('aria-pressed', appliedTheme === 'dark' ? 'true' : 'false');
        toggle.setAttribute('aria-label', appliedTheme === 'dark' ? 'Theme: dark. Activate to switch theme' : 'Theme: light. Activate to switch theme');
    }

    function applyTheme(val) {
        var isDark;
        if (val === 'dark') {
            isDark = true;
        } else if (val === 'light') {
            isDark = false;
        } else {
            isDark = window.matchMedia('(prefers-color-scheme:dark)').matches;
        }
        document.documentElement.setAttribute('data-theme', isDark ? 'dark' : 'light');
        localStorage.setItem('daybreak-theme', val);
        document.querySelectorAll('input[name="theme"]').forEach(function (r) {
            r.checked = (r.value === val);
        });
        updateThemeToggleA11y(document.getElementById('theme-toggle'));
    }

    var themeToggle = document.getElementById('theme-toggle');
    if (themeToggle) {
        themeToggle.addEventListener('click', function () {
            var cur = localStorage.getItem('daybreak-theme') || 'system';
            applyTheme(cur === 'light' ? 'dark' : cur === 'dark' ? 'system' : 'light');
        });
    }
    updateThemeToggleA11y(themeToggle);

    var storedTheme = localStorage.getItem('daybreak-theme') || 'system';
    document.querySelectorAll('input[name="theme"]').forEach(function (r) {
        if (r.value === storedTheme) { r.checked = true; }
        r.addEventListener('change', function () { applyTheme(r.value); });
    });

    // Dismissible notice boxes (site notification, watch term alerts) — dismiss
    // is per-browser only (localStorage), no server round-trip. Each box carries
    // a data-notice-id reflecting its current content, so if that content changes
    // (admin edits the site message, or a new watch-term match appears) a
    // previously dismissed box reappears automatically.
    function setupDismissibleNotice(id) {
        var notice = document.getElementById(id);
        if (!notice) return;
        var target = notice.closest('.notice-wrap') || notice;
        var noticeId = notice.getAttribute('data-notice-id') || '';
        var storageKey = 'daybreak-notice-dismissed-' + id;
        if (noticeId !== '' && localStorage.getItem(storageKey) === noticeId) {
            target.remove();
            return;
        }
        var dismissBtn = notice.querySelector('[data-dismiss-notice]');
        if (dismissBtn) {
            dismissBtn.addEventListener('click', function () {
                localStorage.setItem(storageKey, noticeId);
                target.remove();
            });
        }
    }
    setupDismissibleNotice('site-notice');
    setupDismissibleNotice('watch-alerts');

    // Star toggle — event delegation handles all .star-btn clicks.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.star-btn');
        if (!btn) return;
        e.preventDefault();
        var articleId = btn.dataset.articleId;
        var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
        fetch('/star', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: '_csrf=' + encodeURIComponent(csrf) + '&article_id=' + encodeURIComponent(articleId)
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (typeof data.starred !== 'undefined') {
                    btn.classList.toggle('star-btn--active', data.starred);
                    btn.setAttribute('aria-label', data.starred ? 'Unstar article' : 'Star article');
                }
            });
    });

    // Read tracking — fire-and-forget POST when an outbound article link is clicked.
    // De-emphasises the card immediately; server-side row persists across sessions.
    document.addEventListener('click', function (e) {
        var link = e.target.closest('a[target="_blank"]');
        if (!link) return;
        var card = link.closest('.article-card');
        if (!card || !card.dataset.articleId) return;
        var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
        fetch('/read', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: '_csrf=' + encodeURIComponent(csrf) + '&article_id=' + encodeURIComponent(card.dataset.articleId)
        });
        card.classList.add('article-card--read');
    });
});

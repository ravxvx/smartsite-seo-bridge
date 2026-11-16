(function () {
    'use strict';
    var cfg = window.SSBConfig || {};
    var app = document.getElementById('ssb-app');
    if (!app) { return; }

    function h(tag, props) {
        var e = document.createElement(tag);
        props = props || {};
        Object.keys(props).forEach(function (k) {
            var v = props[k];
            if (k === 'class') { e.className = v; }
            else if (k === 'text') { e.textContent = v; }
            else if (k.indexOf('on') === 0) { e.addEventListener(k.slice(2), v); }
            else if (v !== false && v != null) { e.setAttribute(k, v); }
        });
        for (var i = 2; i < arguments.length; i++) {
            var c = arguments[i];
            if (c == null || c === false) { continue; }
            e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
        }
        return e;
    }

    function api(path, method, data) {
        return fetch(cfg.restUrl + path, {
            method: method || 'GET',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
            body: data ? JSON.stringify(data) : undefined
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) {
                if (!r.ok) { throw new Error(j.message || ('HTTP ' + r.status)); }
                return j;
            });
        });
    }

    var statusEl = h('div', { class: 'ssb-status', style: 'display:none' });
    function say(msg, ok) {
        statusEl.textContent = msg;
        statusEl.className = 'ssb-status ' + (ok ? 'ok' : 'err');
        statusEl.style.display = msg ? 'block' : 'none';
    }
    function fail(e) { say(e.message || String(e), false); }

    /* ------------------------------------------------------------ tabs */
    var tabs = [
        ['audit', 'Audit & edit'],
        ['history', 'History'],
        ['settings', 'Global settings'],
        ['snippets', 'Content snippets'],
        ['redirects', 'Redirects']
    ];
    var current = 'audit';
    var content = h('div', { class: 'ssb-content' });
    var nav = h('h2', { class: 'nav-tab-wrapper' });

    function show(tab) {
        current = tab;
        say('', true);
        Array.prototype.forEach.call(nav.children, function (a) {
            a.className = 'nav-tab' + (a.getAttribute('data-tab') === tab ? ' nav-tab-active' : '');
        });
        content.innerHTML = '';
        ({ audit: auditTab, history: historyTab, settings: settingsTab, redirects: redirectsTab, snippets: snippetsTab })[tab]();
    }
    tabs.forEach(function (t) {
        nav.appendChild(h('a', { href: '#', 'data-tab': t[0], class: 'nav-tab', text: t[1], onclick: function (e) { e.preventDefault(); show(t[0]); } }));
    });
    app.appendChild(nav);
    app.appendChild(statusEl);
    app.appendChild(content);

    /* ----------------------------------------------------------- audit */
    var A = { type: 'page', status: 'any', search: '', below: false, page: 1 };

    function badge(audit) {
        var b = h('span', { class: 'ssb-badge ssb-' + audit.grade, text: audit.score + '%' });
        b.title = 'Estimated score (target ' + cfg.target + '+)';
        return b;
    }



    /* -------------------------------------------------------- snippets */
    function snippetsTab() {
        var out = h('div');
        var lib = h('div');
        content.appendChild(h('p', { class: 'description', text: 'Works on any site: "Generate from Rank Math" writes an intro for every published page or post that has a Rank Math focus keyword and description but does not mention the keyword near the top. Nothing changes on the pages until you press Apply. You can also load a JSON file made for this site.' }));
        content.appendChild(h('p', { class: 'description', text: 'Snippets are saved for this site only. Load a JSON file made for this site, check which page each snippet will go to, then preview and apply. Each snippet targets a page by "slug" (works on any site) or "id", is marked, applied only once and can be removed again; WordPress also keeps a revision.' }));

        function resultTable(rows) {
            var t = h('table', { class: 'widefat striped' }, h('thead', {}, h('tr', {}, h('th', { text: 'ID' }), h('th', { text: 'Slug' }), h('th', { text: 'Title' }), h('th', { text: 'Result' }))));
            var tb = h('tbody');
            rows.forEach(function (r) { tb.appendChild(h('tr', {}, h('td', { text: r.id || '' }), h('td', { text: r.slug || '' }), h('td', { text: r.title || '' }), h('td', { text: r.state }))); });
            t.appendChild(tb);
            return t;
        }

        function showLibrary(items) {
            lib.innerHTML = '';
            if (!items.length) {
                lib.appendChild(h('p', { text: 'No snippets saved for this site yet.' }));
                return;
            }
            var t = h('table', { class: 'widefat striped' }, h('thead', {}, h('tr', {}, h('th', { text: 'Target' }), h('th', { text: 'Goes to' }), h('th', { text: 'Key' }), h('th', { text: 'Position' }), h('th', { text: 'Keyword' }))));
            var tb = h('tbody');
            items.forEach(function (it) {
                var target = it.slug ? 'slug: ' + it.slug : 'id: ' + it.id;
                var goes = it.target_id ? h('a', { href: it.target_url, target: '_blank', text: '#' + it.target_id + ' ' + it.target_title }) : h('strong', { text: 'not found on this site' });
                tb.appendChild(h('tr', {}, h('td', { text: target }), h('td', {}, goes), h('td', { text: it.key }), h('td', { text: it.position }), h('td', { text: it.keyword || '' })));
            });
            t.appendChild(tb);
            lib.appendChild(h('h3', { text: 'Saved snippets (' + items.length + ')' }));
            lib.appendChild(t);
        }

        function loadLibrary() {
            api('snippets').then(function (d) { showLibrary(d.items || []); }).catch(fail);
        }

        function saveLibrary(data) {
            say('Saving…', true);
            api('snippets/library', 'POST', { library: data }).then(function (d) {
                showLibrary(d.items || []);
                say('Saved ' + d.saved + ' snippet(s) for this site. Check the "Goes to" column before applying.', true);
            }).catch(fail);
        }

        function run(dry, remove) {
            say('Working…', true);
            api('snippets', 'POST', { dry_run: dry, remove: remove }).then(function (d) {
                out.innerHTML = '';
                out.appendChild(resultTable(d.results || []));
                say(dry ? 'Preview only, nothing changed.' : 'Done.', true);
            }).catch(fail);
        }

        var file = h('input', { type: 'file', accept: '.json,application/json' });
        file.addEventListener('change', function () {
            var f = file.files && file.files[0];
            if (!f) { return; }
            var reader = new FileReader();
            reader.onload = function () {
                var data;
                try { data = JSON.parse(reader.result); } catch (e) { say('This file is not valid JSON.', false); return; }
                saveLibrary(data);
                file.value = '';
            };
            reader.readAsText(f);
        });

        function generate() {
            say('Reading Rank Math data…', true);
            api('snippets/generate', 'POST', {}).then(function (d) {
                showLibrary(d.items || []);
                out.innerHTML = '';
                out.appendChild(resultTable(d.results || []));
                say('Generated ' + d.generated + ' intro(s) from Rank Math. Check them, then press Preview and Apply.', true);
            }).catch(fail);
        }

        content.appendChild(h('p', {}, h('button', { type: 'button', class: 'button button-primary', text: 'Generate from Rank Math', onclick: generate })));
        content.appendChild(h('p', {}, h('label', {}, 'Load snippet file (JSON): ', file), ' ',
            h('button', { type: 'button', class: 'button', text: 'Clear saved snippets', onclick: function () { if (confirm('Clear the saved snippets for this site? Snippets already in posts stay until you remove them.')) { saveLibrary([]); } } })));
        content.appendChild(lib);
        content.appendChild(h('p', {},
            h('button', { type: 'button', class: 'button', text: 'Preview', onclick: function () { run(true, false); } }), ' ',
            h('button', { type: 'button', class: 'button button-primary', text: 'Apply saved snippets', onclick: function () { if (confirm('Add the saved snippets to the pages listed under "Goes to"?')) { run(false, false); } } }), ' ',
            h('button', { type: 'button', class: 'button', text: 'Remove saved snippets', onclick: function () { if (confirm('Remove the saved snippets from those pages?')) { run(false, true); } } })));
        content.appendChild(out);
        loadLibrary();
    }

    /* ------------------------------------------------------------ export */
    function fetchAll(type, status) {
        var all = [];
        function next(page) {
            return api('items?audit=1&per_page=50&page=' + page + '&post_type=' + encodeURIComponent(type) + '&status=' + encodeURIComponent(status)).then(function (d) {
                all = all.concat(d.items);
                return page < d.pages ? next(page + 1) : all;
            });
        }
        return next(1);
    }
    function download(name, mime, text) {
        var blob = new Blob(['﻿' + text], { type: mime + ';charset=utf-8' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob); a.download = name;
        document.body.appendChild(a); a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
    }
    function csvCell(v) { v = String(v == null ? '' : v); return /[",\n\r;]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; }
    function failed(i) { return (i.audit.checks || []).filter(function (c) { return c.status !== 'pass'; }); }
    function esc(t) { return String(t == null ? '' : t).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function exportReport(kind, type, status) {
        say('Preparing export…', true);
        fetchAll(type, status).then(function (items) {
            var stamp = new Date().toISOString().slice(0, 10);
            items.sort(function (a, b) { return a.audit.score - b.audit.score; });
            if (kind === 'json') {
                download('seo-audit-' + stamp + '.json', 'application/json', JSON.stringify({ generated: stamp, target: cfg.target, estimated: true, items: items.map(function (i) {
                    return { id: i.post_id, type: i.post_type, status: i.status, title: i.post_title, url: i.url, score: i.audit.score, keyword: i.audit.keyword, words: i.audit.words, density: i.audit.density, seo_title: i.seo.title, seo_description: i.seo.description, rank_math_score: i.rank_math_score, checks: i.audit.checks };
                }) }, null, 2));
            } else if (kind === 'csv') {
                var rows = [['ID', 'Type', 'Status', 'Title', 'URL', 'Estimated score', 'Focus keyword', 'Words', 'Density %', 'Rank Math score', 'Failed checks', 'What to fix']];
                items.forEach(function (i) {
                    var f = failed(i);
                    rows.push([i.post_id, i.post_type, i.status, i.post_title, i.url, i.audit.score, i.audit.keyword, i.audit.words, i.audit.density, i.rank_math_score || '', f.map(function (c) { return c.label; }).join(' | '), f.map(function (c) { return c.tip || ''; }).filter(Boolean).join(' | ')]);
                });
                download('seo-audit-' + stamp + '.csv', 'text/csv', rows.map(function (r) { return r.map(csvCell).join(','); }).join('\r\n'));
            } else {
                var avg = items.length ? Math.round(items.reduce(function (a, i) { return a + i.audit.score; }, 0) / items.length) : 0;
                var body = items.map(function (i) {
                    var f = failed(i);
                    return '<section><h2>' + esc(i.post_title) + ' <span class="s ' + i.audit.grade + '">' + i.audit.score + '%</span></h2><p class="m">ID ' + i.post_id + ' · ' + esc(i.post_type) + ' · ' + esc(i.status) + ' · keyword: <b>' + esc(i.audit.keyword || '(none)') + '</b> · ' + i.audit.words + ' words · ' + esc(i.url) + '</p>' +
                        (f.length ? '<ul>' + f.map(function (c) { return '<li><b>' + (c.status === 'partial' ? '◐' : '✗') + ' ' + esc(c.label) + '</b>' + (c.tip ? ' — ' + esc(c.tip) : '') + '</li>'; }).join('') + '</ul>' : '<p>All checks passed.</p>') + '</section>';
                }).join('');
                var w = window.open('', '_blank');
                if (!w) { say('Allow pop-ups for this site, then try again.', false); return; }
                w.document.write('<!doctype html><meta charset="utf-8"><title>SEO audit ' + stamp + '</title><style>body{font:13px/1.45 sans-serif;margin:24px;color:#111}h1{margin:0 0 4px}h2{font-size:15px;margin:0}section{border-top:1px solid #ccc;padding:10px 0;break-inside:avoid}.m{color:#555;margin:2px 0 6px;word-break:break-all}ul{margin:4px 0 0 18px;padding:0}.s{color:#fff;border-radius:10px;padding:1px 8px;font-size:12px}.green{background:#1a7f37}.orange{background:#b45f06}.red{background:#b32d2e}</style><h1>SmartSite SEO audit</h1><p>' + stamp + ' · ' + items.length + ' items · average estimated score ' + avg + '% · target ' + cfg.target + '%+ (estimate, not the Rank Math editor score). Lowest first. Use Print → Save as PDF.</p>' + body);
                w.document.close();
                setTimeout(function () { w.print(); }, 400);
            }
            say('Export ready (' + items.length + ' items).', true);
        }).catch(fail);
    }

    function auditTab() {
        var typeSel = h('select');
        (cfg.types || []).forEach(function (t) { typeSel.appendChild(h('option', { value: t.slug, text: t.label })); });
        typeSel.value = A.type;
        var statusSel = h('select');
        ['any', 'publish', 'draft', 'private'].forEach(function (s) { statusSel.appendChild(h('option', { value: s, text: s })); });
        statusSel.value = A.status;
        var search = h('input', { type: 'search', placeholder: 'Search…', value: A.search });
        var below = h('input', { type: 'checkbox' });
        below.checked = A.below;
        var summary = h('span', { class: 'description' });
        var tbody = h('tbody');
        var pager = h('div', { class: 'ssb-pager' });

        function load(page) {
            A.type = typeSel.value; A.status = statusSel.value; A.search = search.value; A.below = below.checked; A.page = page || 1;
            say('Loading…', true);
            tbody.innerHTML = '';
            api('items?audit=1&per_page=20&page=' + A.page + '&post_type=' + encodeURIComponent(A.type) + '&status=' + A.status + '&search=' + encodeURIComponent(A.search))
                .then(function (d) {
                    var items = d.items.filter(function (i) { return !A.below || i.audit.score < cfg.target; });
                    var sum = 0;
                    d.items.forEach(function (i) { sum += i.audit.score; });
                    summary.textContent = d.total + ' item(s); average estimated score on this page: ' + (d.items.length ? Math.round(sum / d.items.length) : 0) + '%';
                    items.forEach(function (i) { tbody.appendChild(row(i)); });
                    pager.innerHTML = '';
                    if (d.pages > 1) {
                        pager.appendChild(h('button', { type: 'button', class: 'button', text: '‹ Prev', disabled: A.page <= 1 ? 'disabled' : false, onclick: function () { load(A.page - 1); } }));
                        pager.appendChild(h('span', { text: ' Page ' + A.page + ' / ' + d.pages + ' ' }));
                        pager.appendChild(h('button', { type: 'button', class: 'button', text: 'Next ›', disabled: A.page >= d.pages ? 'disabled' : false, onclick: function () { load(A.page + 1); } }));
                    }
                    say('', true);
                }).catch(fail);
        }

        content.appendChild(h('p', { class: 'ssb-controls' },
            typeSel, statusSel, search,
            h('label', {}, below, ' only below ' + cfg.target + '%'),
            h('button', { type: 'button', class: 'button button-primary', text: 'Load', onclick: function () { load(1); } }),
            ' ',
            h('button', { type: 'button', class: 'button', text: 'Export PDF', title: 'All content, opens print view: Save as PDF', onclick: function () { exportReport('pdf', 'any', statusSel.value); } }),
            h('button', { type: 'button', class: 'button', text: 'Export CSV', onclick: function () { exportReport('csv', 'any', statusSel.value); } }),
            h('button', { type: 'button', class: 'button', text: 'Export JSON', onclick: function () { exportReport('json', 'any', statusSel.value); } }),
            ' ', summary));
        content.appendChild(h('table', { class: 'widefat striped ssb-table' },
            h('thead', {}, h('tr', {}, h('th', { text: 'Content' }), h('th', { text: 'Score' }), h('th', { text: 'Focus keyword' }), h('th', { text: 'Words' }), h('th', { text: 'Rank Math score' }), h('th'))),
            tbody));
        content.appendChild(pager);
        load(A.page);
    }

    function row(item) {
        var tr = h('tr');
        var a = h('a', { href: item.url, target: '_blank', rel: 'noopener noreferrer', text: item.post_title || ('#' + item.post_id) });
        tr.appendChild(h('td', {}, a, h('div', { class: 'description', text: item.post_type + ' / ' + item.status + ' / ID ' + item.post_id })));
        var scoreTd = h('td', {}, badge(item.audit));
        tr.appendChild(scoreTd);
        tr.appendChild(h('td', { text: item.audit.keyword || '—' }));
        tr.appendChild(h('td', { text: String(item.audit.words) }));
        tr.appendChild(h('td', { text: item.rank_math_score ? item.rank_math_score + '%' : '—' }));
        var detail = null;
        var btn = h('button', { type: 'button', class: 'button', text: 'Details / edit' });
        btn.addEventListener('click', function () {
            if (detail) { detail.parentNode.removeChild(detail); detail = null; return; }
            detail = h('tr', { class: 'ssb-detail' }, h('td', { colspan: '6' }, panel(item, function (fresh) {
                scoreTd.innerHTML = ''; scoreTd.appendChild(badge(fresh.audit));
            })));
            tr.parentNode.insertBefore(detail, tr.nextSibling);
        });
        tr.appendChild(h('td', {}, btn));
        return tr;
    }

    function counter(input, max) {
        var n = h('span', { class: 'description' });
        function upd() { n.textContent = ' ' + input.value.length + '/' + max; n.style.color = input.value.length > max ? '#b32d2e' : '#646970'; }
        input.addEventListener('input', upd); upd();
        return n;
    }

    function panel(item, onSaved) {
        var wrapEl = h('div', { class: 'ssb-panel' });
        var checksEl = h('div', { class: 'ssb-checks' });
        var formEl = h('div', { class: 'ssb-form' });

        function drawChecks(audit) {
            checksEl.innerHTML = '';
            checksEl.appendChild(h('h3', {}, 'Checks ', badge(audit)));
            if (audit.rank_math_score) { checksEl.appendChild(h('p', { class: 'description', text: 'Last Rank Math editor score: ' + audit.rank_math_score + '%' })); }
            var ul = h('ul');
            audit.checks.forEach(function (c) {
                var mark = c.status === 'pass' ? '✓' : (c.status === 'partial' ? '◐' : '✗');
                ul.appendChild(h('li', { class: 'ssb-' + c.status }, h('strong', { text: mark + ' ' }), c.label, c.tip ? h('div', { class: 'description', text: c.tip }) : null));
            });
            checksEl.appendChild(ul);
        }
        drawChecks(item.audit);

        var inputs = {};
        function field(name, label, kind, max, opts) {
            var el;
            if (kind === 'textarea') { el = h('textarea', { rows: '3', class: 'large-text' }); }
            else if (kind === 'select') { el = h('select'); opts.forEach(function (o) { el.appendChild(h('option', { value: o, text: o || '(default)' })); }); }
            else { el = h('input', { type: 'text', class: 'large-text' }); }
            el.value = item.seo[name] || '';
            inputs[name] = el;
            formEl.appendChild(h('p', {}, h('label', {}, h('strong', { text: label }), h('br'), el, max ? counter(el, max) : null)));
        }
        field('title', 'SEO title', 'input', 60);
        field('description', 'Meta description', 'textarea', 160);
        field('focus_keyword', 'Focus keyword (primary first, comma separated)', 'input');
        field('canonical', 'Canonical URL', 'input');
        field('breadcrumb_title', 'Breadcrumb title', 'input');
        field('facebook_title', 'Facebook title', 'input');
        field('facebook_description', 'Facebook description', 'textarea', 320);
        field('facebook_image', 'Facebook image URL', 'input');
        field('twitter_use_facebook', 'Twitter uses Facebook data', 'select', 0, ['', 'on', 'off']);
        field('twitter_card_type', 'Twitter card type', 'select', 0, ['', 'summary_large_image', 'summary_card']);
        field('twitter_title', 'Twitter title', 'input');
        field('twitter_description', 'Twitter description', 'textarea', 320);
        field('twitter_image', 'Twitter image URL', 'input');

        var robotBoxes = {};
        var robotsP = h('p', {}, h('strong', { text: 'Robots meta' }), h('br'));
        ['noindex', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet'].forEach(function (r) {
            var cb = h('input', { type: 'checkbox' });
            cb.checked = item.robots.indexOf(r) !== -1;
            robotBoxes[r] = cb;
            robotsP.appendChild(h('label', { class: 'ssb-inline' }, cb, ' ' + r));
        });
        formEl.appendChild(robotsP);

        var types = Object.keys(item.schema || {});
        formEl.appendChild(h('p', { class: 'description', text: 'Schema on this item: ' + (types.length ? types.join(', ') : 'none') + ' (edit schema through the REST API / connected tools).' }));

        var save = h('button', { type: 'button', class: 'button button-primary', text: 'Save' });
        save.addEventListener('click', function () {
            var body = {};
            Object.keys(inputs).forEach(function (k) { body[k] = inputs[k].value; });
            body.robots = Object.keys(robotBoxes).filter(function (r) { return robotBoxes[r].checked; });
            save.disabled = true;
            api('pages/' + item.post_id, 'POST', body).then(function (fresh) {
                item.audit = fresh.audit; item.seo = fresh.seo;
                drawChecks(fresh.audit);
                onSaved(fresh);
                say('Saved "' + (item.post_title || item.post_id) + '". Estimated score: ' + fresh.audit.score + '%', true);
            }).catch(fail).then(function () { save.disabled = false; });
        });
        formEl.appendChild(save);
        wrapEl.appendChild(checksEl);
        wrapEl.appendChild(formEl);
        return wrapEl;
    }

    /* --------------------------------------------------------- history */
    function historyTab() {
        var tbody = h('tbody');
        content.appendChild(h('table', { class: 'widefat striped' },
            h('thead', {}, h('tr', {}, h('th', { text: 'When (UTC)' }), h('th', { text: 'User' }), h('th', { text: 'Content' }), h('th', { text: 'Source' }), h('th', { text: 'Changes' }), h('th'))), tbody));
        say('Loading…', true);
        api('history?limit=100').then(function (d) {
            d.entries.forEach(function (e) {
                var changes = h('div');
                Object.keys(e.changed).forEach(function (k) {
                    var c = e.changed[k];
                    changes.appendChild(h('div', {}, h('code', { text: k.replace('rank_math_', '') }), ': ' + JSON.stringify(c.from) + ' → ' + JSON.stringify(c.to)));
                });
                var undo = h('button', { type: 'button', class: 'button', text: 'Undo' });
                undo.addEventListener('click', function () {
                    undo.disabled = true;
                    api('history/' + e.id + '/undo', 'POST').then(function () { say('Restored previous values.', true); historyTabReload(); }).catch(fail);
                });
                tbody.appendChild(h('tr', {}, h('td', { text: e.time.replace('T', ' ').slice(0, 16) }), h('td', { text: e.user }), h('td', { text: e.post_title + ' (#' + e.post_id + ')' }), h('td', { text: e.source }), h('td', {}, changes), h('td', {}, undo)));
            });
            say(d.entries.length ? '' : 'No changes recorded yet.', true);
        }).catch(fail);
    }
    function historyTabReload() { content.innerHTML = ''; historyTab(); }

    /* -------------------------------------------------------- settings */
    function settingsTab() {
        var groupSel = h('select');
        (cfg.groups || []).forEach(function (g) { groupSel.appendChild(h('option', { value: g, text: g })); });
        var area = h('textarea', { class: 'large-text code', rows: '16' });
        var note = h('p', { class: 'description' });
        var preview = h('pre', { class: 'ssb-pre' });
        var backups = h('div');

        function load() {
            say('Loading…', true);
            preview.textContent = '';
            api('settings/' + groupSel.value).then(function (d) {
                area.value = JSON.stringify(d.values, null, 2);
                area.readOnly = !!d.read_only;
                note.textContent = d.read_only ? 'Read-only group.' : 'Edit only the keys you want to change; they are merged into the existing settings.';
                say('', true);
            }).catch(fail);
        }
        function send(dry) {
            var values;
            try { values = JSON.parse(area.value); } catch (e) { say('Invalid JSON: ' + e.message, false); return; }
            api('settings/' + groupSel.value, 'POST', { values: values, dry_run: dry }).then(function (d) {
                preview.textContent = (dry ? 'PREVIEW (nothing saved)\n' : 'SAVED\n') + JSON.stringify(d.changes, null, 2);
                say(dry ? 'Preview ready.' : 'Settings saved. A backup of the previous values was stored.', true);
                if (!dry) { loadBackups(); }
            }).catch(fail);
        }
        function loadBackups() {
            api('settings-backups').then(function (d) {
                backups.innerHTML = '';
                backups.appendChild(h('h3', { text: 'Backups (latest 30)' }));
                if (!d.backups.length) { backups.appendChild(h('p', { class: 'description', text: 'No backups yet.' })); return; }
                var tb = h('tbody');
                d.backups.forEach(function (b) {
                    var r = h('button', { type: 'button', class: 'button', text: 'Restore' });
                    r.addEventListener('click', function () {
                        r.disabled = true;
                        api('settings-backups/' + b.id + '/restore', 'POST').then(function () { say('Backup restored.', true); load(); loadBackups(); }).catch(fail);
                    });
                    tb.appendChild(h('tr', {}, h('td', { text: b.time.replace('T', ' ').slice(0, 16) }), h('td', { text: b.group }), h('td', { text: b.user }), h('td', { text: b.keys + ' keys' }), h('td', {}, r)));
                });
                backups.appendChild(h('table', { class: 'widefat striped' }, tb));
            }).catch(fail);
        }
        groupSel.addEventListener('change', load);
        content.appendChild(h('p', {}, h('label', {}, 'Group ', groupSel), ' ', h('button', { type: 'button', class: 'button', text: 'Reload', onclick: load })));
        content.appendChild(note);
        content.appendChild(area);
        content.appendChild(h('p', {},
            h('button', { type: 'button', class: 'button', text: 'Preview changes', onclick: function () { send(true); } }), ' ',
            h('button', { type: 'button', class: 'button button-primary', text: 'Save settings', onclick: function () { send(false); } })));
        content.appendChild(preview);
        content.appendChild(backups);
        load();
        loadBackups();
    }

    /* ------------------------------------------------------- redirects */
    function redirectsTab() {
        var from = h('input', { type: 'text', placeholder: '/old-page/', class: 'regular-text' });
        var to = h('input', { type: 'text', placeholder: '/new-page/ or https://…', class: 'regular-text' });
        var code = h('select');
        ['301', '302', '307', '308', '410', '451'].forEach(function (c) { code.appendChild(h('option', { value: c, text: c })); });
        var list = h('pre', { class: 'ssb-pre' });
        function load() {
            api('redirects').then(function (d) { list.textContent = JSON.stringify(d.redirections, null, 2); say('', true); }).catch(function (e) { list.textContent = ''; fail(e); });
        }
        content.appendChild(h('p', { class: 'description', text: 'Uses the Rank Math Redirections module (enable it in Rank Math > Dashboard).' }));
        content.appendChild(h('p', {}, from, ' → ', to, ' ', code, ' ',
            h('button', { type: 'button', class: 'button button-primary', text: 'Create redirect', onclick: function () {
                api('redirects', 'POST', { from: from.value, to: to.value, code: parseInt(code.value, 10) }).then(function (d) { say('Created redirect ' + d.from + ' → ' + d.to + ' (' + d.code + ').', true); load(); }).catch(fail);
            } })));
        content.appendChild(list);
        load();
    }

    show('audit');
})();

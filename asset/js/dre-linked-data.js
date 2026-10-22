/*
 * DRE Linked Data — runs the page block's SPARQL form in place.
 *
 * Without this script the form still works: it sends the query to the
 * endpoint with GET, and the browser shows the raw results. With it, the query
 * is POSTed (no URL length limit), the results appear under the form as a
 * table, and the example picker and the downloads appear.
 *
 * The results region follows the DRE asynchronous-state contract (DRE-theme
 * docs/DESIGN-INTEGRATION.md, "Asynchronous states"): one persistent status
 * node announces every outcome, aria-busy is true only while a query runs, an
 * error offers "Try again", and an unavailable endpoint gets a quiet message
 * with no retry. Technical detail (HTTP status, response body) goes to the
 * console. One deliberate exception: when the endpoint rejects the query
 * itself (400), its message is shown, since it is about what the visitor wrote
 * and is the only way to find the mistake.
 *
 * Every value from the endpoint is written as text; only http(s) IRIs become
 * links.
 */
(() => {
    'use strict';

    /** Rows drawn in the page; the downloads carry all of them. */
    const MAX_ROWS = 500;
    const ACCEPT = 'application/sparql-results+json, text/turtle;q=0.9';
    /** XSD numeric datatypes, set in tabular figures and aligned to the end. */
    const NUMERIC = /^http:\/\/www\.w3\.org\/2001\/XMLSchema#(integer|decimal|double|float|long|int|short|byte|nonNegativeInteger|positiveInteger|nonPositiveInteger|negativeInteger|unsignedLong|unsignedInt|unsignedShort|unsignedByte)$/;

    const format = (template, values) =>
        String(template).replace(/\{(\w+)\}/g, (match, key) => (key in values ? String(values[key]) : match));

    const element = (tag, className, text) => {
        const node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text != null) {
            node.textContent = text;
        }
        return node;
    };

    function setUp(panel) {
        const form = panel.querySelector('form');
        const editor = form?.elements.namedItem('query');
        const status = panel.querySelector('.dre-ld__status');
        const results = panel.querySelector('.dre-ld__results');
        if (!form || !editor || !status || !results) {
            return;
        }
        let labels = {};
        try {
            labels = JSON.parse(panel.dataset.labels || '{}');
        } catch {
            labels = {};
        }
        const endpoint = form.getAttribute('action');
        const numbers = new Intl.NumberFormat(document.documentElement.lang || 'en');
        let controller = null;

        const select = panel.querySelector('.dre-ld__select');
        if (select) {
            select.closest('[hidden]')?.removeAttribute('hidden');
            select.addEventListener('change', () => {
                editor.value = select.selectedOptions[0]?.dataset.query ?? editor.value;
            });
        }

        // ── The states of the results region ──────────────────────────────

        const settle = (state, message, children) => {
            results.setAttribute('aria-busy', state === 'loading' ? 'true' : 'false');
            results.dataset.state = state;
            if (children) {
                results.replaceChildren(...children);
                results.hidden = false;
            } else {
                results.replaceChildren();
                results.hidden = true;
            }
            status.textContent = message;
        };

        // A result: a visible summary over a box that scrolls in both
        // directions, so a wide or long result never stretches the page.
        const ready = (message, content) => {
            const scroll = element('div', 'dre-ld__results-scroll');
            scroll.tabIndex = 0;
            scroll.setAttribute('aria-label', message);
            scroll.append(content);
            settle('ready', message, [element('p', 'dre-ld__summary', message), scroll]);
        };

        const loading = () => {
            const box = element('div', 'dre-ld__loading');
            box.setAttribute('aria-hidden', 'true');
            box.append(element('span', 'dre-ld__spinner'), element('span', null, labels.loading));
            settle('loading', labels.loading, [box]);
        };

        const notice = (state, message, detail, retry) => {
            const box = element('div', `dre-ld__state dre-ld__state--${state}`);
            box.append(element('p', null, message));
            if (detail) {
                box.append(element('pre', 'dre-ld__state-detail', detail));
            }
            if (retry) {
                const button = element('button', 'btn--secondary dre-ld__button', labels.retry);
                button.type = 'button';
                button.addEventListener('click', () => {
                    button.disabled = true;
                    retry();
                });
                box.append(button);
            }
            settle(state, detail ? `${message} ${detail}` : message, [box]);
        };

        // A failed response, as the state the visitor should see.
        const fail = (response, body, retry) => {
            console.warn(`DRE Linked Data: the SPARQL endpoint answered HTTP ${response.status}.`, body);
            if (response.status === 400) {
                // Oxigraph's message about the query is its first line.
                notice('error', labels.invalid, body.split('\n', 1)[0].slice(0, 300));
            } else if (response.status === 429) {
                notice('error', labels.busy, null, retry);
            } else if ([502, 503, 504].includes(response.status)) {
                notice('unavailable', labels.unavailable);
            } else {
                notice('error', labels.error, null, retry);
            }
        };

        const unreachable = (error, retry) => {
            if (error.name === 'AbortError') {
                return;
            }
            console.warn('DRE Linked Data: the SPARQL endpoint could not be reached.', error);
            notice('error', labels.error, null, retry);
        };

        // ── Requests ──────────────────────────────────────────────────────

        const send = (accept) => {
            controller?.abort();
            controller = new AbortController();
            return fetch(endpoint, {
                method: 'POST',
                headers: { Accept: accept, 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ query: editor.value }),
                signal: controller.signal,
            });
        };

        const cell = (term) => {
            const td = document.createElement('td');
            if (!term) {
                return td;
            }
            if (term.type === 'uri' && /^https?:\/\//i.test(term.value)) {
                const link = element('a', null, term.value);
                link.href = term.value;
                td.append(link);
            } else if (term.type === 'bnode') {
                td.textContent = `_:${term.value}`;
            } else {
                td.textContent = term.value;
                if (NUMERIC.test(term.datatype ?? '')) {
                    td.className = 'dre-ld__numeric';
                }
                if (term['xml:lang']) {
                    td.lang = term['xml:lang'];
                }
            }
            return td;
        };

        const table = (vars, bindings) => {
            const node = element('table', 'dre-ld__table');
            const head = node.createTHead().insertRow();
            for (const name of vars) {
                const th = element('th', null, `?${name}`);
                th.scope = 'col';
                head.append(th);
            }
            const body = node.createTBody();
            for (const binding of bindings.slice(0, MAX_ROWS)) {
                const row = body.insertRow();
                for (const name of vars) {
                    row.append(cell(binding[name]));
                }
            }
            return node;
        };

        const run = async () => {
            loading();
            const started = performance.now();
            let response;
            let body;
            try {
                response = await send(ACCEPT);
                body = await response.text();
            } catch (error) {
                unreachable(error, run);
                return;
            }
            if (!response.ok) {
                fail(response, body, run);
                return;
            }
            const seconds = ((performance.now() - started) / 1000).toFixed(1);
            if (!(response.headers.get('Content-Type') || '').includes('sparql-results+json')) {
                ready(labels.graph, element('pre', 'dre-ld__code', body));
                return;
            }
            let data;
            try {
                data = JSON.parse(body);
            } catch (error) {
                // An error after the first rows (a SERVICE call that fails, a
                // request cut at its time limit) arrives inside a 200.
                console.warn('DRE Linked Data: the SPARQL results are incomplete.', error);
                notice('error', labels.incomplete, null, run);
                return;
            }
            if (typeof data.boolean === 'boolean') {
                notice('ready', data.boolean ? labels.true : labels.false);
                return;
            }
            const vars = data.head?.vars ?? [];
            const bindings = data.results?.bindings ?? [];
            if (bindings.length === 0) {
                notice('empty', labels.empty);
                return;
            }
            ready(bindings.length > MAX_ROWS
                ? format(labels.truncated, { shown: numbers.format(MAX_ROWS), count: numbers.format(bindings.length) })
                : format(labels.results, { count: numbers.format(bindings.length), seconds }), table(vars, bindings));
        };

        const download = async (type) => {
            loading();
            let response;
            try {
                response = await send(type);
            } catch (error) {
                unreachable(error, () => download(type));
                return;
            }
            if (!response.ok) {
                fail(response, await response.text(), () => download(type));
                return;
            }
            const url = URL.createObjectURL(await response.blob());
            const link = element('a');
            link.href = url;
            link.download = type === 'text/csv' ? 'query-results.csv' : 'query-results.json';
            document.body.append(link);
            link.click();
            link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 0);
            settle('idle', '');
        };

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            run();
        });
        for (const button of panel.querySelectorAll('[data-download]')) {
            button.hidden = false;
            button.addEventListener('click', () => download(button.dataset.download));
        }
    }

    const start = () => document.querySelectorAll('[data-dre-ld-sparql]').forEach(setUp);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();

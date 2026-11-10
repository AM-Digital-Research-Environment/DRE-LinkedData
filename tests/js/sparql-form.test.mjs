/**
 * asset/js/dre-linked-data.js: the SPARQL form's results and downloads.
 *
 *   node --test tests/js/*.test.mjs        (composer test:js)
 *
 * The script runs in a vm context over the fake DOM in fake-dom.mjs, with
 * Node's own fetch Response, Blob and URL.createObjectURL behind a fetch stub.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { FakeDocument, h } from './fake-dom.mjs';

const SOURCE = readFileSync(new URL('../../asset/js/dre-linked-data.js', import.meta.url), 'utf8');

const LABELS = {
    loading: 'Loading…',
    retry: 'Try again',
    results: '{count} results in {seconds} s.',
    truncated: 'Showing the first {shown} of {count} results.',
    empty: 'No results match this query.',
    invalid: 'The endpoint could not run this query:',
    busy: 'Too many queries at once.',
    unavailable: 'The SPARQL endpoint is temporarily unavailable.',
    incomplete: 'The results were cut short.',
    error: 'The query could not be run.',
    preparing: 'Preparing the download…',
    downloaded: 'Download started.',
    downloadError: 'The results could not be downloaded.',
};

const RESULTS = JSON.stringify({
    head: { vars: ['s', 'n'] },
    results: {
        bindings: [
            { s: { type: 'uri', value: 'https://example.org/resource/1' }, n: { type: 'literal', value: '3', datatype: 'http://www.w3.org/2001/XMLSchema#integer' } },
            { s: { type: 'uri', value: 'https://example.org/resource/2' }, n: { type: 'literal', value: '5', datatype: 'http://www.w3.org/2001/XMLSchema#integer' } },
        ],
    },
});

const sparqlJson = () => new Response(RESULTS, { headers: { 'Content-Type': 'application/sparql-results+json' } });

/** A 200 whose body fails after the headers arrived. */
const brokenBody = () => new Response(new ReadableStream({
    start(controller) {
        controller.error(new TypeError('connection reset'));
    },
}), { headers: { 'Content-Type': 'text/csv' } });

/**
 * Renders the block's SPARQL panel (as view/common/block-layout/dre-linked-data.phtml
 * does) and runs the script over it. `answer(accept)` stands in for the endpoint.
 */
function setUp(answer) {
    const document = new FakeDocument();
    const el = (...args) => h(document, ...args);
    const panel = el('section', { class: 'dre-ld__panel dre-ld__sparql', 'data-dre-ld-sparql': true, 'data-labels': JSON.stringify(LABELS) },
        el('form', { class: 'dre-ld__query', method: 'get', action: 'https://sparql.example.org/query' },
            el('textarea', { name: 'query' }),
            el('p', { class: 'dre-ld__actions' },
                el('button', { class: 'button dre-ld__button', type: 'submit' }, 'Run this query'),
                el('button', { class: 'btn--secondary dre-ld__button', type: 'button', 'data-download': 'text/csv', hidden: true }, 'Download data (CSV)'),
                el('button', { class: 'btn--secondary dre-ld__button', type: 'button', 'data-download': 'application/sparql-results+json', hidden: true }, 'Download data (JSON)'))),
        el('p', { class: 'dre-ld__download-note', 'aria-hidden': 'true', hidden: true }),
        el('p', { class: 'dre-ld__status', role: 'status' }),
        el('div', { class: 'dre-ld__results', role: 'region', 'aria-busy': 'false', hidden: true }));
    panel.querySelector('textarea').value = 'SELECT * WHERE { ?s ?p ?o }';
    document.body.append(panel);

    const requests = [];
    const warnings = [];
    const context = vm.createContext({
        document,
        fetch: async (url, init) => {
            requests.push(init.headers.Accept);
            return answer(init.headers.Accept);
        },
        URL,
        URLSearchParams,
        AbortController,
        Intl,
        performance,
        setTimeout,
        console: { warn: (...args) => warnings.push(args) },
    });
    vm.runInContext(SOURCE, context);

    const query = panel.querySelector('form');
    const results = panel.querySelector('.dre-ld__results');
    const [csv, json] = panel.querySelectorAll('[data-download]');
    return {
        document,
        requests,
        warnings,
        results,
        status: panel.querySelector('.dre-ld__status'),
        note: panel.querySelector('.dre-ld__download-note'),
        csv,
        json,
        run: async () => {
            query.dispatch('submit');
            await settled();
        },
        click: async (button) => {
            button.click();
            await settled();
        },
    };
}

/** Lets every pending fetch, body read and timer of the script finish. */
const settled = async () => {
    for (let i = 0; i < 10; i++) {
        await new Promise((resolve) => setTimeout(resolve, 0));
    }
};

/** Runs a query that draws a two-row table, and returns that table. */
async function withResults(page) {
    await page.run();
    assert.equal(page.results.dataset.state, 'ready');
    const table = page.results.querySelector('table');
    assert.ok(table, 'the query draws a table');
    assert.equal(table.querySelectorAll('tr').length, 3, 'a head row and two result rows');
    return table;
}

/** The results region is exactly as the query left it. */
function assertResultsKept(page, table) {
    assert.equal(page.results.dataset.state, 'ready');
    assert.equal(page.results.hidden, false);
    assert.equal(page.results.getAttribute('aria-busy'), 'false');
    assert.equal(page.results.querySelector('table'), table, 'the same table is still shown');
}

test('the download buttons appear once the script runs', () => {
    const page = setUp(sparqlJson);
    assert.equal(page.csv.hidden, false);
    assert.equal(page.json.hidden, false);
});

test('a successful CSV download leaves the results in place', async () => {
    const page = setUp((accept) => (accept === 'text/csv' ? new Response('s,n\n1,3\n') : sparqlJson()));
    const table = await withResults(page);
    await page.click(page.csv);

    assert.deepEqual(page.requests.at(-1), 'text/csv');
    assert.deepEqual(page.document.saved.map((file) => file.name), ['query-results.csv']);
    assertResultsKept(page, table);
    assert.equal(page.status.textContent, LABELS.downloaded);
    assert.equal(page.note.textContent, LABELS.downloaded);
    assert.equal(page.note.hidden, false);
});

test('a successful JSON download leaves the results in place', async () => {
    const page = setUp(sparqlJson);
    const table = await withResults(page);
    await page.click(page.json);

    assert.deepEqual(page.document.saved.map((file) => file.name), ['query-results.json']);
    assertResultsKept(page, table);
});

test('a download before any query shows no results region', async () => {
    const page = setUp(() => new Response('s\n'));
    await page.click(page.csv);

    assert.equal(page.results.hidden, true);
    assert.equal(page.results.children.length, 0);
    assert.equal(page.status.textContent, LABELS.downloaded);
});

const failures = [
    ['an unreachable endpoint', () => Promise.reject(new TypeError('Failed to fetch')), LABELS.downloadError],
    ['a server error', () => new Response('boom', { status: 500 }), LABELS.downloadError],
    ['an unavailable endpoint', () => new Response('', { status: 503 }), LABELS.unavailable],
    ['too many queries', () => new Response('', { status: 429 }), LABELS.busy],
    ['a rejected query', () => new Response('Parse error at line 1\nmore', { status: 400 }), `${LABELS.invalid} Parse error at line 1`],
    ['a body that fails to read', brokenBody, LABELS.downloadError],
];

for (const [name, answer, message] of failures) {
    test(`a download failing on ${name} is reported in the status region and keeps the results`, async () => {
        const page = setUp((accept) => (accept === 'text/csv' ? answer() : sparqlJson()));
        const table = await withResults(page);
        await page.click(page.csv);

        assert.equal(page.status.textContent, message);
        assert.equal(page.note.textContent, message, 'the visible note says the same');
        assert.equal(page.note.hidden, false);
        assert.deepEqual(page.document.saved, [], 'nothing is handed to the browser to save');
        assertResultsKept(page, table);
        assert.ok(page.warnings.length > 0, 'the technical detail goes to the console');
    });
}

test('a new query clears the download note', async () => {
    const page = setUp((accept) => (accept === 'text/csv' ? new Response('', { status: 500 }) : sparqlJson()));
    await withResults(page);
    await page.click(page.csv);
    assert.equal(page.note.hidden, false);

    await page.run();
    assert.equal(page.note.hidden, true);
    assert.equal(page.note.textContent, '');
    assert.match(page.status.textContent, /^2 results in \d+\.\d s\.$/);
});

test('a failed query still offers Try again', async () => {
    let calls = 0;
    const page = setUp(() => (++calls === 1 ? new Response('', { status: 500 }) : sparqlJson()));
    await page.run();
    assert.equal(page.results.dataset.state, 'error');
    assert.equal(page.status.textContent, LABELS.error);

    const retry = page.results.querySelector('button');
    assert.ok(retry, 'the error state has a button');
    await page.click(retry);
    assert.equal(page.results.dataset.state, 'ready');
});

/**
 * The smallest DOM asset/js/dre-linked-data.js runs against.
 *
 * The module has no Node toolchain, so no jsdom: these tests run on what Node
 * ships (`node --test`). The fake implements only what the script touches and
 * matches only the simple selectors it uses (a tag, `.class` or `[attribute]`).
 * A script change that needs more of the DOM fails here loudly, with a
 * TypeError, rather than passing on a stub that does nothing.
 */

const matches = (node, selector) => {
    if (selector.startsWith('.')) {
        return node.className.split(/\s+/).includes(selector.slice(1));
    }
    if (selector.startsWith('[') && selector.endsWith(']')) {
        return node.hasAttribute(selector.slice(1, -1));
    }
    return node.tagName === selector.toUpperCase();
};

export class FakeElement {
    constructor(tag, document) {
        this.tagName = tag.toUpperCase();
        this.ownerDocument = document;
        this.children = [];
        this.parentNode = null;
        this.attributes = new Map();
        this.dataset = {};
        this.hidden = false;
        this.className = '';
        this.listeners = {};
        this.ownText = '';
    }

    get textContent() {
        return this.ownText + this.children.map((child) => child.textContent).join('');
    }

    set textContent(value) {
        this.children = [];
        this.ownText = String(value);
    }

    setAttribute(name, value) {
        this.attributes.set(name, String(value));
        if (name === 'class') {
            this.className = String(value);
        } else if (name === 'hidden') {
            this.hidden = true;
        } else if (name.startsWith('data-')) {
            const key = name.slice(5).replace(/-(\w)/g, (match, letter) => letter.toUpperCase());
            this.dataset[key] = String(value);
        }
    }

    getAttribute(name) {
        return this.attributes.has(name) ? this.attributes.get(name) : null;
    }

    hasAttribute(name) {
        if (name === 'hidden') {
            return this.hidden;
        }
        return this.attributes.has(name);
    }

    removeAttribute(name) {
        this.attributes.delete(name);
        if (name === 'hidden') {
            this.hidden = false;
        }
    }

    append(...nodes) {
        for (const node of nodes) {
            node.remove();
            node.parentNode = this;
            this.children.push(node);
        }
    }

    replaceChildren(...nodes) {
        for (const child of this.children) {
            child.parentNode = null;
        }
        this.children = [];
        this.ownText = '';
        this.append(...nodes);
    }

    remove() {
        if (this.parentNode) {
            this.parentNode.children = this.parentNode.children.filter((child) => child !== this);
            this.parentNode = null;
        }
    }

    *descendants() {
        for (const child of this.children) {
            yield child;
            yield* child.descendants();
        }
    }

    querySelectorAll(selector) {
        return [...this.descendants()].filter((node) => matches(node, selector));
    }

    querySelector(selector) {
        return this.querySelectorAll(selector)[0] ?? null;
    }

    closest(selector) {
        for (let node = this; node; node = node.parentNode) {
            if (matches(node, selector)) {
                return node;
            }
        }
        return null;
    }

    get elements() {
        return { namedItem: (name) => [...this.descendants()].find((node) => node.getAttribute('name') === name) ?? null };
    }

    addEventListener(type, listener) {
        (this.listeners[type] ??= []).push(listener);
    }

    dispatch(type, event = {}) {
        const full = { type, target: this, preventDefault() {}, ...event };
        for (const listener of this.listeners[type] ?? []) {
            listener(full);
        }
    }

    click() {
        if (this.tagName === 'A' && this.download) {
            this.ownerDocument.saved.push({ name: this.download, href: this.href });
        }
        this.dispatch('click');
    }

    // Tables: just enough for the result table.
    createTHead() {
        const head = this.ownerDocument.createElement('thead');
        this.append(head);
        return head;
    }

    createTBody() {
        const body = this.ownerDocument.createElement('tbody');
        this.append(body);
        return body;
    }

    insertRow() {
        const row = this.ownerDocument.createElement('tr');
        this.append(row);
        return row;
    }
}

export class FakeDocument {
    constructor() {
        this.readyState = 'complete';
        this.documentElement = { lang: 'en-GB' };
        this.body = this.createElement('body');
        /** Every file the page handed to the browser to save. */
        this.saved = [];
    }

    createElement(tag) {
        return new FakeElement(tag, this);
    }

    querySelectorAll(selector) {
        return this.body.querySelectorAll(selector);
    }

    querySelector(selector) {
        return this.body.querySelector(selector);
    }

    addEventListener() {}
}

/** h('p', {class: 'x', hidden: true}, child, 'text') */
export const h = (document, tag, attributes = {}, ...children) => {
    const node = document.createElement(tag);
    for (const [name, value] of Object.entries(attributes)) {
        if (value === true) {
            node.setAttribute(name, '');
        } else if (value !== false && value != null) {
            node.setAttribute(name, value);
        }
    }
    for (const child of children) {
        if (typeof child === 'string') {
            node.ownText += child;
        } else {
            node.append(child);
        }
    }
    return node;
};

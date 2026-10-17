import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Behavioural tests for the Page content block's script
 * (addon-euph-pages-in-blocks/js/addons/euph_pages_in_blocks/func.js).
 *
 * The rules that must not break: without the script the panels read in
 * order; with it, exactly one tab panel shows and only the selected tab is a
 * Tab stop; the arrow keys (reversed right-to-left), Home and End move the
 * selection; an address ending in #<id> opens that tab or section; "Keep
 * only one section open" closes the other sections; printing shows
 * everything and puts it back; a block is set up once, also when CS-Cart
 * redraws part of the page (ce.commoninit).
 */

const handlers = {};

function fakeJquery() {
    const $ = () => undefined;
    $.ceEvent = (action, name, handler) => {
        if (action === 'on') {
            handlers[name] = handler;
        }
    };
    return $;
}

const api = () => window.EuphPagesInBlocks;

// The markup of tabs.tpl and accordion.tpl, as the server draws it.
function tabsBlock(id = 'euph-pib-12', titles = ['Check-in', 'Payment', 'Cancellation']) {
    const tabs = titles
        .map((title, i) => `<button type="button" class="euph-pib__tab" id="${id}-p${i + 1}-tab" data-euph-pib-target="${id}-p${i + 1}"${i === 0 ? ' aria-selected="true"' : ''}>${title}</button>`)
        .join('');
    const panels = titles
        .map((title, i) => `<div class="euph-pib__panel euph-pib__item" id="${id}-p${i + 1}"><h3 class="euph-pib__panel-title">${title}</h3><p id="${id}-p${i + 1}-text">Text of ${title}</p></div>`)
        .join('');
    const root = document.createElement('div');
    root.className = 'euph-pib euph-pib--tabs';
    root.id = id;
    root.setAttribute('data-euph-pib-tabs', '');
    root.innerHTML = `<div class="euph-pib__tablist" hidden>${tabs}</div>${panels}`;
    document.body.appendChild(root);
    return root;
}

function accordionBlock(id = 'euph-pib-20', oneOpen = true, titles = ['Rooms', 'Food', 'Beach']) {
    const root = document.createElement('div');
    root.className = 'euph-pib euph-pib--accordion';
    root.id = id;
    if (oneOpen) {
        root.setAttribute('data-euph-pib-one-open', '');
    }
    root.innerHTML = titles
        .map((title, i) => `<details class="euph-pib__section euph-pib__item" id="${id}-p${i + 1}"${oneOpen ? ` name="${id}"` : ''}><summary class="euph-pib__summary">${title}</summary><div class="euph-pib__section-body"><p id="${id}-p${i + 1}-text">About ${title}</p></div></details>`)
        .join('');
    document.body.appendChild(root);
    return root;
}

const tabsOf = (root) => [...root.querySelectorAll('.euph-pib__tab')];
const panelsOf = (root) => [...root.querySelectorAll('.euph-pib__panel')];
const selected = (root) => tabsOf(root).filter((tab) => tab.getAttribute('aria-selected') === 'true').map((tab) => tab.textContent);
const shown = (root) => panelsOf(root).filter((panel) => !panel.hasAttribute('hidden')).map((panel) => panel.id);

function press(element, key) {
    const event = new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true });
    element.dispatchEvent(event);
    return event;
}

function open(section) {
    section.open = true;
    section.dispatchEvent(new Event('toggle'));
}

function setHash(id) {
    window.history.replaceState(null, '', id ? `#${id}` : window.location.pathname);
}

beforeAll(async () => {
    window.Tygh = { $: fakeJquery() };
    // jsdom has no layout: scrolling is a no-op.
    window.HTMLElement.prototype.scrollIntoView = function () {};
    await import('../../addon-euph-pages-in-blocks/js/addons/euph_pages_in_blocks/func.js');
});

beforeEach(() => {
    document.body.innerHTML = '';
    document.documentElement.removeAttribute('dir');
    setHash('');
});

describe('tabs', () => {
    it('shows the tab row and one panel, with the tabs roles', () => {
        const root = tabsBlock();
        api().init(document);

        const list = root.querySelector('.euph-pib__tablist');
        expect(list.hasAttribute('hidden')).toBe(false);
        expect(list.getAttribute('role')).toBe('tablist');
        expect(root.classList.contains('euph-pib--tabs-ready')).toBe(true);
        tabsOf(root).forEach((tab, i) => {
            const panel = panelsOf(root)[i];
            expect(tab.getAttribute('role')).toBe('tab');
            expect(tab.getAttribute('aria-controls')).toBe(panel.id);
            expect(panel.getAttribute('role')).toBe('tabpanel');
            expect(panel.getAttribute('aria-labelledby')).toBe(tab.id);
            expect(panel.getAttribute('tabindex')).toBe('0');
        });
        expect(selected(root)).toEqual(['Check-in']);
        expect(shown(root)).toEqual(['euph-pib-12-p1']);
        expect(tabsOf(root).map((tab) => tab.getAttribute('tabindex'))).toEqual(['0', '-1', '-1']);
    });

    it('selects a tab on click', () => {
        const root = tabsBlock();
        api().init(document);

        tabsOf(root)[2].click();

        expect(selected(root)).toEqual(['Cancellation']);
        expect(shown(root)).toEqual(['euph-pib-12-p3']);
        expect(tabsOf(root).map((tab) => tab.getAttribute('aria-selected'))).toEqual(['false', 'false', 'true']);
        expect(tabsOf(root).map((tab) => tab.getAttribute('tabindex'))).toEqual(['-1', '-1', '0']);
    });

    it('moves with the arrow keys, round, and with Home and End', () => {
        const root = tabsBlock();
        api().init(document);
        const tabs = tabsOf(root);

        expect(press(tabs[0], 'ArrowRight').defaultPrevented).toBe(true);
        expect(selected(root)).toEqual(['Payment']);
        expect(document.activeElement).toBe(tabs[1]);
        press(tabs[1], 'ArrowRight');
        press(tabs[2], 'ArrowRight');
        expect(selected(root)).toEqual(['Check-in']);
        press(tabs[0], 'ArrowLeft');
        expect(selected(root)).toEqual(['Cancellation']);
        press(tabs[2], 'Home');
        expect(selected(root)).toEqual(['Check-in']);
        press(tabs[0], 'End');
        expect(selected(root)).toEqual(['Cancellation']);
        expect(document.activeElement).toBe(tabs[2]);
    });

    it('leaves the other keys alone', () => {
        const root = tabsBlock();
        api().init(document);

        expect(press(tabsOf(root)[0], 'Enter').defaultPrevented).toBe(false);
        expect(press(tabsOf(root)[0], 'a').defaultPrevented).toBe(false);
        expect(selected(root)).toEqual(['Check-in']);
    });

    it('reverses the arrows on a right-to-left page', () => {
        document.documentElement.setAttribute('dir', 'rtl');
        const root = tabsBlock();
        root.style.direction = 'rtl';
        api().init(document);

        press(tabsOf(root)[0], 'ArrowLeft');
        expect(selected(root)).toEqual(['Payment']);
        press(tabsOf(root)[1], 'ArrowRight');
        expect(selected(root)).toEqual(['Check-in']);
    });

    it('starts on the tab the server marked', () => {
        const root = tabsBlock();
        tabsOf(root)[0].removeAttribute('aria-selected');
        tabsOf(root)[1].setAttribute('aria-selected', 'true');
        api().init(document);

        expect(selected(root)).toEqual(['Payment']);
        expect(shown(root)).toEqual(['euph-pib-12-p2']);
    });

    it('sets a block up once', () => {
        const root = tabsBlock();
        api().init(document);
        tabsOf(root)[1].click();
        const listen = vi.spyOn(tabsOf(root)[0], 'addEventListener');

        api().init(document);

        expect(listen).not.toHaveBeenCalled();
        expect(selected(root)).toEqual(['Payment'], 'a second set-up keeps the selection');
    });

    it('ignores a block without tabs', () => {
        const root = document.createElement('div');
        root.setAttribute('data-euph-pib-tabs', '');
        document.body.appendChild(root);

        expect(() => api().init(document)).not.toThrow();
        expect(root.hasAttribute('data-euph-pib-ready')).toBe(false);
    });
});

describe('CS-Cart redraws', () => {
    it('sets up the blocks of a part of the page drawn by AJAX', () => {
        expect(typeof handlers['ce.commoninit']).toBe('function');
        const first = tabsBlock('euph-pib-1');
        const container = document.createElement('div');
        document.body.appendChild(container);
        const second = tabsBlock('euph-pib-2');
        container.appendChild(second);

        // CS-Cart passes the redrawn part as a jQuery object.
        handlers['ce.commoninit']({ jquery: '3.7.1', 0: container, length: 1 });

        expect(second.classList.contains('euph-pib--tabs-ready')).toBe(true);
        expect(first.classList.contains('euph-pib--tabs-ready')).toBe(false);
        handlers['ce.commoninit'](undefined);
        expect(first.classList.contains('euph-pib--tabs-ready')).toBe(true);
    });
});

describe('address hash', () => {
    it('opens the tab of a panel or of an element in it', () => {
        const root = tabsBlock();
        api().init(document);

        setHash('euph-pib-12-p3');
        expect(api().openFromHash()).toBe(true);
        expect(selected(root)).toEqual(['Cancellation']);

        setHash('euph-pib-12-p2-text');
        expect(api().openFromHash()).toBe(true);
        expect(selected(root)).toEqual(['Payment']);
    });

    it('opens the section of an accordion', () => {
        const root = accordionBlock();
        api().init(document);

        setHash('euph-pib-20-p2-text');
        expect(api().openFromHash()).toBe(true);
        expect([...root.querySelectorAll('details')].map((d) => d.open)).toEqual([false, true, false]);
    });

    it('ignores ids that are not in a block, and broken hashes', () => {
        tabsBlock();
        const other = document.createElement('p');
        other.id = 'elsewhere';
        document.body.appendChild(other);
        api().init(document);

        setHash('elsewhere');
        expect(api().openFromHash()).toBe(false);
        setHash('nothing-here');
        expect(api().openFromHash()).toBe(false);
        setHash('%E0%A4%A');
        expect(() => api().openFromHash()).not.toThrow();
        setHash('');
        expect(api().openFromHash()).toBe(false);
    });

    it('follows the hash when it changes', () => {
        const root = tabsBlock();
        api().init(document);

        setHash('euph-pib-12-p2');
        window.dispatchEvent(new HashChangeEvent('hashchange'));

        expect(selected(root)).toEqual(['Payment']);
    });
});

describe('accordion', () => {
    it('keeps one section open when the block says so', () => {
        const root = accordionBlock();
        api().init(document);
        const sections = [...root.querySelectorAll('details')];

        open(sections[0]);
        open(sections[2]);

        expect(sections.map((d) => d.open)).toEqual([false, false, true]);
    });

    it('lets sections open freely otherwise', () => {
        const root = accordionBlock('euph-pib-21', false);
        api().init(document);
        const sections = [...root.querySelectorAll('details')];

        open(sections[0]);
        open(sections[2]);

        expect(sections.map((d) => d.open)).toEqual([true, false, true]);
    });
});

describe('printing', () => {
    it('shows every section, then puts them back', () => {
        const root = accordionBlock();
        api().init(document);
        const sections = [...root.querySelectorAll('details')];
        open(sections[1]);

        api().beforePrint();
        sections.forEach((d) => d.dispatchEvent(new Event('toggle')));
        expect(sections.map((d) => d.open)).toEqual([true, true, true]);
        expect(sections.every((d) => !d.hasAttribute('name'))).toBe(true);

        api().afterPrint();
        expect(sections.map((d) => d.open)).toEqual([false, true, false]);
        expect(sections.map((d) => d.getAttribute('name'))).toEqual(['euph-pib-20', 'euph-pib-20', 'euph-pib-20']);
    });
});

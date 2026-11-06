import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Behavioral tests for "Load N more" under the hotel list
 * (addon-travel-core/js/addons/travel_core/list-load-more.js, board 15):
 * the link fetches the next page and appends its cards, takes over its
 * button, drops it on the last page, keeps "Showing 1–N of T" right, and
 * falls back to opening the page when the fetch fails.
 */

const TEXT = 'Afișate [from]–[to] din [total]';

function card(id) {
    return `<li class="travel-lc"><a class="travel-lc__photo" href="/h${id}" tabindex="-1"></a><a class="travel-lc__name" href="/h${id}">Hotel ${id}</a></li>`;
}

function page({ ids, from, to, total, next }) {
    const more = next
        ? `<div class="travel-list-more" data-travel-list-more-box>
               <p data-travel-list-shown data-from="${from}" data-to="${to}" data-total="${total}" data-text="${TEXT}">${TEXT.replace('[from]', from).replace('[to]', to).replace('[total]', total)}</p>
               <a class="travel-list-more__btn" href="${next}" data-travel-list-more>Încarcă încă ${Math.min(20, total - to)}</a>
           </div>`
        : '';

    return `<div data-travel-list><ul data-travel-list-items>${ids.map(card).join('')}</ul>${more}</div>`;
}

beforeAll(async () => {
    await import('../../addon-travel-core/js/addons/travel_core/list-load-more.js');
});

let assign;

beforeEach(() => {
    assign = vi.fn();
    Object.defineProperty(window, 'location', { configurable: true, value: { ...window.location, assign } });
});

afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
});

async function click(link) {
    link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
    await new Promise((resolve) => setTimeout(resolve, 0));
    await new Promise((resolve) => setTimeout(resolve, 0));
}

describe('Load N more', () => {
    it('appends the next page, takes over its button and keeps the count from the first card', async () => {
        document.body.innerHTML = page({ ids: [1, 2], from: 1, to: 2, total: 5, next: '/romania/page-2/' });
        window.fetch = vi.fn().mockResolvedValue({ ok: true, text: () => Promise.resolve(page({ ids: [3, 4], from: 3, to: 4, total: 5, next: '/romania/page-3/' })) });

        await click(document.querySelector('[data-travel-list-more]'));

        expect(window.fetch).toHaveBeenCalledWith(expect.stringContaining('/romania/page-2/'), expect.objectContaining({ credentials: 'same-origin' }));
        expect([...document.querySelectorAll('.travel-lc__name')].map((a) => a.textContent)).toEqual(['Hotel 1', 'Hotel 2', 'Hotel 3', 'Hotel 4']);
        expect(document.querySelector('[data-travel-list-shown]').textContent).toBe('Afișate 1–4 din 5');
        expect(document.querySelector('[data-travel-list-more]').getAttribute('href')).toBe('/romania/page-3/');
        expect(document.activeElement.textContent).toBe('Hotel 3');
        expect(assign).not.toHaveBeenCalled();
    });

    it('drops the button on the last page and shows every card counted', async () => {
        document.body.innerHTML = page({ ids: [1, 2], from: 1, to: 2, total: 3, next: '/romania/page-2/' });
        window.fetch = vi.fn().mockResolvedValue({ ok: true, text: () => Promise.resolve(page({ ids: [3], from: 3, to: 3, total: 3, next: '' })) });

        await click(document.querySelector('[data-travel-list-more]'));

        expect(document.querySelectorAll('.travel-lc')).toHaveLength(3);
        expect(document.querySelector('[data-travel-list-more]')).toBeNull();
        expect(document.querySelector('[data-travel-list-shown]').textContent).toBe('Afișate 1–3 din 3');
    });

    it('opens the next page when the fetch fails or brings no cards', async () => {
        document.body.innerHTML = page({ ids: [1], from: 1, to: 1, total: 2, next: '/romania/page-2/' });
        window.fetch = vi.fn().mockResolvedValue({ ok: false, status: 500, text: () => Promise.resolve('') });
        await click(document.querySelector('[data-travel-list-more]'));
        expect(assign).toHaveBeenCalledWith(expect.stringContaining('/romania/page-2/'));

        assign.mockClear();
        window.fetch = vi.fn().mockResolvedValue({ ok: true, text: () => Promise.resolve('<p>maintenance</p>') });
        await click(document.querySelector('[data-travel-list-more]'));
        expect(assign).toHaveBeenCalledTimes(1);
        expect(document.querySelectorAll('.travel-lc')).toHaveLength(1);
    });

    it('leaves a modified click to the browser (new tab)', async () => {
        // A same-document link: jsdom cannot open another page.
        document.body.innerHTML = page({ ids: [1], from: 1, to: 1, total: 2, next: '#page-2' });
        window.fetch = vi.fn();
        const link = document.querySelector('[data-travel-list-more]');
        const event = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ctrlKey: true });
        link.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(false);
        expect(window.fetch).not.toHaveBeenCalled();
    });
});

import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';

/**
 * Behavioural tests for the admin order page's booking card copy buttons
 * (addon-travel-core/js/addons/travel_core/order-booking-card.js): the
 * supplier reference and the balance pay link go to the clipboard, the button
 * says so and then reads as before; with no clipboard, a prompt offers the
 * text instead of failing silently.
 */

let copied = [];

function card() {
    document.body.innerHTML = `
        <div class="travel-obc">
            <button type="button" class="travel-obc__copy" data-ca-travel-copy="778899" data-ca-travel-copied="Copied">Copy</button>
            <button type="button" class="travel-obc__copy" data-ca-travel-copy="https://shop.example.ro/index.php?dispatch=travel_balance.pay&amp;balance_id=2&amp;key=abc" data-ca-travel-copied="Copied"><span>Copy balance pay link</span></button>
        </div>`;
}

beforeAll(async () => {
    Object.defineProperty(window, 'isSecureContext', { value: true, writable: true, configurable: true });
    Object.defineProperty(navigator, 'clipboard', {
        value: { writeText: (text) => { copied.push(text); return Promise.resolve(); } },
        writable: true,
        configurable: true,
    });
    await import('../../addon-travel-core/js/addons/travel_core/order-booking-card.js');
});

afterEach(() => {
    copied = [];
    vi.useRealTimers();
});

describe('order booking card copy buttons', () => {
    it('copies the supplier reference and confirms on the button', async () => {
        vi.useFakeTimers();
        card();
        const button = document.querySelector('[data-ca-travel-copy="778899"]');

        button.click();
        await Promise.resolve();

        expect(copied).toEqual(['778899']);
        expect(button.textContent).toBe('Copied');
        vi.advanceTimersByTime(2000);
        expect(button.textContent).toBe('Copy');
    });

    it('copies the pay link when the click lands on the label inside the button', async () => {
        card();
        document.querySelector('[data-ca-travel-copy^="https://"] span').click();
        await Promise.resolve();

        expect(copied).toEqual(['https://shop.example.ro/index.php?dispatch=travel_balance.pay&balance_id=2&key=abc']);
    });

    it('offers the text in a prompt when nothing can copy it', async () => {
        card();
        window.isSecureContext = false;
        document.execCommand = () => false;
        const prompt = vi.spyOn(window, 'prompt').mockImplementation(() => null);

        document.querySelector('[data-ca-travel-copy="778899"]').click();
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(prompt).toHaveBeenCalledWith('', '778899');
        window.isSecureContext = true;
    });

    it('listens once, however often the script is loaded', async () => {
        card();
        await import('../../addon-travel-core/js/addons/travel_core/order-booking-card.js?again');
        document.querySelector('[data-ca-travel-copy="778899"]').click();
        await Promise.resolve();

        expect(copied).toEqual(['778899']);
    });
});

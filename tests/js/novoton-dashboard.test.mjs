import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Behavioral tests for the Novoton dashboard's scheduled-jobs helpers
 * (addon-novoton-holidays/js/addons/novoton_holidays/dashboard.js).
 *
 * The rule that must not break: the cron key is masked in the DISPLAY only.
 * Every copy — a row's crontab line, URL or CLI command, or the whole
 * "All commands" block — puts the REAL key on the clipboard, because a
 * masked command pasted into a crontab is a refused run nobody sees.
 */

const KEY = 'realKEY123';
const CLI = `# Novoton Holidays: scheduled jobs, in the order they run\n0 1 * * 0  php /var/www/html/app/addons/novoton_holidays/cron.php access_key=${KEY} mode=resort_list`;
const URL_FORM = `# URLs for a cron service: add each one at the time shown\n# 0 1 * * 0\nhttp://shop.example/index.php?dispatch=novoton_cron.run&access_key=${KEY}&mode=resort_list`;

let copied = [];

beforeAll(async () => {
    document.body.innerHTML = `
        <table><tbody><tr><td>
            <div class="novoton-menu-wrap">
                <button type="button" class="btn novoton-menu-toggle" aria-haspopup="true" aria-expanded="false"
                        aria-controls="novoton-menu-resort_list" aria-label="More actions: Resort list">⋯</button>
                <div class="novoton-menu" id="novoton-menu-resort_list" role="menu" hidden>
                    <button type="button" role="menuitem" class="novoton-menu__item novoton-copy"
                            data-copy="0 1 * * 0  php cron.php access_key=${KEY} mode=resort_list">Copy crontab line</button>
                    <button type="button" role="menuitem" class="novoton-menu__item novoton-copy"
                            data-copy="http://shop.example/?access_key=${KEY}&amp;mode=resort_list">Copy URL</button>
                    <form class="novoton-run-form" method="post" data-novoton-confirm="Reset the progress of Resort list?">
                        <button type="submit" role="menuitem" class="novoton-menu__item">Reset progress…</button>
                    </form>
                </div>
            </div>
            <div class="novoton-menu-wrap">
                <button type="button" class="btn novoton-menu-toggle" aria-haspopup="true" aria-expanded="false"
                        aria-controls="novoton-menu-cleanup">⋯</button>
                <div class="novoton-menu" id="novoton-menu-cleanup" role="menu" hidden>
                    <button type="button" role="menuitem" class="novoton-menu__item novoton-copy" data-copy="cleanup-line">Copy crontab line</button>
                </div>
            </div>
        </td></tr></tbody></table>

        <section id="novoton-crontab"
                 data-crontab-cli="${CLI.replace(/&/g, '&amp;').replace(/\n/g, '&#10;')}"
                 data-crontab-url="${URL_FORM.replace(/&/g, '&amp;').replace(/\n/g, '&#10;')}"
                 data-key="${KEY}" data-txt-copied="Copied" data-txt-copy-failed="Copy failed">
            <button type="button" data-novoton-format="cli" aria-pressed="true">CLI (php)</button>
            <button type="button" data-novoton-format="url" aria-pressed="false">URL</button>
            <button type="button" id="novoton-crontab-copy">Copy all</button>
            <pre id="novoton-crontab-text">server-rendered masked text</pre>
            <button type="button" class="novoton-copy" id="feed-copy" data-copy="http://shop.example/feed?access_key=${KEY}">Copy URL</button>
        </section>
    `;

    Object.defineProperty(window, 'isSecureContext', { value: true, configurable: true });
    Object.defineProperty(navigator, 'clipboard', {
        value: { writeText: (text) => { copied.push(text); return Promise.resolve(); } },
        configurable: true,
    });

    await import('../../addon-novoton-holidays/js/addons/novoton_holidays/dashboard.js');
});

beforeEach(() => {
    copied = [];
});

const $ = (sel) => document.querySelector(sel);
const flush = () => new Promise((r) => setTimeout(r, 0));

describe('All commands block', () => {
    it('shows the CLI crontab with the key masked', () => {
        const text = $('#novoton-crontab-text').textContent;
        expect(text).toContain('mode=resort_list');
        expect(text).toContain('access_key=••••••••');
        expect(text).not.toContain(KEY);
    });

    it('switches to the URL form, still masked, and marks the pressed button', () => {
        $('[data-novoton-format="url"]').click();
        const text = $('#novoton-crontab-text').textContent;
        expect(text).toContain('# URLs for a cron service');
        expect(text).toContain('&access_key=••••••••&mode=resort_list');
        expect(text).not.toContain(KEY);
        expect($('[data-novoton-format="url"]').getAttribute('aria-pressed')).toBe('true');
        expect($('[data-novoton-format="cli"]').getAttribute('aria-pressed')).toBe('false');
    });

    it('Copy all copies the REAL text of the chosen form', async () => {
        $('[data-novoton-format="url"]').click();
        $('#novoton-crontab-copy').click();
        await flush();
        expect(copied).toEqual([URL_FORM]);

        $('[data-novoton-format="cli"]').click();
        $('#novoton-crontab-copy').click();
        await flush();
        expect(copied[1]).toBe(CLI);
        expect(copied[1]).toContain(`access_key=${KEY}`);
    });

    it('says Copied, then puts its own label back — even after a second click in the window', async () => {
        vi.useFakeTimers();
        try {
            const btn = $('#novoton-crontab-copy');
            btn.click();
            await vi.advanceTimersByTimeAsync(0);
            expect(btn.textContent).toBe('Copied');
            btn.click();
            await vi.advanceTimersByTimeAsync(0);
            await vi.advanceTimersByTimeAsync(2000);
            expect(btn.textContent).toBe('Copy all');
        } finally {
            vi.useRealTimers();
        }
    });
});

describe('copy buttons', () => {
    it('copy the real command from data-copy', async () => {
        $('#feed-copy').click();
        await flush();
        expect(copied).toEqual([`http://shop.example/feed?access_key=${KEY}`]);
    });
});

describe('row menus', () => {
    const toggleA = () => document.querySelectorAll('.novoton-menu-toggle')[0];
    const toggleB = () => document.querySelectorAll('.novoton-menu-toggle')[1];
    const menuA = () => $('#novoton-menu-resort_list');
    const menuB = () => $('#novoton-menu-cleanup');

    it('open on click and say so', () => {
        toggleA().click();
        expect(menuA().hidden).toBe(false);
        expect(toggleA().getAttribute('aria-expanded')).toBe('true');
    });

    it('only one is ever open', () => {
        toggleB().click();
        expect(menuB().hidden).toBe(false);
        expect(menuA().hidden).toBe(true);
        expect(toggleA().getAttribute('aria-expanded')).toBe('false');
    });

    it('close on a click elsewhere and on Escape, returning focus to the toggle', () => {
        document.body.click();
        expect(menuB().hidden).toBe(true);

        toggleA().click();
        menuA().querySelector('[role="menuitem"]').focus();
        menuA().dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        expect(menuA().hidden).toBe(true);
        expect(document.activeElement).toBe(toggleA());
    });

    it('open from the keyboard onto the first item, and arrow through the items', () => {
        toggleA().dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
        const items = menuA().querySelectorAll('[role="menuitem"]');
        expect(menuA().hidden).toBe(false);
        expect(document.activeElement).toBe(items[0]);

        menuA().dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp', bubbles: true }));
        expect(document.activeElement).toBe(items[items.length - 1]);
        menuA().dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
        expect(document.activeElement).toBe(items[0]);
    });

    it('a copy item copies the real line', async () => {
        menuA().querySelector('.novoton-copy').click();
        await flush();
        expect(copied).toEqual([`0 1 * * 0  php cron.php access_key=${KEY} mode=resort_list`]);
    });
});

describe('confirmations', () => {
    it('a form with data-novoton-confirm submits only when the admin agrees', () => {
        const form = document.querySelector('form[data-novoton-confirm]');
        const ask = vi.spyOn(window, 'confirm');

        ask.mockReturnValueOnce(false);
        const refused = new Event('submit', { cancelable: true });
        form.dispatchEvent(refused);
        expect(ask).toHaveBeenCalledWith('Reset the progress of Resort list?');
        expect(refused.defaultPrevented).toBe(true);

        ask.mockReturnValueOnce(true);
        const agreed = new Event('submit', { cancelable: true });
        form.dispatchEvent(agreed);
        expect(agreed.defaultPrevented).toBe(false);

        ask.mockRestore();
    });
});

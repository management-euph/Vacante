import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Behavioral tests for the providers' scheduled-jobs cards
 * (addon-travel-core/js/addons/travel_core/cron-jobs.js, driving Travel Core's
 * components/jobs_card.tpl, job_row.tpl, run_job.tpl and commands_card.tpl;
 * Novoton's dashboard was the first to use them).
 *
 * The rule that must not break: the cron key is masked in the DISPLAY only.
 * Every copy — a row's crontab line, URL or CLI command, or the whole
 * "All commands" block — puts the REAL key on the clipboard, because a
 * masked command pasted into a crontab is a refused run nobody sees.
 *
 * And the one the move fixed: an open ⋯ menu is lifted out of the jobs card,
 * whose sideways scroll box cut off the menus of its last rows.
 */

const KEY = 'realKEY123';
const CLI = `# Novoton Holidays: scheduled jobs, in the order they run\n0 1 * * 0  php /var/www/html/app/addons/novoton_holidays/cron.php access_key=${KEY} mode=resort_list`;
const URL_FORM = `# URLs for a cron service: add each one at the time shown\n# 0 1 * * 0\nhttp://shop.example/index.php?dispatch=novoton_cron.run&access_key=${KEY}&mode=resort_list`;

let copied = [];

const SCRIPT = '../../addon-travel-core/js/addons/travel_core/cron-jobs.js';

beforeAll(async () => {
    document.body.innerHTML = `
        <section id="jobs" class="travel-cron-card travel-cron-card--flush travel-cron-jobs"
                 data-txt-copied="Copiat" data-txt-copy-failed="Copierea a eșuat">
            <table><tbody><tr><td>
                <div class="travel-cron-menu-wrap" id="wrap-a">
                    <button type="button" class="btn travel-cron-menu-toggle" aria-haspopup="true" aria-expanded="false"
                            aria-controls="travel-cron-menu-resort_list" aria-label="More actions: Resort list">⋯</button>
                    <div class="travel-cron-menu" id="travel-cron-menu-resort_list" role="menu" hidden>
                        <button type="button" role="menuitem" class="travel-cron-menu__item travel-cron-copy"
                                data-copy="0 1 * * 0  php cron.php access_key=${KEY} mode=resort_list">Copy crontab line</button>
                        <button type="button" role="menuitem" class="travel-cron-menu__item travel-cron-copy"
                                data-copy="http://shop.example/?access_key=${KEY}&amp;mode=resort_list">Copy URL</button>
                        <form class="travel-cron-run-form" id="form-reset" method="post" data-travel-cron-confirm="Reset the progress of Resort list?">
                            <button type="submit" role="menuitem" class="travel-cron-menu__item" id="btn-reset"><span>Reset progress…</span></button>
                        </form>
                    </div>
                </div>
                <div class="travel-cron-menu-wrap" id="wrap-b">
                    <button type="button" class="btn travel-cron-menu-toggle" aria-haspopup="true" aria-expanded="false"
                            aria-controls="travel-cron-menu-cleanup">⋯</button>
                    <div class="travel-cron-menu" id="travel-cron-menu-cleanup" role="menu" hidden>
                        <button type="button" role="menuitem" class="travel-cron-menu__item travel-cron-copy" data-copy="cleanup-line">Copy crontab line</button>
                    </div>
                </div>
            </td></tr></tbody></table>
        </section>

        <form class="travel-cron-run-form" id="form-run" method="post">
            <button type="submit" class="btn btn-primary" id="btn-run">Run</button>
        </form>

        <form class="travel-cron-run-form" id="form-check" method="post">
            <input type="text" name="hotel" id="check-hotel" required>
            <input type="checkbox" name="attach" value="Y" id="check-attach"
                   data-travel-cron-confirm="Attach this hotel's images to its product?" data-travel-cron-confirm-for="diagnose_images">
            <button type="submit" name="job" value="diagnose_search" id="check-search">Search API</button>
            <button type="submit" name="job" value="diagnose_images" id="check-images">Images</button>
        </form>

        <section id="commands" data-travel-cron-commands
                 data-crontab-cli="${CLI.replace(/&/g, '&amp;').replace(/\n/g, '&#10;')}"
                 data-crontab-url="${URL_FORM.replace(/&/g, '&amp;').replace(/\n/g, '&#10;')}"
                 data-key="${KEY}" data-txt-copied="Copied" data-txt-copy-failed="Copy failed">
            <button type="button" data-travel-cron-format="cli" aria-pressed="true">CLI (php)</button>
            <button type="button" data-travel-cron-format="url" aria-pressed="false">URL</button>
            <button type="button" id="copy-all" data-travel-cron-copy-all>Copy all</button>
            <pre id="crontab-text" data-travel-cron-text>server-rendered masked text</pre>
            <button type="button" class="travel-cron-copy" id="feed-copy" data-copy="http://shop.example/feed?access_key=${KEY}">Copy URL</button>
        </section>
    `;

    Object.defineProperty(window, 'isSecureContext', { value: true, configurable: true });
    Object.defineProperty(navigator, 'clipboard', {
        value: { writeText: (text) => { copied.push(text); return Promise.resolve(); } },
        configurable: true,
    });

    await import(SCRIPT);
});

beforeEach(() => {
    copied = [];
});

const $ = (sel) => document.querySelector(sel);
const flush = () => new Promise((r) => setTimeout(r, 0));
const toggleA = () => $('#wrap-a .travel-cron-menu-toggle');
const toggleB = () => $('#wrap-b .travel-cron-menu-toggle');
const menuA = () => $('#travel-cron-menu-resort_list');
const menuB = () => $('#travel-cron-menu-cleanup');
const escape = () => document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

/** Where the ⋯ button sits on screen, and how big its menu is (jsdom lays nothing out). */
function layout(toggle, menu, rect, size = { width: 220, height: 180 }) {
    toggle.getBoundingClientRect = () => ({ ...rect, x: rect.left, y: rect.top, width: rect.right - rect.left, height: rect.bottom - rect.top });
    Object.defineProperty(menu, 'offsetWidth', { value: size.width, configurable: true });
    Object.defineProperty(menu, 'offsetHeight', { value: size.height, configurable: true });
}

describe('All commands block', () => {
    it('shows the CLI crontab with the key masked', () => {
        const text = $('#crontab-text').textContent;
        expect(text).toContain('mode=resort_list');
        expect(text).toContain('access_key=••••••••');
        expect(text).not.toContain(KEY);
    });

    it('switches to the URL form, still masked, and marks the pressed button', () => {
        $('[data-travel-cron-format="url"]').click();
        const text = $('#crontab-text').textContent;
        expect(text).toContain('# URLs for a cron service');
        expect(text).toContain('&access_key=••••••••&mode=resort_list');
        expect(text).not.toContain(KEY);
        expect($('[data-travel-cron-format="url"]').getAttribute('aria-pressed')).toBe('true');
        expect($('[data-travel-cron-format="cli"]').getAttribute('aria-pressed')).toBe('false');
    });

    it('Copy all copies the REAL text of the chosen form', async () => {
        $('[data-travel-cron-format="url"]').click();
        $('#copy-all').click();
        await flush();
        expect(copied).toEqual([URL_FORM]);

        $('[data-travel-cron-format="cli"]').click();
        $('#copy-all').click();
        await flush();
        expect(copied[1]).toBe(CLI);
        expect(copied[1]).toContain(`access_key=${KEY}`);
    });

    it('says Copied, then puts its own label back — even after a second click in the window', async () => {
        vi.useFakeTimers();
        try {
            const btn = $('#copy-all');
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

    it('a copy item copies the real line, says so in the card\'s words, and the menu closes a moment later', async () => {
        vi.useFakeTimers();
        try {
            const item = menuA().querySelector('.travel-cron-copy');
            item.click();
            await vi.advanceTimersByTimeAsync(0);
            expect(copied).toEqual([`0 1 * * 0  php cron.php access_key=${KEY} mode=resort_list`]);
            // The open menu sits on <body>, outside its card: the words still come from the page's jobs card.
            expect(item.textContent).toBe('Copiat');
            expect(menuA().hidden).toBe(false);

            await vi.advanceTimersByTimeAsync(1000);
            expect(menuA().hidden).toBe(true);
            await vi.advanceTimersByTimeAsync(1000);
            expect(item.textContent).toBe('Copy crontab line');
        } finally {
            vi.useRealTimers();
        }
    });
});

describe('an open menu is never cut off', () => {
    afterEach(() => {
        escape();
    });

    it('is lifted onto the page, beside its button, and put back in its row when it closes', () => {
        layout(toggleA(), menuA(), { top: 100, bottom: 130, left: 900, right: 938 });
        toggleA().click();

        expect(menuA().parentNode).toBe(document.body);
        expect(menuA().classList.contains('travel-cron-menu--floating')).toBe(true);
        expect(menuA().style.top).toBe('134px');   // 4px under the button
        expect(menuA().style.left).toBe('718px');  // right edges line up: 938 - 220
        expect(menuA().getAttribute('data-placement')).toBe('down');

        escape();
        expect(menuA().parentNode).toBe($('#wrap-a'));
        expect(menuA().previousElementSibling).toBe(toggleA());
        expect(menuA().classList.contains('travel-cron-menu--floating')).toBe(false);
        expect(menuA().style.top).toBe('');
        expect(menuA().style.left).toBe('');
        expect(menuA().hasAttribute('data-placement')).toBe(false);
    });

    it('opens upward when it does not fit below the button', () => {
        layout(toggleA(), menuA(), { top: 700, bottom: 730, left: 900, right: 938 });
        toggleA().click();

        expect(menuA().getAttribute('data-placement')).toBe('up');
        expect(menuA().style.top).toBe('516px');   // 700 - 4 - 180
    });

    it('stays inside the window', () => {
        layout(toggleA(), menuA(), { top: 100, bottom: 130, left: 62, right: 100 });
        toggleA().click();
        expect(menuA().style.left).toBe('8px');

        escape();
        layout(toggleA(), menuA(), { top: 100, bottom: 130, left: 1100, right: 1138 });
        toggleA().click();
        expect(menuA().style.left).toBe(`${window.innerWidth - 8 - 220}px`);
    });

    it('closes when the page scrolls or the window changes size, not when the menu itself scrolls', () => {
        toggleA().click();
        menuA().dispatchEvent(new Event('scroll'));
        expect(menuA().hidden).toBe(false);

        window.dispatchEvent(new Event('scroll'));
        expect(menuA().hidden).toBe(true);
        expect(menuA().parentNode).toBe($('#wrap-a'));

        toggleA().click();
        window.dispatchEvent(new Event('resize'));
        expect(menuA().hidden).toBe(true);
    });

    it('Tab leaves it for the next control in its row', () => {
        toggleA().dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
        menuA().dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true }));
        expect(menuA().hidden).toBe(true);
        expect(document.activeElement).toBe(toggleA());
    });
});

describe('job buttons open the job in a new window', () => {
    let opened;
    let submitted;
    let pageHandler;
    let openSpy;
    let submitSpy;

    beforeEach(() => {
        opened = [];
        submitted = [];
        openSpy = vi.spyOn(window, 'open').mockImplementation((url, name) => { opened.push([url, name]); return {}; });
        submitSpy = vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(function () { submitted.push(this); });
        // Stands in for CS-Cart's admin handlers, which listen on the document.
        pageHandler = vi.fn();
        document.addEventListener('click', pageHandler);
        document.addEventListener('submit', pageHandler);
    });

    afterEach(() => {
        openSpy.mockRestore();
        submitSpy.mockRestore();
        document.removeEventListener('click', pageHandler);
        document.removeEventListener('submit', pageHandler);
    });

    it('Run posts into a freshly opened named window, and the page never sees the click', () => {
        $('#btn-run').click();

        expect(opened).toHaveLength(1);
        expect(opened[0][0]).toBe('about:blank');
        expect(opened[0][1]).toMatch(/^travel_cron_job_/);
        expect($('#form-run').getAttribute('target')).toBe(opened[0][1]);
        expect(submitted).toEqual([$('#form-run')]);
        expect(pageHandler).not.toHaveBeenCalled();
    });

    it('each run gets its own window, so two jobs never share one', () => {
        $('#btn-run').click();
        $('#btn-run').click();
        expect(opened[0][1]).not.toBe(opened[1][1]);
    });

    it('asks first where the form says so, and does nothing on Cancel', () => {
        const ask = vi.spyOn(window, 'confirm').mockReturnValueOnce(false);
        $('#btn-reset span').click();
        expect(ask).toHaveBeenCalledWith('Reset the progress of Resort list?');
        expect(opened).toEqual([]);
        expect(submitted).toEqual([]);

        ask.mockReturnValueOnce(true);
        $('#btn-reset').click();
        expect(submitted).toEqual([$('#form-reset')]);
        expect($('#form-reset').getAttribute('target')).toMatch(/^travel_cron_job_/);
        ask.mockRestore();
    });

    it('falls back to target _blank when the window could not be opened', () => {
        openSpy.mockImplementation(() => null);
        $('#btn-run').click();
        expect($('#form-run').getAttribute('target')).toBe('_blank');
        expect(submitted).toEqual([$('#form-run')]);
    });

    it('a submit by other means (Enter) opens a new window too', () => {
        const ev = new Event('submit', { cancelable: true, bubbles: true });
        $('#form-run').dispatchEvent(ev);
        expect(ev.defaultPrevented).toBe(true);
        expect(opened).toHaveLength(1);
        expect(submitted).toEqual([$('#form-run')]);
        expect(pageHandler).not.toHaveBeenCalled();
    });

    it('a form with several buttons sends the one pressed, in one hidden field', () => {
        $('#check-hotel').value = '89812';
        $('#check-images').click();
        const carried = () => $('#form-check').querySelectorAll('input[data-travel-cron-submitter]');
        expect(submitted).toEqual([$('#form-check')]);
        expect(carried()).toHaveLength(1);
        expect(carried()[0].name).toBe('job');
        expect(carried()[0].value).toBe('diagnose_images');

        $('#check-search').click();
        expect(carried()).toHaveLength(1);
        expect(carried()[0].value).toBe('diagnose_search');
    });

    it('a required field left empty stops the run before any window opens', () => {
        $('#check-hotel').value = '';
        $('#check-search').click();
        expect(opened).toEqual([]);
        expect(submitted).toEqual([]);
        expect(pageHandler).not.toHaveBeenCalled();
    });

    it('a ticked box that makes the run write asks first, and only for its own button', () => {
        const ask = vi.spyOn(window, 'confirm');
        $('#check-hotel').value = '89812';
        $('#check-attach').checked = true;

        $('#check-search').click();
        expect(ask).not.toHaveBeenCalled();
        expect(submitted).toEqual([$('#form-check')]);

        ask.mockReturnValueOnce(false);
        $('#check-images').click();
        expect(ask).toHaveBeenCalledWith("Attach this hotel's images to its product?");
        expect(submitted).toHaveLength(1);

        ask.mockReturnValueOnce(true);
        $('#check-images').click();
        expect(submitted).toHaveLength(2);
        $('#check-attach').checked = false;
        ask.mockRestore();
    });

    it('closes the ⋯ menu it was launched from, putting it back in its row', () => {
        toggleA().click();
        expect(menuA().hidden).toBe(false);
        expect(menuA().parentNode).toBe(document.body);
        vi.spyOn(window, 'confirm').mockReturnValueOnce(true);
        $('#btn-reset').click();
        expect(submitted).toEqual([$('#form-reset')]);
        expect(menuA().hidden).toBe(true);
        expect(menuA().parentNode).toBe($('#wrap-a'));
    });
});

describe('after admin AJAX navigation runs the script again', () => {
    async function loadAgain() {
        vi.resetModules();
        await import(SCRIPT);
    }

    it('a click elsewhere still closes a menu the new page opened', async () => {
        $('#jobs td').insertAdjacentHTML('beforeend', `
            <div class="travel-cron-menu-wrap" id="wrap-c">
                <button type="button" class="btn travel-cron-menu-toggle" aria-haspopup="true" aria-expanded="false">⋯</button>
                <div class="travel-cron-menu" id="menu-c" role="menu" hidden>
                    <button type="button" role="menuitem" class="travel-cron-menu__item travel-cron-copy" data-copy="c-line">Copy crontab line</button>
                </div>
            </div>`);
        await loadAgain();

        $('#wrap-c .travel-cron-menu-toggle').click();
        expect($('#menu-c').hidden).toBe(false);
        toggleA().click();  // a row of the first load: still one menu at a time
        expect($('#menu-c').hidden).toBe(true);
        expect(menuA().hidden).toBe(false);

        document.body.click();
        expect(menuA().hidden).toBe(true);
    });

    it('a menu left open by the page that was replaced leaves <body> with its row', async () => {
        $('#wrap-c .travel-cron-menu-toggle').click();
        expect($('#menu-c').parentNode).toBe(document.body);

        $('#wrap-c').remove();  // the admin swapped the page's content
        await loadAgain();

        expect(document.getElementById('menu-c')).toBeNull();
        expect(window.__travelCronJobs.open).toBeNull();
    });
});

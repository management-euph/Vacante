import { beforeAll, beforeEach, describe, expect, it } from 'vitest';

/**
 * Behavioural tests for Travel Core -> Tools & Cron
 * (addon-travel-core/js/addons/travel_core/tools-cron.js).
 *
 * The rule that must not break: the key is masked on SCREEN only. Every copy
 * — the key itself or a command — puts the real value on the clipboard,
 * because a masked command pasted into a crontab fails with 403 at 13:05 and
 * nobody is awake to see it.
 */

const KEY = '3f7c1a9e5b2d8f406e1c9a7b5d3f2e18';
const MASK = '••••••••••••';
const URL_CMD = `https://shop.example.ro/index.php?dispatch=travel_cron.run&access_key=${KEY}&cron_mode=exchange_rates`;
const CLI_CMD = `php /var/www/app/addons/travel_core/cron.php access_key=${KEY} mode=exchange_rates`;

let copied = [];

beforeAll(async () => {
    document.body.innerHTML = `
        <div id="travel-cron-tools" data-key="${KEY}" data-mask="${MASK}"
             data-txt-copied="Copied" data-txt-copy-failed="Copy failed"
             data-txt-reveal="Reveal" data-txt-hide="Hide">
            <input id="travel-cron-key" readonly value="${MASK}">
            <button type="button" id="travel-cron-key-reveal">Reveal</button>
            <button type="button" id="travel-cron-key-copy">Copy key</button>

            <button type="button" data-cron-format="url" aria-pressed="true">URL</button>
            <button type="button" data-cron-format="cli" aria-pressed="false">CLI (php)</button>

            <code id="travel-cron-cmd-exchange_rates" class="travel-cron-cmd"
                  data-cmd-url='${URL_CMD}' data-cmd-cli='${CLI_CMD}'></code>
            <button type="button" class="travel-cron-copy" data-copy-target="travel-cron-cmd-exchange_rates">Copy</button>
        </div>`;

    Object.defineProperty(window, 'isSecureContext', { value: true, writable: true });
    Object.defineProperty(navigator, 'clipboard', {
        value: {
            writeText: (text) => {
                copied.push(text);
                return Promise.resolve();
            },
        },
        writable: true,
        configurable: true,
    });
    document.execCommand = () => true;

    await import('../../addon-travel-core/js/addons/travel_core/tools-cron.js');
});

beforeEach(() => {
    copied = [];
});

const $ = (sel) => document.querySelector(sel);
const cmd = () => $('#travel-cron-cmd-exchange_rates').textContent;
const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('initial paint', () => {
    it('masks the key in the field and in the command', () => {
        expect($('#travel-cron-key').value).toBe(MASK);
        expect(cmd()).toContain('https://shop.example.ro/index.php?dispatch=travel_cron.run');
        expect(cmd()).not.toContain(KEY);
        expect(cmd()).toContain(`access_key=${MASK}`);
    });
});

describe('copying always copies the real value', () => {
    it('copies the real key while it is masked', async () => {
        $('#travel-cron-key-copy').click();
        await flush();
        expect(copied).toEqual([KEY]);
    });

    it('copies the real command while it is masked', async () => {
        $('.travel-cron-copy').click();
        await flush();
        expect(copied).toEqual([URL_CMD]);
    });

    it('says so, then restores the label', async () => {
        const btn = $('.travel-cron-copy');
        btn.click();
        await flush();
        expect(btn.textContent).toBe('Copied');
    });
});

describe('format switch', () => {
    it('switches the command to the CLI form and copies that', async () => {
        $('[data-cron-format="cli"]').click();
        expect(cmd()).toContain('php /var/www/app/addons/travel_core/cron.php');
        expect(cmd()).not.toContain(KEY);
        expect($('[data-cron-format="cli"]').getAttribute('aria-pressed')).toBe('true');
        expect($('[data-cron-format="url"]').getAttribute('aria-pressed')).toBe('false');

        $('.travel-cron-copy').click();
        await flush();
        expect(copied).toEqual([CLI_CMD]);

        $('[data-cron-format="url"]').click();
        expect(cmd()).toContain('https://shop.example.ro/index.php?dispatch=travel_cron.run');
    });
});

describe('reveal', () => {
    it('shows the real key everywhere, then hides it again', () => {
        $('#travel-cron-key-reveal').click();
        expect($('#travel-cron-key').value).toBe(KEY);
        expect(cmd()).toContain(KEY);
        expect($('#travel-cron-key-reveal').textContent).toBe('Hide');
        expect($('#travel-cron-key-reveal').getAttribute('aria-pressed')).toBe('true');

        $('#travel-cron-key-reveal').click();
        expect($('#travel-cron-key').value).toBe(MASK);
        expect(cmd()).not.toContain(KEY);
    });
});

describe('admin AJAX navigation re-runs the script', () => {
    it('does not bind twice to the same page', async () => {
        await import('../../addon-travel-core/js/addons/travel_core/tools-cron.js?again');
        $('#travel-cron-key-copy').click();
        await flush();
        expect(copied).toEqual([KEY]);
    });
});

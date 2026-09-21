import { beforeAll, beforeEach, describe, expect, it } from 'vitest';

/**
 * Behavioral tests for the eurosite dashboard's crontab block
 * (eurosite_addon/js/addons/eurosite/dashboard.js).
 *
 * The block exists because nobody schedules nine URLs one at a time — they
 * paste a crontab. Two decisions shape it (nightly full vs per-catalog, curl
 * vs php) and the access key is masked on screen. The rule that must not break:
 * masking is a DISPLAY concern. A crontab pasted with bullets where the key
 * should be is a 403 at 01:00 that nobody is awake to see.
 */

const KEY = 'abc123deadbeef';

const CRONTABS = {
    full_url: `# Eurosite Touring — nightly full pipeline\n0 1 * * *  curl -fsS "https://shop.example.ro/index.php?dispatch=eurosite_cron.run&access_key=${KEY}&cron_mode=full" >/dev/null`,
    full_cli: `# Eurosite Touring — nightly full pipeline\n0 1 * * *  php app/addons/eurosite/cron.php access_key=${KEY} mode=full`,
    per_url: `# Eurosite Touring — per-catalog schedule\n0 1 * * 0  curl -fsS "https://shop.example.ro/index.php?dispatch=eurosite_cron.run&access_key=${KEY}&cron_mode=countries" >/dev/null`,
    per_cli: `# Eurosite Touring — per-catalog schedule\n0 1 * * 0  php app/addons/eurosite/cron.php access_key=${KEY} mode=countries`,
};

let copied = [];

beforeAll(async () => {
    document.body.innerHTML = `
        <button type="button" class="eurosite-copy"
                data-copy="0 3 * * *  curl -fsS &quot;https://shop.example.ro/x&amp;access_key=${KEY}&quot;"
                data-txt-copied="Copied"
                data-txt-copy-failed="Copy failed">Copy line</button>

        <div id="eurosite-crontab"
             data-crontabs='${JSON.stringify(CRONTABS)}'
             data-key="${KEY}"
             data-txt-copied="Copied"
             data-txt-copy-failed="Copy failed"
             data-txt-copy-all="Copy all"
             data-txt-reveal="Reveal access key"
             data-txt-hide="Hide access key"
             data-txt-hidden-note="Access key hidden — copying still copies the real value"
             data-txt-shown-note="Access key shown">
            <input type="radio" name="eurosite_cron_plan" value="full" checked />
            <input type="radio" name="eurosite_cron_plan" value="per" />
            <input type="radio" name="eurosite_cron_format" value="url" checked />
            <input type="radio" name="eurosite_cron_format" value="cli" />
            <pre id="eurosite-crontab-text"></pre>
            <button type="button" id="eurosite-crontab-copy">Copy all</button>
            <button type="button" id="eurosite-crontab-reveal">Reveal access key</button>
            <span id="eurosite-crontab-note"></span>
        </div>`;

    // jsdom has no clipboard; record what the page tried to copy.
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
    // jsdom 29 dropped document.execCommand entirely; the script's fallback
    // path needs it to exist even though this suite runs the clipboard path.
    document.execCommand = () => true;

    await import('../../eurosite_addon/js/addons/eurosite/dashboard.js');
});

beforeEach(() => {
    copied = [];
});

const $ = (sel) => document.querySelector(sel);
const text = () => $('#eurosite-crontab-text').textContent;
const pick = (name, value) => {
    const input = $(`input[name="${name}"][value="${value}"]`);
    input.checked = true;
    input.dispatchEvent(new Event('change'));
};
const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('initial paint', () => {
    it('shows the nightly curl plan with the key masked', () => {
        expect(text()).toContain('nightly full pipeline');
        expect(text()).toContain('curl -fsS');
        expect(text()).not.toContain(KEY);
        expect(text()).toContain('access_key=••••••••••••');
        expect($('#eurosite-crontab-note').textContent).toContain('copying still copies the real value');
    });
});

describe('plan and format toggles', () => {
    it('switches to the per-catalog plan', () => {
        pick('eurosite_cron_plan', 'per');
        expect(text()).toContain('per-catalog schedule');
        expect(text()).toContain('cron_mode=countries');
    });

    it('switches to the CLI format, keeping the chosen plan', () => {
        pick('eurosite_cron_format', 'cli');
        expect(text()).toContain('per-catalog schedule');
        expect(text()).toContain('php app/addons/eurosite/cron.php');
        expect(text()).not.toContain('curl');
    });

    it('goes back to the nightly CLI variant', () => {
        pick('eurosite_cron_plan', 'full');
        expect(text()).toContain('nightly full pipeline');
        expect(text()).toContain('php app/addons/eurosite/cron.php');
    });

    it('still masks the key in every variant', () => {
        ['full', 'per'].forEach((plan) => {
            ['url', 'cli'].forEach((format) => {
                pick('eurosite_cron_plan', plan);
                pick('eurosite_cron_format', format);
                expect(text(), `${plan}/${format}`).not.toContain(KEY);
            });
        });
    });
});

describe('reveal', () => {
    it('shows the real key and puts the label back when hidden again', () => {
        pick('eurosite_cron_plan', 'full');
        pick('eurosite_cron_format', 'url');

        $('#eurosite-crontab-reveal').click();
        expect(text()).toContain(KEY);
        expect($('#eurosite-crontab-reveal').textContent).toBe('Hide access key');
        expect($('#eurosite-crontab-note').textContent).toBe('Access key shown');

        $('#eurosite-crontab-reveal').click();
        expect(text()).not.toContain(KEY);
        expect($('#eurosite-crontab-reveal').textContent).toBe('Reveal access key');
    });
});

describe('copy', () => {
    it('copies the REAL key even while the display is masked', async () => {
        pick('eurosite_cron_plan', 'full');
        pick('eurosite_cron_format', 'url');
        expect(text()).not.toContain(KEY);

        $('#eurosite-crontab-copy').click();
        await flush();

        expect(copied).toHaveLength(1);
        expect(copied[0]).toContain(KEY);
        expect(copied[0]).not.toContain('•');
        expect(copied[0]).toBe(CRONTABS.full_url);
    });

    it('copies whichever variant is showing', async () => {
        pick('eurosite_cron_plan', 'per');
        pick('eurosite_cron_format', 'cli');

        $('#eurosite-crontab-copy').click();
        await flush();

        expect(copied[0]).toBe(CRONTABS.per_cli);
    });

    it('confirms on the button and restores its label', async () => {
        const btn = $('#eurosite-crontab-copy');
        btn.click();
        await flush();

        expect(btn.textContent).toContain('Copied');
        await new Promise((resolve) => setTimeout(resolve, 1700));
        expect(btn.textContent).toContain('Copy all');
    });

    it('survives a second click inside the feedback window', async () => {
        // REGRESSION: reading the label per click captured "Copied" as the
        // text to restore, so an impatient double-click left the button
        // saying Copied for the rest of the session.
        const btn = $('#eurosite-crontab-copy');
        btn.click();
        await flush();
        btn.click();
        await flush();

        await new Promise((resolve) => setTimeout(resolve, 1700));
        expect(btn.textContent).toContain('Copy all');
        expect(btn.textContent).not.toContain('Copied');
    });

    it('a per-row copy button copies its own payload', async () => {
        $('.eurosite-copy').click();
        await flush();

        expect(copied).toHaveLength(1);
        expect(copied[0]).toContain('0 3 * * *');
        expect(copied[0]).toContain(KEY);
    });
});

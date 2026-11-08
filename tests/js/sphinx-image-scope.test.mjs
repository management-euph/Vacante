import { beforeAll, beforeEach, describe, expect, it } from 'vitest';

/*
 * Queue images for a scope (addon-sphinx-holidays/js/addons/sphinx_holidays/
 * image-scope.js): Copy URL and Copy CLI command copy Queue images' command
 * with the scope's parameters, by the rules CronPlan::runParams() applies to
 * the same fields for Run (CronPlanTest::scopes pins the PHP side).
 */

const KEY = 'k3y';
const URL = `http://shop.example/index.php?dispatch=sphinx_cron.run&access_key=${KEY}&cron_mode=sync_images`;
const CLI = `php /var/www/html/app/addons/sphinx_holidays/cron.php access_key=${KEY} mode=sync_images`;

let copied = [];

const $ = (sel) => document.querySelector(sel);

function set(name, value) {
    const field = $('#scope-form').elements[name];
    if (field.type === 'checkbox') {
        field.checked = value;
    } else {
        field.value = value;
    }
    field.dispatchEvent(new Event('change', { bubbles: true }));
}

beforeAll(async () => {
    document.body.innerHTML = `
        <section id="sphinx-image-scope" data-txt-copied="Copiat" data-txt-copy-failed="Copierea a eșuat">
            <form id="scope-form" class="travel-cron-run-form travel-cron-form" data-sphinx-scope>
                <input type="hidden" name="job" value="sync_images" />
                <select name="scope">
                    <option value="all">Every country you sell</option>
                    <option value="strict">Only the destinations you picked</option>
                    <option value="country">One country</option>
                    <option value="destination">One destination</option>
                    <option value="region">One region</option>
                </select>
                <select name="country"><option value="TR">Turkey</option><option value="GR">Greece</option></select>
                <input type="text" name="scope_id" />
                <input type="text" name="limit" />
                <input type="checkbox" name="force" value="Y" />
                <button type="submit" id="run">Run</button>
                <button type="button" id="copy-url" class="btn travel-cron-copy" data-copy="${URL}" data-sphinx-scope-base="${URL}" data-sphinx-scope-format="url">Copy URL</button>
                <button type="button" id="copy-cli" class="btn travel-cron-copy" data-copy="${CLI}" data-sphinx-scope-base="${CLI}" data-sphinx-scope-format="cli">Copy CLI command</button>
            </form>
        </section>
    `;

    Object.defineProperty(window, 'isSecureContext', { value: true, configurable: true });
    Object.defineProperty(navigator, 'clipboard', {
        value: { writeText: (text) => { copied.push(text); return Promise.resolve(); } },
        configurable: true,
    });

    await import('../../addon-sphinx-holidays/js/addons/sphinx_holidays/image-scope.js');
    await import('../../addon-travel-core/js/addons/travel_core/cron-jobs.js');
});

beforeEach(() => {
    copied = [];
    set('scope', 'all');
    set('country', 'TR');
    set('scope_id', '');
    set('limit', '');
    set('force', false);
});

const url = () => $('#copy-url').getAttribute('data-copy');
const cli = () => $('#copy-cli').getAttribute('data-copy');

describe('the copied commands carry the scope', () => {
    it('every country sold: Queue images\' own command', () => {
        expect(url()).toBe(URL);
        expect(cli()).toBe(CLI);
    });

    it('only the picked destinations', () => {
        set('scope', 'strict');
        expect(url()).toBe(`${URL}&whitelist=strict`);
        expect(cli()).toBe(`${CLI} whitelist=strict`);
    });

    it('one country, by its code', () => {
        set('scope', 'country');
        set('country', 'GR');
        expect(url()).toBe(`${URL}&country=GR`);
        expect(cli()).toBe(`${CLI} country=GR`);
    });

    it('one destination or one region, by a whole number above zero', () => {
        set('scope', 'destination');
        set('scope_id', '1234');
        expect(url()).toBe(`${URL}&destination_id=1234`);
        set('scope', 'region');
        set('scope_id', ' 5678 ');
        expect(cli()).toBe(`${CLI} region_id=5678`);
    });

    it('an ID that is not a whole number above zero is left out', () => {
        set('scope', 'destination');
        for (const bad of ['0', '-5', '12 OR 1=1', '1e3']) {
            set('scope_id', bad);
            expect(url()).toBe(URL);
        }
    });

    it('force and a limit, after the scope, in Run\'s order', () => {
        set('scope', 'strict');
        set('force', true);
        set('limit', '50');
        expect(url()).toBe(`${URL}&whitelist=strict&force=Y&limit=50`);
        expect(cli()).toBe(`${CLI} whitelist=strict force=Y limit=50`);
    });

    it('a scope ID typed for the country scope is ignored', () => {
        set('scope', 'country');
        set('scope_id', '1234');
        expect(url()).toBe(`${URL}&country=TR`);
    });
});

describe('copying', () => {
    it('puts the command for the scope on the clipboard, and Run is untouched', async () => {
        set('scope', 'region');
        set('scope_id', '77');
        $('#copy-url').click();
        await Promise.resolve();
        expect(copied).toEqual([`${URL}&region_id=77`]);
        expect($('#run').getAttribute('data-copy')).toBeNull();
    });

    it('updates as the admin types, not only on change', () => {
        set('scope', 'destination');
        const id = $('#scope-form').elements.scope_id;
        id.value = '42';
        id.dispatchEvent(new Event('input', { bubbles: true }));
        expect(url()).toBe(`${URL}&destination_id=42`);
    });
});

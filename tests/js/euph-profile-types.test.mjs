import { beforeAll, beforeEach, describe, expect, it } from 'vitest';

/**
 * Behavioural tests for the profile type switch
 * (Addon-euph-profile_types/js/addons/euph_profile_types/func.js).
 *
 * The rules that must not break: when the customer switches the type, what
 * they typed comes back into the form of the new type (by field name, the
 * type choice itself and technical fields excepted); passwords never go to
 * sessionStorage; values kept over a reload are used once, on the same page,
 * and not when stale; the address of the redrawn page carries the new type.
 */

let readyCallbacks = [];

function fakeJquery() {
    const $ = (arg) => {
        if (typeof arg === 'function') {
            readyCallbacks.push(arg);
            return undefined;
        }
        return { on: () => undefined };
    };
    $.ceEvent = () => undefined;
    return $;
}

function form(html) {
    const element = document.createElement('form');
    element.setAttribute('name', 'profiles_register_form');
    element.innerHTML = html;
    document.body.appendChild(element);
    return element;
}

const api = () => window.EuphProfileTypes;

beforeAll(async () => {
    window.Tygh = { $: fakeJquery(), tr: () => undefined };
    await import('../../Addon-euph-profile_types/js/addons/euph_profile_types/func.js');
});

beforeEach(() => {
    document.body.innerHTML = '';
    window.sessionStorage.clear();
});

describe('snapshot', () => {
    it('keeps what was typed and chosen, not the type choice or technical fields', () => {
        const f = form(`
            <input name="user_data[firstname]" value="Ana">
            <input type="radio" name="user_data[euph_profile_type]" value="B" checked>
            <input type="hidden" name="security_hash" value="x">
            <input type="hidden" name="user_data[profile_id]" value="3">
            <input type="checkbox" name="user_data[fields][7]" value="Y" checked>
            <select name="user_data[fields][8]"><option value="1">1</option><option value="2" selected>2</option></select>
            <textarea name="user_data[fields][9]">Notes</textarea>
            <input name="dispatch[profiles.update]" value="x">
            <input type="password" name="user_data[password1]" value="secret">
            <input name="user_data[disabled]" value="no" disabled>
        `);

        expect(api().snapshot(f, false)).toEqual([
            { name: 'user_data[firstname]', kind: 'value', value: 'Ana' },
            { name: 'user_data[fields][7]', kind: 'checkbox', value: 'Y', checked: true },
            { name: 'user_data[fields][8]', kind: 'value', value: '2' },
            { name: 'user_data[fields][9]', kind: 'value', value: 'Notes' },
        ]);
        expect(api().snapshot(f, true).map((item) => item.name)).toContain('user_data[password1]');
    });
});

describe('restore', () => {
    it('puts values back by name and skips fields the new type does not have', () => {
        const before = form(`
            <input name="user_data[firstname]" value="Ana">
            <input name="user_data[fields][31]" value="RO123">
            <input type="radio" name="user_data[fields][40]" value="a">
            <input type="radio" name="user_data[fields][40]" value="b" checked>
        `);
        const values = api().snapshot(before, true);
        before.remove();

        const after = form(`
            <input name="user_data[firstname]" value="">
            <input type="radio" name="user_data[fields][40]" value="a" checked>
            <input type="radio" name="user_data[fields][40]" value="b">
            <input type="radio" name="user_data[euph_profile_type]" value="P" checked>
        `);

        expect(api().restore(after, values)).toBe(3);
        expect(after.querySelector('[name="user_data[firstname]"]').value).toBe('Ana');
        expect(after.querySelector('[name="user_data[fields][40]"][value="b"]').checked).toBe(true);
        expect(after.querySelector('[name="user_data[fields][40]"][value="a"]').checked).toBe(false);
        expect(after.querySelector('[name="user_data[euph_profile_type]"]').checked).toBe(true);
    });

    it('never overwrites the type choice, even when asked to', () => {
        const f = form('<select name="user_data[euph_profile_type]"><option value="B">B</option><option value="P" selected>P</option></select>');

        expect(api().restore(f, [{ name: 'user_data[euph_profile_type]', kind: 'value', value: 'B' }])).toBe(0);
        expect(f.querySelector('select').value).toBe('P');
    });
});

describe('the address of the new type', () => {
    it('sets or replaces the type and keeps the rest', () => {
        expect(api().urlWithType('https://shop.example/index.php?dispatch=profiles.add&euph_profile_type_locked=1#top', 'B'))
            .toBe('https://shop.example/index.php?dispatch=profiles.add&euph_profile_type_locked=1&euph_profile_type=B');
        expect(api().urlWithType('https://shop.example/register/?euph_profile_type=P', 'B'))
            .toBe('https://shop.example/register/?euph_profile_type=B');
    });
});

describe('values kept over a reload', () => {
    it('are used once, on the same page', () => {
        const f = form('<input name="user_data[firstname]" value="Ana"><input type="password" name="user_data[password1]" value="secret">');

        api().storeForReload(f, api().snapshot(f, false));
        const stored = api().takeStored();

        expect(stored.form).toBe('profiles_register_form');
        expect(stored.values).toEqual([{ name: 'user_data[firstname]', kind: 'value', value: 'Ana' }]);
        expect(JSON.stringify(stored)).not.toContain('secret');
        expect(api().takeStored()).toBeNull();
    });

    it('are dropped when stale or from another page', () => {
        const f = form('<input name="user_data[firstname]" value="Ana">');

        api().storeForReload(f, api().snapshot(f, false));
        expect(api().takeStored(Date.now() + 6 * 60 * 1000)).toBeNull();

        window.sessionStorage.setItem('euph_profile_types.restore', JSON.stringify({ form: 'x', path: '/other', values: [], at: Date.now() }));
        expect(api().takeStored()).toBeNull();

        window.sessionStorage.setItem('euph_profile_types.restore', '{not json');
        expect(api().takeStored()).toBeNull();
    });
});

describe('names', () => {
    it('tells the type choice and the checkbox flags apart', () => {
        expect(api().isChoiceName('user_data[euph_profile_type]')).toBe(true);
        expect(api().isChoiceName('company_data[euph_profile_type]')).toBe(true);
        expect(api().isChoiceName('user_data[euph_profile_type_changes]')).toBe(false);
        expect(api().flagOf('euph_field_rules[3][checkout_required]')).toBe('checkout_required');
        expect(api().flagOf('nothing')).toBe('');
    });
});

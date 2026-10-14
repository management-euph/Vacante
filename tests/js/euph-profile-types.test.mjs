import { afterEach, beforeAll, beforeEach, describe, expect, it } from 'vitest';

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

describe('pills', () => {
    const choice = (options) => form(`
        <div class="ty-control-group">
            <label class="ty-control-group__title cm-required" for="elm_euph_profile_type">Profile type</label>
            <div id="elm_euph_profile_type">${options}</div>
        </div>
        <input name="user_data[firstname]" value="Ana">
    `);
    const pills = (f) => [...f.querySelectorAll('.euph-pt-pills__input')];
    const texts = (f) => [...f.querySelectorAll('.euph-pt-pills__text')].map((text) => text.textContent);

    beforeEach(() => {
        window.Tygh.euph_profile_types = { pills: true };
    });

    afterEach(() => {
        delete window.Tygh.euph_profile_types;
    });

    it('draw a pill per option, keep the selected one and hide the theme options, not the title', () => {
        const f = choice(`
            <input type="radio" id="t_U" name="user_data[euph_profile_type]" value="U"><label for="t_U">Private person</label>
            <input type="radio" id="t_B" name="user_data[euph_profile_type]" value="B" checked><label for="t_B">Company</label>
            <input type="radio" id="t_D" name="user_data[euph_profile_type]" value="D"><label for="t_D">Dealer</label>
        `);

        expect(api().applyPills(f)).toBe(1);
        expect(pills(f).map((pill) => pill.value)).toEqual(['U', 'B', 'D']);
        expect(texts(f)).toEqual(['Private person', 'Company', 'Dealer']);
        expect(pills(f).map((pill) => pill.checked)).toEqual([false, true, false]);
        expect(f.querySelector('.euph-pt-pills').getAttribute('role')).toBe('radiogroup');
        expect(f.querySelector('.euph-pt-pills').nextElementSibling.id).toBe('t_U');
        expect(f.querySelectorAll('.euph-pt-pills-source')).toHaveLength(6);
        expect(f.querySelector('.ty-control-group__title').classList.contains('euph-pt-pills-source')).toBe(false);
        // Drawn once; the pills belong to no form and are never sent.
        expect(api().applyPills(f)).toBe(0);
        expect([...f.elements].filter((el) => el.classList.contains('euph-pt-pills__input'))).toHaveLength(0);
        expect(api().snapshot(f, false)).toEqual([{ name: 'user_data[firstname]', kind: 'value', value: 'Ana' }]);
    });

    it('read the option texts however the theme draws them', () => {
        const wrapped = choice(`
            <label><input type="radio" name="user_data[euph_profile_type]" value="U" checked> Private person</label>
            <label><input type="radio" name="user_data[euph_profile_type]" value="B"> Company</label>
        `);

        expect(api().applyPills(wrapped)).toBe(1);
        expect(texts(wrapped)).toEqual(['Private person', 'Company']);
        expect(wrapped.querySelectorAll('label.euph-pt-pills-source')).toHaveLength(2);

        document.body.innerHTML = '';
        const bare = choice(`
            <input type="radio" name="user_data[euph_profile_type]" value="U"><span class="radio">Private person</span><br>
            <input type="radio" name="user_data[euph_profile_type]" value="B" checked><span class="radio">Company</span>
        `);

        expect(api().applyPills(bare)).toBe(1);
        expect(texts(bare)).toEqual(['Private person', 'Company']);
        // Each radio button and its text are wrapped and hidden; they stay in the form.
        expect(bare.querySelectorAll('span.euph-pt-pills-source')).toHaveLength(2);
        expect(bare.querySelectorAll('span.euph-pt-pills-source input[type="radio"]')).toHaveLength(2);
        expect(bare.elements['user_data[euph_profile_type]'].value).toBe('B');
    });

    it('a picked pill ticks its radio button, whose change redraws the form', () => {
        const f = choice(`
            <input type="radio" id="t_U" name="user_data[euph_profile_type]" value="U" checked><label for="t_U">Private person</label>
            <input type="radio" id="t_B" name="user_data[euph_profile_type]" value="B"><label for="t_B">Company</label>
        `);
        const changed = [];

        f.addEventListener('change', (event) => changed.push(event.target.value));
        api().applyPills(f);
        const [, company] = pills(f);

        company.checked = true;
        expect(api().pickPill(company)).toBe(true);
        expect(f.querySelector('#t_B').checked).toBe(true);
        expect(changed).toEqual(['B']);
        // Picking the type already chosen changes nothing.
        expect(api().pickPill(company)).toBe(false);
        expect(changed).toEqual(['B']);
    });

    it('follow their radio buttons', () => {
        const f = choice(`
            <input type="radio" id="t_U" name="user_data[euph_profile_type]" value="U" checked><label for="t_U">Private person</label>
            <input type="radio" id="t_B" name="user_data[euph_profile_type]" value="B"><label for="t_B">Company</label>
        `);

        api().applyPills(f);
        const radio = f.querySelector('#t_B');

        radio.checked = true;
        api().syncPill(radio);
        expect(pills(f)[1].checked).toBe(true);
    });

    it('are not drawn for another view or a single option', () => {
        const f = choice(`
            <input type="radio" id="t_U" name="user_data[euph_profile_type]" value="U"><label for="t_U">Private person</label>
            <input type="radio" id="t_B" name="user_data[euph_profile_type]" value="B"><label for="t_B">Company</label>
        `);

        window.Tygh.euph_profile_types = { pills: false };
        expect(api().applyPills(f)).toBe(0);
        expect(f.querySelector('.euph-pt-pills')).toBeNull();

        document.body.innerHTML = '';
        window.Tygh.euph_profile_types = { pills: true };
        const single = choice('<input type="radio" id="t_U" name="user_data[euph_profile_type]" value="U" checked><label for="t_U">Private person</label>');

        expect(api().applyPills(single)).toBe(0);
        expect(single.querySelector('.euph-pt-pills-source')).toBeNull();
    });
});

describe("CS-Cart's list of profile fields, by type", () => {
    const TYPES = [
        { id: 1, code: 'U', name: 'Private person', status: 'A', tone: 1 },
        { id: 2, code: 'B', name: 'Company', status: 'A', tone: 2 },
    ];
    const AREAS = {
        profile: ['profile_show', 'profile_required', 'registration_show'],
        checkout: ['checkout_show', 'checkout_required'],
    };
    const CONFIG = {
        kind: 'C',
        types: TYPES,
        areas: AREAS,
        first_area: 'profile',
        view: '',
        manage_url: '/admin.php?dispatch=euph_profile_types.manage',
        labels: {
            see: 'Customers see:',
            all_types: 'All types',
            manage_types: 'Manage types',
            profile_type: 'Profile type',
            flags: {
                profile_show: 'show',
                profile_required: 'required',
                registration_show: 'At registration',
                checkout_show: 'show',
                checkout_required: 'required',
                storefront_show: 'On the storefront',
            },
        },
    };

    // CS-Cart's own checkboxes of an area cell (views/profile_fields/manage.tpl).
    const stock = (id, area, [show, required]) => `
        <input type="hidden" name="fields_data[${id}][${area}_show]" value="N">
        <input type="checkbox" name="fields_data[${id}][${area}_show]" value="Y" id="sw_req_${area}_${id}" class="cm-switch-availability"${show ? ' checked' : ''}>
        <input type="hidden" name="fields_data[${id}][${area}_required]" value="N">
        <span id="req_${area}_${id}"><input type="checkbox" name="fields_data[${id}][${area}_required]" value="Y"${required ? ' checked' : ''}${show ? '' : ' disabled'}></span>`;

    // The add-on's part of the cell, as views/profile_fields/components/list_cell.tpl draws it.
    const cell = (id, area, mode, rows) => {
        const flags = AREAS[area];
        const first = area === 'profile';
        const name = `euph_pt_list[${id}]`;
        let html = `<div class="euph-pt-list-cell" data-ca-euph-pt-field="${id}" data-ca-euph-pt-area="${area}" data-ca-euph-pt-mode="${mode}" data-ca-euph-pt-first="${first ? 'Y' : 'N'}">`;

        if (first && (mode === 'general' || mode === 'types')) {
            html += `<input type="hidden" name="${name}[split]" value="N">`;
        }
        html += '<div class="euph-pt-list-row euph-pt-list-row--general">';
        if (first) {
            html += `<span class="euph-pt-list-label">All types <a class="euph-pt-list-split cm-euph-pt-split-field" data-ca-euph-pt-field="${id}">Set per type</a></span>`;
        }
        flags.forEach((flag) => {
            html += `<span class="euph-pt-list-slot" data-ca-euph-pt-slot="${flag}"></span>`;
        });
        html += '</div>';
        if (mode !== 'locked') {
            TYPES.forEach((type) => {
                const row = rows[type.id] || {};
                const prefix = `euph_pt_l_${id}_${type.id}`;

                html += `<div class="euph-pt-list-row euph-pt-list-row--type" data-ca-euph-pt-type="${type.id}">`;
                if (first) {
                    html += `<span class="euph-pt-list-label">${type.name}</span><input type="hidden" name="${name}[${type.id}][baseline]" value="NNNNNN">`;
                }
                flags.forEach((flag) => {
                    const required = flag.replace('_show', '_required');
                    const show = flag.replace('_required', '_show');
                    const pair = required !== flag && flags.includes(required) ? ` class="cm-euph-pt-show" data-ca-euph-pt-required="#${prefix}_${required}"` : '';
                    const locked = show !== flag && flags.includes(show) && row[show] !== 'Y' ? ' disabled' : '';

                    html += `<span class="euph-pt-list-slot"><input type="hidden" name="${name}[${type.id}][${flag}]" value="N">`
                        + `<input type="checkbox" name="${name}[${type.id}][${flag}]" value="Y" id="${prefix}_${flag}"${pair}${row[flag] === 'Y' ? ' checked' : ''}${locked}></span>`;
                });
                html += '</div>';
            });
        }

        return `${html}</div>`;
    };

    const page = (fields, config = CONFIG) => {
        document.body.innerHTML = `
            <form id="fields_form" name="fields_form" action="admin.php" method="post">
                <input type="hidden" name="profile_type" value="U">
                <div class="table-responsive-wrapper" id="profile_fields">
                    <table><thead><tr><th></th><th>Pos.</th><th>Description</th><th>Type</th><th>Profile<br>Show&nbsp;/&nbsp;Required</th><th>Checkout<br>Show&nbsp;/&nbsp;Required</th><th></th></tr></thead></table>
                    <table><tbody>${fields.map((f) => `
                        <tr data-ca-profile-fields-row="f${f.id}">
                            <td></td><td>${f.id}</td><td>Field ${f.id}</td><td>Input field</td>
                            <td data-ca-profile-fields-area-group="profile">${stock(f.id, 'profile', f.profile)}${cell(f.id, 'profile', f.mode, f.rows)}</td>
                            <td data-ca-profile-fields-area-group="checkout">${stock(f.id, 'checkout', f.checkout)}${cell(f.id, 'checkout', f.mode, f.rows)}</td>
                            <td></td>
                        </tr>`).join('')}
                    </tbody></table>
                </div>
            </form>
            ${config ? `<script type="application/json" id="euph_pt_list_config">${JSON.stringify(config)}</script>` : ''}`;

        return document.getElementById('fields_form');
    };

    // Company name: set per type, Company only. Phone: on its general checkboxes.
    const COMPANY = { id: 5, mode: 'types', profile: [false, false], checkout: [false, false], rows: { 2: { profile_show: 'Y', profile_required: 'Y', registration_show: 'Y', checkout_show: 'Y' } } };
    const PHONE = { id: 6, mode: 'general', profile: [true, true], checkout: [true, false], rows: {} };
    const typeBox = (form, id, type, flag) => form.querySelector(`input[type="checkbox"][name="euph_pt_list[${id}][${type}][${flag}]"]`);

    beforeEach(() => {
        window.localStorage.clear();
    });

    it("set up once: CS-Cart's checkboxes go into the general line and are still sent; switcher and column heads appear", () => {
        const f = page([COMPANY, PHONE]);

        expect(api().initFieldsList(document)).toBe(f);
        expect(api().initFieldsList(document)).toBeNull();

        const general = f.querySelector('.euph-pt-list-cell[data-ca-euph-pt-field="6"][data-ca-euph-pt-area="profile"] .euph-pt-list-row--general');

        expect(general.querySelector('[data-ca-euph-pt-slot="profile_show"] #sw_req_profile_6')).not.toBeNull();
        expect(general.querySelector('[data-ca-euph-pt-slot="profile_required"] #req_profile_6')).not.toBeNull();
        // No checkbox of CS-Cart for registration: a mirror of "show" stands there.
        expect(general.querySelector('[data-ca-euph-pt-slot="registration_show"] .euph-pt-list-mirror').checked).toBe(true);
        expect(new window.FormData(f).getAll('fields_data[6][profile_show]')).toEqual(['N', 'Y']);
        expect(new window.FormData(f).getAll('fields_data[6][profile_required]')).toEqual(['N', 'Y']);

        expect(f.classList.contains('euph-pt-list')).toBe(true);
        expect(f.classList.contains('euph-pt-list--all')).toBe(true);
        expect([...f.querySelectorAll('.euph-pt-list-views__btn')].map((b) => [b.dataset.caEuphPtView, b.textContent, b.classList.contains('is-active')]))
            .toEqual([['all', 'All types', true], ['1', 'Private person', false], ['2', 'Company', false]]);
        expect(f.querySelector('.euph-pt-list-views__manage').getAttribute('href')).toBe(CONFIG.manage_url);

        const heads = f.querySelectorAll('thead th');

        expect(heads[4].querySelector('.euph-pt-list-head__title').textContent).toBe('Profile');
        expect([...heads[4].querySelectorAll('.euph-pt-list-head span')].map((s) => s.textContent)).toEqual(['Profile type', 'show', 'required', 'At registration']);
        expect([...heads[5].querySelectorAll('.euph-pt-list-head span')].map((s) => s.textContent)).toEqual(['show', 'required']);
        expect(f.querySelector('tr[data-ca-profile-fields-row="f5"]').classList.contains('euph-pt-row--types')).toBe(true);
        expect(f.querySelector('tr[data-ca-profile-fields-row="f6"]').classList.contains('euph-pt-row--general')).toBe(true);
    });

    it('"Set per type" opens the rows filled from the general checkboxes and tells the save', () => {
        const f = page([COMPANY, PHONE]);

        api().initFieldsList(document);
        api().splitField(f, '6');

        TYPES.forEach(({ id }) => {
            expect(typeBox(f, 6, id, 'profile_show').checked).toBe(true);
            expect(typeBox(f, 6, id, 'profile_required').checked).toBe(true);
            expect(typeBox(f, 6, id, 'registration_show').checked).toBe(true);
            expect(typeBox(f, 6, id, 'checkout_show').checked).toBe(true);
            expect(typeBox(f, 6, id, 'checkout_required').checked).toBe(false);
        });
        expect(f.querySelector('input[name="euph_pt_list[6][split]"]').value).toBe('Y');
        expect(f.querySelector('.euph-pt-list-cell[data-ca-euph-pt-field="6"]').dataset.caEuphPtMode).toBe('types');
        expect(f.querySelector('tr[data-ca-profile-fields-row="f6"]').classList.contains('euph-pt-row--types')).toBe(true);
        // The field set per type is not split again.
        expect(f.querySelector('input[name="euph_pt_list[5][split]"]').value).toBe('N');
    });

    it('one type at a time: its rows; a field on its general checkboxes shows them there, and ticking sets it per type', () => {
        const f = page([COMPANY, PHONE]);

        api().initFieldsList(document);
        expect(api().setListView(f, '2')).toBe('2');
        expect(f.classList.contains('euph-pt-list--one')).toBe(true);
        expect([...f.querySelectorAll('.euph-pt-list-row--type.is-current')].every((row) => row.dataset.caEuphPtType === '2')).toBe(true);
        expect(f.querySelectorAll('.euph-pt-list-row--type.is-current')).toHaveLength(4);
        expect(typeBox(f, 6, 2, 'profile_show').checked).toBe(true);
        expect(f.querySelector('input[name="euph_pt_list[6][split]"]').value).toBe('N');
        expect(window.localStorage.getItem('euph_profile_types.list_view.C')).toBe('2');

        const box = typeBox(f, 6, 2, 'checkout_required');

        box.checked = true;
        api().onListTypeChange(box);
        expect(f.querySelector('input[name="euph_pt_list[6][split]"]').value).toBe('Y');
        // The other types keep what they saw: the general checkboxes.
        expect(typeBox(f, 6, 1, 'checkout_required').checked).toBe(false);
        expect(typeBox(f, 6, 1, 'profile_show').checked).toBe(true);

        // A view that is not a type of the list is all types.
        expect(api().setListView(f, '99')).toBe('all');
        expect(f.classList.contains('euph-pt-list--all')).toBe(true);
    });

    it('open on the view of a type card link, else on the one remembered', () => {
        window.localStorage.setItem('euph_profile_types.list_view.C', '2');
        let f = page([COMPANY, PHONE]);

        api().initFieldsList(document);
        expect(f.getAttribute('data-ca-euph-pt-view')).toBe('2');

        f = page([COMPANY, PHONE], { ...CONFIG, view: '1' });
        api().initFieldsList(document);
        expect(f.getAttribute('data-ca-euph-pt-view')).toBe('1');
    });

    it("leave CS-Cart's list as it is without the add-on's settings", () => {
        const f = page([PHONE], null);

        expect(api().initFieldsList(document)).toBeNull();
        expect(f.querySelector('.euph-pt-list-cell #sw_req_profile_6')).toBeNull();
        expect(f.querySelector('.euph-pt-list-views')).toBeNull();
    });

    it('"show" off unticks and locks "required"', () => {
        const f = page([COMPANY]);
        const show = typeBox(f, 5, 2, 'profile_show');
        const required = typeBox(f, 5, 2, 'profile_required');

        show.checked = false;
        api().syncRequired(show);
        expect(required.checked).toBe(false);
        expect(required.disabled).toBe(true);
        show.checked = true;
        api().syncRequired(show);
        expect(required.disabled).toBe(false);
    });
});

describe('the view of one type greys the fields it does not see', () => {
    it('marks the rows whose type shows nothing, and unmarks them once ticked', () => {
        document.body.innerHTML = `
            <form id="fields_form"><table><thead><tr><th></th><th>Profile<br>Show</th></tr></thead><tbody>
                <tr id="r5"><td></td><td>
                    <div class="euph-pt-list-cell" data-ca-euph-pt-field="5" data-ca-euph-pt-area="profile" data-ca-euph-pt-mode="types" data-ca-euph-pt-first="Y">
                        <div class="euph-pt-list-row euph-pt-list-row--general"></div>
                        <div class="euph-pt-list-row euph-pt-list-row--type" data-ca-euph-pt-type="1"><input type="checkbox" name="euph_pt_list[5][1][profile_show]" value="Y"></div>
                        <div class="euph-pt-list-row euph-pt-list-row--type" data-ca-euph-pt-type="2"><input type="checkbox" name="euph_pt_list[5][2][profile_show]" value="Y" checked></div>
                    </div>
                </td></tr>
            </tbody></table></form>
            <script type="application/json" id="euph_pt_list_config">${JSON.stringify({
                kind: 'C', types: [{ id: 1, name: 'Private person', tone: 1 }, { id: 2, name: 'Company', tone: 2 }],
                areas: { profile: ['profile_show'] }, first_area: 'profile', view: '1', manage_url: '#',
                labels: { see: 'Customers see:', all_types: 'All types', manage_types: 'Manage types', profile_type: 'Profile type', flags: { profile_show: 'show' } },
            })}</script>`;
        window.localStorage.clear();
        const f = api().initFieldsList(document);
        const row = f.querySelector('#r5');

        expect(row.classList.contains('euph-pt-row--unseen')).toBe(true);
        api().setListView(f, '2');
        expect(row.classList.contains('euph-pt-row--unseen')).toBe(false);
        api().setListView(f, '1');
        const box = f.querySelector('input[name="euph_pt_list[5][1][profile_show]"]');

        box.checked = true;
        api().onListTypeChange(box);
        expect(row.classList.contains('euph-pt-row--unseen')).toBe(false);
        api().setListView(f, 'all');
        expect(row.classList.contains('euph-pt-row--unseen')).toBe(false);
    });
});

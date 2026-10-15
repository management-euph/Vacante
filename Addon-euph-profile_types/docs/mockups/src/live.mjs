// Renders the screens of the Profile fields list by type and of the type
// choice as pills from the add-on's own code: its styles (compiled from its
// LESS files) and its script (js/addons/euph_profile_types/func.js), on the
// markup its templates draw (views/profile_fields/components/list_cell.tpl
// inside CS-Cart's list of profile fields; the choice as CS-Cart draws a
// radio profile field). Only the admin chrome around them is a mockup.
//   PLAYWRIGHT_MODULE=<playwright> LESS_MODULE=<less> node live.mjs
// The committed PNGs were then reduced to 256 colours, as in render.mjs.
import { readFileSync, writeFileSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { createRequire } from 'node:module';

const here = dirname(fileURLToPath(import.meta.url));
const out = join(here, '..');
const addon = join(here, '..', '..', '..');
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const less = require(process.env.LESS_MODULE || 'less');

const compile = async (file) => (await less.render(readFileSync(file, 'utf8'), { filename: file })).css;
const adminCss = await compile(join(addon, 'design/backend/css/addons/euph_profile_types/styles.less'));
const storefrontCss = await compile(join(addon, 'design/themes/responsive/css/addons/euph_profile_types/styles.less'));
const script = pathToFileURL(join(addon, 'js/addons/euph_profile_types/func.js')).href;
const read = (name) => readFileSync(join(here, name), 'utf8');

/* ── The data of the screens ─────────────────────────────────────────── */

const TYPES = [
    { id: 1, code: 'I', name: 'Individual', status: 'A', tone: 1 },
    { id: 2, code: 'LE', name: 'Legal entity', status: 'A', tone: 2 },
    { id: 3, code: 'D', name: 'Dealer', status: 'A', tone: 3 },
];
const AREAS = {
    profile: ['profile_show', 'profile_required', 'registration_show'],
    checkout: ['checkout_show', 'checkout_required'],
};
const FLAGS = ['profile_show', 'profile_required', 'registration_show', 'checkout_show', 'checkout_required', 'storefront_show'];
const ALL = { profile_show: 'Y', profile_required: 'Y', registration_show: 'Y', checkout_show: 'Y', checkout_required: 'Y' };
const CONFIG = {
    kind: 'C',
    types: TYPES,
    areas: AREAS,
    first_area: 'profile',
    view: 'all',
    manage_url: '#',
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
// [id, description, type, mode, general [profile show, required, checkout show, required], rows by type id]
const SECTIONS = [
    ['Contact information', [
        [10, 'E-mail', 'E-mail', 'locked', [1, 1, 1, 1]],
        [20, 'First name', 'Input field', 'general', [1, 1, 1, 1]],
        [30, 'Last name', 'Input field', 'general', [1, 1, 1, 1]],
        [40, 'Phone', 'Phone', 'general', [1, 0, 1, 0]],
        [50, 'Company', 'Input field', 'types', [0, 0, 0, 0], { 2: ALL, 3: ALL }],
        [60, 'Tax number (CUI)', 'Input field', 'types', [0, 0, 0, 0], { 2: ALL, 3: ALL }],
        [61, 'Tax registration code', 'Input field', 'types', [0, 0, 0, 0], { 2: { profile_show: 'Y', checkout_show: 'Y' } }],
        [62, 'Registered address', 'Input field', 'types', [0, 0, 0, 0], { 2: { profile_show: 'Y', profile_required: 'Y', checkout_show: 'Y' }, 3: { profile_show: 'Y' } }],
        [63, 'Dealer contract number', 'Input field', 'types', [0, 0, 0, 0], { 3: { profile_show: 'Y', profile_required: 'Y', registration_show: 'Y' } }],
        [64, 'Date of birth', 'Date', 'types', [0, 0, 0, 0], { 1: { profile_show: 'Y' } }],
    ]],
    ['Billing address', [
        [70, 'Address', 'Input field', 'general', [1, 1, 1, 1]],
        [80, 'City', 'Input field', 'general', [1, 1, 1, 1]],
        [90, 'Country', 'Country ››', 'general', [1, 1, 1, 1]],
    ]],
];

/* ── CS-Cart's list (views/profile_fields/manage.tpl) ────────────────── */

const checked = (on) => (on ? ' checked="checked"' : '');

function stockCell(id, area, mode, general) {
    const [show, required] = area === 'profile' ? general.slice(0, 2) : general.slice(2, 4);
    const locked = mode === 'locked';

    return `
        <input type="hidden" name="fields_data[${id}][${area}_show]" value="${locked ? 'Y' : 'N'}" />
        <input type="checkbox" name="fields_data[${id}][${area}_show]" value="Y" id="sw_req_${area}_${id}" class="cm-switch-availability"${checked(show)}${locked ? ' disabled="disabled"' : ''} />
        <input type="hidden" name="fields_data[${id}][${area}_required]" value="${locked ? 'Y' : 'N'}" />
        <span id="req_${area}_${id}"><input type="checkbox" name="fields_data[${id}][${area}_required]" value="Y"${checked(required)}${locked || !show ? ' disabled="disabled"' : ''} /></span>`;
}

/* ── The add-on's cell (views/profile_fields/components/list_cell.tpl) ─ */

function signature(row) {
    return FLAGS.map((flag) => (row[flag] === 'Y' ? 'Y' : 'N')).join('');
}

function listCell(id, area, mode, general, rows) {
    const flags = AREAS[area];
    const first = area === 'profile';
    const name = `euph_pt_list[${id}]`;
    let html = `<div class="euph-pt-list-cell" data-ca-euph-pt-field="${id}" data-ca-euph-pt-area="${area}" data-ca-euph-pt-mode="${mode}" data-ca-euph-pt-first="${first ? 'Y' : 'N'}" style="--euph-pt-cols: ${flags.length};">`;

    if (first && mode !== 'locked') {
        html += `<input type="hidden" name="${name}[split]" value="N" />`;
    }
    html += '<div class="euph-pt-list-row euph-pt-list-row--general">';
    if (first) {
        html += '<span class="euph-pt-list-label">All types ';
        html += mode === 'locked'
            ? '<span class="euph-pt-list-tag">always</span>'
            : `<span class="euph-pt-list-tag">general</span> <a class="euph-pt-list-split cm-euph-pt-split-field" data-ca-euph-pt-field="${id}">Set per type&nbsp;›</a>`;
        html += '</span>';
    }
    flags.forEach((flag) => {
        html += `<span class="euph-pt-list-slot" data-ca-euph-pt-slot="${flag}"></span>`;
    });
    html += '</div>';
    if (mode !== 'locked') {
        TYPES.forEach((type) => {
            const row = (rows || {})[type.id] || {};
            const prefix = `euph_pt_l_${id}_${type.id}`;

            html += `<div class="euph-pt-list-row euph-pt-list-row--type" data-ca-euph-pt-type="${type.id}">`;
            if (first) {
                html += `<span class="euph-pt-list-label"><span class="euph-pt-dot euph-pt-dot--${type.tone}"></span>${type.name}</span>`;
                html += `<input type="hidden" name="${name}[${type.id}][baseline]" value="${signature(row)}" />`;
            }
            flags.forEach((flag) => {
                const required = flag.replace('_show', '_required');
                const show = flag.replace('_required', '_show');
                const pair = required !== flag && flags.includes(required) ? ` class="cm-euph-pt-show" data-ca-euph-pt-required="#${prefix}_${required}"` : '';
                const locked = show !== flag && flags.includes(show) && row[show] !== 'Y' ? ' disabled="disabled"' : '';

                html += `<span class="euph-pt-list-slot"><input type="hidden" name="${name}[${type.id}][${flag}]" value="N" />`
                    + `<input type="checkbox" name="${name}[${type.id}][${flag}]" value="Y" id="${prefix}_${flag}"${pair}${checked(row[flag] === 'Y')}${locked} /></span>`;
            });
            html += '</div>';
        });
    }

    return `${html}</div>`;
}

function fieldRow([id, description, type, mode, general, rows]) {
    const tag = mode === 'locked' ? 'always' : 'general';

    return `
        <tr class="cm-row-item" data-ca-profile-fields-row="f${id}">
            <td class="center" width="5%"><input type="checkbox" class="cm-item" /></td>
            <td width="10%"><input class="input-micro input-hidden" type="text" value="${id}" /></td>
            <td width="30%"><a>${description}</a></td>
            <td class="nowrap" width="10%">${type}<span class="euph-pt-list-type-tag">${tag}</span></td>
            <td class="center" data-ca-profile-fields-area-group="profile" width="20%">${stockCell(id, 'profile', mode, general)}${listCell(id, 'profile', mode, general, rows)}</td>
            <td class="center" data-ca-profile-fields-area-group="checkout" width="20%">${stockCell(id, 'checkout', mode, general)}${listCell(id, 'checkout', mode, general, rows)}</td>
            <td width="5%">&nbsp;</td>
        </tr>`;
}

function listPage(view, legend) {
    const sections = SECTIONS.map(([title, fields]) => `
        <table width="100%" class="table table-middle table--relative table-responsive profile-fields__section">
            <tr><td colspan="7" class="row-header"><h5><span class="caret">▾</span> ${title}</h5></td></tr>
            <tbody>${fields.map(fieldRow).join('')}</tbody>
        </table>`).join('');

    return `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Profile fields</title>
<style>${read('admin421.css')}</style>
<style>
    .content-card { padding: 12px 14px 4px; }
    .table { width: 100%; border-collapse: collapse; }
    .table th { text-align: left; font-weight: normal; color: var(--muted); font-size: 11px; padding: 8px; border-bottom: 1px solid var(--line); vertical-align: bottom; }
    .table th.center, .table td.center { text-align: center; }
    .table td { padding: 7px 8px; border-bottom: 1px solid var(--line2); vertical-align: middle; }
    .row-header { background: var(--sec); }
    .row-header h5 { margin: 0; font-size: 12px; }
    .input-micro { width: 34px; border: 1px solid transparent; background: transparent; font: inherit; color: #4b5563; }
    td a { color: var(--text); }
    .nowrap { white-space: nowrap; }
    input[type=checkbox] { width: 13px; height: 13px; }
</style>
<style>${adminCss}</style>
<script>window.Tygh = { $: function () { return { on: function () {} }; }, tr: function () {} }; window.Tygh.$.ceEvent = function () {};</script>
<script src="${script}"></script></head>
<body><div class="wrap">
${read('_admin421_top.html')}
  <div class="head"><span class="back">←</span><h1>Profile fields</h1><div class="actions"><span class="btn">Save</span><span class="btn btn-primary">＋ Add field</span></div></div>
  <div class="cols">
    <section class="card content-card">
      <form id="fields_form" name="fields_form" method="post">
        <input type="hidden" name="profile_type" value="U" />
        <div class="table-responsive-wrapper longtap-selection" id="profile_fields">
          <table width="100%" class="table table-middle table--relative table-responsive"><thead><tr>
            <th width="5%"><input type="checkbox" /></th><th width="10%">Pos.</th><th width="30%">Description</th><th width="10%">Type</th>
            <th class="center" width="20%">Profile<br/>Show&nbsp;/&nbsp;Required</th><th class="center" width="20%">Checkout<br/>Show&nbsp;/&nbsp;Required</th><th width="5%">&nbsp;</th>
          </tr></thead></table>
          ${sections}
        </div>
      </form>
    </section>
    <aside class="rside"><div class="rcard"><a>Vendor information</a></div></aside>
  </div>
  <div class="legend">${legend}</div>
</div></div></div>
<script>window.Tygh.euph_profile_types = { reload: true, list: ${JSON.stringify({ ...CONFIG, view })} };</script>
</body></html>`;
}

/* ── The registration form with the choice as pills ─────────────────── */

function radio(code, name, isChecked) {
    return `<input type="radio" class="radio cm-euph-profile-type" id="elm_euph_profile_type_${code}" name="user_data[euph_profile_type]" value="${code}"${checked(isChecked)} />`
        + `<label for="elm_euph_profile_type_${code}" class="ty-profile-field__radio">${name}</label>`;
}

const field = (label, value, required = true) => `
    <div class="ty-control-group"><label class="ty-control-group__title${required ? ' req' : ''}">${label}</label><input class="ty-input" value="${value}"></div>`;

function storefrontPage(phone) {
    const choice = `
        <div class="ty-control-group euph-pt-choice">
            <label class="ty-control-group__title cm-required req" for="elm_euph_profile_type">Profile type</label>
            <div id="elm_euph_profile_type">${radio('I', 'Individual', false)}${radio('LE', 'Legal entity', true)}${radio('D', 'Dealer', false)}</div>
        </div>`;
    const form = `
        <form name="profiles_register_form" class="${phone ? 'phone-form' : 'card'}">
            ${choice}
            <div class="${phone ? '' : 'grid2'}">${field('First name', 'Andrei')}${field('Last name', 'Ionescu')}</div>
            <div class="${phone ? '' : 'grid2'}">${field('Company', 'Litoral Turism SRL')}${field('Tax number (CUI)', 'RO12345678')}</div>
            <div class="${phone ? '' : 'grid2'}">${field('E-mail', 'andrei@litoralturism.ro')}${field('Password', '••••••••••')}</div>
            <div style="margin-top:8px"><span class="btn-cta"${phone ? ' style="display:block;text-align:center"' : ''}>Register</span></div>
        </form>`;
    const body = phone
        ? `<div class="ph-head"><span>VacanteLitoral.ro</span><span>☰</span></div><div class="ph-body"><h1 class="title">Register</h1>${form}</div>`
        : `${read('_storefront_top.html')}<div class="container"><div class="breadcrumbs"><a>Home</a> › Register</div><h1 class="title">Register for a new account</h1>
           <div class="sf-cols"><div>${form}</div><div class="sf-note"></div></div></div>
           <div class="legend">
             <div><span class="mark">1</span><span>Setting <b>Profile type choice view: Tabs (pills)</b>. The theme's own radio buttons are hidden and stay in the form; the pills are radio buttons too (arrow keys, screen readers) that tick them.</span></div>
             <div><span class="mark">2</span><span>One type is always selected: the type of the registration link, else the customer's own, else the default type. Changing it redraws the form with the fields of the type.</span></div>
           </div><div class="footer"><span>© 2026 VacanteLitoral.ro</span><span>Terms</span><span>Privacy</span></div>`;

    return `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Registration</title>
<style>${read('storefront.css')}</style>
<style>
    .sf-cols { display: grid; grid-template-columns: minmax(0, 760px); }
    .ty-profile-field__radio { margin: 0 16px 0 4px; }
    .ph-head { background: var(--brand); color: #fff; padding: 12px 14px; font-weight: 700; font-size: 15px; display: flex; justify-content: space-between; }
    .ph-body { padding: 14px; }
    .ph-body h1.title { font-size: 20px; margin: 4px 0 14px; }
    .wrap { position: relative; }
</style>
<style>${storefrontCss}</style>
<script>window.Tygh = { $: function () { return { on: function () {} }; }, tr: function () {}, euph_profile_types: { pills: true } }; window.Tygh.$.ceEvent = function () {};</script>
<script src="${script}"></script></head>
<body><div class="wrap">${body}</div></body></html>`;
}

/* ── Render ──────────────────────────────────────────────────────────── */

const ALL_LEGEND = `
    <div><span class="mark">1</span><span><b>View switcher</b> on CS-Cart's own list: all types side by side, or one type at a time. The choice is remembered; a type card opens the list on its type.</span></div>
    <div><span class="mark">2</span><span>A field set per type: a row per type, the type named once, with its colour. "Required" stays locked while "show" is off.</span></div>
    <div><span class="mark">3</span><span>A field nobody set per type takes <b>one line</b>: CS-Cart's own general checkboxes, moved in here. <b>Set per type</b> opens the rows, filled from them; nothing changes until a row differs.</span></div>
    <div><span class="mark">4</span><span>Saved with the list's own <b>Save</b>. No template of CS-Cart is replaced: the rows hang on the list's own hook in each Show / Required cell.</span></div>`;
const ONE_LEGEND = `
    <div><span class="mark">1</span><span><b>One type</b>: CS-Cart's layout, one line per field: the form this type gets.</span></div>
    <div><span class="mark">2</span><span><b>general</b>: the field follows the general checkboxes, the same for all types. Ticking it here sets it per type; the other types keep what they saw.</span></div>`;

const build = mkdtempSync(join(tmpdir(), 'euph-live-'));
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });

async function shot(name, html, viewport, after, args = []) {
    const page = await browser.newPage({ viewport, deviceScaleFactor: 1.5 });
    const file = join(build, `${name}.html`);

    writeFileSync(file, html);
    await page.goto(pathToFileURL(file).href);
    await page.evaluate(after, args);
    await page.screenshot({ path: join(out, `${name}.png`), fullPage: true });
    await page.close();
    console.log('rendered', name);
}

const mark = (n) => `<span class="mark">${n}</span>`;

await shot('admin-09-fields-all-types', listPage('all', ALL_LEGEND), { width: 1440, height: 400 }, (marks) => {
    window.EuphProfileTypes.initFieldsList(document);
    document.querySelector('.euph-pt-list-views__group').insertAdjacentHTML('afterend', marks[0]);
    document.querySelector('tr[data-ca-profile-fields-row="f50"] .euph-pt-list-row--type[data-ca-euph-pt-type="2"] .euph-pt-list-label').insertAdjacentHTML('beforeend', marks[1]);
    document.querySelector('tr[data-ca-profile-fields-row="f20"] .euph-pt-list-split').insertAdjacentHTML('afterend', marks[2]);
    document.querySelector('thead .euph-pt-list-head__title').insertAdjacentHTML('beforeend', marks[3]);
}, [mark(1), mark(2), mark(3), mark(4)]);

await shot('admin-10-fields-one-type', listPage('2', ONE_LEGEND), { width: 1440, height: 400 }, (marks) => {
    window.EuphProfileTypes.initFieldsList(document);
    document.querySelector('.euph-pt-list-views__group').insertAdjacentHTML('afterend', marks[0]);
    document.querySelector('tr[data-ca-profile-fields-row="f20"] .euph-pt-list-type-tag').insertAdjacentHTML('afterend', marks[1]);
}, [mark(1), mark(2)]);

await shot('storefront-05-pills', storefrontPage(false), { width: 1360, height: 400 }, (marks) => {
    window.EuphProfileTypes.applyPills(document);
    document.querySelector('.euph-pt-pills').insertAdjacentHTML('afterend', `<p class="muted small" style="margin:0 0 14px">${marks[0]} ${marks[1]}</p>`);
}, [mark(1), mark(2)]);

await shot('storefront-06-pills-phone', storefrontPage(true), { width: 390, height: 400 }, () => {
    window.EuphProfileTypes.applyPills(document);
});

await browser.close();
rmSync(build, { recursive: true, force: true });

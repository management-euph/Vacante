import { beforeAll, beforeEach, describe, expect, it } from 'vitest';

/**
 * Behavioral tests for the shared SEO Templates page script
 * (addon-travel-core/js/addons/travel_core/seo-templates.js), driven through
 * the markup components/seo_templates_page.tpl + seo_lang_fields.tpl render.
 *
 * The old page listed placeholders as plain text: clicking one did nothing.
 * These pin that a click now inserts at the caret of the field being edited,
 * that modifiers attach to the placeholder under the caret, and that the
 * ticks, counters, checks and preview follow the fields.
 */

const CONFIG = {
    keys: ['name', 'city', 'country', 'facilities'],
    modifiers: ['lower', 'upper', 'title', 'capitalize', 'trim', 'slug', 'strip_tags', 'first', 'last', 'abs', 'round'],
    sample: { name: 'Parc CM', city: 'Mamaia', country: 'Romania', facilities: ['Pool', 'Spa', 'Wi-Fi', 'Bar'] },
    store_url: 'shop.example',
    labels: {
        target_none: 'Click in a field first',
        target_into: 'Inserting into [value]',
        target_fallback: 'Went into [value]',
        modifier_needs_field: 'Click in a field first',
        modifier_needs_token: 'Put the cursor on a placeholder',
        modifier_replaced: 'Replaced [value]',
        restored: '[value] restored',
        counter_title: 'Aim for [value]',
        preview_kept: '(left as it is)',
        unknown_placeholder: 'Unknown placeholder [value]',
        unknown_modifier: 'Unknown modifier [value]',
        one_modifier: 'One modifier only',
        unbalanced: 'Missing braces',
    },
};

const FIELDS = [
    ['seo_product_name', '{{name}}', 80, 255],
    ['seo_page_title', '{{name}} - {{city}}', 60, 70],
    ['seo_meta_description', 'Book {{name}} in {{city}}.', 160, 200],
    ['seo_name_slug', '{{name}}-{{city}}', 0, 0],
];

function pane(lc, name, first) {
    const groups = FIELDS.map(([key, value, ideal, max]) => {
        const toggle = 'seo_field_' + key.replace('seo_', '');
        return `
        <div class="control-group" data-seo-field="${key}" data-seo-toggle-key="${toggle}">
            <label class="control-label" for="${lc}_${key}">${key}</label>
            <div class="controls">
                <input type="text" id="${lc}_${key}" name="seo_lang[${lc}][${key}]" data-seo-key="${key}"
                       data-seo-default="${value}" ${ideal ? `data-seo-ideal="${ideal}" data-seo-max="${max}"` : ''}
                       value="${value}" />
                <div class="seo-field-meta">
                    <label><input type="checkbox" ${first ? `name="seo[${toggle}]" value="Y"` : ''} data-seo-toggle="${toggle}" checked /> Apply</label>
                    <span class="label" data-seo-counter hidden></span>
                    <a href="#" data-seo-restore>Restore default</a>
                </div>
                <div data-seo-problems></div>
            </div>
        </div>`;
    }).join('');
    return `
        <div id="seo_lang_${lc}" data-seo-lang-pane="${lc}" data-seo-lang-name="${name}"${first ? '' : ' hidden'}>
            ${groups}
            <div data-seo-preview>
                <div data-seo-preview-url></div><div data-seo-preview-title></div>
                <div data-seo-preview-desc></div><span data-seo-preview-name></span>
            </div>
        </div>`;
}

function page() {
    const config = JSON.stringify(CONFIG).replace(/"/g, '&quot;');
    document.body.innerHTML = `
        <aside>
            <p data-seo-target></p>
            <button type="button" data-seo-placeholder="country">{{country}}</button>
            <button type="button" data-seo-placeholder="city">{{city}}</button>
            <button type="button" data-seo-modifier="upper">|upper</button>
            <button type="button" data-seo-modifier="lower">|lower</button>
        </aside>
        <form id="seo_form" data-seo-config="${config}">
            <ul class="nav nav-tabs">
                <li data-seo-lang-tab="en" class="active"><a href="#seo_lang_en">English</a></li>
                <li data-seo-lang-tab="ro"><a href="#seo_lang_ro">Română</a></li>
            </ul>
            ${pane('en', 'English', true)}
            ${pane('ro', 'Română', false)}
        </form>`;
}

const $ = (sel) => document.querySelector(sel);
const click = (sel) => $(sel).dispatchEvent(new MouseEvent('click', { bubbles: true }));
function focusAt(id, start, end = start) {
    const el = document.getElementById(id);
    el.focus();
    el.setSelectionRange(start, end);
    return el;
}

// CS-Cart runs page scripts again after each AJAX page load through the
// ce.commoninit event: the tests rebuild the page and fire it the same way.
let commoninit = null;

beforeAll(async () => {
    window.Tygh = { $: { ceEvent: (action, name, fn) => { commoninit = fn; } } };
    page();
    await import('../../addon-travel-core/js/addons/travel_core/seo-templates.js');
});

beforeEach(() => {
    page();
    commoninit();
});

describe('click to insert', () => {
    it('inserts the placeholder at the caret of the field being edited', () => {
        const title = focusAt('en_seo_page_title', 8); // after "{{name}}"
        click('[data-seo-placeholder="country"]');

        expect(title.value).toBe('{{name}}{{country}} - {{city}}');
        expect(title.selectionStart).toBe(19);
        expect(document.activeElement).toBe(title);
        expect($('[data-seo-target]').textContent).toBe('Inserting into seo_page_title · English');
    });

    it('replaces the selected text', () => {
        const title = focusAt('en_seo_page_title', 11, 19); // "{{city}}"
        click('[data-seo-placeholder="country"]');

        expect(title.value).toBe('{{name}} - {{country}}');
    });

    it('goes into the first field of the open tab when no field was chosen, and says so', () => {
        click('[data-seo-placeholder="city"]');

        expect($('#en_seo_product_name').value).toBe('{{name}}{{city}}');
        expect($('[data-seo-target]').textContent).toBe('Went into seo_product_name');
    });

    it('keeps the caret when the button is pressed with the mouse', () => {
        focusAt('en_seo_page_title', 0);
        const down = new MouseEvent('mousedown', { bubbles: true, cancelable: true });
        $('[data-seo-placeholder="city"]').dispatchEvent(down);

        expect(down.defaultPrevented).toBe(true);
    });
});

describe('modifiers', () => {
    it('sets the modifier of the placeholder under the caret', () => {
        const title = focusAt('en_seo_page_title', 3); // inside {{name}}
        click('[data-seo-modifier="upper"]');

        expect(title.value).toBe('{{name|upper}} - {{city}}');
    });

    it('replaces an existing modifier: the engine takes one per placeholder', () => {
        const title = focusAt('en_seo_page_title', 3);
        click('[data-seo-modifier="upper"]');
        title.setSelectionRange(3, 3);
        click('[data-seo-modifier="lower"]');

        expect(title.value).toBe('{{name|lower}} - {{city}}');
        expect($('[data-seo-target]').textContent).toBe('Replaced |upper');
    });

    it('asks for a placeholder when the caret is on plain text', () => {
        const meta = focusAt('en_seo_meta_description', 1); // inside "Book"
        click('[data-seo-modifier="upper"]');

        expect(meta.value).toBe('Book {{name}} in {{city}}.');
        expect($('[data-seo-target]').textContent).toBe('Put the cursor on a placeholder');
    });
});

describe('tabs, ticks and restore', () => {
    it('switches language tabs', () => {
        click('[data-seo-lang-tab="ro"] a');

        expect($('[data-seo-lang-pane="ro"]').hidden).toBe(false);
        expect($('[data-seo-lang-pane="en"]').hidden).toBe(true);
        expect($('[data-seo-lang-tab="ro"]').classList.contains('active')).toBe(true);
    });

    it('mirrors a tick into every language and greys the field out', () => {
        const copy = document.querySelector('#seo_lang_ro [data-seo-toggle="seo_field_page_title"]');
        copy.checked = false;
        copy.dispatchEvent(new Event('change', { bubbles: true }));

        const named = document.querySelector('input[name="seo[seo_field_page_title]"]');
        expect(named.checked).toBe(false);
        expect(document.querySelector('#seo_lang_en [data-seo-field="seo_page_title"]').classList.contains('seo-field-off')).toBe(true);
        expect($('#seo_lang_en [data-seo-preview-title]').textContent).toBe('(left as it is)');
    });

    it('restores the built-in default', () => {
        const title = $('#en_seo_page_title');
        title.value = 'Something else';
        click('#seo_lang_en [data-seo-field="seo_page_title"] [data-seo-restore]');

        expect(title.value).toBe('{{name}} - {{city}}');
    });
});

describe('counters, checks and preview', () => {
    it('counts the length of the rendered sample', () => {
        const counter = $('#seo_lang_en [data-seo-field="seo_page_title"] [data-seo-counter]');

        expect(counter.hidden).toBe(false);
        expect(counter.textContent).toBe('16 / 60'); // "Parc CM - Mamaia"
        expect(counter.className).toBe('label label-success');
    });

    it('flags an unknown placeholder while typing', () => {
        const title = $('#en_seo_page_title');
        title.value = '{{name}} {{citty}} {{name|upper|lower}}';
        title.dispatchEvent(new Event('input', { bubbles: true }));

        const problems = $('#seo_lang_en [data-seo-field="seo_page_title"] [data-seo-problems]').textContent;
        expect(problems).toContain('Unknown placeholder {{citty}}');
        expect(problems).toContain('One modifier only');
    });

    it('previews the title, URL and description for the sample hotel', () => {
        expect($('#seo_lang_en [data-seo-preview-title]').textContent).toBe('Parc CM - Mamaia');
        expect($('#seo_lang_en [data-seo-preview-desc]').textContent).toBe('Book Parc CM in Mamaia.');
        expect($('#seo_lang_en [data-seo-preview-url]').textContent).toBe('shop.example › parc-cm-mamaia.html');
        expect($('#seo_lang_en [data-seo-preview-name]').textContent).toBe('Parc CM');
    });
});

describe('render() follows fn_travel_core_render_seo_template', () => {
    const render = (tpl, data) => window.TravelCoreSeoTemplates.render(tpl, data);

    it('joins the first 3 list items and applies modifiers', () => {
        expect(render('{{name|upper}} with {{facilities}}', CONFIG.sample)).toBe('PARC CM with Pool, Spa, Wi-Fi');
    });

    it('drops empty placeholders and the separators they leave', () => {
        expect(render('{{name}} - {{missing}}, {{city}}', { name: 'A', city: 'B' })).toBe('A, B');
        expect(render('{{name}} ({{missing}})', { name: 'A' })).toBe('A');
        expect(render('{{missing}} - {{name}}', { name: 'A' })).toBe('A');
    });
});

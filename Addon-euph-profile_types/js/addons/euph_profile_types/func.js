/**
 * Profile types — storefront and admin panel.
 *
 * The type choice is a profile field CS-Cart draws itself
 * (user_data[euph_profile_type], company_data[euph_profile_type]). When it
 * changes, the form is drawn again for the new type:
 *   - registration and the customer account: the form of the new type
 *     takes the place of this one and what was typed stays. The forms of
 *     the other types are fetched once the page is idle, so the switch is
 *     instant;
 *   - the checkout, the admin panel, and with the setting "Reload the page
 *     when the type is changed" (a captcha or another script that does not
 *     survive the redraw): the page reloads with ?euph_profile_type=CODE and
 *     what was typed is put back after the reload (passwords excepted).
 *
 * With the choice view "Tabs (pills)" the radio buttons of the choice are
 * drawn as a segmented control; the radio buttons stay and are sent.
 *
 * Admin panel: CS-Cart's list of profile fields with the per-type
 * checkboxes, all types side by side or one type at a time ("Set per type"
 * opens the rows of a field still on its general checkboxes); the per-type
 * checkboxes of a field card and of a type card ("show" off unticks and
 * locks "required"), "Same as the general checkboxes", "Clear", and the copy
 * buttons of the registration links.
 *
 * The helpers that need no page are on window.EuphProfileTypes, for
 * tests/js/euph-profile-types.test.mjs.
 */
(function (_, $) {
    'use strict';

    var FIELD = 'euph_profile_type';
    var CHOICE = 'select[name$="[' + FIELD + ']"], input[type="radio"][name$="[' + FIELD + ']"]';
    var STORAGE_KEY = 'euph_profile_types.restore';
    var MAX_AGE_MS = 5 * 60 * 1000;
    var SKIPPED_NAMES = /^(dispatch|security_hash|result_ids|is_ajax|redirect_url|return_url)(\[|$)/;
    var SKIPPED_TYPES = ['file', 'submit', 'button', 'reset', 'hidden', 'image'];

    function isChoiceName(name) {
        return typeof name === 'string' && name.slice(-(FIELD.length + 2)) === '[' + FIELD + ']';
    }

    function cssEscape(value) {
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(value);
        }

        return String(value).replace(/["\\\]\[]/g, '\\$&');
    }

    /**
     * What the visitor typed or chose in a form: [{name, kind, value|values, checked}].
     * The type choice, hidden and technical fields are left out, passwords
     * unless asked for.
     */
    function snapshot(form, keepPasswords) {
        var values = [];
        var elements = form && form.elements ? form.elements : [];

        for (var i = 0; i < elements.length; i++) {
            var el = elements[i];
            var name = el.name;
            var type = String(el.type || '').toLowerCase();

            if (!name || el.disabled || SKIPPED_NAMES.test(name) || isChoiceName(name) || SKIPPED_TYPES.indexOf(type) !== -1) {
                continue;
            }
            if (type === 'password' && !keepPasswords) {
                continue;
            }
            if (type === 'checkbox' || type === 'radio') {
                values.push({ name: name, kind: type, value: el.value, checked: el.checked });
            } else if (el.tagName === 'SELECT' && el.multiple) {
                var selected = [];
                for (var j = 0; j < el.options.length; j++) {
                    if (el.options[j].selected) {
                        selected.push(el.options[j].value);
                    }
                }
                values.push({ name: name, kind: 'multiple', values: selected });
            } else {
                values.push({ name: name, kind: 'value', value: el.value });
            }
        }

        return values;
    }

    /**
     * Puts snapshot values back into a form, by field name. A field the
     * form of the new type does not have is skipped. Returns how many
     * fields were set.
     */
    function restore(form, values) {
        var restored = 0;

        (values || []).forEach(function (item) {
            if (!item || typeof item.name !== 'string' || isChoiceName(item.name)) {
                return;
            }
            var matches = form.querySelectorAll('[name="' + cssEscape(item.name) + '"]');

            for (var i = 0; i < matches.length; i++) {
                var el = matches[i];
                var type = String(el.type || '').toLowerCase();

                if (el.disabled || SKIPPED_TYPES.indexOf(type) !== -1) {
                    continue;
                }
                if (item.kind === 'checkbox' || item.kind === 'radio') {
                    if (el.value === item.value) {
                        el.checked = !!item.checked;
                        restored++;
                    }
                } else if (item.kind === 'multiple' && el.tagName === 'SELECT') {
                    for (var j = 0; j < el.options.length; j++) {
                        el.options[j].selected = item.values.indexOf(el.options[j].value) !== -1;
                    }
                    restored++;
                } else if (item.kind === 'value' && type !== 'checkbox' && type !== 'radio') {
                    el.value = item.value;
                    restored++;
                }
            }
        });

        return restored;
    }

    /** The address of this page drawn for another type. */
    function urlWithType(href, code) {
        var url = new URL(href, window.location.href);
        url.searchParams.set(FIELD, code);
        url.hash = '';

        return url.toString();
    }

    function formKey(form) {
        return form.getAttribute('name') || form.id || '';
    }

    function storeForReload(form, values) {
        try {
            window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
                form: formKey(form),
                path: window.location.pathname,
                values: values,
                at: Date.now()
            }));
        } catch (e) {
            // Private mode or storage full: the reload just loses the typing.
        }
    }

    /** The values kept over a reload, once; null when none or too old. */
    function takeStored(now) {
        var stored;

        try {
            var raw = window.sessionStorage.getItem(STORAGE_KEY);

            // Removed before parsing: a broken entry must not come back.
            window.sessionStorage.removeItem(STORAGE_KEY);
            stored = JSON.parse(raw || 'null');
        } catch (e) {
            return null;
        }
        if (!stored || !Array.isArray(stored.values) || stored.path !== window.location.pathname) {
            return null;
        }
        if (typeof stored.at !== 'number' || (now || Date.now()) - stored.at > MAX_AGE_MS) {
            return null;
        }

        return stored;
    }

    function reloadWithType(form, code) {
        storeForReload(form, snapshot(form, false));
        window.location.href = urlWithType(window.location.href, code);
    }

    function findForm(doc, form) {
        var key = formKey(form);

        if (form.getAttribute('name')) {
            return doc.querySelector('form[name="' + cssEscape(key) + '"]');
        }

        return key ? doc.getElementById(key) : null;
    }

    function isCheckout(form) {
        return form.id === 'litecheckout_form'
            || $(form).closest('.litecheckout, #litecheckout_form, [data-ca-lite-checkout-element]').length > 0;
    }

    /** Whether a change of type draws the form again in place (else the page reloads). */
    function redrawsInPlace(form) {
        var config = _.euph_profile_types || {};

        return !config.reload && !isCheckout(form) && typeof window.fetch === 'function' && typeof window.DOMParser === 'function';
    }

    /* The forms of the types, by form: code => promise of its HTML. */
    var typeForms = new WeakMap();
    /* The last switch of each form: a later switch wins over a slower one. */
    var switches = new WeakMap();

    /**
     * The form as the page drawn for a type has it. Fetched once per form
     * and type and kept, so a switch to a type fetched ahead needs no wait;
     * a fetch that failed is tried again by the next switch.
     */
    function typeForm(form, code) {
        var forms = typeForms.get(form);

        if (!forms) {
            forms = {};
            typeForms.set(form, forms);
        }
        if (!forms[code]) {
            forms[code] = window.fetch(urlWithType(window.location.href, code), { credentials: 'same-origin' })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }

                    return response.text();
                })
                .then(function (html) {
                    var fresh = findForm(new window.DOMParser().parseFromString(html, 'text/html'), form);

                    if (!fresh) {
                        throw new Error('The form of the new type was not found');
                    }

                    return fresh.innerHTML;
                });
            forms[code].catch(function () {
                delete forms[code];
            });
        }

        return forms[code];
    }

    /** The codes the type choice of a form offers, and the one chosen. */
    function choiceOf(form) {
        var codes = [];
        var chosen = '';
        var inputs = form.querySelectorAll(CHOICE);

        for (var i = 0; i < inputs.length; i++) {
            var options = inputs[i].tagName === 'SELECT' ? inputs[i].options : [inputs[i]];

            for (var j = 0; j < options.length; j++) {
                if (options[j].value && codes.indexOf(options[j].value) === -1) {
                    codes.push(options[j].value);
                }
            }
            if (inputs[i].tagName === 'SELECT' || inputs[i].checked) {
                chosen = inputs[i].value;
            }
        }

        return { codes: codes, chosen: chosen };
    }

    /**
     * Fetches ahead the forms of the types the visitor may switch to, so the
     * switch is instant. Returns their codes.
     */
    function prefetchTypes(form) {
        if (!form || !redrawsInPlace(form)) {
            return [];
        }
        var choice = choiceOf(form);
        var others = choice.codes.filter(function (code) {
            return code !== choice.chosen;
        });

        others.forEach(function (code) {
            typeForm(form, code);
        });

        return others;
    }

    /** The forms with a type choice on the page: their other types, fetched ahead. */
    function prefetchPageTypes(doc) {
        var forms = [];
        var inputs = (doc || document).querySelectorAll(CHOICE);

        for (var i = 0; i < inputs.length; i++) {
            if (inputs[i].form && forms.indexOf(inputs[i].form) === -1) {
                forms.push(inputs[i].form);
            }
        }
        forms.forEach(prefetchTypes);

        return forms.length;
    }

    function whenIdle(callback) {
        if (typeof window.requestIdleCallback === 'function') {
            window.requestIdleCallback(callback, { timeout: 2000 });
        } else {
            window.setTimeout(callback, 300);
        }
    }

    /** Puts the form of the new type in place of this one; what was typed stays. */
    function redraw(form, code) {
        var url = urlWithType(window.location.href, code);
        var values = snapshot(form, true);
        var $form = $(form);
        var turn = (switches.get(form) || 0) + 1;

        switches.set(form, turn);
        $form.addClass('euph-pt-redrawing');

        return typeForm(form, code)
            .then(function (html) {
                if (switches.get(form) !== turn) {
                    // The visitor switched again: that switch draws the form.
                    return;
                }
                $form.html(html);
                restore(form, values);
                $form.removeClass('euph-pt-redrawing');
                if (typeof $.commonInit === 'function') {
                    $.commonInit($form);
                }
                if (window.history && typeof window.history.replaceState === 'function') {
                    window.history.replaceState(window.history.state, '', url);
                }
                // The type left is one the visitor may come back to.
                prefetchTypes(form);
            })
            .catch(function () {
                if (switches.get(form) !== turn) {
                    return;
                }
                $form.removeClass('euph-pt-redrawing');
                reloadWithType(form, code);
            });
    }

    /** The type changed: the form is drawn for it. Returns the redraw under way, else null. */
    function onChoiceChange(event) {
        var input = event.target;
        var form = input.form;

        if (!form || (input.type === 'radio' && !input.checked) || !input.value) {
            return null;
        }
        if (!redrawsInPlace(form)) {
            reloadWithType(form, input.value);

            return null;
        }

        return redraw(form, input.value);
    }

    /** After a reload for another type: the typing goes back into its form. */
    function restoreAfterReload() {
        var stored = takeStored();

        if (!stored) {
            return;
        }
        var form = document.querySelector('form[name="' + cssEscape(stored.form) + '"]') || document.getElementById(stored.form);

        if (form) {
            restore(form, stored.values);
        }
    }

    /* ── Tabs (pills): the type choice as a segmented control ─────────── */

    var PILLS_NO_FORM = 'euph_pt_pills_no_form';
    var TITLE_LABEL = /(^|\s)(ty-control-group__title|litecheckout__label|control-label)(\s|$)/;
    var pillOf = new WeakMap();
    var originalOf = new WeakMap();
    var pillGroups = 0;

    /** The <label> of an option, not the title of the field. */
    function optionLabel(radio) {
        var label = radio.id ? radio.ownerDocument.querySelector('label[for="' + cssEscape(radio.id) + '"]') : null;

        if (label && TITLE_LABEL.test(label.className)) {
            label = null;
        }

        return label || (radio.parentNode && radio.parentNode.closest ? radio.parentNode.closest('label') : null);
    }

    /** The text of an option: its label, else the text after its radio button. */
    function optionText(radio) {
        var label = optionLabel(radio);
        var text = '';

        if (label) {
            text = label.textContent || '';
        } else {
            for (var node = radio.nextSibling; node && node.nodeName !== 'INPUT' && node.nodeName !== 'BR'; node = node.nextSibling) {
                text += node.textContent || '';
            }
        }

        return text.trim() || radio.value;
    }

    /**
     * Hides one option as the theme drew it: its label, or the radio button
     * and the text after it (wrapped, as a text node takes no class). The
     * radio button stays in the form and keeps being sent. Returns the node
     * the option now starts with.
     */
    function hideOption(radio) {
        var label = optionLabel(radio);

        if (label && label.contains(radio)) {
            label.classList.add('euph-pt-pills-source');

            return label;
        }
        if (label) {
            radio.classList.add('euph-pt-pills-source');
            label.classList.add('euph-pt-pills-source');

            return radio;
        }
        var wrap = radio.ownerDocument.createElement('span');
        var nodes = [radio];

        for (var node = radio.nextSibling; node && node.nodeName !== 'INPUT'; node = node.nextSibling) {
            nodes.push(node);
            if (node.nodeName === 'BR') {
                break;
            }
        }
        wrap.className = 'euph-pt-pills-source';
        radio.parentNode.insertBefore(wrap, radio);
        nodes.forEach(function (item) {
            wrap.appendChild(item);
        });

        return wrap;
    }

    /**
     * Draws the radio buttons of the type choice as pills. The pills are
     * radio buttons too (arrow keys, screen readers) that belong to no form,
     * so they are never sent: picking one ticks the original radio button,
     * whose change redraws the form as usual. Returns how many choices were
     * drawn.
     */
    function applyPills(context) {
        var config = _.euph_profile_types || {};
        var root = context && context.jquery ? context[0] : (context || document);
        var groups = {};
        var names = [];
        var drawn = 0;

        if (!config.pills || !root || typeof root.querySelectorAll !== 'function') {
            return 0;
        }
        var radios = root.querySelectorAll('input[type="radio"][name$="[' + FIELD + ']"]');

        for (var i = 0; i < radios.length; i++) {
            var radio = radios[i];

            if (pillOf.has(radio)) {
                continue;
            }
            if (!groups[radio.name]) {
                groups[radio.name] = [];
                names.push(radio.name);
            }
            groups[radio.name].push(radio);
        }
        names.forEach(function (name) {
            var options = groups[name];

            if (options.length < 2) {
                return;
            }
            var doc = options[0].ownerDocument;
            var group = 'euph_pt_pills_' + (++pillGroups);
            var pills = doc.createElement('div');
            var first = null;

            pills.className = 'euph-pt-pills';
            pills.setAttribute('role', 'radiogroup');
            options.forEach(function (option) {
                var pill = doc.createElement('label');
                var input = doc.createElement('input');
                var text = doc.createElement('span');

                input.type = 'radio';
                input.className = 'euph-pt-pills__input';
                input.name = group;
                input.value = option.value;
                input.setAttribute('form', PILLS_NO_FORM);
                input.checked = option.checked;
                input.disabled = option.disabled;
                text.className = 'euph-pt-pills__text';
                text.textContent = optionText(option);
                pill.className = 'euph-pt-pills__pill';
                pill.appendChild(input);
                pill.appendChild(text);
                pills.appendChild(pill);
                pillOf.set(option, input);
                originalOf.set(input, option);

                var start = hideOption(option);

                first = first || start;
            });
            first.parentNode.insertBefore(pills, first);
            drawn++;
        });

        return drawn;
    }

    /** A pill was picked: its radio button takes the choice, and its change redraws the form. */
    function pickPill(pill) {
        var original = originalOf.get(pill);

        if (!pill.checked || !original || original.checked) {
            return false;
        }
        original.checked = true;
        original.dispatchEvent(new window.Event('change', { bubbles: true }));

        return true;
    }

    /** Pills follow their radio buttons (a restore after a reload, a script). */
    function syncPill(radio) {
        var pill = pillOf.get(radio);

        if (pill) {
            pill.checked = radio.checked;
        }
    }

    /* ── Admin panel ──────────────────────────────────────────────────── */

    /** "show" off unticks and locks "required". */
    function syncRequired(showBox) {
        var target = showBox.getAttribute('data-ca-euph-pt-required');
        var required = target ? showBox.ownerDocument.querySelector(target) : null;

        if (!required) {
            return;
        }
        if (showBox.checked) {
            required.disabled = false;
        } else {
            required.checked = false;
            required.disabled = true;
        }
    }

    /* ── Admin panel: CS-Cart's list of profile fields, by type ──────── */

    var LIST_VIEW_KEY = 'euph_profile_types.list_view.';
    var LIST_MODES = ['types', 'general', 'locked'];

    /** Set by the add-on's inline script (hooks/index/scripts.post.tpl). */
    function listConfig() {
        var data = _.euph_profile_types ? _.euph_profile_types.list : null;

        return data && typeof data === 'object' ? data : null;
    }

    function fieldCells(form, fieldId) {
        return form.querySelectorAll('.euph-pt-list-cell[data-ca-euph-pt-field="' + fieldId + '"]');
    }

    /**
     * What CS-Cart's general checkboxes of a field say now. A locked
     * "required" counts as unticked, as it is not sent; the checkboxes of a
     * field CS-Cart keeps shown (the e-mail) count as they are.
     */
    function listGeneralFlags(form, fieldId, asDrawn) {
        var flags = {};

        ['profile_show', 'profile_required', 'checkout_show', 'checkout_required', 'storefront_show'].forEach(function (flag) {
            var box = form.querySelector('input[type="checkbox"][name="fields_data[' + fieldId + '][' + flag + ']"]');

            flags[flag] = !!box && box.checked && (asDrawn || !box.disabled);
        });
        flags.registration_show = flags.profile_show;

        return flags;
    }

    /** The general line shows what applies where CS-Cart has no checkbox in the cell. */
    function refreshMirrors(form, fieldId) {
        var cells = fieldCells(form, fieldId);
        var locked = cells.length > 0 && cells[0].getAttribute('data-ca-euph-pt-mode') === 'locked';
        var flags = listGeneralFlags(form, fieldId, locked);

        for (var i = 0; i < cells.length; i++) {
            var mirrors = cells[i].querySelectorAll('.euph-pt-list-mirror');

            for (var j = 0; j < mirrors.length; j++) {
                mirrors[j].checked = !!flags[mirrors[j].getAttribute('data-ca-euph-pt-mirror')];
            }
        }
    }

    /**
     * Moves CS-Cart's own checkboxes of a cell into the general line of the
     * add-on, slot by slot, so that line lines up with the rows of the
     * types. They keep their names and ids and are sent as before.
     */
    function adoptStockCheckboxes(cell) {
        var td = cell.parentNode;
        var fieldId = cell.getAttribute('data-ca-euph-pt-field');
        var slots = cell.querySelectorAll('.euph-pt-list-row--general [data-ca-euph-pt-slot]');

        for (var i = 0; i < slots.length; i++) {
            var flag = slots[i].getAttribute('data-ca-euph-pt-slot');
            var stock = td.querySelectorAll('[name="fields_data[' + fieldId + '][' + flag + ']"]');
            var nodes = [];

            for (var j = 0; j < stock.length; j++) {
                var node = stock[j];

                while (node && node.parentNode !== td) {
                    node = node.parentNode;
                }
                if (node && node !== cell && nodes.indexOf(node) === -1) {
                    nodes.push(node);
                }
            }
            if (nodes.length === 0) {
                var mirror = cell.ownerDocument.createElement('input');

                mirror.type = 'checkbox';
                mirror.disabled = true;
                mirror.className = 'euph-pt-list-mirror';
                mirror.setAttribute('data-ca-euph-pt-mirror', flag);
                nodes.push(mirror);
            }
            for (var k = 0; k < nodes.length; k++) {
                slots[i].appendChild(nodes[k]);
            }
        }
    }

    /** How a field is drawn: its rows (types) or its general line (general, locked). */
    function setFieldMode(form, fieldId, mode) {
        var cells = fieldCells(form, fieldId);
        var storefront = form.querySelector('input[type="checkbox"][name="fields_data[' + fieldId + '][storefront_show]"]');

        for (var i = 0; i < cells.length; i++) {
            var row = cells[i].closest('tr');

            cells[i].setAttribute('data-ca-euph-pt-mode', mode);
            if (row) {
                LIST_MODES.forEach(function (each) {
                    row.classList.toggle('euph-pt-row--' + each, each === mode);
                });
            }
        }
        // A vendor field set per type: CS-Cart's "Show on storefront" column does not decide.
        if (storefront && !storefront.closest('.euph-pt-list-cell') && storefront.closest('td')) {
            storefront.closest('td').classList.toggle('euph-pt-list-inert', mode === 'types');
        }
    }

    /** The rows of a field take what its general checkboxes say. */
    function fillRowsFromGeneral(form, fieldId) {
        var flags = listGeneralFlags(form, fieldId);
        var cells = fieldCells(form, fieldId);

        for (var i = 0; i < cells.length; i++) {
            var boxes = cells[i].querySelectorAll('.euph-pt-list-row--type input[type="checkbox"][name]');

            for (var j = 0; j < boxes.length; j++) {
                var flag = flagOf(boxes[j].name);

                if (Object.prototype.hasOwnProperty.call(flags, flag)) {
                    boxes[j].checked = flags[flag];
                    boxes[j].disabled = false;
                }
            }
            var shows = cells[i].querySelectorAll('.euph-pt-list-row--type .cm-euph-pt-show');

            for (var k = 0; k < shows.length; k++) {
                syncRequired(shows[k]);
            }
        }
    }

    /** From now on the field is set per type: the save stores a row per type. */
    function markSplit(form, fieldId) {
        var split = form.querySelector('input[name="euph_pt_list[' + fieldId + '][split]"]');

        if (split) {
            split.value = 'Y';
        }
        setFieldMode(form, fieldId, 'types');
    }

    /** "Set per type": the rows open, filled from the general checkboxes. */
    function splitField(form, fieldId) {
        fillRowsFromGeneral(form, fieldId);
        markSplit(form, fieldId);
    }

    /** A checkbox of a type changed: a field still on its general checkboxes becomes set per type. */
    function onListTypeChange(box) {
        var cell = box.closest('.euph-pt-list-cell');
        var form = cell ? cell.closest('form') : null;

        if (!form) {
            return;
        }
        if (cell.getAttribute('data-ca-euph-pt-mode') === 'general') {
            markSplit(form, cell.getAttribute('data-ca-euph-pt-field'));
        }
        markUnseen(form);
    }

    /** In the view of one type, the fields that type does not see at all are greyed. */
    function markUnseen(form) {
        var view = form.getAttribute('data-ca-euph-pt-view') || 'all';
        var firsts = form.querySelectorAll('.euph-pt-list-cell[data-ca-euph-pt-first="Y"]');

        for (var i = 0; i < firsts.length; i++) {
            var row = firsts[i].closest('tr');
            var unseen = false;

            if (view !== 'all' && firsts[i].getAttribute('data-ca-euph-pt-mode') !== 'locked') {
                var boxes = form.querySelectorAll('.euph-pt-list-cell[data-ca-euph-pt-field="' + firsts[i].getAttribute('data-ca-euph-pt-field') + '"]'
                    + ' .euph-pt-list-row--type[data-ca-euph-pt-type="' + view + '"] input[type="checkbox"]');

                unseen = true;
                for (var j = 0; j < boxes.length; j++) {
                    var flag = boxes[j].getAttribute('data-ca-euph-pt-flag') || flagOf(boxes[j].name);

                    if (boxes[j].checked && /_show$/.test(flag)) {
                        unseen = false;
                    }
                }
            }
            if (row) {
                row.classList.toggle('euph-pt-row--unseen', unseen);
            }
        }
    }

    function listFlagsHead(doc, config, area, first) {
        var grid = doc.createElement('div');
        var flags = config.areas[area] || [];

        grid.className = 'euph-pt-list-row euph-pt-list-head';
        grid.setAttribute('data-ca-euph-pt-first', first ? 'Y' : 'N');
        grid.style.setProperty('--euph-pt-cols', String(flags.length));
        if (first) {
            var label = doc.createElement('span');

            label.className = 'euph-pt-list-label';
            label.textContent = config.labels.profile_type;
            grid.appendChild(label);
        }
        flags.forEach(function (flag) {
            var name = doc.createElement('span');

            name.textContent = config.labels.flags[flag] || flag;
            grid.appendChild(name);
        });

        return grid;
    }

    /** Under the title of each area of the list's header: what each column is. */
    function addListHeads(form, config) {
        var doc = form.ownerDocument;
        var head = form.querySelector('thead tr');

        if (!head) {
            return;
        }
        Object.keys(config.areas || {}).forEach(function (area) {
            var cell = form.querySelector('.euph-pt-list-cell[data-ca-euph-pt-area="' + area + '"]');
            var td = cell ? cell.parentNode : null;
            var th = td && typeof td.cellIndex === 'number' && td.cellIndex >= 0 ? head.cells[td.cellIndex] : null;
            var first = area === config.first_area;

            if (!th || th.querySelector('.euph-pt-list-head')) {
                return;
            }
            var title = doc.createElement('div');

            title.className = 'euph-pt-list-head__title';
            title.textContent = (th.firstChild && th.firstChild.nodeType === 3 ? th.firstChild.nodeValue : th.textContent).trim();
            th.textContent = '';
            th.appendChild(title);
            th.appendChild(listFlagsHead(doc, config, area, first));
            th.classList.add('euph-pt-list-col');
            th.classList.toggle('euph-pt-list-col--first', first);
        });
    }

    /**
     * At the top of each section of the list (Contact information, Billing
     * address, Shipping address...): what each column is, as under the
     * titles of the list, so a long list needs no scrolling back up.
     */
    function addSectionHeads(form, config) {
        var doc = form.ownerDocument;
        var bodies = form.querySelectorAll('tbody');
        var added = 0;

        for (var i = 0; i < bodies.length; i++) {
            var cellOfList = bodies[i].querySelector('.euph-pt-list-cell');
            var row = cellOfList ? cellOfList.closest('tr') : null;

            if (!row || row.parentNode !== bodies[i] || bodies[i].querySelector('.euph-pt-list-section-head')) {
                continue;
            }
            var head = doc.createElement('tr');

            head.className = 'euph-pt-list-section-head';
            for (var c = 0; c < row.cells.length; c++) {
                var source = row.cells[c];
                var td = doc.createElement('td');
                var listCell = source.querySelector('.euph-pt-list-cell');

                if (source.getAttribute('width')) {
                    td.setAttribute('width', source.getAttribute('width'));
                }
                if (source.colSpan > 1) {
                    td.colSpan = source.colSpan;
                }
                if (listCell) {
                    var area = listCell.getAttribute('data-ca-euph-pt-area');
                    var first = area === config.first_area;

                    td.className = 'euph-pt-list-col' + (first ? ' euph-pt-list-col--first' : '');
                    td.appendChild(listFlagsHead(doc, config, area, first));
                }
                head.appendChild(td);
            }
            bodies[i].insertBefore(head, bodies[i].firstChild);
            added++;
        }

        return added;
    }

    function listSwitcher(doc, config) {
        var bar = doc.createElement('div');
        var label = doc.createElement('span');
        var group = doc.createElement('div');
        var manage = doc.createElement('a');
        var views = [{ id: 'all', name: config.labels.all_types }].concat(config.types || []);

        bar.className = 'euph-pt-list-views';
        label.className = 'euph-pt-list-views__label';
        label.textContent = config.labels.see;
        group.className = 'euph-pt-list-views__group';
        group.setAttribute('role', 'group');
        views.forEach(function (view) {
            var button = doc.createElement('button');

            button.type = 'button';
            button.className = 'euph-pt-list-views__btn' + (view.status === 'D' ? ' euph-pt-list-views__btn--disabled' : '');
            button.setAttribute('data-ca-euph-pt-view', String(view.id));
            if (view.tone) {
                var dot = doc.createElement('span');

                dot.className = 'euph-pt-dot euph-pt-dot--' + view.tone;
                button.appendChild(dot);
            }
            button.appendChild(doc.createTextNode(view.name));
            group.appendChild(button);
        });
        manage.className = 'euph-pt-list-views__manage';
        manage.href = config.manage_url || '#';
        manage.textContent = config.labels.manage_types + ' ›';
        bar.appendChild(label);
        bar.appendChild(group);
        bar.appendChild(manage);

        return bar;
    }

    function storedListView(kind) {
        try {
            return window.localStorage.getItem(LIST_VIEW_KEY + kind) || '';
        } catch (e) {
            return '';
        }
    }

    /** All types side by side ("all") or one type (its id). */
    function setListView(form, view) {
        var known = form.querySelector('.euph-pt-list-views__btn[data-ca-euph-pt-view="' + cssEscape(String(view)) + '"]');

        view = known ? String(view) : 'all';
        var one = view !== 'all';

        form.classList.toggle('euph-pt-list--all', !one);
        form.classList.toggle('euph-pt-list--one', one);
        form.setAttribute('data-ca-euph-pt-view', view);

        var rows = form.querySelectorAll('.euph-pt-list-row--type');

        for (var i = 0; i < rows.length; i++) {
            rows[i].classList.toggle('is-current', one && rows[i].getAttribute('data-ca-euph-pt-type') === view);
        }
        if (one) {
            // A field on its general checkboxes shows them in the row of the type.
            var general = form.querySelectorAll('.euph-pt-list-cell[data-ca-euph-pt-mode="general"][data-ca-euph-pt-first="Y"]');

            for (var j = 0; j < general.length; j++) {
                fillRowsFromGeneral(form, general[j].getAttribute('data-ca-euph-pt-field'));
            }
        }
        markUnseen(form);
        var buttons = form.querySelectorAll('.euph-pt-list-views__btn');

        for (var k = 0; k < buttons.length; k++) {
            var on = buttons[k].getAttribute('data-ca-euph-pt-view') === view;

            buttons[k].classList.toggle('is-active', on);
            buttons[k].setAttribute('aria-pressed', on ? 'true' : 'false');
        }
        try {
            window.localStorage.setItem(LIST_VIEW_KEY + (form.getAttribute('data-ca-euph-pt-kind') || 'C'), view);
        } catch (e) {
            // The view is just not remembered.
        }

        return view;
    }

    /**
     * Sets up CS-Cart's list of profile fields drawn with the per-type
     * checkboxes (hooks/profile_fields/field_settings.post.tpl). Without
     * this script the list stays CS-Cart's own. Returns the form, or null.
     */
    function initFieldsList(doc) {
        doc = doc || document;
        var config = listConfig();
        var first = doc.querySelector('.euph-pt-list-cell');
        var form = first ? first.closest('form') : null;

        if (!config || !form || form.classList.contains('euph-pt-list')) {
            return null;
        }
        var cells = form.querySelectorAll('.euph-pt-list-cell');
        var seen = {};

        for (var i = 0; i < cells.length; i++) {
            var cell = cells[i];
            var fieldId = cell.getAttribute('data-ca-euph-pt-field');
            var td = cell.parentNode;

            adoptStockCheckboxes(cell);
            if (td && td.classList) {
                td.classList.add('euph-pt-list-col');
                td.classList.toggle('euph-pt-list-col--first', cell.getAttribute('data-ca-euph-pt-first') === 'Y');
            }
            if (!seen[fieldId]) {
                seen[fieldId] = true;
                setFieldMode(form, fieldId, cell.getAttribute('data-ca-euph-pt-mode'));
                refreshMirrors(form, fieldId);
            }
        }
        // CS-Cart draws each section as its own table: a Type cell may wrap
        // its tag rather than widen one table and shift its columns.
        var tags = form.querySelectorAll('.euph-pt-list-type-tag');

        for (var t = 0; t < tags.length; t++) {
            var typeCell = tags[t].closest('td');

            if (typeCell) {
                typeCell.classList.add('euph-pt-list-type-cell');
            }
        }
        addListHeads(form, config);
        addSectionHeads(form, config);
        form.insertBefore(listSwitcher(doc, config), form.firstChild);
        form.setAttribute('data-ca-euph-pt-kind', config.kind || 'C');
        form.classList.add('euph-pt-list');
        setListView(form, config.view || storedListView(config.kind || 'C') || 'all');

        return form;
    }

    /** The general checkboxes of a field card, as the per-type checkboxes read them. */
    function generalFlags($form) {
        var flags = {};

        ['profile_show', 'profile_required', 'checkout_show', 'checkout_required', 'storefront_show'].forEach(function (flag) {
            var $box = $form.find('input[type="checkbox"][name="field_data[' + flag + ']"]');

            flags[flag] = $box.length > 0 && $box.prop('checked');
        });
        flags.registration_show = flags.profile_show;

        return flags;
    }

    function flagOf(name) {
        var match = String(name || '').match(/\[([a-z_]+)]$/);

        return match ? match[1] : '';
    }

    $(document).on('change', CHOICE, onChoiceChange);

    $(document).on('change', 'input[type="radio"][name$="[' + FIELD + ']"]', function () {
        syncPill(this);
    });

    $(document).on('change', '.euph-pt-pills__input', function () {
        pickPill(this);
    });

    $(document).on('change', '.cm-euph-pt-show', function () {
        syncRequired(this);
    });

    $(document).on('click', '.cm-euph-pt-fill-general', function () {
        var $scope = $($(this).data('caEuphPtScope'));
        var flags = generalFlags($scope.closest('form'));

        $scope.find('input[type="checkbox"]').each(function () {
            var flag = flagOf(this.name);

            if (Object.prototype.hasOwnProperty.call(flags, flag)) {
                this.checked = flags[flag];
            }
        });
        $scope.find('.cm-euph-pt-show').each(function () {
            syncRequired(this);
        });
    });

    $(document).on('click', '.cm-euph-pt-clear', function () {
        var $scope = $($(this).data('caEuphPtScope'));

        $scope.find('input[type="checkbox"]').prop('checked', false);
        $scope.find('.cm-euph-pt-show').each(function () {
            syncRequired(this);
        });
    });

    $(document).on('click', '.cm-euph-pt-split-field', function (event) {
        var form = this.closest('form');

        event.preventDefault();
        if (form) {
            splitField(form, this.getAttribute('data-ca-euph-pt-field'));
        }
    });

    $(document).on('change', '.euph-pt-list-row--type input[type="checkbox"]', function () {
        onListTypeChange(this);
    });

    $(document).on('change', 'input[type="checkbox"][name^="fields_data["]', function () {
        var form = this.closest('form');
        var match = this.name.match(/^fields_data\[(\d+)]/);

        if (form && match) {
            refreshMirrors(form, match[1]);
        }
    });

    $(document).on('click', '.euph-pt-list-views__btn', function () {
        var form = this.closest('form');

        if (form) {
            setListView(form, this.getAttribute('data-ca-euph-pt-view'));
        }
    });

    $(document).on('click', '.cm-euph-pt-copy', function () {
        var $input = $($(this).data('caEuphPtTarget'));
        var text = String($input.val() || '');
        var copied = function () {
            if ($.ceNotification) {
                $.ceNotification('show', { type: 'N', title: '', message: _.tr('euph_profile_types.copied') || 'Copied' });
            }
        };

        if (window.navigator.clipboard && typeof window.navigator.clipboard.writeText === 'function') {
            window.navigator.clipboard.writeText(text).then(copied, function () {
                $input.trigger('select');
            });
        } else {
            $input.trigger('select');
            try {
                document.execCommand('copy');
                copied();
            } catch (e) {
                // The text stays selected for Ctrl+C.
            }
        }
    });

    if ($.ceEvent) {
        $.ceEvent('on', 'ce.commoninit', function (context) {
            applyPills(context);
        });
    }
    $(function () {
        restoreAfterReload();
        initFieldsList(document);
        whenIdle(function () {
            prefetchPageTypes(document);
        });
    });

    window.EuphProfileTypes = {
        snapshot: snapshot,
        restore: restore,
        urlWithType: urlWithType,
        storeForReload: storeForReload,
        takeStored: takeStored,
        generalFlags: generalFlags,
        flagOf: flagOf,
        isChoiceName: isChoiceName,
        redraw: redraw,
        prefetchTypes: prefetchTypes,
        prefetchPageTypes: prefetchPageTypes,
        applyPills: applyPills,
        initFieldsList: initFieldsList,
        setListView: setListView,
        splitField: splitField,
        onListTypeChange: onListTypeChange,
        listGeneralFlags: listGeneralFlags,
        syncRequired: syncRequired,
        pickPill: pickPill,
        syncPill: syncPill,
        optionText: optionText
    };
}(Tygh, Tygh.$));

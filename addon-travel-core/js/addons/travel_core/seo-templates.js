/**
 * Travel Core — SEO Templates page (shared by every provider add-on).
 *
 * Wires the page components/seo_templates_page.tpl renders:
 *
 *   - Click to insert: a placeholder button in the sidebar inserts
 *     {{key}} at the caret of the field last edited (or replaces the
 *     selected text), then returns focus there.
 *   - Modifiers: a modifier button sets the modifier of the placeholder
 *     under the caret ({{name}} → {{name|upper}}); the engine allows one
 *     modifier per placeholder, so a second one replaces the first.
 *   - Language tabs (nav-tabs), the per-field "Apply" ticks (one named
 *     checkbox per field; the copies in the other tabs mirror it),
 *     "Restore default", the length counters, the checks for unknown
 *     placeholders and the live preview for a sample hotel.
 *
 * The preview renders with the same rules as fn_travel_core_render_seo_template
 * (functions/seo.php); tests/js/travel-core-seo-templates.test.mjs pins both.
 */
(function () {
    'use strict';

    var TOKEN = /\{\{([a-z_][a-z0-9_]*)(?:\|([a-z_]+))?}}/g;

    function slugify(value) {
        return String(value).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
            .replace(/[^a-z0-9-]+/g, '-').replace(/-{2,}/g, '-').replace(/^-+|-+$/g, '');
    }

    function applyModifier(value, modifier) {
        switch (modifier) {
            case 'lower': return value.toLowerCase();
            case 'upper': return value.toUpperCase();
            case 'title': return value.toLowerCase().replace(/(^|[\s-])(\S)/g, function (all, sep, ch) { return sep + ch.toUpperCase(); });
            case 'capitalize': return value.charAt(0).toUpperCase() + value.slice(1);
            case 'trim': return value.trim();
            case 'slug': return slugify(value);
            case 'first': return value.charAt(0);
            case 'last': return value.charAt(value.length - 1);
            case 'abs': return String(Math.abs(parseFloat(value) || 0));
            case 'round': return String(Math.round(parseFloat(value) || 0));
            case 'strip_tags': return value.replace(/<[^>]*>/g, '');
            default: return value;
        }
    }

    /** Same steps as fn_travel_core_render_seo_template(). */
    function render(pattern, placeholders) {
        if (!pattern) {
            return '';
        }
        var data = placeholders || {};
        var out = String(pattern).replace(TOKEN, function (all, key, modifier) {
            var value = data[key];
            if (Array.isArray(value)) {
                value = value.map(function (item) { return String(item).trim(); })
                    .filter(Boolean).slice(0, 3).join(', ');
            }
            value = value === undefined || value === null ? '' : String(value);
            return modifier ? applyModifier(value, modifier) : value;
        });
        out = out.replace(/,\s*,/g, ',')
            .replace(/\s*-\s*,/g, ',')
            .replace(/,\s*-\s*/g, ' - ')
            .replace(/^\s*[-,]\s*/, '')
            .replace(/\s*[-,]\s*$/, '')
            .replace(/\(\s*\)/g, '')
            .replace(/\s*-\s*-\s*/g, ' - ');
        return out.replace(/\s{2,}/g, ' ').trim();
    }

    /** Problems the engine would silently swallow; mirrors fn_travel_core_seo_template_problems(). */
    function problems(pattern, keys, modifiers, labels) {
        var list = [];
        var seen = {};
        var text = String(pattern || '');
        var match;
        TOKEN.lastIndex = 0;
        while ((match = TOKEN.exec(text)) !== null) {
            if (keys.indexOf(match[1]) < 0 && !seen['p' + match[1]]) {
                seen['p' + match[1]] = true;
                list.push(fill(labels.unknown_placeholder, '{{' + match[1] + '}}'));
            }
            if (match[2] && modifiers.indexOf(match[2]) < 0 && !seen['m' + match[2]]) {
                seen['m' + match[2]] = true;
                list.push(fill(labels.unknown_modifier, '|' + match[2]));
            }
        }
        if (/\{\{[^{}]*\|[^{}]*\|[^{}]*}}/.test(text)) {
            list.push(labels.one_modifier);
        }
        if ((text.match(/\{\{/g) || []).length !== (text.match(/}}/g) || []).length) {
            list.push(labels.unbalanced);
        }
        return list;
    }

    function fill(label, value) {
        return String(label || '').replace('[value]', value);
    }

    // The page on screen. Admin AJAX navigation re-runs init() for each new
    // page; the document-level listeners below are bound once and act on it.
    var current = null;

    // The sidebar sits outside the form. mousedown keeps the field's caret
    // and selection when a button is pressed with the mouse.
    document.addEventListener('mousedown', function (ev) {
        if (current && ev.target.closest && ev.target.closest('[data-seo-placeholder], [data-seo-modifier]')) {
            ev.preventDefault();
        }
    });
    document.addEventListener('click', function (ev) {
        if (!current || !ev.target.closest) {
            return;
        }
        var ph = ev.target.closest('[data-seo-placeholder]');
        if (ph) {
            ev.preventDefault();
            current.insertPlaceholder(ph.getAttribute('data-seo-placeholder'));
            return;
        }
        var mod = ev.target.closest('[data-seo-modifier]');
        if (mod) {
            ev.preventDefault();
            current.setModifier(mod.getAttribute('data-seo-modifier'));
        }
    });

    function init() {
        var form = document.querySelector('form[data-seo-config]');
        if (!form || form.__seoInit) {
            return;
        }
        form.__seoInit = true;

        var config = {};
        try {
            config = JSON.parse(form.getAttribute('data-seo-config') || '{}');
        } catch (e) {
            config = {};
        }
        var keys = config.keys || [];
        var modifiers = config.modifiers || [];
        var labels = config.labels || {};
        var sample = config.sample || null;
        var target = document.querySelector('[data-seo-target]');
        var active = null;

        function fields() {
            return Array.prototype.slice.call(form.querySelectorAll('[data-seo-key]'));
        }

        function openLang() {
            var pane = form.querySelector('[data-seo-lang-pane]:not([hidden])');
            return pane ? pane.getAttribute('data-seo-lang-pane') : '';
        }

        function fieldLabel(el) {
            var group = el.closest('[data-seo-field]');
            var label = group ? group.querySelector('.control-label') : null;
            return label ? label.textContent.trim() : el.getAttribute('data-seo-key');
        }

        function say(text, isNote) {
            if (!target) {
                return;
            }
            target.textContent = text;
            target.classList.toggle('text-warning', !!isNote);
        }

        function sayTarget() {
            if (!active) {
                say(labels.target_none || '');
                return;
            }
            var pane = active.closest('[data-seo-lang-pane]');
            say(fill(labels.target_into, fieldLabel(active)) + (pane ? ' · ' + pane.getAttribute('data-seo-lang-name') : ''));
        }

        function toggleFor(key) {
            return form.querySelector('input[name="seo[' + key + ']"]');
        }

        function isOn(field) {
            var group = field.closest('[data-seo-field]');
            var toggleKey = group ? group.getAttribute('data-seo-toggle-key') : '';
            var named = toggleKey ? toggleFor(toggleKey) : null;
            return !named || named.checked;
        }

        function refreshField(field) {
            var group = field.closest('[data-seo-field]');
            if (!group) {
                return;
            }
            group.classList.toggle('seo-field-off', !isOn(field));

            var counter = group.querySelector('[data-seo-counter]');
            var ideal = parseInt(field.getAttribute('data-seo-ideal') || '0', 10);
            var max = parseInt(field.getAttribute('data-seo-max') || '0', 10);
            if (counter) {
                if (sample && ideal > 0 && isOn(field)) {
                    var length = render(field.value, sample).length;
                    counter.className = 'label ' + (length <= ideal ? 'label-success' : (max && length > max ? 'label-important' : 'label-warning'));
                    counter.textContent = length + ' / ' + ideal;
                    counter.title = fill(labels.counter_title, String(ideal));
                    counter.hidden = false;
                } else {
                    counter.hidden = true;
                }
            }

            var box = group.querySelector('[data-seo-problems]');
            if (box) {
                box.textContent = '';
                problems(field.value, keys, modifiers, labels).forEach(function (text) {
                    var badge = document.createElement('span');
                    badge.className = 'label label-important';
                    badge.textContent = text;
                    box.appendChild(badge);
                    box.appendChild(document.createTextNode(' '));
                });
            }
        }

        function valueOf(pane, key) {
            var field = pane.querySelector('[data-seo-key="' + key + '"]');
            return field && isOn(field) ? field.value : null;
        }

        function refreshPreview(pane) {
            var preview = pane.querySelector('[data-seo-preview]');
            if (!preview || !sample) {
                return;
            }
            var keep = labels.preview_kept || '';
            var title = valueOf(pane, 'seo_page_title');
            var slug = valueOf(pane, 'seo_name_slug');
            var meta = valueOf(pane, 'seo_meta_description');
            var name = valueOf(pane, 'seo_product_name');
            var set = function (sel, text) {
                var el = preview.querySelector(sel);
                if (el) {
                    el.textContent = text;
                }
            };
            var titleText = title === null ? keep : render(title, sample);
            var metaText = meta === null ? keep : render(meta, sample);
            set('[data-seo-preview-url]', (config.store_url || '') + ' › ' + (slug === null ? keep : slugify(render(slug, sample)) + '.html'));
            set('[data-seo-preview-title]', titleText.length > 70 ? titleText.slice(0, 67) + '…' : titleText);
            set('[data-seo-preview-desc]', metaText.length > 200 ? metaText.slice(0, 197) + '…' : metaText);
            set('[data-seo-preview-name]', name === null ? keep : render(name, sample));
        }

        function refreshAll() {
            fields().forEach(refreshField);
            Array.prototype.forEach.call(form.querySelectorAll('[data-seo-lang-pane]'), refreshPreview);
        }

        function edited(field) {
            refreshField(field);
            var pane = field.closest('[data-seo-lang-pane]');
            if (pane) {
                refreshPreview(pane);
            }
        }

        function fireInput(field) {
            var ev;
            try {
                ev = new Event('input', { bubbles: true });
            } catch (e) {
                ev = document.createEvent('Event');
                ev.initEvent('input', true, true);
            }
            field.dispatchEvent(ev);
        }

        function currentField() {
            if (active && form.contains(active) && !active.closest('[hidden]')) {
                return active;
            }
            var pane = form.querySelector('[data-seo-lang-pane]:not([hidden])') || form;
            return pane.querySelector('[data-seo-key]');
        }

        function insertPlaceholder(key) {
            var field = currentField();
            if (!field) {
                return;
            }
            var chose = !!active && field === active;
            var text = '{{' + key + '}}';
            // No field chosen yet: append, rather than trust a caret the admin never placed.
            var start = chose && typeof field.selectionStart === 'number' ? field.selectionStart : field.value.length;
            var end = chose && typeof field.selectionEnd === 'number' ? field.selectionEnd : start;
            field.value = field.value.slice(0, start) + text + field.value.slice(end);
            field.focus();
            var caret = start + text.length;
            field.setSelectionRange(caret, caret);
            active = field;
            fireInput(field);
            if (chose) {
                sayTarget();
            } else {
                say(fill(labels.target_fallback, fieldLabel(field)), true);
            }
        }

        function setModifier(modifier) {
            var field = active && form.contains(active) ? active : null;
            if (!field) {
                say(labels.modifier_needs_field || '', true);
                return;
            }
            var caret = field.selectionStart;
            var re = /\{\{([a-z_][a-z0-9_]*)(\|[a-z_]+)?}}/g;
            var hit = null;
            var match;
            while ((match = re.exec(field.value)) !== null) {
                if (caret >= match.index && caret <= match.index + match[0].length) {
                    hit = match;
                    break;
                }
            }
            field.focus();
            if (!hit) {
                say(labels.modifier_needs_token || '', true);
                return;
            }
            var text = '{{' + hit[1] + '|' + modifier + '}}';
            field.value = field.value.slice(0, hit.index) + text + field.value.slice(hit.index + hit[0].length);
            var after = hit.index + text.length;
            field.setSelectionRange(after, after);
            fireInput(field);
            if (hit[2] && hit[2] !== '|' + modifier) {
                say(fill(labels.modifier_replaced, hit[2]), true);
            } else {
                sayTarget();
            }
        }

        function showLang(lc) {
            Array.prototype.forEach.call(form.querySelectorAll('[data-seo-lang-tab]'), function (li) {
                li.classList.toggle('active', li.getAttribute('data-seo-lang-tab') === lc);
            });
            Array.prototype.forEach.call(form.querySelectorAll('[data-seo-lang-pane]'), function (pane) {
                pane.hidden = pane.getAttribute('data-seo-lang-pane') !== lc;
            });
            if (active && active.closest('[hidden]')) {
                active = null;
            }
            sayTarget();
        }

        form.addEventListener('focusin', function (ev) {
            var el = ev.target;
            if (el && el.hasAttribute && el.hasAttribute('data-seo-key')) {
                active = el;
                sayTarget();
            }
        });
        form.addEventListener('input', function (ev) {
            if (ev.target && ev.target.hasAttribute && ev.target.hasAttribute('data-seo-key')) {
                edited(ev.target);
            }
        });
        form.addEventListener('change', function (ev) {
            var cb = ev.target;
            var key = cb && cb.getAttribute ? cb.getAttribute('data-seo-toggle') : null;
            if (!key) {
                return;
            }
            // Every language shows the same tick: mirror it onto the others.
            Array.prototype.forEach.call(form.querySelectorAll('[data-seo-toggle="' + key + '"]'), function (other) {
                other.checked = cb.checked;
            });
            refreshAll();
        });
        form.addEventListener('click', function (ev) {
            var tab = ev.target.closest ? ev.target.closest('[data-seo-lang-tab] a') : null;
            if (tab) {
                ev.preventDefault();
                showLang(tab.parentNode.getAttribute('data-seo-lang-tab'));
                return;
            }
            var restore = ev.target.closest ? ev.target.closest('[data-seo-restore]') : null;
            if (restore) {
                ev.preventDefault();
                var group = restore.closest('[data-seo-field]');
                var field = group ? group.querySelector('[data-seo-key]') : null;
                if (field) {
                    field.value = field.getAttribute('data-seo-default') || '';
                    field.focus();
                    active = field;
                    fireInput(field);
                    say(fill(labels.restored, fieldLabel(field)), true);
                }
            }
        });

        current = { insertPlaceholder: insertPlaceholder, setModifier: setModifier };

        showLang(openLang() || (form.querySelector('[data-seo-lang-tab]') || { getAttribute: function () { return ''; } }).getAttribute('data-seo-lang-tab'));
        refreshAll();
    }

    window.TravelCoreSeoTemplates = { render: render, slugify: slugify, problems: problems };

    // Admin pages are reached by AJAX navigation, which never fires
    // DOMContentLoaded again: CS-Cart's ce.commoninit runs after every load.
    var Tygh = window.Tygh;
    if (Tygh && Tygh.$ && typeof Tygh.$.ceEvent === 'function') {
        Tygh.$.ceEvent('on', 'ce.commoninit', function () { init(); });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

/* Queue images for a scope (components/image_scope.tpl): Copy URL and Copy
 * CLI command copy Queue images' command with the scope's parameters, so a
 * scope can be scheduled as well as run.
 *
 * The parameters are the ones CronPlan::runParams() reads from the same
 * fields for Run, by the same rules: whitelist=strict, a two-letter country,
 * a destination or region ID, force=Y, a limit. The real commands (with the
 * cron key) ride only in the buttons' data-* and are never shown; this keeps
 * each button's data-copy up to date as the form changes, and Travel Core's
 * cron-jobs.js copies whatever it holds when the button is pressed. */
(function () {
    'use strict';

    var POSITIVE = /^[1-9]\d{0,9}$/;

    function value(form, name) {
        var field = form.elements[name];
        return field && typeof field.value === 'string' ? field.value.trim() : '';
    }

    // [name, value] pairs, in CronPlan::runParams()'s order.
    function params(form) {
        var out = [];
        var scope = value(form, 'scope');
        var id = value(form, 'scope_id');
        if (scope === 'strict') {
            out.push(['whitelist', 'strict']);
        } else if (scope === 'country') {
            var country = value(form, 'country').toUpperCase();
            if (/^[A-Z]{2}$/.test(country)) { out.push(['country', country]); }
        } else if (scope === 'destination' && POSITIVE.test(id)) {
            out.push(['destination_id', id]);
        } else if (scope === 'region' && POSITIVE.test(id)) {
            out.push(['region_id', id]);
        }
        var force = form.elements.force;
        if (force && force.checked && force.value === 'Y') { out.push(['force', 'Y']); }
        var limit = value(form, 'limit');
        if (POSITIVE.test(limit)) { out.push(['limit', limit]); }
        return out;
    }

    // A URL takes &name=value (encoded), a CLI line name=value after a space.
    function command(base, format, list) {
        return list.reduce(function (text, pair) {
            return format === 'url'
                ? text + '&' + encodeURIComponent(pair[0]) + '=' + encodeURIComponent(pair[1])
                : text + ' ' + pair[0] + '=' + pair[1];
        }, base);
    }

    function update(form) {
        var list = params(form);
        Array.prototype.slice.call(form.querySelectorAll('[data-sphinx-scope-base]')).forEach(function (btn) {
            btn.setAttribute('data-copy', command(btn.getAttribute('data-sphinx-scope-base') || '', btn.getAttribute('data-sphinx-scope-format'), list));
        });
    }

    function init() {
        Array.prototype.slice.call(document.querySelectorAll('form[data-sphinx-scope]')).forEach(function (form) {
            if (form.__sphinxScope) { return; }
            form.__sphinxScope = true;
            form.addEventListener('input', function () { update(form); });
            form.addEventListener('change', function () { update(form); });
            update(form);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

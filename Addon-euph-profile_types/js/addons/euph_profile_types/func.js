/**
 * Profile types — storefront and admin panel.
 *
 * The type choice is a profile field CS-Cart draws itself
 * (user_data[euph_profile_type], company_data[euph_profile_type]). When it
 * changes, the form is drawn again for the new type:
 *   - registration and the customer account: the page of the new type is
 *     fetched and its form takes the place of this one; what was typed stays;
 *   - the checkout, the admin panel, and with the setting "Reload the page
 *     when the type is changed" (a captcha or another script that does not
 *     survive the redraw): the page reloads with ?euph_profile_type=CODE and
 *     what was typed is put back after the reload (passwords excepted).
 *
 * Admin panel: the per-type checkboxes of the profile fields ("show" off
 * unticks and locks "required"), "Same as the general checkboxes", "Clear",
 * and the copy buttons of the registration links.
 *
 * Russian Payments, "Issue an invoice": the details the profile already
 * holds are filled in and their inputs hidden ("Do not ask again for invoice
 * details taken from the profile").
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

    /** Fetches the page of the new type and puts its form in place of this one. */
    function redraw(form, code) {
        var url = urlWithType(window.location.href, code);
        var values = snapshot(form, true);
        var $form = $(form);

        $form.addClass('euph-pt-redrawing');

        return window.fetch(url, { credentials: 'same-origin' })
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
                $form.html(fresh.innerHTML);
                restore(form, values);
                $form.removeClass('euph-pt-redrawing');
                if (typeof $.commonInit === 'function') {
                    $.commonInit($form);
                }
                if (window.history && typeof window.history.replaceState === 'function') {
                    window.history.replaceState(window.history.state, '', url);
                }
            })
            .catch(function () {
                $form.removeClass('euph-pt-redrawing');
                reloadWithType(form, code);
            });
    }

    function isCheckout(form) {
        return form.id === 'litecheckout_form'
            || $(form).closest('.litecheckout, #litecheckout_form, [data-ca-lite-checkout-element]').length > 0;
    }

    function onChoiceChange(event) {
        var input = event.target;
        var form = input.form;
        var config = _.euph_profile_types || {};

        if (!form || (input.type === 'radio' && !input.checked) || !input.value) {
            return;
        }
        if (config.reload || isCheckout(form) || typeof window.fetch !== 'function' || typeof window.DOMParser !== 'function') {
            reloadWithType(form, input.value);
        } else {
            redraw(form, input.value);
        }
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

    /* ── Russian Payments: invoice details taken from the profile ─────── */

    function invoiceDetails() {
        var source = document.getElementById('euph_pt_invoice_prefill');

        if (!source) {
            return null;
        }
        try {
            return JSON.parse(source.textContent || '{}');
        } catch (e) {
            return null;
        }
    }

    function applyInvoiceDetails(context) {
        var details = invoiceDetails();

        if (!details) {
            return;
        }
        var $context = $(context || document);
        var taken = [];

        Object.keys(details).forEach(function (key) {
            var $input = $context.find('[name="payment_info[' + cssEscape(key) + ']"]');

            if (!$input.length || $input.hasClass('euph-pt-prefilled-input')) {
                return;
            }
            $input.val(details[key]).addClass('euph-pt-prefilled-input');
            var $group = $input.closest('.ty-control-group, .litecheckout__field, .control-group');

            ($group.length ? $group : $input).addClass('euph-pt-prefilled');
            var label = $.trim(($group.find('label').first().text() || key).replace(/[:*]\s*$/, ''));

            taken.push(label);
        });

        if (taken.length) {
            var $summary = $('<p class="euph-pt-prefilled-summary"></p>');

            $summary.text((_.tr('euph_profile_types.invoice_details_taken') || 'Taken from your profile:') + ' ' + taken.join(', '));
            $context.find('.euph-pt-prefilled').first().before($summary);
        }
    }

    /* ── Admin panel ──────────────────────────────────────────────────── */

    function syncRequired(showBox) {
        var target = $(showBox).data('caEuphPtRequired');

        if (!target) {
            return;
        }
        var $required = $(target);

        if (showBox.checked) {
            $required.prop('disabled', false);
        } else {
            $required.prop('checked', false).prop('disabled', true);
        }
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
        var match = String(name || '').match(/\[([a-z_]+)\]$/);

        return match ? match[1] : '';
    }

    $(document).on('change', CHOICE, onChoiceChange);

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
            applyInvoiceDetails(context);
        });
    }
    $(function () {
        restoreAfterReload();
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
        applyInvoiceDetails: applyInvoiceDetails
    };
}(Tygh, Tygh.$));

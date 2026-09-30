// NETOPIA Payments — payment method settings screen.
//
// Drives views/payments/components/cc_processors/netopia_payments.tpl: the
// four tabs, the Sandbox/Live switch (Live asks first), the per-mode API key
// and POS signature, show/hide for the secrets, "Check settings", the
// installments pair, the order description preview, the status table
// (changed rows, reset) and the key cards.
//
// The settings arrive in a dialog loaded by AJAX, so every screen is set up
// on ce.commoninit as well as on load, once per screen (data-np-ready).
// Every field stays in the one form; tabs only hide, so Save sends them all.
(function () {
    function all(root, sel) {
        return Array.prototype.slice.call(root.querySelectorAll(sel));
    }

    function txt(root, name) {
        return root.getAttribute('data-txt-' + name) || '';
    }

    // ── Tabs ──────────────────────────────────────────────────────────
    function selectTab(root, name) {
        all(root, '[data-np-tab]').forEach(function (tab) {
            tab.setAttribute('aria-selected', tab.getAttribute('data-np-tab') === name ? 'true' : 'false');
        });
        all(root, '[data-np-panel]').forEach(function (panel) {
            panel.classList.toggle('is-active', panel.getAttribute('data-np-panel') === name);
        });
    }

    function setupTabs(root) {
        root.classList.add('netopia-cfg--tabs');
        var tabs = all(root, '[data-np-tab]');
        tabs.forEach(function (tab, i) {
            tab.addEventListener('click', function () {
                selectTab(root, tab.getAttribute('data-np-tab'));
            });
            tab.addEventListener('keydown', function (e) {
                var step = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
                if (step === 0) {
                    return;
                }
                e.preventDefault();
                var next = tabs[(i + step + tabs.length) % tabs.length];
                next.focus();
                selectTab(root, next.getAttribute('data-np-tab'));
            });
        });
    }

    // ── Mode ──────────────────────────────────────────────────────────
    function modeInput(root, mode) {
        return root.querySelector('input[type="radio"][value="' + mode + '"]');
    }

    function applyMode(root, mode) {
        root.setAttribute('data-mode', mode);
        // Mirrors :has(input:checked) for browsers without :has().
        ['sandbox', 'live'].forEach(function (m) {
            var input = modeInput(root, m);
            var label = input ? input.closest('label') : null;
            if (label) {
                label.classList.toggle('is-checked', m === mode);
            }
        });
        all(root, '[data-np-keys-intro]').forEach(function (el) {
            el.hidden = el.getAttribute('data-np-keys-intro') !== mode;
        });
        refreshKeysDot(root);
    }

    function setupMode(root) {
        var confirmBox = root.querySelector('[data-np-live-confirm]');
        var sandbox = modeInput(root, 'sandbox');
        var live = modeInput(root, 'live');
        if (!sandbox || !live) {
            return;
        }

        live.addEventListener('change', function () {
            if (!live.checked || root.getAttribute('data-mode') === 'live') {
                return;
            }
            // Not live until the admin says so: keep Sandbox checked meanwhile.
            sandbox.checked = true;
            var liveKey = root.querySelector('#netopia_live_api_key');
            var credsMissing = root.querySelector('[data-np-live-creds-missing]');
            if (credsMissing) {
                credsMissing.hidden = !!(liveKey && liveKey.value.trim() !== '');
            }
            if (confirmBox) {
                confirmBox.hidden = false;
            } else {
                live.checked = true;
                applyMode(root, 'live');
            }
        });
        sandbox.addEventListener('change', function () {
            if (sandbox.checked) {
                if (confirmBox) {
                    confirmBox.hidden = true;
                }
                applyMode(root, 'sandbox');
            }
        });
        var yes = root.querySelector('[data-np-live-yes]');
        var no = root.querySelector('[data-np-live-no]');
        if (yes) {
            yes.addEventListener('click', function () {
                live.checked = true;
                confirmBox.hidden = true;
                applyMode(root, 'live');
            });
        }
        if (no) {
            no.addEventListener('click', function () {
                sandbox.checked = true;
                confirmBox.hidden = true;
            });
        }
    }

    // ── Secrets ───────────────────────────────────────────────────────
    function setupReveal(root) {
        all(root, '[data-np-reveal]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var input = root.querySelector('#' + btn.getAttribute('data-np-reveal'));
                if (!input) {
                    return;
                }
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                btn.textContent = show ? txt(root, 'hide') : txt(root, 'show');
            });
        });
    }

    // ── Checkout ──────────────────────────────────────────────────────
    function setupInstallments(root) {
        var box = root.querySelector('#netopia_allow_installments');
        var max = root.querySelector('#netopia_max_installments');
        if (!box || !max) {
            return;
        }
        var sync = function () {
            // readonly, not disabled: a disabled field is not submitted, and
            // the saved maximum would be lost while installments are off.
            max.readOnly = !box.checked;
            max.setAttribute('aria-disabled', box.checked ? 'false' : 'true');
        };
        box.addEventListener('change', sync);
        sync();
    }

    function descriptionPreview(root, template) {
        var samples = {
            '[order_id]': root.getAttribute('data-sample-order-id') || '',
            '[total]': root.getAttribute('data-sample-total') || '',
            '[currency]': root.getAttribute('data-sample-currency') || '',
            '[email]': root.getAttribute('data-sample-email') || '',
            '[site_url]': root.getAttribute('data-sample-site-url') || '',
        };
        return Object.keys(samples).reduce(function (out, token) {
            return out.split(token).join(samples[token]);
        }, template);
    }

    function setupDescription(root) {
        var input = root.querySelector('#netopia_order_description');
        var preview = root.querySelector('[data-np-desc-preview]');
        if (!input || !preview) {
            return;
        }
        var update = function () {
            preview.textContent = descriptionPreview(root, input.value);
        };
        input.addEventListener('input', update);
        all(root, '[data-np-token]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var token = btn.getAttribute('data-np-token');
                var start = typeof input.selectionStart === 'number' ? input.selectionStart : input.value.length;
                var end = typeof input.selectionEnd === 'number' ? input.selectionEnd : start;
                input.value = input.value.slice(0, start) + token + input.value.slice(end);
                input.focus();
                if (input.setSelectionRange) {
                    input.setSelectionRange(start + token.length, start + token.length);
                }
                update();
            });
        });
        update();
    }

    // ── Order statuses ────────────────────────────────────────────────
    function markRow(select) {
        var row = select.closest('tr');
        if (row) {
            row.classList.toggle('is-changed', select.value !== select.getAttribute('data-np-default'));
        }
    }

    function setupStatuses(root) {
        var selects = all(root, 'select[data-np-default]');
        selects.forEach(function (select) {
            select.addEventListener('change', function () {
                markRow(select);
            });
        });
        var reset = root.querySelector('[data-np-reset]');
        if (reset) {
            reset.addEventListener('click', function () {
                selects.forEach(function (select) {
                    select.value = select.getAttribute('data-np-default');
                    markRow(select);
                });
            });
        }
    }

    // ── Keys ──────────────────────────────────────────────────────────
    function refreshKeysDot(root) {
        var dot = root.querySelector('[data-np-keys-dot]');
        if (!dot) {
            return;
        }
        var mode = root.getAttribute('data-mode');
        var blocked = root.getAttribute('data-blocked-' + mode) === '1';
        // A file picked for the mode's public key will fix it on Save.
        var picked = root.querySelector('[data-np-key="' + mode + '_public_key"] input[type="file"]');
        if (picked && picked.files && picked.files.length > 0) {
            blocked = false;
        }
        dot.hidden = !blocked;
        var missing = root.querySelector('[data-np-live-keys-missing]');
        if (missing) {
            missing.hidden = root.getAttribute('data-blocked-live') !== '1';
        }
    }

    // NETOPIA names its key files "<mode>.<POS signature>…", e.g.
    // sandbox.39EG-NK6H-N6LV-IVP3-SLJC.public.cer (KeyFileName.php server side).
    function keyFileMode(name) {
        var m = /^(sandbox|live)\./i.exec(String(name || '').replace(/^.*[\\/]/, ''));
        return m ? m[1].toLowerCase() : '';
    }

    function keyFileSignature(name) {
        var m = /(?:^|[./])([A-Z0-9]{4}(?:-[A-Z0-9]{4}){4})/i.exec(String(name || ''));
        return m ? m[1].toUpperCase() : '';
    }

    // An empty POS signature is filled in from the picked key file's name.
    function fillPosSignature(root, mode, fileName) {
        var input = root.querySelector('#netopia_' + mode + '_pos_signature');
        var signature = keyFileSignature(fileName);
        if (!input || signature === '' || input.value.trim() !== '') {
            return;
        }
        // Shown as the placeholder only: an empty field stays empty, the
        // signature is read from the key file name at payment time.
        input.placeholder = signature;
        var note = root.querySelector('[data-np-pos-note="' + mode + '"]');
        if (note) {
            note.hidden = false;
        }
    }

    function setupKeys(root) {
        all(root, '[data-np-drop]').forEach(function (drop) {
            var input = drop.querySelector('input[type="file"]');
            var chosen = drop.querySelector('[data-np-chosen]');
            if (!input) {
                return;
            }
            var warn = drop.querySelector('[data-np-wrong-mode]');
            var card = drop.closest('[data-np-key]');
            var slotMode = card ? String(card.getAttribute('data-np-key') || '').split('_')[0] : '';
            input.addEventListener('change', function () {
                var name = input.files && input.files.length > 0 ? input.files[0].name : '';
                if (chosen) {
                    chosen.textContent = name;
                    chosen.hidden = name === '';
                }
                var fileMode = keyFileMode(name);
                var wrong = fileMode !== '' && slotMode !== '' && fileMode !== slotMode;
                if (warn) {
                    // The server refuses it on Save; say so before the admin saves.
                    warn.textContent = wrong
                        ? txt(root, 'key-wrong-mode')
                            .replace('[file_mode]', txt(root, 'mode-' + fileMode))
                            .replace('[slot_mode]', txt(root, 'mode-' + slotMode))
                        : '';
                    warn.hidden = !wrong;
                }
                if (!wrong && slotMode !== '') {
                    fillPosSignature(root, slotMode, name);
                }
                refreshKeysDot(root);
            });
            ['dragenter', 'dragover'].forEach(function (type) {
                drop.addEventListener(type, function () {
                    drop.classList.add('is-over');
                });
            });
            ['dragleave', 'drop'].forEach(function (type) {
                drop.addEventListener(type, function () {
                    drop.classList.remove('is-over');
                });
            });
        });
        all(root, '[data-np-remove]').forEach(function (box) {
            box.addEventListener('change', function () {
                var file = box.closest('.netopia-key__file');
                if (file) {
                    file.style.textDecoration = box.checked ? 'line-through' : '';
                    file.title = box.checked ? txt(root, 'remove-marked') : '';
                }
            });
        });
        var showCreds = root.querySelector('[data-np-show-all-creds]');
        if (showCreds) {
            showCreds.addEventListener('click', function (e) {
                e.preventDefault();
                root.classList.toggle('is-showing-all-creds');
            });
        }
        var showAll = root.querySelector('[data-np-show-all-keys]');
        if (showAll) {
            showAll.addEventListener('click', function (e) {
                e.preventDefault();
                root.classList.toggle('is-showing-all-keys');
            });
        }
    }

    // ── Check settings ───────────────────────────────────────────────
    function readPickedKey(root, mode) {
        var input = root.querySelector('[data-np-key="' + mode + '_public_key"] input[type="file"]');
        var file = input && input.files && input.files.length > 0 ? input.files[0] : null;
        if (file && typeof FileReader !== 'undefined') {
            return new Promise(function (resolve) {
                var reader = new FileReader();
                reader.onload = function () {
                    resolve(String(reader.result || ''));
                };
                reader.onerror = function () {
                    resolve('');
                };
                reader.readAsText(file);
            });
        }
        var pasted = root.querySelector('#netopia_' + mode + '_public_key');
        return Promise.resolve(pasted ? pasted.value : '');
    }

    function renderTest(root, result) {
        var summary = root.querySelector('[data-np-test-summary]');
        var list = root.querySelector('[data-np-test-list]');
        var intro = root.querySelector('[data-np-test-intro]');
        if (!summary || !list) {
            return;
        }
        if (intro) {
            intro.hidden = true;
        }
        summary.hidden = false;
        list.innerHTML = '';
        if (!result || typeof result !== 'object') {
            summary.textContent = txt(root, 'test-failed');
            list.hidden = true;
            return;
        }
        summary.textContent = String(result.summary || '');
        summary.className = result.ready ? 'text-success' : 'text-error';
        (Array.isArray(result.checks) ? result.checks : []).forEach(function (check) {
            var li = document.createElement('li');
            var pill = document.createElement('span');
            var state = check.state === 'ok' ? 'ok' : check.state === 'warn' ? 'warn' : 'bad';
            pill.className = 'netopia-pill netopia-pill--' + state;
            pill.textContent = state === 'ok' ? '✓' : state === 'warn' ? '!' : '✕';
            var text = document.createElement('span');
            text.textContent = String(check.text || '');
            li.appendChild(pill);
            li.appendChild(text);
            list.appendChild(li);
        });
        list.hidden = false;
    }

    function setupTest(root) {
        var btn = root.querySelector('[data-np-test-run]');
        if (!btn) {
            return;
        }
        btn.addEventListener('click', function () {
            var $ = window.jQuery;
            if (!$ || typeof $.ceAjax !== 'function') {
                renderTest(root, null);
                return;
            }
            var mode = root.getAttribute('data-mode') || 'sandbox';
            var label = btn.textContent;
            btn.disabled = true;
            btn.textContent = txt(root, 'testing');
            var done = function (result) {
                btn.disabled = false;
                btn.textContent = label;
                renderTest(root, result);
            };
            readPickedKey(root, mode).then(function (publicKey) {
                // The selected mode's pair: NETOPIA issues one per mode.
                var api = root.querySelector('#netopia_' + mode + '_api_key');
                var pos = root.querySelector('#netopia_' + mode + '_pos_signature');
                var other = root.querySelector('#netopia_' + (mode === 'live' ? 'sandbox' : 'live') + '_api_key');
                $.ceAjax('request', root.getAttribute('data-test-url'), {
                    method: 'post',
                    hidden: true,
                    data: {
                        mode: mode,
                        payment_id: root.getAttribute('data-payment-id') || '0',
                        api_key: api ? api.value : '',
                        pos_signature: pos ? pos.value : '',
                        other_api_key: other ? other.value : '',
                        public_key: publicKey,
                    },
                    callback: function (data) {
                        done(data && typeof data === 'object' ? data.netopia_test : null);
                    },
                    error_callback: function () {
                        done(null);
                    },
                });
            });
        });
    }

    // ── Setup ─────────────────────────────────────────────────────────
    function init(context) {
        var scope = context && context.querySelectorAll ? context : document;
        var roots = all(scope, '.netopia-cfg');
        if (scope !== document && scope.classList && scope.classList.contains('netopia-cfg')) {
            roots.push(scope);
        }
        roots.forEach(function (root) {
            if (root.getAttribute('data-np-ready') === '1') {
                return;
            }
            root.setAttribute('data-np-ready', '1');
            var form = root.closest('form');
            if (form) {
                form.setAttribute('enctype', 'multipart/form-data');
            }
            setupTabs(root);
            setupMode(root);
            setupReveal(root);
            setupInstallments(root);
            setupDescription(root);
            setupStatuses(root);
            setupKeys(root);
            setupTest(root);
            applyMode(root, root.getAttribute('data-mode') === 'live' ? 'live' : 'sandbox');
        });
    }

    window.NetopiaProcessorConfig = { init: init, descriptionPreview: descriptionPreview, keyFileMode: keyFileMode, keyFileSignature: keyFileSignature };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
        });
    } else {
        init(document);
    }
    var jq = window.jQuery;
    if (jq && typeof jq.ceEvent === 'function') {
        jq.ceEvent('on', 'ce.commoninit', function (context) {
            init(context && context[0] ? context[0] : document);
        });
    }
})();

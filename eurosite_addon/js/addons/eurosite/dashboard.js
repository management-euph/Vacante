// Eurosite — dashboard page helpers.
//
// Two jobs: the per-row copy buttons, and the crontab block (plan / format
// toggles, key masking, copy-all).
//
// A real file rather than an inline <script> because CS-Cart's backend
// rewrites inline scripts into {inline_script} blocks for CSP nonces, and the
// rewrite chokes on a {literal}-wrapped body. Loaded as
// {script src="js/addons/eurosite/dashboard.js"} — a leading "js/" resolves to
// the DOCROOT /js/ tree, which is where this file deploys from.
(function () {
    // navigator.clipboard needs a secure context. The admin is often plain
    // http on a LAN host, where it is simply undefined — hence the
    // execCommand fallback, which works everywhere this admin runs.
    function copyToClipboard(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (resolve, reject) {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.top = '-1000px';
            document.body.appendChild(ta);
            ta.select();
            try {
                if (document.execCommand('copy')) { resolve(); } else { reject(new Error('copy rejected')); }
            } catch (e) {
                reject(e);
            } finally {
                document.body.removeChild(ta);
            }
        });
    }

    // Any button carrying data-copy copies it and says so for a moment.
    function initCopyButtons() {
        Array.prototype.slice.call(document.querySelectorAll('.eurosite-copy')).forEach(function (btn) {
            // Captured once, at bind time: reading it per click means a second
            // click inside the feedback window captures "Copied" as the label
            // to restore, and the button says Copied for ever after.
            var original = btn.innerHTML;

            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var text = btn.getAttribute('data-copy') || '';
                if (!text) { return; }
                var done = btn.getAttribute('data-txt-copied') || 'Copied';
                var failed = btn.getAttribute('data-txt-copy-failed') || 'Copy failed';
                copyToClipboard(text).then(function () {
                    btn.innerHTML = '<i class="icon-ok"></i> ' + done;
                }).catch(function () {
                    btn.innerHTML = failed;
                }).then(function () {
                    setTimeout(function () { btn.innerHTML = original; }, 1500);
                });
            });
        });
    }

    // ── Crontab block ──
    //
    // The server ships all four variants (nightly-full vs per-catalog × curl
    // vs php) in one data attribute, so switching is instant and needs no
    // round trip. The access key is masked in the DISPLAY only — copying
    // always puts the real value on the clipboard, because a masked command
    // pasted into a crontab is a 403 at 01:00 that nobody is awake to see.
    function initCrontab() {
        var root = document.getElementById('eurosite-crontab');
        if (!root) {
            return; // no key configured, or not on the dashboard
        }

        var pre = document.getElementById('eurosite-crontab-text');
        var copyBtn = document.getElementById('eurosite-crontab-copy');
        var revealBtn = document.getElementById('eurosite-crontab-reveal');
        var note = document.getElementById('eurosite-crontab-note');
        if (!pre) {
            return;
        }

        var variants = {};
        try {
            variants = JSON.parse(root.getAttribute('data-crontabs') || '{}');
        } catch (e) {
            variants = {};
        }

        var key = root.getAttribute('data-key') || '';
        var txt = {
            copied: root.getAttribute('data-txt-copied') || 'Copied',
            failed: root.getAttribute('data-txt-copy-failed') || 'Copy failed',
            reveal: root.getAttribute('data-txt-reveal') || 'Reveal access key',
            hide: root.getAttribute('data-txt-hide') || 'Hide access key',
            hiddenNote: root.getAttribute('data-txt-hidden-note') || '',
            shownNote: root.getAttribute('data-txt-shown-note') || ''
        };

        var state = { plan: 'full', format: 'url', revealed: false };
        var MASK = '••••••••••••';

        function plainText() {
            return variants[state.plan + '_' + state.format] || '';
        }

        function masked(text) {
            if (state.revealed || key === '') {
                return text;
            }
            return text.split(key).join(MASK);
        }

        function render() {
            pre.textContent = masked(plainText());
            if (revealBtn) {
                revealBtn.textContent = state.revealed ? txt.hide : txt.reveal;
            }
            if (note) {
                note.textContent = state.revealed ? txt.shownNote : txt.hiddenNote;
            }
        }

        function bindRadios(name, field) {
            Array.prototype.slice.call(
                root.querySelectorAll('input[name="' + name + '"]')
            ).forEach(function (input) {
                input.addEventListener('change', function () {
                    if (!input.checked) {
                        return;
                    }
                    state[field] = input.value;
                    render();
                });
            });
        }

        bindRadios('eurosite_cron_plan', 'plan');
        bindRadios('eurosite_cron_format', 'format');

        if (revealBtn) {
            revealBtn.addEventListener('click', function () {
                state.revealed = !state.revealed;
                render();
            });
        }

        if (copyBtn) {
            var copyLabel = copyBtn.innerHTML; // see the note in initCopyButtons
            copyBtn.addEventListener('click', function () {
                copyToClipboard(plainText()).then(function () {
                    copyBtn.innerHTML = '<i class="icon-ok"></i> ' + txt.copied;
                }).catch(function () {
                    copyBtn.innerHTML = txt.failed;
                }).then(function () {
                    setTimeout(function () { copyBtn.innerHTML = copyLabel; }, 1600);
                });
            });
        }

        render();
    }

    function init() {
        initCopyButtons();
        initCrontab();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

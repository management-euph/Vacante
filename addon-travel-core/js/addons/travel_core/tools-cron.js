// Travel Core — Tools & Cron page helpers.
//
// The shared cron key is masked on screen and revealed on request; the
// command of each Travel Core job switches between URL and CLI (php)
// form; Copy buttons put the REAL command on the clipboard.
//
// The key is masked in the DISPLAY only. A masked command pasted into a
// crontab is a 403 at 13:05 that nobody is awake to see, so every copy uses
// the real value (the same rule as eurosite's dashboard.js).
//
// A file rather than an inline <script>: CS-Cart's backend rewrites inline
// scripts for CSP nonces and InlineScriptRatchetTest bans new ones. Loaded as
// {script src="js/addons/travel_core/tools-cron.js"} inside the mainbox
// capture, so admin AJAX navigation runs it again — hence the per-root guard.
(function () {
    // navigator.clipboard needs a secure context; plain-http admins fall back.
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

    function init() {
        var root = document.getElementById('travel-cron-tools');
        if (!root || root.__travelCronInit) {
            return;
        }
        root.__travelCronInit = true;

        var key = root.getAttribute('data-key') || '';
        var mask = root.getAttribute('data-mask') || '••••••••';
        var txt = {
            copied: root.getAttribute('data-txt-copied') || 'Copied',
            failed: root.getAttribute('data-txt-copy-failed') || 'Copy failed',
            reveal: root.getAttribute('data-txt-reveal') || 'Reveal',
            hide: root.getAttribute('data-txt-hide') || 'Hide'
        };
        var state = { revealed: false, format: 'url' };

        function masked(text) {
            return key === '' || state.revealed ? text : text.split(key).join(mask);
        }

        function feedback(btn, ok) {
            var original = btn.getAttribute('data-label') || btn.textContent;
            btn.setAttribute('data-label', original);
            btn.textContent = ok ? txt.copied : txt.failed;
            setTimeout(function () { btn.textContent = original; }, 1500);
        }

        function copy(btn, text) {
            if (!text) { return; }
            copyToClipboard(text).then(function () { feedback(btn, true); }, function () { feedback(btn, false); });
        }

        // ── The key field ──
        var input = document.getElementById('travel-cron-key');
        var revealBtn = document.getElementById('travel-cron-key-reveal');
        var copyKeyBtn = document.getElementById('travel-cron-key-copy');

        function commandText(el) {
            return el.getAttribute(state.format === 'cli' ? 'data-cmd-cli' : 'data-cmd-url') || '';
        }

        function render() {
            if (input) {
                input.value = state.revealed ? key : mask;
            }
            if (revealBtn) {
                revealBtn.textContent = state.revealed ? txt.hide : txt.reveal;
                revealBtn.setAttribute('aria-pressed', state.revealed ? 'true' : 'false');
            }
            Array.prototype.slice.call(root.querySelectorAll('[data-cron-format]')).forEach(function (b) {
                b.setAttribute('aria-pressed', b.getAttribute('data-cron-format') === state.format ? 'true' : 'false');
            });
            Array.prototype.slice.call(root.querySelectorAll('.travel-cron-cmd')).forEach(function (el) {
                el.textContent = masked(commandText(el));
            });
        }

        if (revealBtn) {
            revealBtn.addEventListener('click', function (e) {
                e.preventDefault();
                state.revealed = !state.revealed;
                render();
            });
        }
        if (copyKeyBtn) {
            copyKeyBtn.addEventListener('click', function (e) {
                e.preventDefault();
                copy(copyKeyBtn, key);
            });
        }

        // ── URL / CLI switch ──
        Array.prototype.slice.call(root.querySelectorAll('[data-cron-format]')).forEach(function (b) {
            b.addEventListener('click', function (e) {
                e.preventDefault();
                state.format = b.getAttribute('data-cron-format') === 'cli' ? 'cli' : 'url';
                render();
            });
        });

        // ── Copy a command: always the real one, in the chosen format ──
        Array.prototype.slice.call(root.querySelectorAll('.travel-cron-copy')).forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var target = document.getElementById(btn.getAttribute('data-copy-target') || '');
                if (target) {
                    copy(btn, commandText(target));
                }
            });
        });

        render();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

// Eurosite — dashboard page helpers.
//
// Copy-to-clipboard for the cron rows. A real file rather than an inline
// <script> for the same reason as whitelist.js: CS-Cart's backend rewrites
// inline scripts into {inline_script} blocks for CSP nonces, and the rewrite
// chokes on a {literal}-wrapped body. Loaded as
// {script src="js/addons/eurosite/dashboard.js"} — a leading "js/" resolves to
// the DOCROOT /js/ tree, which is where this file deploys from.
(function () {
    function init() {
        var buttons = Array.prototype.slice.call(document.querySelectorAll('.eurosite-copy'));
        if (!buttons.length) {
            return; // not on the dashboard
        }

        // navigator.clipboard needs a secure context. The admin is often plain
        // http on a LAN host, where it is simply undefined — hence the
        // execCommand fallback, which works everywhere this admin runs.
        function copy(text) {
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

        buttons.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var text = btn.getAttribute('data-copy') || '';
                if (!text) { return; }
                var original = btn.innerHTML;
                var done = btn.getAttribute('data-txt-copied') || 'Copied';
                var failed = btn.getAttribute('data-txt-copy-failed') || 'Copy failed';
                copy(text).then(function () {
                    btn.innerHTML = '<i class="icon-ok"></i> ' + done;
                }).catch(function () {
                    btn.innerHTML = failed;
                }).then(function () {
                    setTimeout(function () { btn.innerHTML = original; }, 1500);
                });
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

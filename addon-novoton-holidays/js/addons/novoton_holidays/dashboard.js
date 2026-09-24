// Novoton Holidays — dashboard (novoton_holidays.manage) helpers.
//
//  - copy buttons: any .novoton-copy[data-copy] copies its REAL value;
//  - the ⋯ menu on each scheduled job;
//  - the "All commands" block: CLI / URL switch, masked key, Copy all;
//  - forms carrying data-novoton-confirm ask before they submit (Reset,
//    Force full sync): the question comes from the template, no inline JS.
//
// The cron key is masked in the DISPLAY only. A masked command pasted into a
// crontab is a refused run at 04:30 that nobody is awake to see, so every
// copy uses the real value (the rule eurosite's dashboard.js and Travel
// Core's tools-cron.js follow).
//
// A file rather than an inline <script>: CS-Cart's backend rewrites inline
// scripts for CSP nonces and InlineScriptRatchetTest bans new ones. Loaded
// inside the mainbox capture, so admin AJAX navigation runs it again — hence
// the per-element guards.
(function () {
    var MASK = '••••••••';

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

    function labels() {
        var root = document.getElementById('novoton-crontab');
        return {
            copied: (root && root.getAttribute('data-txt-copied')) || 'Copied',
            failed: (root && root.getAttribute('data-txt-copy-failed')) || 'Copy failed'
        };
    }

    // Says so on the button for a moment. The label is captured once per
    // button: reading it per click would capture "Copied" on a second click
    // inside the window, and the button would say Copied for ever after.
    function copyWithFeedback(btn, text) {
        if (!text) { return Promise.resolve(); }
        if (btn.__novotonLabel === undefined) { btn.__novotonLabel = btn.textContent; }
        var txt = labels();
        return copyToClipboard(text).then(function () {
            btn.textContent = txt.copied;
        }, function () {
            btn.textContent = txt.failed;
        }).then(function () {
            setTimeout(function () { btn.textContent = btn.__novotonLabel; }, 1500);
        });
    }

    function each(selector, fn) {
        Array.prototype.slice.call(document.querySelectorAll(selector)).forEach(fn);
    }

    function initCopyButtons() {
        each('.novoton-copy', function (btn) {
            if (btn.__novotonCopy) { return; }
            btn.__novotonCopy = true;
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                copyWithFeedback(btn, btn.getAttribute('data-copy') || '');
            });
        });
    }

    function initConfirms() {
        each('form[data-novoton-confirm]', function (form) {
            if (form.__novotonConfirm) { return; }
            form.__novotonConfirm = true;
            form.addEventListener('submit', function (e) {
                if (!window.confirm(form.getAttribute('data-novoton-confirm') || '')) {
                    e.preventDefault();
                }
            });
        });
    }

    // ── "All commands": CLI / URL, masked on screen, copied for real ──
    function initCrontab() {
        var root = document.getElementById('novoton-crontab');
        if (!root || root.__novotonCrontab) { return; }
        root.__novotonCrontab = true;

        var pre = document.getElementById('novoton-crontab-text');
        var copyBtn = document.getElementById('novoton-crontab-copy');
        var key = root.getAttribute('data-key') || '';
        var state = { format: 'cli' };

        function text() {
            return root.getAttribute(state.format === 'url' ? 'data-crontab-url' : 'data-crontab-cli') || '';
        }

        function render() {
            if (pre) {
                pre.textContent = key === '' ? text() : text().split(key).join(MASK);
            }
            Array.prototype.slice.call(root.querySelectorAll('[data-novoton-format]')).forEach(function (b) {
                b.setAttribute('aria-pressed', b.getAttribute('data-novoton-format') === state.format ? 'true' : 'false');
            });
        }

        Array.prototype.slice.call(root.querySelectorAll('[data-novoton-format]')).forEach(function (b) {
            b.addEventListener('click', function (e) {
                e.preventDefault();
                state.format = b.getAttribute('data-novoton-format') === 'url' ? 'url' : 'cli';
                render();
            });
        });

        if (copyBtn) {
            copyBtn.addEventListener('click', function (e) {
                e.preventDefault();
                copyWithFeedback(copyBtn, text());
            });
        }

        render();
    }

    // ── Row ⋯ menus: one open at a time, keyboard-operable ──
    var open = null; // { toggle, menu }

    function closeMenu() {
        if (!open) { return; }
        open.menu.hidden = true;
        open.toggle.setAttribute('aria-expanded', 'false');
        open = null;
    }

    function menuItems(menu) {
        return Array.prototype.slice.call(menu.querySelectorAll('[role="menuitem"]'));
    }

    function openMenu(toggle, menu, focusFirst) {
        closeMenu();
        menu.hidden = false;
        toggle.setAttribute('aria-expanded', 'true');
        open = { toggle: toggle, menu: menu };
        if (focusFirst) {
            var first = menuItems(menu)[0];
            if (first) { first.focus(); }
        }
    }

    function initMenus() {
        each('.novoton-menu-wrap', function (wrap) {
            if (wrap.__novotonMenu) { return; }
            wrap.__novotonMenu = true;
            var toggle = wrap.querySelector('.novoton-menu-toggle');
            var menu = wrap.querySelector('.novoton-menu');
            if (!toggle || !menu) { return; }

            toggle.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                if (open && open.menu === menu) { closeMenu(); return; }
                openMenu(toggle, menu, false);
            });

            toggle.addEventListener('keydown', function (e) {
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    openMenu(toggle, menu, true);
                }
            });

            menu.addEventListener('keydown', function (e) {
                var list = menuItems(menu);
                var at = list.indexOf(document.activeElement);
                if (e.key === 'Escape') {
                    e.preventDefault();
                    closeMenu();
                    toggle.focus(); // never strand focus inside a hidden element
                } else if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (list.length) { list[(at + 1) % list.length].focus(); }
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (list.length) { list[(at - 1 + list.length) % list.length].focus(); }
                } else if (e.key === 'Tab') {
                    closeMenu();
                }
            });

            // A copy keeps its "Copied" visible for a moment, then the menu closes.
            menu.addEventListener('click', function (e) {
                var item = e.target && e.target.closest ? e.target.closest('[role="menuitem"]') : null;
                if (item) { setTimeout(closeMenu, 900); }
            });
        });

        if (!document.__novotonMenuDoc) {
            document.__novotonMenuDoc = true;
            document.addEventListener('click', function (e) {
                if (open && !open.menu.contains(e.target) && e.target !== open.toggle) {
                    closeMenu();
                }
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && open) {
                    var t = open.toggle;
                    closeMenu();
                    t.focus();
                }
            });
        }
    }

    function init() {
        initCopyButtons();
        initConfirms();
        initCrontab();
        initMenus();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

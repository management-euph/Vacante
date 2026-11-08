// Travel Core — the providers' scheduled-jobs cards (components/jobs_card.tpl,
// job_row.tpl, run_job.tpl, commands_card.tpl), shared by every dashboard.
//
//  - copy buttons: any .travel-cron-copy[data-copy] copies its REAL value;
//  - the ⋯ menu on each job: one open at a time, keyboard-operable, and
//    lifted onto <body> while open, beside its button, opening upward when
//    there is no room below — no table, card or scroll box around the row
//    can cut it off (the jobs card scrolls sideways, which clipped the
//    menus of its last rows);
//  - the "All commands" block: CLI / URL switch, masked key, Copy all;
//  - every job button (Run, Check status, Force full sync, Reset progress,
//    a "Needs attention" Run) opens the job's log in a NEW window; the ones
//    carrying data-travel-cron-confirm ask first (the question comes from
//    the template, no inline JS), and so does a ticked box that makes the
//    run write. A form's required fields are checked, and the button pressed
//    is sent along (a form can offer several jobs, as Check one hotel).
//
// The cron key is masked in the DISPLAY only. A masked command pasted into a
// crontab is a refused run at 04:30 that nobody is awake to see, so every
// copy uses the real value (the rule tools-cron.js follows too).
//
// A file rather than an inline <script>: CS-Cart's backend rewrites inline
// scripts for CSP nonces and InlineScriptRatchetTest bans new ones. Loaded
// inside the mainbox capture, so admin AJAX navigation runs it again — hence
// the per-element guards, and the state kept on window so every load shares
// one open menu and one set of document listeners.
(function () {
    var MASK = '••••••••';
    var GAP = 4;   // between the ⋯ button and its menu
    var EDGE = 8;  // the menu keeps this far from the window's edges

    var shared = window.__travelCronJobs || (window.__travelCronJobs = { open: null, runSeq: 0, docBound: false });

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

    // The words come from the card the button sits in (data-txt-*), so they
    // are the admin's language; a button in a menu lifted onto <body> asks
    // the first card on the page.
    function labels(btn) {
        var holder = (btn && btn.closest && btn.closest('[data-txt-copied]')) || document.querySelector('[data-txt-copied]');
        return {
            copied: (holder && holder.getAttribute('data-txt-copied')) || 'Copied',
            failed: (holder && holder.getAttribute('data-txt-copy-failed')) || 'Copy failed'
        };
    }

    // Says so on the button for a moment. The label is captured once per
    // button: reading it per click would capture "Copied" on a second click
    // inside the window, and the button would say Copied for ever after.
    function copyWithFeedback(btn, text) {
        if (!text) { return; }
        if (btn.__travelCronLabel === undefined) { btn.__travelCronLabel = btn.textContent; }
        var txt = labels(btn);
        // The outcome is shown on the button, never returned: nothing awaits it.
        copyToClipboard(text).then(function () {
            btn.textContent = txt.copied;
        }, function () {
            btn.textContent = txt.failed;
        }).then(function () {
            setTimeout(function () { btn.textContent = btn.__travelCronLabel; }, 1500);
        });
    }

    function each(selector, fn) {
        Array.prototype.slice.call(document.querySelectorAll(selector)).forEach(fn);
    }

    function initCopyButtons() {
        each('.travel-cron-copy', function (btn) {
            if (btn.__travelCronCopy) { return; }
            btn.__travelCronCopy = true;
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                copyWithFeedback(btn, btn.getAttribute('data-copy') || '');
            });
        });
    }

    // ── Run / Check status / Force full sync / Reset progress / attention "Run …" ──
    //
    // Every one of them is a .travel-cron-run-form (components/run_job.tpl),
    // and each must open the job's log in a NEW window, leaving the dashboard
    // where it was. target="_blank" alone did not do it: CS-Cart's admin
    // scripts take over form submits on the page and the job replaced the
    // dashboard. So the submit is handled here, before theirs: ask first
    // where the form says so, open a named window inside the click (so no
    // popup blocker objects), and post into it with the native submit(),
    // which fires no submit event for anything else to intercept.
    // The native submit() below checks no field and sends no button, so this
    // does both: a required field left empty stops the run, and the button
    // pressed (a form with several, as Check one hotel) rides in a hidden field.
    function carryButton(form, btn) {
        var carrier = form.querySelector('input[data-travel-cron-submitter]');
        if (btn && btn.name) {
            if (!carrier) {
                carrier = document.createElement('input');
                carrier.type = 'hidden';
                carrier.setAttribute('data-travel-cron-submitter', '');
                form.appendChild(carrier);
            }
            carrier.name = btn.name;
            carrier.value = btn.value;
        } else if (carrier) {
            carrier.parentNode.removeChild(carrier);
        }
    }

    // A ticked box that makes the run write (attach images, apply SEO) asks
    // its own question first; data-travel-cron-confirm-for names the one
    // button it is about, when the form has several.
    function confirmTicked(form, btn) {
        var boxes = Array.prototype.slice.call(form.querySelectorAll('input[type="checkbox"][data-travel-cron-confirm]'));
        for (var i = 0; i < boxes.length; i++) {
            var box = boxes[i];
            var forButton = box.getAttribute('data-travel-cron-confirm-for');
            if (!box.checked || (forButton && (!btn || btn.value !== forButton))) {
                continue;
            }
            if (!window.confirm(box.getAttribute('data-travel-cron-confirm'))) {
                return false;
            }
        }
        return true;
    }

    function launch(form, btn) {
        if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
            return;
        }
        var question = form.getAttribute('data-travel-cron-confirm');
        if (question && !window.confirm(question)) {
            return;
        }
        if (!confirmTicked(form, btn)) {
            return;
        }
        carryButton(form, btn);

        var name = 'travel_cron_job_' + Date.now() + '_' + (++shared.runSeq);
        var win = window.open('about:blank', name);
        form.setAttribute('target', win ? name : '_blank');
        closeMenu();
        HTMLFormElement.prototype.submit.call(form);
    }

    function initRunForms() {
        each('form.travel-cron-run-form', function (form) {
            if (form.__travelCronRun) { return; }
            form.__travelCronRun = true;

            // The click on the button, in the capture phase: CS-Cart's admin
            // handlers listen further up and would otherwise take it over.
            form.addEventListener('click', function (e) {
                var btn = e.target && e.target.closest ? e.target.closest('button[type="submit"], input[type="submit"]') : null;
                if (!btn || !form.contains(btn)) { return; }
                e.preventDefault();
                e.stopImmediatePropagation();
                launch(form, btn);
            }, true);

            // Any other way the form gets submitted (Enter key, requestSubmit).
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                e.stopImmediatePropagation();
                launch(form, e.submitter || null);
            }, true);
        });
    }

    // ── "All commands": CLI / URL, masked on screen, copied for real ──
    function initCommands() {
        each('[data-travel-cron-commands]', function (root) {
            if (root.__travelCronCommands) { return; }
            root.__travelCronCommands = true;

            var pre = root.querySelector('[data-travel-cron-text]');
            var copyBtn = root.querySelector('[data-travel-cron-copy-all]');
            var formats = Array.prototype.slice.call(root.querySelectorAll('[data-travel-cron-format]'));
            var key = root.getAttribute('data-key') || '';
            var state = { format: 'cli' };

            function text() {
                return root.getAttribute(state.format === 'url' ? 'data-crontab-url' : 'data-crontab-cli') || '';
            }

            function render() {
                if (pre) {
                    pre.textContent = key === '' ? text() : text().split(key).join(MASK);
                }
                formats.forEach(function (b) {
                    b.setAttribute('aria-pressed', b.getAttribute('data-travel-cron-format') === state.format ? 'true' : 'false');
                });
            }

            formats.forEach(function (b) {
                b.addEventListener('click', function (e) {
                    e.preventDefault();
                    state.format = b.getAttribute('data-travel-cron-format') === 'url' ? 'url' : 'cli';
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
        });
    }

    // ── Row ⋯ menus ──

    // Lifted onto <body> and pinned beside its button (position: fixed), so
    // the card's sideways scroll box, which also clips downwards, cannot cut
    // it off. Right edges line up with the button's; it opens upward when it
    // does not fit below and there is more room above; it stays inside the
    // window.
    function place(toggle, menu) {
        if (menu.parentNode !== document.body) {
            menu.__travelCronHome = { parent: menu.parentNode, next: menu.nextSibling };
            document.body.appendChild(menu);
        }
        menu.classList.add('travel-cron-menu--floating');

        var r = toggle.getBoundingClientRect();
        var width = menu.offsetWidth;
        var height = menu.offsetHeight;
        var viewW = document.documentElement.clientWidth || window.innerWidth;
        var viewH = window.innerHeight || document.documentElement.clientHeight;
        var below = viewH - r.bottom - GAP - EDGE;
        var above = r.top - GAP - EDGE;
        var up = height > below && above > below;

        var top = up ? r.top - GAP - height : r.bottom + GAP;
        var left = Math.min(r.right - width, viewW - EDGE - width);
        menu.style.top = Math.round(Math.max(EDGE, top)) + 'px';
        menu.style.left = Math.round(Math.max(EDGE, left)) + 'px';
        menu.setAttribute('data-placement', up ? 'up' : 'down');
    }

    // Back where the template put it, so the row is whole again.
    function putBack(menu) {
        var home = menu.__travelCronHome;
        menu.__travelCronHome = null;
        menu.classList.remove('travel-cron-menu--floating');
        menu.style.top = '';
        menu.style.left = '';
        menu.removeAttribute('data-placement');
        if (home && home.parent) {
            home.parent.insertBefore(menu, home.next && home.next.parentNode === home.parent ? home.next : null);
        }
    }

    function closeMenu() {
        var open = shared.open;
        if (!open) { return; }
        shared.open = null;
        open.menu.hidden = true;
        open.toggle.setAttribute('aria-expanded', 'false');
        putBack(open.menu);
    }

    function menuItems(menu) {
        return Array.prototype.slice.call(menu.querySelectorAll('[role="menuitem"]'));
    }

    function openMenu(toggle, menu, focusFirst) {
        closeMenu();
        menu.hidden = false;
        toggle.setAttribute('aria-expanded', 'true');
        shared.open = { toggle: toggle, menu: menu };
        place(toggle, menu);
        if (focusFirst) {
            var first = menuItems(menu)[0];
            if (first) { first.focus({ preventScroll: true }); }
        }
    }

    function initMenus() {
        // A menu left open by the page this one replaced (admin AJAX
        // navigation) would stay on <body>: put it back with its old row.
        if (shared.open && !document.documentElement.contains(shared.open.toggle)) {
            closeMenu();
        }

        each('.travel-cron-menu-wrap', function (wrap) {
            if (wrap.__travelCronMenu) { return; }
            wrap.__travelCronMenu = true;
            var toggle = wrap.querySelector('.travel-cron-menu-toggle');
            var menu = wrap.querySelector('.travel-cron-menu');
            if (!toggle || !menu) { return; }

            toggle.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                if (shared.open && shared.open.menu === menu) { closeMenu(); return; }
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
                    toggle.focus(); // the menu sits at the end of <body>: Tab moves on from its row
                }
            });

            // A copy keeps its "Copied" visible for a moment, then the menu closes.
            menu.addEventListener('click', function (e) {
                var item = e.target && e.target.closest ? e.target.closest('[role="menuitem"]') : null;
                if (item) {
                    setTimeout(function () {
                        if (shared.open && shared.open.menu === menu) { closeMenu(); }
                    }, 900);
                }
            });
        });

        if (!shared.docBound) {
            shared.docBound = true;
            document.addEventListener('click', function (e) {
                var open = shared.open;
                if (open && !open.menu.contains(e.target) && !open.toggle.contains(e.target)) {
                    closeMenu();
                }
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && shared.open) {
                    var t = shared.open.toggle;
                    closeMenu();
                    t.focus();
                }
            });
            // Pinned to the window, the menu would drift off its button when
            // the page (or the card) scrolls, or the window changes size.
            window.addEventListener('scroll', function (e) {
                var open = shared.open;
                if (open && !(e.target instanceof Node && open.menu.contains(e.target))) {
                    closeMenu();
                }
            }, true);
            window.addEventListener('resize', closeMenu);
        }
    }

    function init() {
        initCopyButtons();
        initRunForms();
        initCommands();
        initMenus();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

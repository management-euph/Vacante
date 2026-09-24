// Eurosite → Hotels (eurosite.hotels): the selection bar.
//
//  - counts what is ticked, and how many of those can become products (the
//    row's data-eligible, decided server-side by the same rule the product
//    step uses);
//  - "Select all that can become products" / "Clear" / the header checkbox
//    for the page;
//  - "Create products (N)" is enabled only when N > 0 and asks first;
//    "Check availability now" asks first, and says so when nothing is
//    ticked (it then checks every listed destination).
//
// A file rather than an inline <script> (CS-Cart rewrites inline scripts for
// CSP nonces). Loaded inside the mainbox capture, so admin AJAX navigation
// runs it again: hence the guard on the root element.
(function () {
    function init() {
        var root = document.getElementById('es-hotels');
        if (!root || root.__esHotels) { return; }
        root.__esHotels = true;

        var form = document.getElementById('es-hotels-form');
        var pageBox = document.getElementById('es-check-page');
        var createBtn = document.getElementById('es-create-btn');
        var checkBtn = document.getElementById('es-check-btn');

        function boxes() {
            return Array.prototype.slice.call(root.querySelectorAll('.es-row-check'));
        }

        function set(id, value) {
            var el = document.getElementById(id);
            if (el) { el.textContent = String(value); }
        }

        function refresh() {
            var all = boxes();
            var ticked = all.filter(function (b) { return b.checked; });
            var ok = ticked.filter(function (b) { return b.getAttribute('data-eligible') === '1'; }).length;
            var skip = ticked.length - ok;
            set('es-sel-count', ticked.length);
            set('es-sel-ok', ok);
            set('es-sel-skip', skip);
            set('es-create-n', ok);
            var skipWrap = document.getElementById('es-sel-skip-wrap');
            if (skipWrap) { skipWrap.hidden = skip === 0; }
            if (createBtn) { createBtn.disabled = ok === 0; }
            if (pageBox) {
                pageBox.checked = all.length > 0 && ticked.length === all.length;
                pageBox.indeterminate = ticked.length > 0 && ticked.length < all.length;
            }
            all.forEach(function (b) {
                var row = b.closest ? b.closest('tr') : null;
                if (row) { row.classList.toggle('is-selected', b.checked); }
            });
            return { ticked: ticked.length, ok: ok };
        }

        root.addEventListener('change', function (e) {
            var t = e.target;
            if (t === pageBox) {
                boxes().forEach(function (b) { b.checked = pageBox.checked; });
            }
            if (t === pageBox || (t.classList && t.classList.contains('es-row-check'))) {
                refresh();
            }
        });

        var eligibleBtn = document.getElementById('es-select-eligible');
        if (eligibleBtn) {
            eligibleBtn.addEventListener('click', function (e) {
                e.preventDefault();
                boxes().forEach(function (b) { b.checked = b.getAttribute('data-eligible') === '1'; });
                refresh();
            });
        }
        var clearBtn = document.getElementById('es-select-clear');
        if (clearBtn) {
            clearBtn.addEventListener('click', function (e) {
                e.preventDefault();
                boxes().forEach(function (b) { b.checked = false; });
                refresh();
            });
        }

        function ask(e, question) {
            if (question && !window.confirm(question)) {
                e.preventDefault();
                e.stopImmediatePropagation();
                return false;
            }
            return true;
        }

        if (createBtn) {
            createBtn.addEventListener('click', function (e) {
                var s = refresh();
                if (s.ok === 0) { e.preventDefault(); return; }
                ask(e, (root.getAttribute('data-txt-confirm-create') || '').split('[n]').join(String(s.ok)));
            });
        }
        if (checkBtn) {
            checkBtn.addEventListener('click', function (e) {
                var s = refresh();
                ask(e, root.getAttribute(s.ticked === 0 ? 'data-txt-confirm-check-all' : 'data-txt-confirm-check') || '');
            });
        }

        // No "disable the buttons on submit" here: CS-Cart's admin scripts
        // submit the form themselves, carrying the clicked button's
        // dispatch[...] name, and a disabled button can drop out of that.
        // A second run is refused anyway: the dispatcher holds a per-mode lock.
        if (!form) { return; }

        refresh();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

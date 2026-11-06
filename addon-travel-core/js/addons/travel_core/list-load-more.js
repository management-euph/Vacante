/**
 * Travel Core — "Load N more" under the hotel list (board 15,
 * blocks/product_list_templates/travelcore_cards_list.tpl, _grid.tpl).
 *
 * The button is a plain link to the next page, so it works without this
 * script and search engines follow it. With the script, a click fetches that
 * page, appends its cards under the ones shown, takes over its button (the
 * next N and the page after) or drops the button on the last page, updates
 * "Showing 1–29 of 29" and moves the focus to the first new card. If the
 * fetch fails, the browser simply opens the next page.
 *
 * Markup (one list per page):
 *   [data-travel-list]                         the list's wrapper
 *     [data-travel-list-items]                 the <ul> of cards
 *     [data-travel-list-more-box]              under it, on every page but the last
 *       [data-travel-list-shown]               data-from, data-to, data-total, data-text ("… [from]–[to] … [total]")
 *       a[data-travel-list-more]               the link to the next page
 *
 * One listener on the document: lists that arrive later (CS-Cart's AJAX
 * sorting and filters) work without re-initialising.
 */
(function () {
    'use strict';

    if (window.__travelListLoadMore) {
        return;
    }
    window.__travelListLoadMore = true;

    function shownText(node, from, to, total) {
        var text = node.getAttribute('data-text') || '';

        return text.replace('[from]', from).replace('[to]', to).replace('[total]', total);
    }

    function append(list, link, html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var items = list.querySelector('[data-travel-list-items]');
        var incoming = doc.querySelector('[data-travel-list-items]');
        if (!items || !incoming) {
            return false;
        }

        var added = [];
        Array.prototype.slice.call(incoming.children).forEach(function (card) {
            var node = document.importNode(card, true);
            items.appendChild(node);
            added.push(node);
        });

        var box = list.querySelector('[data-travel-list-more-box]');
        var shown = box ? box.querySelector('[data-travel-list-shown]') : null;
        var from = shown ? shown.getAttribute('data-from') : '1';
        var total = shown ? shown.getAttribute('data-total') : '';
        var nextBox = doc.querySelector('[data-travel-list-more-box]');
        var nextShown = nextBox ? nextBox.querySelector('[data-travel-list-shown]') : null;

        if (box && nextBox && nextShown) {
            var replacement = document.importNode(nextBox, true);
            var replacementShown = replacement.querySelector('[data-travel-list-shown]');
            replacementShown.setAttribute('data-from', from);
            replacementShown.textContent = shownText(replacementShown, from, nextShown.getAttribute('data-to'), total);
            box.parentNode.replaceChild(replacement, box);
        } else if (box) {
            // The last page: everything is shown, the button goes.
            if (shown) {
                shown.textContent = shownText(shown, from, total, total);
            }
            var stale = box.querySelector('[data-travel-list-more]');
            if (stale) {
                stale.parentNode.removeChild(stale);
            }
        }

        var first = added.length ? added[0].querySelector('a[href]:not([tabindex="-1"])') : null;
        if (first) {
            first.focus();
        }

        return added.length > 0;
    }

    document.addEventListener('click', function (event) {
        var link = event.target && event.target.closest ? event.target.closest('a[data-travel-list-more]') : null;
        if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        var list = link.closest('[data-travel-list]');
        if (!list || typeof window.fetch !== 'function' || typeof window.DOMParser !== 'function') {
            return;
        }
        event.preventDefault();
        if (link.getAttribute('aria-busy') === 'true') {
            return;
        }
        link.setAttribute('aria-busy', 'true');

        var href = link.href;
        window.fetch(href, { credentials: 'same-origin', headers: { Accept: 'text/html' } })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }

                return response.text();
            })
            .then(function (html) {
                if (!append(list, link, html)) {
                    throw new Error('no cards');
                }
            })
            .catch(function () {
                window.location.assign(href);
            })
            .then(function () {
                link.removeAttribute('aria-busy');
            });
    });
})();

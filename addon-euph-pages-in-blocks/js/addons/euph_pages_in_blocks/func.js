/**
 * Pages in blocks — the storefront side of the Page content block.
 *
 * Tabs template: the markup comes with the tab row hidden and every panel
 * shown (so it reads without this script); here the row is shown and wired
 * as WAI-ARIA tabs: one panel open, a click or the arrow keys (reversed for
 * right-to-left pages), Home and End select a tab, and only the selected
 * tab is a Tab stop.
 *
 * Accordion template: native <details> sections, which need no script. With
 * "Keep only one section open" they share a name, so the browser closes the
 * others; for browsers that do not, opening one closes the others here.
 *
 * Both: an address ending in #<panel or section id> (or the id of an element
 * inside one) opens that tab or section, on load and when the hash changes.
 * When printing, every tab panel and section is shown, then put back.
 *
 * Blocks drawn later by AJAX are set up on CS-Cart's ce.commoninit; a block
 * is never set up twice. Plain ES5: CS-Cart bundles the storefront scripts.
 */
(function (_, $) {
    'use strict';

    var READY = 'data-euph-pib-ready';
    var printing = false;

    function toArray(list) {
        return Array.prototype.slice.call(list);
    }

    function isRtl(element) {
        return window.getComputedStyle ? window.getComputedStyle(element).direction === 'rtl' : false;
    }

    // ── Tabs ──────────────────────────────────────────────────────────────

    function tabsOf(root) {
        var list = root.querySelector('.euph-pib__tablist');
        return list ? toArray(list.querySelectorAll('.euph-pib__tab')) : [];
    }

    function panelOf(tab) {
        return document.getElementById(tab.getAttribute('data-euph-pib-target') || '');
    }

    function select(root, tab, focus) {
        tabsOf(root).forEach(function (other) {
            var on = other === tab;
            var panel = panelOf(other);
            other.setAttribute('aria-selected', on ? 'true' : 'false');
            other.setAttribute('tabindex', on ? '0' : '-1');
            if (panel) {
                if (on) {
                    panel.removeAttribute('hidden');
                } else {
                    panel.setAttribute('hidden', '');
                }
            }
        });
        if (focus) {
            tab.focus();
        }
    }

    function onTabKey(root, tab, event) {
        var tabs = tabsOf(root);
        var index = tabs.indexOf(tab);
        var step = isRtl(root) ? -1 : 1;
        var next;

        switch (event.key) {
            case 'ArrowRight':
            case 'Right':
                next = tabs[(index + step + tabs.length) % tabs.length];
                break;
            case 'ArrowLeft':
            case 'Left':
                next = tabs[(index - step + tabs.length) % tabs.length];
                break;
            case 'Home':
                next = tabs[0];
                break;
            case 'End':
                next = tabs[tabs.length - 1];
                break;
            default:
                return;
        }
        event.preventDefault();
        select(root, next, true);
    }

    function initTabs(root) {
        var tabs = tabsOf(root);
        var list = root.querySelector('.euph-pib__tablist');
        if (root.getAttribute(READY) === 'Y' || !list || !tabs.length) {
            return;
        }
        root.setAttribute(READY, 'Y');

        list.setAttribute('role', 'tablist');
        list.removeAttribute('hidden');
        tabs.forEach(function (tab) {
            var panel = panelOf(tab);
            tab.setAttribute('role', 'tab');
            if (panel) {
                tab.setAttribute('aria-controls', panel.id);
                panel.setAttribute('role', 'tabpanel');
                panel.setAttribute('aria-labelledby', tab.id);
                panel.setAttribute('tabindex', '0');
            }
            tab.addEventListener('click', function () {
                select(root, tab, false);
            });
            tab.addEventListener('keydown', function (event) {
                onTabKey(root, tab, event);
            });
        });
        root.classList.add('euph-pib--tabs-ready');

        var selected = tabs.filter(function (tab) {
            return tab.getAttribute('aria-selected') === 'true';
        })[0];
        select(root, selected || tabs[0], false);
    }

    // ── Accordion ─────────────────────────────────────────────────────────

    function sectionsOf(root) {
        return toArray(root.querySelectorAll('.euph-pib__section'));
    }

    function initAccordion(root) {
        if (root.getAttribute(READY) === 'Y') {
            return;
        }
        root.setAttribute(READY, 'Y');
        if (!root.hasAttribute('data-euph-pib-one-open')) {
            return;
        }
        // "toggle" does not bubble: listen in the capture phase.
        root.addEventListener('toggle', function (event) {
            var opened = event.target;
            if (printing || !opened.open || !opened.classList || !opened.classList.contains('euph-pib__section')) {
                return;
            }
            sectionsOf(root).forEach(function (section) {
                if (section !== opened && section.open) {
                    section.open = false;
                }
            });
        }, true);
    }

    // ── Address hash ──────────────────────────────────────────────────────

    /** Opens the tab and the sections that hold an element; true if any. */
    function reveal(element) {
        var found = false;
        var node = element;
        while (node && node.nodeType === 1) {
            if (node.classList.contains('euph-pib__section') && node.tagName === 'DETAILS') {
                node.open = true;
                found = true;
            }
            if (node.classList.contains('euph-pib__panel')) {
                var root = node.closest('[data-euph-pib-tabs]');
                var tab = root ? tabsOf(root).filter(function (candidate) {
                    return candidate.getAttribute('data-euph-pib-target') === node.id;
                })[0] : null;
                if (tab) {
                    select(root, tab, false);
                    found = true;
                }
            }
            node = node.parentNode;
        }
        return found;
    }

    function openFromHash() {
        var hash = window.location.hash ? window.location.hash.slice(1) : '';
        var id;
        try {
            id = decodeURIComponent(hash);
        } catch (e) {
            id = hash;
        }
        var target = id ? document.getElementById(id) : null;
        if (!target || !target.closest('.euph-pib') || !reveal(target)) {
            return false;
        }
        if (target.scrollIntoView) {
            target.scrollIntoView();
        }
        return true;
    }

    // ── Printing: everything shown, then put back ────────────────────────

    function beforePrint() {
        printing = true;
        toArray(document.querySelectorAll('.euph-pib__section')).forEach(function (section) {
            if (section.hasAttribute('name')) {
                section.setAttribute('data-euph-pib-name', section.getAttribute('name'));
                section.removeAttribute('name');
            }
            if (!section.open) {
                section.setAttribute('data-euph-pib-print', 'Y');
                section.open = true;
            }
        });
    }

    function afterPrint() {
        toArray(document.querySelectorAll('[data-euph-pib-print]')).forEach(function (section) {
            section.removeAttribute('data-euph-pib-print');
            section.open = false;
        });
        toArray(document.querySelectorAll('[data-euph-pib-name]')).forEach(function (section) {
            section.setAttribute('name', section.getAttribute('data-euph-pib-name'));
            section.removeAttribute('data-euph-pib-name');
        });
        printing = false;
    }

    // ── Set-up ────────────────────────────────────────────────────────────

    function init(context) {
        var scope = context && context.jquery ? context[0] : context;
        if (!scope || typeof scope.querySelectorAll !== 'function') {
            scope = document;
        }
        toArray(scope.querySelectorAll('[data-euph-pib-tabs]')).forEach(initTabs);
        toArray(scope.querySelectorAll('.euph-pib--accordion')).forEach(initAccordion);
    }

    window.EuphPagesInBlocks = {
        init: init,
        openFromHash: openFromHash,
        beforePrint: beforePrint,
        afterPrint: afterPrint
    };

    if ($ && $.ceEvent) {
        $.ceEvent('on', 'ce.commoninit', function (context) {
            init(context);
        });
    }

    function start() {
        init(document);
        openFromHash();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
    window.addEventListener('hashchange', openFromHash);
    window.addEventListener('beforeprint', beforePrint);
    window.addEventListener('afterprint', afterPrint);
}(window.Tygh, window.Tygh ? window.Tygh.$ : window.jQuery));

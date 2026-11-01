/**
 * Travel Core — hotel page Design A, several rooms
 * (components/design_a_groups.tpl, the owner's boards 13c; phase 5 of
 * docs/hotel-page-design-a-plan.md).
 *
 *  - One tab per group of rooms with the same guests (two or three rooms
 *    with different guests: one per room); arrow keys move between them.
 *  - A group's till counts its rooms: at most the group's (what is left of
 *    it to choose), at most the rooms left of the type, which every group
 *    shares (data-quota / data-left on the room card). The total, the struck
 *    total and the button follow the count.
 *  - "Alege pentru …" chooses; the button turns into "Aleasă" (pressed) and
 *    pressing it again lets go. A group already complete is chosen afresh.
 *    A note says what was added ("+7.240 €") with "Anulează"; a complete
 *    group opens the next one still to choose.
 *  - A group chosen in part shows it on its tab (amber, "6 din 8 alese ·
 *    mai alegi 2"); when the type it took ran out, a note offers the closest
 *    type for the rest: the same meal, enough rooms left, the difference per
 *    room said, chosen only on a click.
 *  - The bar: progress, what is chosen (with "Schimbă"), the total so far,
 *    "Mergi la …" only when the next rooms to choose are not the ones open,
 *    then the only booking button. A room on request is booked apart: the
 *    button says both ("Rezervă 11 camere · cere 1 cameră") and the total
 *    what is paid now.
 *  - Booking: the supplier's GET form (data-travel-book-form) with the rooms
 *    as a JSON list in room order, each its option's room_json plus room_num,
 *    adults, children and childrenAges — the entries the supplier's booking
 *    of several rooms reads — and their total.
 *
 * pdp-design-a.js keeps driving each group's list (filters, radios and
 * tills, the ⓘ). Document-level listeners: the rooms arrive with the inline
 * search (travel:results-swapped).
 */
(function () {
    'use strict';

    if (window.TravelPdpDesignARooms) {
        return;
    }

    var STATES = typeof WeakMap === 'function' ? new WeakMap() : null;

    // ── Pure helpers (exported for the tests) ─────────────────────────────

    /** The plural form a count takes (RoomResultsBuilder::form()). */
    function form(n) {
        if (n === 1) {
            return 'one';
        }
        var tail = n % 100;

        return n === 0 || (tail >= 1 && tail <= 19) ? 'few' : 'many';
    }

    /** A lang var's [placeholders] filled in; unknown ones stay. */
    function fill(template, values) {
        return String(template || '').replace(/\[([a-z_]+)]/g, function (match, key) {
            return Object.prototype.hasOwnProperty.call(values, key) ? String(values[key]) : match;
        });
    }

    /** MoneyFormatter::formatDisplay() for an amount in the shopper's currency. */
    function formatMoney(amount, money) {
        var decimals = Math.max(0, money.decimals || 0);
        var scale = Math.pow(10, decimals);
        var fixed = (Math.round(Math.abs(amount) * scale + 1e-9) / scale).toFixed(decimals);
        var parts = fixed.split('.');
        var whole = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, money.thousands);
        var number = (amount < 0 ? '-' : '') + (decimals > 0 ? whole + money.decimal + parts[1] : whole);
        if (!money.symbol) {
            return number;
        }

        return money.after ? number + ' ' + money.symbol : money.symbol + number;
    }

    /** Room numbers as the guest reads them: runs as ranges ("1–8"), the rest listed. */
    function numbers(rooms) {
        var parts = [];
        var start = null;
        var prev = null;
        rooms.forEach(function (room) {
            if (prev !== null && room === prev + 1) {
                prev = room;
                return;
            }
            if (start !== null) {
                parts.push(start === prev ? String(start) : start + '–' + prev);
            }
            start = prev = room;
        });
        if (start !== null) {
            parts.push(start === prev ? String(start) : start + '–' + prev);
        }

        return parts.join(', ');
    }

    function toInt(value) {
        var n = parseInt(value, 10);

        return isNaN(n) ? 0 : n;
    }

    function toNumber(value) {
        var n = parseFloat(value);

        return isNaN(n) ? 0 : n;
    }

    function each(root, selector, fn) {
        Array.prototype.forEach.call(root.querySelectorAll(selector), fn);
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined) {
            node.textContent = text;
        }

        return node;
    }

    // ── State ─────────────────────────────────────────────────────────────

    function readState(section) {
        var money = {
            symbol: section.getAttribute('data-money-symbol') || '',
            after: section.getAttribute('data-money-after') === '1',
            decimals: toInt(section.getAttribute('data-money-decimals')),
            decimal: section.getAttribute('data-money-decimal') || '.',
            thousands: section.getAttribute('data-money-thousands') || ''
        };
        var groups = [];
        each(section, '[data-travel-group]', function (panel) {
            var key = panel.getAttribute('data-travel-group');
            groups.push({
                key: key,
                panel: panel,
                tab: section.querySelector('[data-travel-gtab="' + key + '"]'),
                size: toInt(panel.getAttribute('data-size')),
                rooms: (panel.getAttribute('data-rooms') || '').split(',').map(toInt).filter(function (n) { return n > 0; }),
                adults: toInt(panel.getAttribute('data-adults')),
                ages: (panel.getAttribute('data-ages') || '').split(',').map(function (age) { return age.trim(); })
                    .filter(function (age) { return age !== ''; }).map(toInt),
                guests: panel.getAttribute('data-guests') || ''
            });
        });

        return {
            section: section,
            money: money,
            groups: groups,
            rooms: toInt(section.getAttribute('data-rooms')),
            nights: section.getAttribute('data-nights') || '',
            choices: [],
            note: null,
            seq: 0,
            open: groups.length > 0 ? groups[0].key : ''
        };
    }

    function stateOf(section) {
        var state = STATES ? STATES.get(section) : section.travelRoomsState;
        if (!state) {
            state = readState(section);
            if (STATES) {
                STATES.set(section, state);
            } else {
                section.travelRoomsState = state;
            }
        }

        return state;
    }

    function t(state, name, values) {
        return fill(state.section.getAttribute('data-t-' + name), values || {});
    }

    function roomsText(state, n) {
        return t(state, 'rooms-' + form(n), { n: n });
    }

    function price(state, amount) {
        return formatMoney(amount, state.money);
    }

    function group(state, key) {
        for (var i = 0; i < state.groups.length; i++) {
            if (state.groups[i].key === key) {
                return state.groups[i];
            }
        }

        return null;
    }

    function choicesOf(state, key) {
        return state.choices.filter(function (choice) { return choice.group === key; });
    }

    function used(state, key) {
        return choicesOf(state, key).reduce(function (sum, choice) { return sum + choice.qty; }, 0);
    }

    function remaining(state, g) {
        return Math.max(0, g.size - used(state, g.key));
    }

    /** Rooms of a type taken by the choices, those of one group left out. */
    function taken(state, quota, except) {
        return state.choices.reduce(function (sum, choice) {
            return choice.quota === quota && choice.group !== except ? sum + choice.qty : sum;
        }, 0);
    }

    /**
     * How many rooms a card can still take for its group: what the group has
     * left to choose (all of it again once complete: a new choice replaces
     * the group's), at most the rooms of the type no other choice holds.
     */
    function capOf(state, g, card) {
        var complete = remaining(state, g) === 0;
        var room = complete ? g.size : remaining(state, g);
        var left = toInt(card.getAttribute('data-left'));
        if (left <= 0) {
            return room;
        }

        return Math.max(0, Math.min(room, left - taken(state, card.getAttribute('data-quota'), complete ? g.key : null)));
    }

    function chosenFor(state, card, index) {
        for (var i = 0; i < state.choices.length; i++) {
            var choice = state.choices[i];
            if (choice.card === card && choice.option === index) {
                return choice;
            }
        }

        return null;
    }

    /** The room numbers each choice of a group books, in the order chosen. */
    function numbering(state, g) {
        var next = 0;
        var map = {};
        choicesOf(state, g.key).forEach(function (choice) {
            map[choice.id] = g.rooms.slice(next, next + choice.qty);
            next += choice.qty;
        });

        return map;
    }

    function roomsLabel(state, rooms) {
        return rooms.length === 1 ? t(state, 'room', { n: rooms[0] }) : t(state, 'numbers', { numbers: numbers(rooms) });
    }

    function nextToChoose(state, after) {
        var start = 0;
        state.groups.forEach(function (g, i) {
            if (g.key === after) {
                start = i + 1;
            }
        });
        for (var n = 0; n < state.groups.length; n++) {
            var g = state.groups[(start + n) % state.groups.length];
            if (remaining(state, g) > 0) {
                return g;
            }
        }

        return null;
    }

    function tillQty(till) {
        var value = till.querySelector('[data-travel-qty]');

        return value ? toInt(value.textContent) : 1;
    }

    // ── Rendering ─────────────────────────────────────────────────────────

    function icon(state, name) {
        var template = state.section.querySelector('[data-travel-icon-' + name + ']');

        return template && template.content ? template.content.cloneNode(true) : document.createTextNode('');
    }

    function renderTill(state, g, card, till) {
        var index = till.getAttribute('data-travel-for');
        var chosen = chosenFor(state, card, index);
        var cap = capOf(state, g, card);
        var qty = chosen ? chosen.qty : Math.max(cap > 0 ? 1 : 0, Math.min(tillQty(till) || cap, cap));
        var unit = toNumber(till.getAttribute('data-unit'));
        var unitWas = toNumber(till.getAttribute('data-unit-was'));
        var shown = Math.max(qty, 1);

        var value = till.querySelector('[data-travel-qty]');
        if (value) {
            value.textContent = String(shown);
            each(till, '[data-travel-qty-step]', function (step) {
                var delta = toInt(step.getAttribute('data-travel-qty-step'));
                step.disabled = !!chosen || (delta < 0 ? shown <= 1 : shown >= cap);
            });
        }
        var label = till.querySelector('[data-travel-till-label]');
        if (label) {
            label.textContent = t(state, 'total-rooms', { rooms: roomsText(state, shown), nights: state.nights });
        }
        var total = till.querySelector('[data-travel-till-total]');
        if (total) {
            total.textContent = price(state, unit * shown);
        }
        var was = till.querySelector('[data-travel-till-was] s');
        if (was && unitWas > 0) {
            was.textContent = price(state, unitWas * shown);
        }

        var out = !chosen && cap === 0 && toInt(card.getAttribute('data-left')) > 0;
        var capNote = till.querySelector('[data-travel-cap]');
        if (capNote) {
            var room = remaining(state, g) === 0 ? g.size : remaining(state, g);
            if (out) {
                capNote.textContent = t(state, 'sold-out');
                capNote.hidden = false;
            } else if (!chosen && g.size > 1 && cap > 0 && cap < room) {
                capNote.textContent = t(state, 'cap', { n: cap, size: room });
                capNote.hidden = false;
            } else {
                capNote.textContent = '';
                capNote.hidden = true;
            }
        }

        var button = till.querySelector('[data-travel-choose]');
        if (button) {
            var what = g.size > 1 ? roomsText(state, shown) : t(state, 'room', { n: g.rooms[0] });
            button.setAttribute('aria-pressed', chosen ? 'true' : 'false');
            button.disabled = out;
            while (button.firstChild) {
                button.removeChild(button.firstChild);
            }
            if (chosen) {
                button.appendChild(icon(state, 'check'));
                button.appendChild(document.createTextNode(g.size > 1 ? t(state, 'chosen-rooms', { rooms: what }) : t(state, 'chosen')));
            } else {
                button.appendChild(document.createTextNode(g.size > 1 ? t(state, 'choose-rooms', { rooms: what }) : t(state, 'choose-room', { n: g.rooms[0] })));
            }
            var title = card.getAttribute('data-title') || '';
            button.setAttribute('aria-label', button.textContent + ': ' + title + ', ' + (till.getAttribute('data-meal-name') || '') + ', ' + price(state, unit * shown));
        }

        return out;
    }

    function renderCards(state) {
        state.groups.forEach(function (g) {
            each(g.panel, '[data-travel-room]', function (card) {
                var out = false;
                each(card, '.travel-pdpa-till--group', function (till) {
                    out = renderTill(state, g, card, till) || out;
                });
                card.classList.toggle('travel-pdpa-rcard--out', out);
            });
        });
    }

    function renderTabs(state) {
        state.groups.forEach(function (g) {
            if (!g.tab) {
                return;
            }
            var open = g.key === state.open;
            g.tab.setAttribute('aria-selected', open ? 'true' : 'false');
            g.tab.setAttribute('tabindex', open ? '0' : '-1');
            g.panel.hidden = !open;

            var stateEl = g.tab.querySelector('[data-travel-gtab-state]');
            if (!stateEl) {
                return;
            }
            var count = used(state, g.key);
            var left = remaining(state, g);
            stateEl.className = 'travel-pdpa-gtab__s';
            while (stateEl.firstChild) {
                stateEl.removeChild(stateEl.firstChild);
            }
            if (count > 0 && left === 0) {
                var sum = choicesOf(state, g.key).reduce(function (total, choice) { return total + choice.unit * choice.qty; }, 0);
                stateEl.appendChild(icon(state, 'ok'));
                stateEl.appendChild(el('span', 'travel-pdpa-sr', t(state, 'state-done-' + (g.size === 1 ? 'one' : 'many')) + ' '));
                stateEl.appendChild(el('b', 'travel-pdpa-num', price(state, sum)));
            } else if (count > 0) {
                stateEl.classList.add('travel-pdpa-gtab__s--part');
                stateEl.appendChild(el('span', 'travel-pdpa-dot'));
                stateEl.lastChild.setAttribute('aria-hidden', 'true');
                stateEl.appendChild(document.createTextNode(t(state, 'state-part', { chosen: count, size: g.size, left: roomsText(state, left) })));
            } else if (open) {
                stateEl.classList.add('travel-pdpa-gtab__s--now');
                stateEl.appendChild(document.createTextNode(t(state, 'state-now')));
            } else {
                stateEl.appendChild(el('span', 'travel-pdpa-dot'));
                stateEl.lastChild.setAttribute('aria-hidden', 'true');
                stateEl.appendChild(document.createTextNode(t(state, 'state-todo-' + (g.size === 1 ? 'one' : 'many'))));
            }
        });
    }

    /** The closest type for the rest of a group whose last choice's type ran out. */
    function suggestion(state, g) {
        var choices = choicesOf(state, g.key);
        var left = remaining(state, g);
        if (choices.length === 0 || left === 0) {
            return null;
        }
        var last = choices[choices.length - 1];
        if (capOf(state, g, last.card) > 0) {
            return null;
        }
        var best = null;
        each(g.panel, '[data-travel-room]', function (card) {
            if (card === last.card || capOf(state, g, card) < left) {
                return;
            }
            each(card, '.travel-pdpa-till--group', function (till) {
                if ((till.getAttribute('data-meal-key') || '') !== last.mealKey) {
                    return;
                }
                var unit = toNumber(till.getAttribute('data-unit'));
                var diff = unit - last.unit;
                if (best === null || Math.abs(diff) < Math.abs(best.diff) || (Math.abs(diff) === Math.abs(best.diff) && unit < best.unit)) {
                    best = { card: card, till: till, unit: unit, diff: diff, qty: left, last: last };
                }
            });
        });

        return best;
    }

    function renderNotes(state) {
        state.groups.forEach(function (g) {
            var notes = g.panel.querySelector('[data-travel-gnotes]');
            if (notes) {
                while (notes.firstChild) {
                    notes.removeChild(notes.firstChild);
                }
            }
        });
        var openGroup = group(state, state.open);
        var notes = openGroup ? openGroup.panel.querySelector('[data-travel-gnotes]') : null;
        if (!notes) {
            return;
        }

        var all = state.choices.reduce(function (sum, choice) { return sum + choice.qty; }, 0) === state.rooms && state.rooms > 0;
        var noted = state.note ? state.choices.filter(function (choice) { return choice.id === state.note; })[0] : null;
        if (noted) {
            var g = group(state, noted.group);
            var rooms = numbering(state, g)[noted.id] || [];
            var text = t(state, 'done', {
                what: noted.title + ' · ' + noted.meal,
                rooms: rooms.length > 0 ? roomsLabel(state, rooms) : roomsText(state, noted.qty),
                amount: price(state, noted.unit * noted.qty)
            });
            if (remaining(state, g) > 0) {
                text += ' ' + t(state, 'done-left', { left: roomsText(state, remaining(state, g)), guests: g.guests });
            } else if (all) {
                text += ' ' + t(state, 'all-done', { n: state.rooms });
            }
            var note = el('div', 'travel-pdpa-note travel-pdpa-note--ok');
            note.appendChild(icon(state, 'check'));
            var body = el('p', 'travel-pdpa-note__body', text);
            body.setAttribute('role', 'status');
            note.appendChild(body);
            var undo = el('button', 'travel-pdpa-linkbtn', t(state, 'undo'));
            undo.type = 'button';
            undo.setAttribute('data-travel-undo', String(noted.id));
            undo.setAttribute('aria-label', t(state, 'undo-aria', { rooms: rooms.length > 0 ? roomsLabel(state, rooms) : roomsText(state, noted.qty) }));
            note.appendChild(undo);
            notes.appendChild(note);
        }

        var best = openGroup ? suggestion(state, openGroup) : null;
        if (best) {
            var hint = el('div', 'travel-pdpa-note');
            var hintBody = el('div', 'travel-pdpa-note__body');
            var lead = el('p');
            lead.appendChild(el('strong', '', t(state, 'suggest-lead', { rooms: roomsText(state, best.qty) })));
            lead.appendChild(document.createTextNode(' ' + t(state, 'suggest', {
                room: best.card.getAttribute('data-title') || '',
                meal: best.till.getAttribute('data-meal-name') || '',
                price: price(state, best.unit),
                diff: (best.diff >= 0 ? '+' : '−') + price(state, Math.abs(best.diff)),
                was: best.last.title
            })));
            hintBody.appendChild(lead);
            var acts = el('div', 'travel-pdpa-note__acts');
            var take = el('button', 'travel-pdpa-btn travel-pdpa-btn--sec travel-pdpa-btn--sm', t(state, 'suggest-btn', {
                rooms: roomsText(state, best.qty),
                n: best.qty,
                price: price(state, best.unit),
                total: price(state, best.unit * best.qty)
            }));
            take.type = 'button';
            take.setAttribute('data-travel-suggest', best.card.id + '|' + best.till.getAttribute('data-travel-for') + '|' + best.qty);
            acts.appendChild(take);
            var seeAll = el('button', 'travel-pdpa-linkbtn', t(state, 'suggest-all'));
            seeAll.type = 'button';
            seeAll.setAttribute('data-travel-see-types', '');
            acts.appendChild(seeAll);
            hintBody.appendChild(acts);
            hint.appendChild(hintBody);
            notes.appendChild(hint);
        }
    }

    function renderBar(state) {
        var bar = state.section.querySelector('[data-travel-bar]');
        if (!bar) {
            return;
        }
        var chosen = state.choices.reduce(function (sum, choice) { return sum + choice.qty; }, 0);
        bar.hidden = chosen === 0;
        if (chosen === 0) {
            return;
        }
        var total = 0;
        var now = 0;
        var booked = 0;
        var requested = [];
        var picks = bar.querySelector('[data-travel-bar-picks]');
        while (picks && picks.firstChild) {
            picks.removeChild(picks.firstChild);
        }
        state.groups.forEach(function (g) {
            var map = numbering(state, g);
            choicesOf(state, g.key).forEach(function (choice) {
                var rooms = map[choice.id] || [];
                var amount = choice.unit * choice.qty;
                total += amount;
                if (choice.onRequest) {
                    requested = requested.concat(rooms);
                } else {
                    now += amount;
                    booked += choice.qty;
                }
                if (!picks) {
                    return;
                }
                var line = el('p', 'travel-pdpa-bbar__pick');
                var what = el('span', '', t(state, 'bar-pick', { rooms: roomsLabel(state, rooms), room: choice.title, meal: choice.meal }) + ' · ');
                what.appendChild(el('b', '', choice.qty > 1 ? choice.qty + ' × ' + price(state, choice.unit) : price(state, choice.unit)));
                line.appendChild(what);
                var change = el('button', 'travel-pdpa-linkbtn', t(state, 'change'));
                change.type = 'button';
                change.setAttribute('data-travel-change', String(choice.id));
                change.setAttribute('aria-label', t(state, 'change-aria', { rooms: roomsLabel(state, rooms) }));
                line.appendChild(change);
                picks.appendChild(line);
            });
        });

        var all = chosen === state.rooms;
        var progress = bar.querySelector('[data-travel-bar-progress]');
        if (progress) {
            progress.textContent = t(state, 'bar-progress', { n: chosen, total: state.rooms });
        }
        var meter = bar.querySelector('[data-travel-bar-meter]');
        if (meter) {
            meter.style.width = (state.rooms > 0 ? Math.round(chosen / state.rooms * 1000) / 10 : 0) + '%';
        }
        var totalEl = bar.querySelector('[data-travel-bar-total]');
        if (totalEl) {
            while (totalEl.firstChild) {
                totalEl.removeChild(totalEl.firstChild);
            }
            totalEl.className = 'travel-pdpa-bbar__total' + (all ? ' travel-pdpa-bbar__total--all' : '');
            totalEl.appendChild(document.createTextNode(t(state, all ? 'total' : 'so-far') + ' '));
            totalEl.appendChild(el('b', 'travel-pdpa-num', price(state, total)));
            if (all && requested.length > 0) {
                totalEl.appendChild(el('span', 'travel-pdpa-bbar__split', t(state, 'pay-now', { amount: price(state, now) })));
            }
        }

        var goto = bar.querySelector('[data-travel-bar-goto]');
        var next = all ? null : nextToChoose(state, state.open);
        if (goto) {
            var show = !!next && next.key !== state.open;
            goto.hidden = !show;
            if (show) {
                goto.textContent = t(state, 'goto', { rooms: roomsLabel(state, next.rooms) });
                goto.setAttribute('data-travel-goto', next.key);
                goto.setAttribute('href', '#travel-pdpa-gtab-' + next.key);
            }
        }

        var cta = bar.querySelector('[data-travel-bar-cta]');
        if (cta) {
            cta.hidden = !all;
            var button = cta.querySelector('[data-travel-book]');
            var mixed = requested.length > 0;
            if (button) {
                if (mixed) {
                    button.textContent = t(state, 'book-mixed', { booked: roomsText(state, booked), requested: roomsText(state, requested.length) });
                    button.setAttribute('aria-label', t(state, 'book-mixed-aria', {
                        booked: roomsText(state, booked),
                        requested: roomsText(state, requested.length),
                        total: price(state, total),
                        now: price(state, now)
                    }));
                } else {
                    button.textContent = t(state, 'book', { rooms: roomsText(state, state.rooms), total: price(state, total) });
                    button.setAttribute('aria-label', t(state, 'book-aria', { rooms: roomsText(state, state.rooms), total: price(state, total), nights: state.nights }));
                }
            }
            var plain = cta.querySelector('[data-travel-bar-note]');
            var request = cta.querySelector('[data-travel-bar-note-request]');
            if (plain) {
                plain.hidden = mixed;
            }
            if (request) {
                request.hidden = !mixed;
                var reply = request.querySelector('[data-travel-bar-reply]');
                if (reply) {
                    reply.textContent = t(state, 'reply-for', { rooms: roomsLabel(state, requested.slice().sort(function (a, b) { return a - b; })) });
                }
            }
        }
    }

    function render(state) {
        renderTabs(state);
        renderCards(state);
        renderNotes(state);
        renderBar(state);
    }

    // ── Actions ───────────────────────────────────────────────────────────

    function openGroup(state, key, focus) {
        if (!group(state, key)) {
            return;
        }
        state.open = key;
        render(state);
        var g = group(state, key);
        if (focus && g.tab) {
            g.tab.focus();
        }
    }

    function choose(state, g, card, till, qty) {
        var cap = capOf(state, g, card);
        var count = Math.max(1, Math.min(qty || tillQty(till) || 1, cap));
        if (cap < 1) {
            return;
        }
        if (remaining(state, g) === 0) {
            // The group is complete: a new choice replaces what it holds.
            state.choices = state.choices.filter(function (choice) { return choice.group !== g.key; });
        }
        state.seq += 1;
        var choice = {
            id: state.seq,
            group: g.key,
            card: card,
            quota: card.getAttribute('data-quota') || '',
            option: till.getAttribute('data-travel-for'),
            qty: count,
            unit: toNumber(till.getAttribute('data-unit')),
            meal: till.getAttribute('data-meal-name') || '',
            mealKey: till.getAttribute('data-meal-key') || '',
            title: card.getAttribute('data-title') || '',
            onRequest: till.getAttribute('data-on-request') === '1',
            json: till.getAttribute('data-room-json') || ''
        };
        state.choices.push(choice);
        state.note = choice.id;
        if (remaining(state, g) === 0) {
            var next = nextToChoose(state, g.key);
            if (next) {
                state.open = next.key;
            }
        }
        render(state);
    }

    function letGo(state, id) {
        var removed = null;
        state.choices = state.choices.filter(function (choice) {
            if (choice.id === id) {
                removed = choice;
                return false;
            }

            return true;
        });
        if (removed) {
            state.note = null;
            state.open = removed.group;
        }
        render(state);
    }

    /** The rooms chosen as the supplier's booking of several rooms reads them, in room order. */
    function bookingRooms(state) {
        var rooms = [];
        state.groups.forEach(function (g) {
            var map = numbering(state, g);
            choicesOf(state, g.key).forEach(function (choice) {
                (map[choice.id] || []).forEach(function (number) {
                    var entry;
                    try {
                        entry = JSON.parse(choice.json || '{}') || {};
                    } catch (e) {
                        entry = {};
                    }
                    entry.room_num = number;
                    entry.adults = g.adults;
                    entry.children = g.ages.length;
                    entry.childrenAges = g.ages.slice();
                    rooms.push(entry);
                });
            });
        });
        rooms.sort(function (a, b) { return a.room_num - b.room_num; });

        return rooms;
    }

    function book(state) {
        var form = state.section.querySelector('[data-travel-book-form]');
        var rooms = bookingRooms(state);
        if (!form || rooms.length !== state.rooms) {
            return;
        }
        var total = rooms.reduce(function (sum, room) { return sum + toNumber(room.price); }, 0);
        var roomsField = form.querySelector('[data-travel-book-rooms]');
        var totalField = form.querySelector('[data-travel-book-total]');
        if (roomsField) {
            roomsField.value = JSON.stringify(rooms);
        }
        if (totalField) {
            totalField.value = (Math.round(total * 100) / 100).toFixed(2);
        }
        form.submit();
    }

    // ── Wiring ────────────────────────────────────────────────────────────

    function sectionOf(target) {
        return target && target.closest ? target.closest('[data-travel-multi]') : null;
    }

    function groupOfPanel(state, node) {
        var panel = node.closest('[data-travel-group]');

        return panel ? group(state, panel.getAttribute('data-travel-group')) : null;
    }

    function onClick(event) {
        var target = event.target;
        var section = sectionOf(target);
        if (!section) {
            return;
        }
        var state = stateOf(section);

        var tab = target.closest('[data-travel-gtab]');
        if (tab) {
            openGroup(state, tab.getAttribute('data-travel-gtab'), false);
            return;
        }
        var step = target.closest('[data-travel-qty-step]');
        if (step && !step.disabled) {
            var stepTill = step.closest('.travel-pdpa-till--group');
            var stepCard = step.closest('[data-travel-room]');
            var stepGroup = groupOfPanel(state, step);
            if (stepTill && stepCard && stepGroup) {
                var value = stepTill.querySelector('[data-travel-qty]');
                var cap = capOf(state, stepGroup, stepCard);
                var qty = Math.max(1, Math.min(tillQty(stepTill) + toInt(step.getAttribute('data-travel-qty-step')), cap));
                if (value) {
                    value.textContent = String(qty);
                }
                renderTill(state, stepGroup, stepCard, stepTill);
            }
            return;
        }
        var chooseButton = target.closest('[data-travel-choose]');
        if (chooseButton && !chooseButton.disabled) {
            var till = chooseButton.closest('.travel-pdpa-till--group');
            var card = chooseButton.closest('[data-travel-room]');
            var g = groupOfPanel(state, chooseButton);
            if (till && card && g) {
                var held = chosenFor(state, card, till.getAttribute('data-travel-for'));
                if (held) {
                    letGo(state, held.id);
                } else {
                    choose(state, g, card, till);
                }
            }
            return;
        }
        var undo = target.closest('[data-travel-undo]');
        if (undo) {
            letGo(state, toInt(undo.getAttribute('data-travel-undo')));
            return;
        }
        var suggest = target.closest('[data-travel-suggest]');
        if (suggest) {
            var parts = (suggest.getAttribute('data-travel-suggest') || '').split('|');
            var suggestCard = document.getElementById(parts[0]);
            var suggestGroup = suggestCard ? groupOfPanel(state, suggestCard) : null;
            if (suggestCard && suggestGroup) {
                var radio = suggestCard.querySelector('[data-travel-pick][value="' + parts[1] + '"]');
                if (radio && !radio.checked) {
                    radio.checked = true;
                    radio.dispatchEvent(new Event('change', { bubbles: true }));
                }
                var suggestTill = suggestCard.querySelector('.travel-pdpa-till--group[data-travel-for="' + parts[1] + '"]');
                if (suggestTill) {
                    choose(state, suggestGroup, suggestCard, suggestTill, toInt(parts[2]));
                }
            }
            return;
        }
        if (target.closest('[data-travel-see-types]')) {
            var openNow = group(state, state.open);
            var list = openNow ? openNow.panel.querySelector('[data-travel-room-list]') : null;
            if (list && list.scrollIntoView) {
                list.scrollIntoView({ block: 'start' });
            }
            return;
        }
        var change = target.closest('[data-travel-change]');
        if (change) {
            var id = toInt(change.getAttribute('data-travel-change'));
            var held2 = state.choices.filter(function (choice) { return choice.id === id; })[0];
            if (held2) {
                openGroup(state, held2.group, false);
                if (held2.card.scrollIntoView) {
                    held2.card.scrollIntoView({ block: 'center' });
                }
            }
            return;
        }
        var goto = target.closest('[data-travel-goto]');
        if (goto) {
            event.preventDefault();
            openGroup(state, goto.getAttribute('data-travel-goto'), true);
            return;
        }
        var more = target.closest('[data-travel-bar-more]');
        if (more) {
            var bar = more.closest('[data-travel-bar]');
            var open = !bar.classList.contains('travel-pdpa-bbar--open');
            bar.classList.toggle('travel-pdpa-bbar--open', open);
            more.setAttribute('aria-expanded', open ? 'true' : 'false');
            return;
        }
        if (target.closest('[data-travel-book]')) {
            book(state);
        }
    }

    /** The tabs as a tab list: arrows, Home and End move between them. */
    function onKeydown(event) {
        var tab = event.target && event.target.closest ? event.target.closest('[data-travel-gtab]') : null;
        var section = tab ? sectionOf(tab) : null;
        if (!section) {
            return;
        }
        var state = stateOf(section);
        var keys = state.groups.map(function (g) { return g.key; });
        var at = keys.indexOf(tab.getAttribute('data-travel-gtab'));
        var to = -1;
        if (event.key === 'ArrowRight') {
            to = (at + 1) % keys.length;
        } else if (event.key === 'ArrowLeft') {
            to = (at - 1 + keys.length) % keys.length;
        } else if (event.key === 'Home') {
            to = 0;
        } else if (event.key === 'End') {
            to = keys.length - 1;
        }
        if (to >= 0) {
            event.preventDefault();
            openGroup(state, keys[to], true);
        }
    }

    /** A meal switched in a group's card: its till shows the other option (pdp-design-a.js), counted afresh. */
    function onChange(event) {
        var section = sectionOf(event.target);
        if (section && event.target.matches && event.target.matches('[data-travel-pick]')) {
            render(stateOf(section));
        }
    }

    function init() {
        each(document, '[data-travel-multi]', function (section) {
            render(stateOf(section));
        });
    }

    document.addEventListener('click', onClick);
    document.addEventListener('keydown', onKeydown);
    document.addEventListener('change', onChange);
    document.addEventListener('travel:results-swapped', init);

    window.TravelPdpDesignARooms = {
        form: form,
        fill: fill,
        formatMoney: formatMoney,
        numbers: numbers,
        bookingRooms: function (section) {
            return bookingRooms(stateOf(section));
        },
        init: init
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());

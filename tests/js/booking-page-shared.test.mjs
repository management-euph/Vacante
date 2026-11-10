import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * The shared booking page's small behaviours, one set for sphinx, novoton
 * and eurosite:
 *  - inline required-field messages instead of browser bubbles / alert()
 *    (booking-form-validation.js),
 *  - the facility chips' "+N more" toggle, the mobile bottom bar's submit
 *    proxy, the server-HTML conditions section, the "same last name as
 *    Adult 1" shortcut and the guest form kept in view (booking-conditions.js).
 */
beforeAll(async () => {
    window.TravelBooking = window.TravelBooking || {};
    window.TravelTranslations = { fieldRequired: 'Completați câmpul.', chooseOption: 'Alegeți o opțiune.' };
    await import('../../addon-travel-core/js/addons/travel_core/booking-form-validation.js');
    await import('../../addon-travel-core/js/addons/travel_core/booking-conditions.js');
});

beforeEach(() => {
    document.body.innerHTML = '';
});

describe('inline required-field messages', () => {
    function page(inner) {
        document.body.innerHTML = '<div class="travel-booking-page"><form id="f">' + inner + '</form></div>';
        return document.getElementById('f');
    }

    it('replaces the browser bubble with a message under the field', () => {
        const form = page('<div class="travel-guest-field"><input id="last" required pattern=".*\\S.*"></div>');
        const input = document.getElementById('last');
        const ev = new Event('invalid', { cancelable: true });
        input.dispatchEvent(ev);

        expect(ev.defaultPrevented).toBe(true);
        expect(input.getAttribute('aria-invalid')).toBe('true');
        expect(form.querySelector('.travel-guest-field .js-required-msg').textContent).toBe('Completați câmpul.');
    });

    it('clears the message as soon as the field is filled', () => {
        page('<div class="travel-guest-field"><input id="last" required></div>');
        const input = document.getElementById('last');
        input.dispatchEvent(new Event('invalid', { cancelable: true }));
        input.value = 'Popescu';
        input.dispatchEvent(new Event('input', { bubbles: true }));

        expect(document.querySelector('.js-required-msg')).toBeNull();
        expect(input.hasAttribute('aria-invalid')).toBe(false);
    });

    it('asks to choose one for a required radio group and marks the whole group', () => {
        page('<div class="travel-guest-field"><label><input type="radio" name="g" value="B" required></label>'
            + '<label><input type="radio" name="g" value="F" required></label></div>');
        const [male, female] = document.querySelectorAll('input[type="radio"]');
        male.dispatchEvent(new Event('invalid', { cancelable: true }));

        expect(document.querySelector('.js-required-msg').textContent).toBe('Alegeți o opțiune.');
        expect(female.getAttribute('aria-invalid')).toBe('true');

        female.checked = true;
        female.dispatchEvent(new Event('change', { bubbles: true }));
        expect(document.querySelector('.js-required-msg')).toBeNull();
        expect(male.hasAttribute('aria-invalid')).toBe(false);
    });

    it('leaves fields outside the booking page alone', () => {
        document.body.innerHTML = '<form><input id="x" required></form>';
        const ev = new Event('invalid', { cancelable: true });
        document.getElementById('x').dispatchEvent(ev);

        expect(ev.defaultPrevented).toBe(false);
        expect(document.querySelector('.js-required-msg')).toBeNull();
    });
});

describe('named messages and a red state that follows the messages', () => {
    function page(inner) {
        document.body.innerHTML = '<div class="travel-booking-page"><form id="f">' + inner + '</form></div>';
        return document.getElementById('f');
    }

    it('names the field from its label, without the required asterisk', () => {
        page('<div class="travel-guest-field"><label for="ln">Last Name<span class="travel-guest-required">*</span></label>'
            + '<input id="ln" required></div>');
        document.getElementById('ln').dispatchEvent(new Event('invalid', { cancelable: true }));

        expect(document.querySelector('.js-required-msg').textContent).toBe('Please fill in the Last Name field.');
    });

    it('names both radio options ("Please choose Male or Female.")', () => {
        page('<div class="travel-guest-field"><div class="travel-guest-radios">'
            + '<label class="travel-guest-radio"><input type="radio" name="g" value="B" required> Male</label>'
            + '<label class="travel-guest-radio"><input type="radio" name="g" value="F" required> Female</label></div></div>');
        document.querySelector('input[value="B"]').dispatchEvent(new Event('invalid', { cancelable: true }));

        expect(document.querySelector('.js-required-msg').textContent).toBe('Please choose Male or Female.');
    });

    it('keeps the red state while another message still stands for the field', () => {
        page('<div class="travel-guest-field"><input id="d" required>'
            + '<span class="travel-field-error-message js-dob-basics-msg">Format invalid</span></div>');
        const input = document.getElementById('d');
        input.dispatchEvent(new Event('invalid', { cancelable: true }));
        input.value = '12';
        input.dispatchEvent(new Event('input', { bubbles: true }));

        expect(document.querySelector('.js-required-msg')).toBeNull();
        expect(input.getAttribute('aria-invalid')).toBe('true');
    });

    it('blocks a submit with a half-typed date of birth', () => {
        const form = page('<div class="travel-guest-field"><input class="js-dob-basics" '
            + 'name="guests[room1_adult_1][dob]" value="12/05"></div>');
        const ev = new Event('submit', { cancelable: true, bubbles: true });
        form.dispatchEvent(ev);

        expect(ev.defaultPrevented).toBe(true);
        expect(form.querySelector('.js-dob-basics-msg')).not.toBeNull();
        expect(form.querySelector('input').getAttribute('aria-invalid')).toBe('true');
    });
});

describe('the first field to fix is brought to the middle of the screen', () => {
    let scrolled;
    beforeEach(async () => {
        // The `invalid` handler focuses one field per round; let the last round end.
        await new Promise((resolve) => setTimeout(resolve, 0));
        scrolled = [];
        HTMLElement.prototype.scrollIntoView = function (options) {
            scrolled.push({ id: this.id, options });
        };
    });
    afterEach(() => {
        delete HTMLElement.prototype.scrollIntoView;
        delete window.matchMedia;
    });
    function page(inner) {
        document.body.innerHTML = '<div class="travel-booking-page"><form id="f">' + inner + '</form></div>';
        return document.getElementById('f');
    }

    it('scrolls only the first missing field to the middle, then focuses it', () => {
        page('<div class="travel-guest-field"><input id="a" required></div>'
            + '<div class="travel-guest-field"><input id="b" required></div>');
        document.getElementById('a').dispatchEvent(new Event('invalid', { cancelable: true }));
        document.getElementById('b').dispatchEvent(new Event('invalid', { cancelable: true }));

        expect(scrolled).toEqual([{ id: 'a', options: { block: 'center', behavior: 'smooth' } }]);
        expect(document.activeElement.id).toBe('a');
    });

    it('jumps without the glide for a guest who asked for reduced motion', () => {
        window.matchMedia = (query) => ({ matches: query.includes('reduce'), media: query });
        page('<div class="travel-guest-field"><input id="a" required></div>');
        document.getElementById('a').dispatchEvent(new Event('invalid', { cancelable: true }));

        expect(scrolled).toEqual([{ id: 'a', options: { block: 'center', behavior: 'auto' } }]);
    });

    it('does the same for a half-typed date of birth caught on submit', () => {
        const form = page('<div class="travel-guest-field"><input id="dob" class="js-dob-basics" '
            + 'name="guests[room1_adult_1][dob]" value="12/05"></div>');
        form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));

        expect(scrolled.map((s) => s.id)).toEqual(['dob']);
        expect(document.activeElement.id).toBe('dob');
    });
});

describe('child age at check-in', () => {
    it('uses the page\'s ISO check-in when the form field is not ISO', () => {
        window.bookingData = { checkIn: '2026-10-05' };
        document.body.innerHTML = '<div class="travel-booking-page"><form id="f">'
            + '<input type="hidden" name="check_in" value="05.10.2026">'
            + '<div class="travel-guest-field"><input class="js-dob-basics" '
            + 'name="guests[room1_child_1][dob]" value="01/01/2000"></div></form></div>';
        const form = document.getElementById('f');
        const ev = new Event('submit', { cancelable: true, bubbles: true });
        form.dispatchEvent(ev);

        // 26 at check-in: not a child — blocked with the shared message.
        expect(ev.defaultPrevented).toBe(true);
        expect(form.querySelector('.js-dob-basics-msg')).not.toBeNull();
        delete window.bookingData;
    });
});

describe('facility chips "+N more"', () => {
    function chips() {
        document.body.innerHTML = '<ul class="travel-bsidebar-features" data-travel-features>'
            + '<li>Pool</li><li class="travel-feature--extra">Spa</li>'
            + '<li class="travel-features-more-item"><button type="button" data-travel-features-more '
            + 'aria-expanded="false" data-label-less="Show less">+1 more</button></li></ul>';
        window.TravelConditions.armFeatureLists(document);
        return document.querySelector('[data-travel-features]');
    }

    it('arms the collapse only when JS runs (no JS = every chip shows)', () => {
        expect(chips().classList.contains('is-collapsible')).toBe(true);
    });

    it('expands in place and toggles its own label', () => {
        const list = chips();
        const btn = list.querySelector('button');
        btn.click();
        expect(list.classList.contains('is-expanded')).toBe(true);
        expect(btn.getAttribute('aria-expanded')).toBe('true');
        expect(btn.textContent).toBe('Show less');
        btn.click();
        expect(list.classList.contains('is-expanded')).toBe(false);
        expect(btn.textContent).toBe('+1 more');
    });
});

describe('mobile bottom bar', () => {
    it('clicks the form\'s real submit button, so every guard still applies', () => {
        document.body.innerHTML = '<form id="booking-form"><button type="submit" id="real">Go</button></form>'
            + '<button type="button" data-travel-submit-proxy="booking-form">Continue</button>';
        let clicked = 0;
        document.getElementById('real').addEventListener('click', (e) => { clicked++; e.preventDefault(); });
        document.querySelector('[data-travel-submit-proxy]').click();

        expect(clicked).toBe(1);
    });

    it('does nothing while the real button is disabled (novoton: price unverified)', () => {
        document.body.innerHTML = '<form id="booking-form"><button type="submit" id="real" disabled>Go</button></form>'
            + '<button type="button" data-travel-submit-proxy="booking-form">Continue</button>';
        let clicked = 0;
        document.getElementById('real').addEventListener('click', () => { clicked++; });
        document.querySelector('[data-travel-submit-proxy]').click();

        expect(clicked).toBe(0);
    });
});

describe('conditions modal: server-rendered room section', () => {
    it('adds, replaces and removes a room section from HTML', () => {
        document.body.innerHTML = '<div id="travel-conditions-rooms"></div><p id="travel-conditions-empty"></p>';
        window.TravelConditions.setRoomHtml(1, 'Room 1', '<ol class="travel-timeline"><li>Free</li></ol>');
        const host = document.getElementById('travel-conditions-rooms');

        expect(host.querySelectorAll('[data-room="1"]').length).toBe(1);
        expect(host.querySelector('.travel-conditions-room__title').textContent).toBe('Room 1');
        expect(document.getElementById('travel-conditions-empty').classList.contains('travel-is-hidden')).toBe(true);

        window.TravelConditions.setRoomHtml(1, 'Room 1', '<p>updated</p>');
        expect(host.querySelectorAll('[data-room="1"]').length).toBe(1);
        expect(host.textContent).toContain('updated');

        window.TravelConditions.setRoomHtml(1, '', '');
        expect(host.children.length).toBe(0);
    });
});

describe('"same last name as Adult 1"', () => {
    function page(guests) {
        let fields = '';
        for (let i = 1; i <= guests; i++) {
            fields += '<input id="last' + i + '" autocomplete="family-name"><input id="first' + i + '" autocomplete="given-name">';
        }
        document.body.innerHTML = '<div class="travel-booking-page"><form id="f">'
            + '<label class="travel-same-surname" data-travel-same-surname hidden><input type="checkbox" id="same"></label>'
            + fields + '</form></div>';
        window.TravelConditions.armSameSurname(document);
        return document.getElementById('same');
    }
    function type(id, value) {
        const input = document.getElementById(id);
        input.value = value;
        input.dispatchEvent(new Event('input', { bubbles: true }));
    }
    function tick(box) {
        box.checked = true;
        box.dispatchEvent(new Event('change', { bubbles: true }));
    }
    function untick(box) {
        box.checked = false;
        box.dispatchEvent(new Event('change', { bubbles: true }));
    }

    it('is offered only to two guests or more', () => {
        expect(page(1).closest('label').hidden).toBe(true);
        expect(page(2).closest('label').hidden).toBe(false);
    });

    it('gives every guest Adult 1\'s last name, and keeps them in step', () => {
        const box = page(3);
        type('last1', 'Popescu');
        tick(box);

        expect(document.getElementById('last2').value).toBe('Popescu');
        expect(document.getElementById('last3').value).toBe('Popescu');
        expect(document.getElementById('first2').value, 'first names are their own').toBe('');

        type('last1', 'Ionescu');
        expect(document.getElementById('last3').value).toBe('Ionescu');
        expect(box.checked).toBe(true);
    });

    it('lets go when a guest types a last name of their own', () => {
        const box = page(2);
        type('last1', 'Popescu');
        tick(box);
        type('last2', 'Ilie');

        expect(box.checked).toBe(false);
        type('last1', 'Ionescu');
        expect(document.getElementById('last2').value).toBe('Ilie');
    });

    it('takes the copies back when unticked, and gives each guest what they had before', () => {
        const box = page(4);
        type('last1', 'Popescu');
        type('last3', 'Ilie');
        tick(box);
        expect(['last2', 'last3', 'last4'].map((id) => document.getElementById(id).value)).toEqual(['Popescu', 'Popescu', 'Popescu']);

        untick(box);
        expect(document.getElementById('last1').value).toBe('Popescu');
        expect(['last2', 'last3', 'last4'].map((id) => document.getElementById(id).value)).toEqual(['', 'Ilie', '']);
    });

    it('takes back the copies Adult 1 kept in step, too', () => {
        const box = page(3);
        type('last1', 'Popescu');
        tick(box);
        type('last1', 'Ionescu');
        untick(box);

        expect(document.getElementById('last2').value).toBe('');
        expect(document.getElementById('last3').value).toBe('');
    });

    it('keeps the names once a guest typed their own, and a later untick only undoes the later tick', () => {
        const box = page(3);
        type('last1', 'Popescu');
        tick(box);
        type('last2', 'Marin');
        expect(box.checked).toBe(false);
        expect(document.getElementById('last3').value, 'the other copies stay').toBe('Popescu');

        type('last1', 'Ionescu');
        expect(document.getElementById('last3').value, 'no longer in step').toBe('Popescu');

        tick(box);
        expect(document.getElementById('last2').value).toBe('Ionescu');
        untick(box);
        expect(document.getElementById('last2').value).toBe('Marin');
        expect(document.getElementById('last3').value).toBe('Popescu');
    });

    it('lets the validation script see a name taken back', () => {
        const box = page(2);
        type('last1', 'Popescu');
        tick(box);
        const seen = [];
        document.getElementById('last2').addEventListener('input', (e) => seen.push(e.target.value));
        untick(box);

        expect(seen).toEqual(['']);
    });
});

describe('the cancellation card\'s folded line', () => {
    function card(bodyHtml) {
        document.body.innerHTML = '<div class="travel-booking-page"><section id="travel-cancel-card"><details class="travel-cancel-fold">'
            + '<summary><span id="travel-cancel-preview"></span></summary>'
            + '<div id="travel-cancel-body">' + bodyHtml + '</div></details></section></div>';
        window.TravelConditions.armCancelPreview();
        return document.getElementById('travel-cancel-preview');
    }
    const terms = (line) => '<ol class="travel-timeline"></ol><template class="travel-cancel-preview-src">'
        + '<span class="travel-cancel-fold__preview travel-cancel-fold__preview--cost">' + line + '</span></template>';

    it('takes the line of the terms swapped in after the price re-check', async () => {
        const slot = card('');
        document.getElementById('travel-cancel-body').innerHTML = terms('Dacă anulați azi, plătiți 708,50 € (50%)');
        await Promise.resolve();

        expect(slot.textContent).toBe('Dacă anulați azi, plătiți 708,50 € (50%)');
        expect(slot.querySelector('.travel-cancel-fold__preview--cost')).not.toBeNull();
    });

    it('follows a later re-price, and keeps its line when new terms bring none', async () => {
        const slot = card('');
        const body = document.getElementById('travel-cancel-body');
        body.innerHTML = terms('Dacă anulați azi, plătiți 620 € (50%)');
        await Promise.resolve();
        body.innerHTML = terms('Dacă anulați azi, plătiți 650 € (50%)');
        await Promise.resolve();
        expect(slot.textContent).toBe('Dacă anulați azi, plătiți 650 € (50%)');

        body.innerHTML = '<ul class="travel-bsidebar-cancel"><li>Anulare gratuită până la 1 iunie</li></ul>';
        await Promise.resolve();
        expect(slot.textContent).toBe('Dacă anulați azi, plătiți 650 € (50%)');
    });
});

describe('phone: the summary folded into one sticky bar', () => {
    function page() {
        document.body.innerHTML = '<div class="travel-booking-page"><div class="travel-booking-layout" id="layout">'
            + '<div class="travel-sumbar"><button type="button" class="travel-sumbar__toggle" aria-expanded="true" aria-controls="travel-bsidebar" data-travel-sumbar>'
            + '<span>Adalya Elite Lara</span></button></div>'
            + '<aside class="travel-bsidebar" id="travel-bsidebar"></aside><div class="travel-booking-col-main"></div></div></div>';
        window.TravelConditions.armSummaryBar(document);
        return { layout: document.getElementById('layout'), toggle: document.querySelector('[data-travel-sumbar]') };
    }

    it('starts folded, and says so', () => {
        const { layout, toggle } = page();

        expect(layout.classList.contains('travel-booking-layout--folded')).toBe(true);
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
    });

    it('opens and closes the summary on a tap', () => {
        const { layout, toggle } = page();
        toggle.click();
        expect(toggle.getAttribute('aria-expanded')).toBe('true');
        expect(layout.classList.contains('travel-booking-layout--folded')).toBe(false);

        toggle.querySelector('span').click();
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
        expect(layout.classList.contains('travel-booking-layout--folded')).toBe(true);
    });
});

describe('a trip\'s extras move the total', () => {
    afterEach(() => {
        delete window.matchMedia;
    });
    function page() {
        window.matchMedia = (query) => ({ matches: query.includes('reduce'), media: query });
        document.body.innerHTML = '<div class="travel-booking-page">'
            + '<div class="travel-bsidebar-total"><span class="travel-price-label">Preț total<span data-travel-total-delta></span></span>'
            + '<span class="price-total">1.490 €</span></div>'
            + '<div class="travel-mobile-bar"><strong class="price-total">1.490 €</strong></div>'
            + '<section data-travel-extras data-base="1490" data-money-symbol="€" data-money-after="1" data-money-decimals="0"'
            + ' data-money-decimal="," data-money-thousands="." data-total-label="Preț total">'
            + '<input type="checkbox" id="cruise" data-amount="130">'
            + '<input type="checkbox" id="dinner" data-amount="60" checked>'
            + '<input type="checkbox" id="fixed" checked disabled>'
            + '<p data-travel-extras-live></p></section></div>';
    }
    function toggle(id) {
        const input = document.getElementById(id);
        input.checked = !input.checked;
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }
    const totals = () => [...document.querySelectorAll('.price-total')].map((el) => el.textContent);

    it('adds a ticked service to every total and says so', () => {
        page();
        toggle('cruise');

        expect(totals()).toEqual(['1.620 €', '1.620 €']);
        const chip = document.querySelector('[data-travel-total-delta]');
        expect(chip.textContent).toBe('+130 €');
        expect(chip.classList.contains('travel-total-delta--up')).toBe(true);
        expect(document.querySelector('[data-travel-extras-live]').textContent).toBe('Preț total: 1.620 €');
    });

    it('takes off a service the page opened with ticked, which the total included', () => {
        page();
        toggle('cruise');
        toggle('dinner');

        expect(totals()).toEqual(['1.560 €', '1.560 €']);
        const chip = document.querySelector('[data-travel-total-delta]');
        expect(chip.textContent).toBe('\u221260 €');
        expect(chip.classList.contains('travel-total-delta--down')).toBe(true);
    });

    it('counts up to the new total when motion is fine', () => {
        page();
        window.matchMedia = (query) => ({ matches: false, media: query });
        const frames = [];
        window.requestAnimationFrame = (cb) => { frames.push(cb); return frames.length; };
        toggle('cruise');
        frames.shift()(1000);
        frames.shift()(1225);
        const midway = totals()[0];
        frames.shift()(1460);

        expect(midway).not.toBe('1.490 €');
        expect(midway).not.toBe('1.620 €');
        expect(totals()).toEqual(['1.620 €', '1.620 €']);
        delete window.requestAnimationFrame;
    });

    it('prints money as the store does', () => {
        const usd = { symbol: '$', after: false, decimals: 2, decimal: '.', thousands: ',' };
        expect(window.TravelConditions.formatMoney(1234.5, usd)).toBe('$1,234.50');
        expect(window.TravelConditions.formatMoney(1620, { symbol: '€', after: true, decimals: 0, decimal: ',', thousands: '.' })).toBe('1.620 €');
    });
});

describe('guest form kept in view beside a tall summary', () => {
    function page(formHeight, sidebarHeight) {
        document.body.innerHTML = '<div class="travel-booking-page"><div class="travel-booking-layout">'
            + '<aside class="travel-bsidebar"></aside><div class="travel-booking-col-main"></div></div></div>';
        const col = document.querySelector('.travel-booking-col-main');
        Object.defineProperty(col, 'offsetHeight', { configurable: true, value: formHeight });
        Object.defineProperty(document.querySelector('.travel-bsidebar'), 'offsetHeight', { configurable: true, value: sidebarHeight });
        window.innerWidth = 1280;
        window.innerHeight = 800;
        window.TravelConditions.fitStickyForm();
        return col.classList.contains('travel-booking-col-main--sticky');
    }

    it('stays in view while it is the shorter column and fits the screen', () => {
        expect(page(500, 1400)).toBe(true);
    });

    it('scrolls as usual when it would not fit, or is the taller column', () => {
        expect(page(900, 1400)).toBe(false);
        expect(page(600, 500)).toBe(false);
    });
});

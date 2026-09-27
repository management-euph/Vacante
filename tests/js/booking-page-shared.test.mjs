import { beforeAll, beforeEach, describe, expect, it } from 'vitest';

/**
 * The shared booking page's small behaviours, one set for sphinx, novoton
 * and eurosite:
 *  - inline required-field messages instead of browser bubbles / alert()
 *    (booking-form-validation.js),
 *  - the facility chips' "+N more" toggle, the mobile bottom bar's submit
 *    proxy and the server-HTML conditions section (booking-conditions.js).
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

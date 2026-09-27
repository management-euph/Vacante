import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Behavioral tests for the novoton booking-form module
 * (addon-novoton-holidays/js/addons/novoton_holidays/booking-form.js) — the
 * REAL file the storefront loads, extracted from booking_form.tpl. Covers
 * the nvtLabel raw-lang-key guard, HTML escaping, the children-ages
 * collector the initial-load recalc depends on, and the submit validation.
 */
beforeAll(async () => {
    document.body.innerHTML = '<form id="novoton-booking-form"></form>';
    window.bookingData = {
        checkIn: '2026-08-10',
        numRooms: 1,
        ajaxRecalculateUrl: 'index.php?dispatch=novoton_booking.ajax_recalculate_price',
    };
    window.NovotonBookingI18n = {
        fillAllFields: 'Completați câmpurile',
        available: '_novoton_holidays.available', // raw key — guard must swap the fallback in
    };
    await import('../../addon-novoton-holidays/js/addons/novoton_holidays/booking-form.js');
});

describe('nvtLabel (raw-lang-key guard)', () => {
    it('returns the config label when it is a real translation', () => {
        expect(window.nvtLabel ?? nvtLabelFrom()).toBeTypeOf('function');
        expect(nvtLabelFrom()('fillAllFields', 'fb')).toBe('Completați câmpurile');
    });

    it('falls back for raw "_"-prefixed and missing values', () => {
        const t = nvtLabelFrom();
        expect(t('available', 'Disponibil')).toBe('Disponibil');
        expect(t('nonexistent', 'fb')).toBe('fb');
    });

    function nvtLabelFrom() {
        // Classic-script top-level functions become window globals.
        return window.nvtLabel;
    }
});

describe('escapeHtml', () => {
    it('escapes markup-significant characters', () => {
        expect(window.escapeHtml('<b>&"</b>')).toContain('&lt;b&gt;');
        expect(window.escapeHtml('')).toBe('');
    });
});

describe('collectChildrenAges', () => {
    beforeEach(() => {
        document.querySelectorAll('input').forEach((el) => el.remove());
    });

    it('collects valid child ages from the single-room inputs', () => {
        document.body.insertAdjacentHTML('beforeend', `
            <input id="child_age_r1_c1" value="4">
            <input id="child_age_r1_c2" value="17">
            <input id="child_age_r1_c3" value="">
        `);
        expect(window.collectChildrenAges(1)).toEqual([4, 17]);
    });

    it('drops adult-range and non-numeric values', () => {
        document.body.insertAdjacentHTML('beforeend', `
            <input id="child_age_r1_c1" value="18">
            <input id="child_age_r1_c2" value="abc">
            <input id="child_age_r1_c3" value="7">
        `);
        expect(window.collectChildrenAges(1)).toEqual([7]);
    });
});

describe('formatDisplayPrice (round setting + currency symbol)', () => {
    it('rounds to a whole number WITH the symbol when roundPrices is on', () => {
        window.NovotonTranslations = { currency: '£', currencyCoeff: 0.9994, roundPrices: true };
        // 453 EUR × 0.9994 = 452.7282 → rounded 453, symbol always present
        expect(window.formatDisplayPrice(453)).toBe('453 £');
    });

    it('keeps two decimals WITH the symbol when roundPrices is off', () => {
        window.NovotonTranslations = { currency: '£', currencyCoeff: 0.9994, roundPrices: false };
        expect(window.formatDisplayPrice(453)).toBe('452.73 £');
    });
});

describe('applyRecalculatedPrice price display', () => {
    beforeEach(() => {
        document.querySelectorAll('.price-total, input[name="total_price"]').forEach((el) => el.remove());
        document.body.insertAdjacentHTML('beforeend', `
            <div class="price-total">453 £</div>
            <input type="hidden" name="total_price" value="453">`);
        window.NovotonTranslations = { currency: '£', currencyCoeff: 0.9994, roundPrices: true };
        window.bookingData.numRooms = 1;
        window.bookingData.currentPrice = 453;
    });

    it('uses the SERVER-formatted price (rounding + symbol) when provided', () => {
        window.applyRecalculatedPrice(
            { new_price: 452.9, formatted_price: '453 £' },
            1, false, true,
        );
        expect(document.querySelector('.price-total').innerHTML).toBe('453 £');
    });

    it('falls back to the client formatter — never a bare number', () => {
        window.applyRecalculatedPrice({ new_price: 452.9 }, 1, false, true);
        const shown = document.querySelector('.price-total').textContent;
        expect(shown).toBe('453 £');
        expect(shown).toContain('£');
    });
});

describe('submit validation', () => {
    it('blocks submit and shows an inline message on the empty field (no alert)', () => {
        const form = document.getElementById('novoton-booking-form');
        form.innerHTML = '<div class="travel-guest-field"><input required value=""></div>';
        const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
        const ev = new Event('submit', { cancelable: true });
        form.dispatchEvent(ev);
        expect(ev.defaultPrevented).toBe(true);
        expect(alertSpy).not.toHaveBeenCalled();
        const input = form.querySelector('input');
        expect(input.getAttribute('aria-invalid')).toBe('true');
        expect(form.querySelector('.travel-guest-field .js-required-msg').textContent).toBe('Completați câmpurile');
        alertSpy.mockRestore();
    });

    it('treats an unchecked required radio group as missing', () => {
        const form = document.getElementById('novoton-booking-form');
        form.innerHTML = '<div class="travel-guest-field"><label><input type="radio" name="g" value="B" required></label>'
            + '<label><input type="radio" name="g" value="F" required></label></div>';
        const ev = new Event('submit', { cancelable: true });
        form.dispatchEvent(ev);
        expect(ev.defaultPrevented).toBe(true);

        form.querySelector('input[value="F"]').checked = true;
        form.querySelector('.js-required-msg').remove();
        const ok = new Event('submit', { cancelable: true });
        form.dispatchEvent(ok);
        expect(ok.defaultPrevented).toBe(false);
    });
});

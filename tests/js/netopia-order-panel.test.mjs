import { beforeAll, describe, expect, it } from 'vitest';

/**
 * Behavioral tests for the order page's NETOPIA refund form
 * (addon-netopia-payments-main/js/addons/netopia_payments/order_panel.js).
 *
 * A refund moves real money: the form must refuse an amount over what is
 * left, say whether the refund is full or partial, and send the controller
 * a plain decimal it can read.
 */

const MARKUP = `
<form class="netopia-order__refund" data-np-remaining="1850.00" data-np-currency="RON"
      data-txt-button="Refund {amount}" data-txt-confirm="Refund {amount} to the card?"
      data-txt-full="Refunded in full" data-txt-partial="Refunded in part" data-txt-too-much="Too much">
  <input type="text" name="amount" id="netopia_refund_amount" value="1850.00">
  <button type="button" data-np-refund-all>All</button>
  <p data-np-refund-error hidden></p>
  <details data-np-refund-review>
    <summary data-np-refund-open>Refund</summary>
    <b data-np-refund-confirm-title></b><div data-np-refund-confirm-text></div>
    <button type="submit" name="dispatch[netopia_refund.process]">Yes, refund</button>
    <button type="button" data-np-refund-cancel>Keep</button>
  </details>
</form>`;

beforeAll(async () => {
    document.body.innerHTML = MARKUP;
    await import('../../addon-netopia-payments-main/js/addons/netopia_payments/order_panel.js');
});

const $ = (sel) => document.querySelector(sel);
const type = (value) => {
    $('#netopia_refund_amount').value = value;
    $('#netopia_refund_amount').dispatchEvent(new Event('input'));
};

describe('netopia order panel', () => {
    it('parses and formats amounts the way the store shows them', () => {
        const { parseAmount, formatAmount } = window.NetopiaOrderPanel;
        expect(parseAmount('1.850,50')).toBe(1850.5);
        expect(parseAmount('1850,5')).toBe(1850.5);
        expect(parseAmount('1850.50')).toBe(1850.5);
        expect(Number.isNaN(parseAmount('12a'))).toBe(true);
        expect(formatAmount(1850.5, 'RON')).toBe('1.850,50 RON');
        expect(formatAmount(600, '')).toBe('600,00');
    });

    it('labels a full refund', () => {
        expect($('[data-np-refund-open]').textContent).toBe('Refund 1.850,00 RON');
        expect($('[data-np-refund-confirm-title]').textContent).toBe('Refund 1.850,00 RON to the card?');
        expect($('[data-np-refund-confirm-text]').textContent).toBe('Refunded in full');
    });

    it('labels a partial refund', () => {
        type('600');
        expect($('[data-np-refund-open]').textContent).toBe('Refund 600,00 RON');
        expect($('[data-np-refund-confirm-text]').textContent).toBe('Refunded in part');
        expect($('[name="dispatch[netopia_refund.process]"]').disabled).toBe(false);
    });

    it('refuses more than is left', () => {
        type('2000');
        expect($('[data-np-refund-error]').hidden).toBe(false);
        expect($('[data-np-refund-error]').textContent).toBe('Too much');
        expect($('[name="dispatch[netopia_refund.process]"]').disabled).toBe(true);
        const e = new Event('submit', { cancelable: true });
        $('form').dispatchEvent(e);
        expect(e.defaultPrevented).toBe(true);
    });

    it('"All" fills what is left', () => {
        $('[data-np-refund-all]').click();
        expect($('#netopia_refund_amount').value).toBe('1850.00');
        expect($('[data-np-refund-error]').hidden).toBe(true);
    });

    it('sends a plain decimal the controller can read', () => {
        type('1.200,50');
        const e = new Event('submit', { cancelable: true });
        $('form').dispatchEvent(e);
        expect(e.defaultPrevented).toBe(false);
        expect($('#netopia_refund_amount').value).toBe('1200.50');
    });
});

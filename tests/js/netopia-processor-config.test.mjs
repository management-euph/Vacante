import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Behavioral tests for the NETOPIA payment method settings screen
 * (addon-netopia-payments-main/js/addons/netopia_payments/processor_config.js).
 *
 * What must not break: every field stays in the one form (tabs only hide),
 * Live is never selected without a confirmation, the maximum installments
 * field stays submittable while installments are off, and "Check settings"
 * sends what the form holds NOW, for the mode that is selected.
 * "Check settings" never calls NETOPIA; it only checks what was typed.
 */

const MARKUP = `
<form name="payments_form">
<div class="netopia-cfg" id="netopia_cfg" data-mode="sandbox"
     data-blocked-sandbox="0" data-blocked-live="1"
     data-test-url="/admin.php?dispatch=netopia_config.test" data-payment-id="7"
     data-sample-order-id="1001" data-sample-total="250.00" data-sample-currency="RON"
     data-sample-email="client@example.ro" data-sample-site-url="shop.example.ro"
     data-txt-show="Show" data-txt-hide="Hide" data-txt-testing="Testing…" data-txt-test-failed="The test could not run."
     data-txt-remove-marked="Removed"
     data-txt-mode-sandbox="Sandbox" data-txt-mode-live="Live"
     data-txt-key-wrong-mode="This is a [file_mode] key file: it will not be saved in the [slot_mode] slot.">
  <div class="netopia-tabs">
    <button type="button" data-np-tab="conn" aria-selected="true">Connection</button>
    <button type="button" data-np-tab="chk" aria-selected="false">Checkout</button>
    <button type="button" data-np-tab="st" aria-selected="false">Statuses</button>
    <button type="button" data-np-tab="keys" aria-selected="false">Keys<span data-np-keys-dot hidden></span></button>
  </div>
  <div class="netopia-panel is-active" data-np-panel="conn">
    <input type="radio" name="payment_data[processor_params][mode]" id="netopia_mode_sandbox" value="sandbox" checked>
    <input type="radio" name="payment_data[processor_params][mode]" id="netopia_mode" value="live">
    <div data-np-live-confirm hidden>
      <p data-np-live-creds-missing hidden>no live api key</p>
      <p data-np-live-keys-missing hidden>missing</p>
      <button type="button" data-np-live-yes>Switch</button><button type="button" data-np-live-no>Stay</button>
    </div>
    <div class="netopia-creds netopia-creds--sandbox">
      <input type="password" id="netopia_sandbox_pos_signature" value="AB12-CD34-EF56-GH78-IJ90">
      <button type="button" data-np-reveal="netopia_sandbox_pos_signature">Show</button>
      <input type="password" id="netopia_sandbox_api_key" value="ApiKey_sandbox">
    </div>
    <div class="netopia-creds netopia-creds--live">
      <input type="password" id="netopia_live_pos_signature" value="LIVE-POS1-AAAA-BBBB-CCCC">
      <p data-np-pos-note="live" hidden>From your key file</p>
      <input type="password" id="netopia_live_api_key" value="">
    </div>
    <a href="#" data-np-show-all-creds>both</a>
    <button type="button" data-np-test-run>Check settings</button>
    <div data-np-test-intro>intro</div><div data-np-test-summary hidden></div><ul data-np-test-list hidden></ul>
  </div>
  <div class="netopia-panel" data-np-panel="chk">
    <input type="checkbox" id="netopia_allow_installments" value="Y">
    <input type="number" id="netopia_max_installments" value="6">
    <input type="text" id="netopia_order_description" value="comanda #[order_id] din [site_url]">
    <button type="button" data-np-token="[total]">[total]</button>
    <code data-np-desc-preview></code>
  </div>
  <div class="netopia-panel" data-np-panel="st">
    <button type="button" data-np-reset>Reset</button>
    <table><tbody>
      <tr id="row5"><td>Confirmed</td><td><select id="s5" data-np-default="P"><option value="P">P</option><option value="C">C</option></select></td></tr>
      <tr id="row12"><td>Declined</td><td><select id="s12" data-np-default="F"><option value="F">F</option><option value="I">I</option></select></td></tr>
    </tbody></table>
  </div>
  <div class="netopia-panel" data-np-panel="keys">
    <span data-np-keys-intro="sandbox">sandbox keys</span><span data-np-keys-intro="live" hidden>live keys</span>
    <div data-np-key="sandbox_public_key"><label data-np-drop><input type="file" id="f_sb"><span data-np-chosen hidden></span></label>
      <textarea id="netopia_sandbox_public_key">SANDBOX-PEM</textarea></div>
    <div data-np-key="live_public_key"><label data-np-drop><input type="file" id="f_lv"><span data-np-chosen hidden></span><span data-np-wrong-mode hidden></span></label>
      <textarea id="netopia_live_public_key">LIVE-PEM</textarea></div>
  </div>
</div>
</form>`;

let requests = [];
let reply = null;

beforeAll(async () => {
    document.body.innerHTML = MARKUP;
    window.jQuery = {
        ceAjax: vi.fn((type, url, opts) => {
            requests.push({ url, data: opts.data });
            if (reply === 'error') {
                opts.error_callback();
            } else {
                opts.callback({ netopia_test: reply });
            }
        }),
    };
    await import('../../addon-netopia-payments-main/js/addons/netopia_payments/processor_config.js');
});

beforeEach(() => {
    requests = [];
});

const $ = (sel) => document.querySelector(sel);
const change = (el) => el.dispatchEvent(new Event('change', { bubbles: true }));
const pick = (input, name) => {
    Object.defineProperty(input, 'files', { value: [{ name }], configurable: true });
    change(input);
};
const flush = () => new Promise((r) => setTimeout(r, 0));

describe('netopia processor config', () => {
    it('turns on tabs, and a tab only hides panels (fields stay in the form)', () => {
        expect($('#netopia_cfg').classList.contains('netopia-cfg--tabs')).toBe(true);
        $('[data-np-tab="st"]').click();
        expect($('[data-np-panel="st"]').classList.contains('is-active')).toBe(true);
        expect($('[data-np-panel="conn"]').classList.contains('is-active')).toBe(false);
        expect($('form').contains($('#netopia_live_api_key'))).toBe(true);
        expect($('form').getAttribute('enctype')).toBe('multipart/form-data');
    });

    it('asks before Live, and "Stay" keeps Sandbox', () => {
        const live = $('#netopia_mode');
        live.checked = true;
        change(live);
        expect($('#netopia_mode_sandbox').checked).toBe(true);
        expect($('[data-np-live-confirm]').hidden).toBe(false);
        expect($('[data-np-live-keys-missing]').hidden).toBe(false);
        // The live API key is empty: the confirmation says so.
        expect($('[data-np-live-creds-missing]').hidden).toBe(false);
        $('[data-np-live-no]').click();
        expect($('#netopia_cfg').getAttribute('data-mode')).toBe('sandbox');
        expect($('[data-np-live-confirm]').hidden).toBe(true);
    });

    it('switches to Live on confirm and shows the live keys', () => {
        const live = $('#netopia_mode');
        live.checked = true;
        change(live);
        $('[data-np-live-yes]').click();
        expect(live.checked).toBe(true);
        expect($('#netopia_cfg').getAttribute('data-mode')).toBe('live');
        expect($('[data-np-keys-intro="live"]').hidden).toBe(false);
        // The live public key is missing: the Keys tab shows a dot.
        expect($('[data-np-keys-dot]').hidden).toBe(false);

        $('#netopia_mode_sandbox').checked = true;
        change($('#netopia_mode_sandbox'));
        expect($('#netopia_cfg').getAttribute('data-mode')).toBe('sandbox');
        expect($('[data-np-keys-dot]').hidden).toBe(true);
    });

    it('shows and hides a secret', () => {
        const btn = $('[data-np-reveal]');
        btn.click();
        expect($('#netopia_sandbox_pos_signature').type).toBe('text');
        expect(btn.textContent).toBe('Hide');
        btn.click();
        expect($('#netopia_sandbox_pos_signature').type).toBe('password');
    });

    it('keeps max installments submittable (readonly, never disabled)', () => {
        const max = $('#netopia_max_installments');
        expect(max.readOnly).toBe(true);
        expect(max.disabled).toBe(false);
        $('#netopia_allow_installments').checked = true;
        change($('#netopia_allow_installments'));
        expect(max.readOnly).toBe(false);
    });

    it('previews the description and inserts placeholders', () => {
        expect($('[data-np-desc-preview]').textContent).toBe('comanda #1001 din shop.example.ro');
        const input = $('#netopia_order_description');
        input.setSelectionRange(input.value.length, input.value.length);
        $('[data-np-token]').click();
        expect(input.value).toBe('comanda #[order_id] din [site_url][total]');
        expect($('[data-np-desc-preview]').textContent).toBe('comanda #1001 din shop.example.ro250.00');
    });

    it('marks changed status rows and resets them', () => {
        const s5 = $('#s5');
        s5.value = 'C';
        change(s5);
        expect($('#row5').classList.contains('is-changed')).toBe(true);
        $('[data-np-reset]').click();
        expect(s5.value).toBe('P');
        expect($('#row5').classList.contains('is-changed')).toBe(false);
    });

    it('tests what the form holds now, for the selected mode', async () => {
        reply = {
            ready: false,
            summary: 'Not ready',
            checks: [
                { id: 'api_key', state: 'ok', text: 'Key accepted' },
                { id: 'public_key', state: 'bad', text: 'No public key' },
            ],
        };
        $('#netopia_sandbox_api_key').value = 'ApiKey_typed';
        $('[data-np-test-run]').click();
        await flush();

        expect(requests).toHaveLength(1);
        expect(requests[0].data).toEqual({
            mode: 'sandbox',
            payment_id: '7',
            api_key: 'ApiKey_typed',
            pos_signature: 'AB12-CD34-EF56-GH78-IJ90',
            other_api_key: '',
            public_key: 'SANDBOX-PEM',
        });
        expect($('[data-np-test-summary]').textContent).toBe('Not ready');
        const items = document.querySelectorAll('[data-np-test-list] li');
        expect(items).toHaveLength(2);
        expect(items[1].textContent).toContain('No public key');
        expect(items[1].querySelector('.netopia-pill--bad')).not.toBeNull();
        expect($('[data-np-test-run]').disabled).toBe(false);
    });

    it('tests the live pair when Live is selected', async () => {
        reply = { ready: true, summary: 'Ready', checks: [] };
        $('#netopia_live_api_key').value = 'ApiKey_live';
        const live = $('#netopia_mode');
        live.checked = true;
        change(live);
        $('[data-np-live-yes]').click();
        $('[data-np-test-run]').click();
        await flush();
        expect(requests[0].data.mode).toBe('live');
        expect(requests[0].data.api_key).toBe('ApiKey_live');
        expect(requests[0].data.pos_signature).toBe('LIVE-POS1-AAAA-BBBB-CCCC');
        expect(requests[0].data.public_key).toBe('LIVE-PEM');
        // The sandbox key goes along, to catch the same key in both modes.
        expect(requests[0].data.other_api_key).toBe('ApiKey_typed');
        $('#netopia_mode_sandbox').checked = true;
        change($('#netopia_mode_sandbox'));
    });

    it('says so when the test request fails', async () => {
        reply = 'error';
        $('[data-np-test-run]').click();
        await flush();
        expect($('[data-np-test-summary]').textContent).toBe('The test could not run.');
    });
    it('warns when a sandbox key file is picked for a Live slot, and does not take its signature', () => {
        $('#netopia_live_pos_signature').value = '';
        pick($('#f_lv'), 'sandbox.39EG-NK6H-N6LV-IVP3-SLJC.2048.public.txt');
        const warn = $('[data-np-key="live_public_key"] [data-np-wrong-mode]');
        expect(warn.hidden).toBe(false);
        expect(warn.textContent).toBe('This is a Sandbox key file: it will not be saved in the Live slot.');
        expect($('#netopia_live_pos_signature').value).toBe('');
    });

    it('shows the picked key file\'s POS signature as a hint, never as a value', () => {
        $('#netopia_live_pos_signature').value = '';
        pick($('#f_lv'), 'live.ab12-cd34-ef56-gh78-ij90.public.cer');
        expect($('[data-np-key="live_public_key"] [data-np-wrong-mode]').hidden).toBe(true);
        expect($('#netopia_live_pos_signature').value).toBe('');
        expect($('#netopia_live_pos_signature').placeholder).toBe('AB12-CD34-EF56-GH78-IJ90');
        expect($('[data-np-pos-note="live"]').hidden).toBe(false);

        // A typed signature is never overwritten.
        $('#netopia_live_pos_signature').value = 'TYPE-DIN1-AAAA-BBBB-CCCC';
        pick($('#f_lv'), 'live.ZZZZ-ZZZZ-ZZZZ-ZZZZ-ZZZZ.public.cer');
        expect($('#netopia_live_pos_signature').value).toBe('TYPE-DIN1-AAAA-BBBB-CCCC');
    });
});

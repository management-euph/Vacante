import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Behavioural tests for the FGO bulk pre-check / run page
 * (addon-fgo-invoicing/js/addons/fgo_invoicing/bulk.js).
 *
 * The rules that must not break: orders go to FGO strictly ONE AT A TIME
 * and never two request starts closer than the pacing interval (each AJAX
 * request is a new PHP process, so the server-side throttle cannot space
 * them); "Stop after current order" lets the one in flight finish and starts
 * no other; a failed or garbled answer is a failed row, never a stuck page;
 * and every server string lands in the page as text, never as HTML.
 */

const I18N = {
    verb: 'Issue',
    count_one: '1 invoice',
    count_few: '[count] invoices',
    count_many: '[count] of invoices',
    chip_to_process: '[count] will be processed',
    chip_issued: '[count] issued',
    chip_done: '[count] done',
    chip_failed: '[count] failed',
    chip_skipped: '[count] skipped',
    chip_remaining: '[count] remaining',
    progress_count: '[done] of [total] processed',
    progress_stopping: 'stopping after the current order…',
    progress_stopped: 'Stopped: [done] of [total] processed.',
    progress_done: 'Done: [done] of [total] processed.',
    current: 'issuing order #[order]',
    running: 'Issuing…',
    queued: 'Queued',
    issued: 'Issued',
    done: 'Done',
    failed: 'Failed',
    skipped: 'Skipped',
    not_processed: 'Not processed',
    emailed: 'PDF link emailed to the customer',
    email_failed: 'Issued, but the e-mail was not sent',
    transport_error: 'No valid answer from the server',
    leave_warning: 'Still running',
    retry_failed: 'Retry failed ([count])',
    open_pdf: 'PDF',
    details: 'Details',
};

let ajaxCalls = [];
let posts = [];
let commoninit = null;

function row(id, { actionable = true, selected = true, hasInvoice = false } = {}) {
    return `
        <tr data-fgo-row data-fgo-order-id="${id}" data-fgo-actionable="${actionable ? 'Y' : 'N'}"
            data-fgo-has-invoice="${hasInvoice ? 'Y' : 'N'}">
            <td><input type="checkbox" value="${id}" data-fgo-select ${selected ? 'checked' : ''} ${actionable ? '' : 'disabled'} /></td>
            <td data-fgo-precheck>pre-check ${id}</td>
            <td class="hidden" data-fgo-result></td>
        </tr>`;
}

function page({ rows, action = 'issue', interval = 0, zip = 'Y' }) {
    document.body.innerHTML = `
        <div class="fgo-bulk" id="fgo_bulk" data-ca-fgo-bulk="true"
             data-ca-fgo-action="${action}"
             data-ca-fgo-run-url="admin.php?dispatch=fgo_invoicing.bulk_run"
             data-ca-fgo-zip-url="admin.php?dispatch=fgo_invoicing.m_download_pdfs"
             data-ca-fgo-retry-url="admin.php?dispatch=fgo_invoicing.bulk&amp;action=retry"
             data-ca-fgo-interval="${interval}"
             data-ca-fgo-zip="${zip}"
             data-ca-fgo-i18n='${JSON.stringify(I18N).replace(/'/g, '&#39;')}'>
            <div data-fgo-precheck-chips><span data-fgo-chip="to_process"></span></div>
            <table><thead><tr><th><input type="checkbox" data-fgo-select-all /></th>
                <th class="hidden" data-fgo-result-head>Result</th></tr></thead>
                <tbody>${rows.join('')}</tbody></table>
            <div data-fgo-options><input type="checkbox" data-fgo-email checked /></div>
            <div data-fgo-precheck-actions><button type="button" data-fgo-start>start</button></div>
            <div class="hidden" data-fgo-progress>
                <div data-fgo-progress-bar><span data-fgo-progress-fill style="width:0%"></span></div>
                <p data-fgo-progress-label></p>
                <div data-fgo-run-chips></div>
                <div><button type="button" data-fgo-stop>stop</button></div>
            </div>
            <div class="hidden" data-fgo-finished>
                <button type="button" class="hidden" data-fgo-zip>zip</button>
                <button type="button" class="hidden" data-fgo-retry></button>
            </div>
        </div>`;
    commoninit();
    return document.getElementById('fgo_bulk');
}

const $ = (sel) => document.querySelector(sel);
const $$ = (sel) => Array.from(document.querySelectorAll(sel));
const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

async function until(predicate) {
    for (let i = 0; i < 50; i++) {
        if (predicate()) {
            return;
        }
        await flush();
    }
    throw new Error('condition never became true');
}

/** Answer the oldest pending bulk_run request. */
function answer(result) {
    const call = ajaxCalls.find((c) => !c.answered);
    call.answered = true;
    call.params.callback({ fgo_result: result });
}

beforeAll(async () => {
    window.Tygh = {
        $: {
            ceEvent: (action, name, fn) => {
                if (action === 'on' && name === 'ce.commoninit') {
                    commoninit = fn;
                }
            },
            ceAjax: (action, url, params) => {
                ajaxCalls.push({ action, url, params, answered: false });
            },
            performPostRequest: (url, data) => {
                posts.push({ url, data });
            },
        },
    };
    await import('../../addon-fgo-invoicing/js/addons/fgo_invoicing/bulk.js');
});

beforeEach(() => {
    ajaxCalls = [];
    posts = [];
});

const api = () => window.FgoInvoicingBulk;

describe('labels', () => {
    it('picks the Romanian plural form (English repeats the plural)', () => {
        const forms = [0, 1, 2, 19, 20, 100, 101, 119, 120].map((n) => api().pluralForm(n));
        expect(forms).toEqual(['few', 'one', 'few', 'few', 'many', 'many', 'few', 'few', 'many']);
    });

    it('fills CS-Cart placeholders and leaves unknown ones', () => {
        expect(api().fill('[done] of [total] · [x]', { done: 3, total: 6 })).toBe('3 of 6 · [x]');
        expect(api().fill(undefined, {})).toBe('');
    });

    it('builds the start button label', () => {
        expect(api().startLabel(I18N, 1)).toBe('Issue 1 invoice');
        expect(api().startLabel(I18N, 6)).toBe('Issue 6 invoices');
        expect(api().startLabel(I18N, 20)).toBe('Issue 20 of invoices');
    });
});

describe('Runner', () => {
    function clock() {
        const state = { now: 1000, sleeps: [] };
        state.fns = {
            now: () => state.now,
            sleep: (ms) => {
                state.sleeps.push(ms);
                state.now += ms;
                return Promise.resolve();
            },
        };
        return state;
    }

    it('sends strictly one order at a time, in order', async () => {
        let inFlight = 0;
        let maxInFlight = 0;
        const order = [];
        const runner = new (api().Runner)({
            ids: [5, 3, 9],
            send: async (id) => {
                inFlight += 1;
                maxInFlight = Math.max(maxInFlight, inFlight);
                order.push(id);
                await flush();
                inFlight -= 1;
                return { outcome: 'issued', invoice_number: String(id) };
            },
        });

        const summary = await runner.start();

        expect(order).toEqual([5, 3, 9]);
        expect(maxInFlight).toBe(1);
        expect(summary.issued).toBe(3);
        expect(summary.issuedIds).toEqual([5, 3, 9]);
        expect(summary.remaining).toBe(0);
        expect(summary.stopped).toBe(false);
    });

    it('keeps request starts at least the interval apart', async () => {
        const c = clock();
        const starts = [];
        const runner = new (api().Runner)({
            ids: [1, 2, 3],
            intervalMs: 1000,
            ...c.fns,
            send: (id) => {
                starts.push(c.now);
                c.now += 300; // the request itself takes 300 ms
                return Promise.resolve({ outcome: 'issued' });
            },
        });

        await runner.start();

        expect(c.sleeps).toEqual([700, 700]);
        expect(starts).toEqual([1000, 2000, 3000]);
    });

    it('does not wait when a request already took longer than the interval', async () => {
        const c = clock();
        const runner = new (api().Runner)({
            ids: [1, 2],
            intervalMs: 1000,
            ...c.fns,
            send: () => {
                c.now += 1500;
                return Promise.resolve({ outcome: 'failed', message: 'HTTP 504' });
            },
        });

        const summary = await runner.start();

        expect(c.sleeps).toEqual([]);
        expect(summary.failed).toBe(2);
        expect(summary.failedIds).toEqual([1, 2]);
    });

    it('stops after the order in flight', async () => {
        const sent = [];
        const runner = new (api().Runner)({
            ids: [1, 2, 3],
            send: (id) => {
                sent.push(id);
                return Promise.resolve({ outcome: 'issued' });
            },
            onStart: () => runner.stop(), // pressed while order 1 is in flight
        });

        const summary = await runner.start();

        expect(sent).toEqual([1]);
        expect(summary.processed).toBe(1);
        expect(summary.remaining).toBe(2);
        expect(summary.stopped).toBe(true);
    });

    it('also stops during the pacing wait', async () => {
        const c = clock();
        const sent = [];
        const runner = new (api().Runner)({
            ids: [1, 2],
            intervalMs: 1000,
            now: c.fns.now,
            sleep: (ms) => {
                runner.stop();
                return c.fns.sleep(ms);
            },
            send: (id) => {
                sent.push(id);
                return Promise.resolve({ outcome: 'issued' });
            },
        });

        const summary = await runner.start();

        expect(sent).toEqual([1]);
        expect(summary.stopped).toBe(true);
    });

    it('turns a rejected, empty or garbled answer into a failed row and moves on', async () => {
        const answers = [
            () => Promise.reject(new Error('network')),
            () => Promise.resolve(null),
            () => Promise.resolve({ outcome: 'weird' }),
            () => Promise.resolve({ outcome: 'skipped', message: 'Already invoiced · F 0002' }),
        ];
        const results = [];
        const runner = new (api().Runner)({
            ids: [1, 2, 3, 4],
            send: () => answers.shift()(),
            onResult: (id, result) => results.push(result),
        });

        const summary = await runner.start();

        expect(results.map((r) => r.outcome)).toEqual(['failed', 'failed', 'failed', 'skipped']);
        expect(results.slice(0, 3).every((r) => r.transport_error === true)).toBe(true);
        expect(results.map((r) => r.order_id)).toEqual([1, 2, 3, 4]);
        expect(summary).toMatchObject({ total: 4, processed: 4, failed: 3, skipped: 1, issued: 0, done: 0 });
    });

    it('stops when the page is gone', async () => {
        let alive = true;
        const sent = [];
        const runner = new (api().Runner)({
            ids: [1, 2, 3],
            isAlive: () => alive,
            send: (id) => {
                sent.push(id);
                alive = false; // the admin navigated away while it ran
                return Promise.resolve({ outcome: 'issued' });
            },
        });

        await runner.start();

        expect(sent).toEqual([1]);
    });

    it('cannot be started twice', async () => {
        const runner = new (api().Runner)({ ids: [1], send: () => new Promise(() => {}) });
        runner.start();
        await expect(runner.start()).rejects.toThrow('already running');
    });
});

describe('the page', () => {
    it('keeps the start button count live and select-all over the actionable rows', () => {
        page({ rows: [row(1), row(2), row(3, { selected: false }), row(4, { actionable: false, selected: false })] });

        expect($('[data-fgo-start]').textContent).toBe('Issue 2 invoices');
        expect($('[data-fgo-chip="to_process"]').textContent).toBe('2 will be processed');
        expect($('[data-fgo-select-all]').indeterminate).toBe(true);

        const selectAll = $('[data-fgo-select-all]');
        selectAll.checked = true;
        selectAll.dispatchEvent(new Event('change', { bubbles: true }));
        expect($('[data-fgo-start]').textContent).toBe('Issue 3 invoices');
        expect($('tr[data-fgo-order-id="4"] input').checked).toBe(false);

        selectAll.checked = false;
        selectAll.dispatchEvent(new Event('change', { bubbles: true }));
        expect($('[data-fgo-start]').textContent).toBe('Issue 0 invoices');
        expect($('[data-fgo-start]').disabled).toBe(true);

        const one = $('tr[data-fgo-order-id="2"] input');
        one.checked = true;
        one.dispatchEvent(new Event('change', { bubbles: true }));
        expect($('[data-fgo-start]').textContent).toBe('Issue 1 invoice');
    });

    it('initialises once however often ce.commoninit fires', async () => {
        const root = page({ rows: [row(1)] });
        const pageObject = root.fgoBulkPage;
        commoninit();
        commoninit();

        expect(root.fgoBulkPage).toBe(pageObject);
        $('[data-fgo-start]').click();
        await until(() => ajaxCalls.length === 1);
        await flush();
        expect(ajaxCalls).toHaveLength(1);

        answer({ outcome: 'issued' });
        await until(() => root.fgoBulkPage.summary !== null);
    });

    it('runs the ticked orders one by one and shows each result', async () => {
        page({ rows: [row(1), row(2), row(3), row(4, { selected: false }), row(5, { actionable: false, selected: false, hasInvoice: true })] });
        const addSpy = vi.spyOn(window, 'addEventListener');
        const removeSpy = vi.spyOn(window, 'removeEventListener');

        const done = window.document.getElementById('fgo_bulk').fgoBulkPage.start();

        expect(addSpy).toHaveBeenCalledWith('beforeunload', expect.any(Function));
        expect($('[data-fgo-progress]').classList.contains('hidden')).toBe(false);
        expect($('[data-fgo-precheck-actions]').classList.contains('hidden')).toBe(true);
        expect($('tr[data-fgo-order-id="4"] [data-fgo-result]').textContent).toContain('Not processed');
        expect($('tr[data-fgo-order-id="2"] [data-fgo-result]').textContent).toContain('Queued');

        await until(() => ajaxCalls.length === 1);
        expect(ajaxCalls[0].url).toBe('admin.php?dispatch=fgo_invoicing.bulk_run');
        expect(ajaxCalls[0].params).toMatchObject({ method: 'post', hidden: true, data: { action: 'issue', order_id: 1, send_email: 'Y' } });
        expect($('[data-fgo-progress-label]').textContent).toBe('0 of 3 processed · issuing order #1');
        expect($('tr[data-fgo-order-id="1"] [data-fgo-result]').textContent).toContain('Issuing…');

        answer({ outcome: 'issued', invoice_series: 'F', invoice_number: '0003', pdf_link: 'https://api.fgo.ro/p/3', view_url: 'admin.php?dispatch=fgo_invoicing.view&order_id=1', email_status: 'sent' });
        await until(() => ajaxCalls.length === 2);
        expect(ajaxCalls.filter((c) => !c.answered)).toHaveLength(1);
        expect($('[data-fgo-progress-label]').textContent).toBe('1 of 3 processed · issuing order #2');
        const issuedCell = $('tr[data-fgo-order-id="1"] [data-fgo-result]');
        expect(issuedCell.textContent).toContain('Issued');
        expect(issuedCell.textContent).toContain('F 0003');
        expect(issuedCell.textContent).toContain('PDF link emailed to the customer');
        const pdf = issuedCell.querySelector('a[target="_blank"]');
        expect(pdf.getAttribute('href')).toBe('https://api.fgo.ro/p/3');
        expect(pdf.getAttribute('rel')).toBe('noopener noreferrer');

        answer({ outcome: 'failed', message: '<img src=x onerror="window.pwned=1">CIF invalid', view_url: 'javascript:alert(1)' });
        await until(() => ajaxCalls.length === 3);
        const failedCell = $('tr[data-fgo-order-id="2"] [data-fgo-result]');
        expect(failedCell.textContent).toContain('<img src=x onerror="window.pwned=1">CIF invalid');
        expect(failedCell.querySelector('img')).toBeNull();
        expect(failedCell.querySelector('a')).toBeNull();

        answer({ outcome: 'skipped', message: 'Already invoiced · F 0002', invoice_series: 'F', invoice_number: '0002' });
        const summary = await done;

        expect(summary).toMatchObject({ total: 3, processed: 3, issued: 1, failed: 1, skipped: 1, stopped: false });
        expect($('[data-fgo-progress-label]').textContent).toBe('Done: 3 of 3 processed.');
        expect($('[data-fgo-progress-fill]').style.width).toBe('100%');
        expect($$('[data-fgo-run-chips] [data-fgo-chip]').map((c) => c.textContent)).toEqual(['1 issued', '1 failed', '3 skipped']);
        expect(removeSpy).toHaveBeenCalledWith('beforeunload', expect.any(Function));
        expect($('[data-fgo-finished]').classList.contains('hidden')).toBe(false);
        expect($('[data-fgo-stop]').parentNode.classList.contains('hidden')).toBe(true);

        // ZIP: the order issued now and the one invoiced before.
        expect($('[data-fgo-zip]').classList.contains('hidden')).toBe(false);
        $('[data-fgo-zip]').click();
        expect(posts).toEqual([{ url: 'admin.php?dispatch=fgo_invoicing.m_download_pdfs', data: { order_ids: '1,5' } }]);

        // Retry failed (1): back to the pre-check with only the failed order.
        expect($('[data-fgo-retry]').textContent).toBe('Retry failed (1)');
        const navigate = vi.spyOn(api(), 'navigate').mockImplementation(() => {});
        $('[data-fgo-retry]').click();
        expect(navigate).toHaveBeenCalledWith('admin.php?dispatch=fgo_invoicing.bulk&action=retry&order_ids=2');

        addSpy.mockRestore();
        removeSpy.mockRestore();
        navigate.mockRestore();
    });

    it('asks before the page is left while it runs', async () => {
        const root = page({ rows: [row(1)] });
        root.fgoBulkPage.start();
        await until(() => ajaxCalls.length === 1);

        const event = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(event);
        expect(event.defaultPrevented).toBe(true);

        answer({ outcome: 'issued' });
        await until(() => root.fgoBulkPage.summary !== null);
        const after = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(after);
        expect(after.defaultPrevented).toBe(false);
    });

    it('stops after the current order when asked', async () => {
        const root = page({ rows: [row(1), row(2), row(3)] });
        const done = root.fgoBulkPage.start();
        await until(() => ajaxCalls.length === 1);

        $('[data-fgo-stop]').click();
        expect($('[data-fgo-progress-label]').textContent).toContain('stopping after the current order');
        expect($('[data-fgo-stop]').disabled).toBe(true);

        answer({ outcome: 'failed', message: 'HTTP 504' });
        const summary = await done;

        expect(ajaxCalls).toHaveLength(1);
        expect(summary.stopped).toBe(true);
        expect($('[data-fgo-progress-label]').textContent).toBe('Stopped: 1 of 3 processed.');
        expect($('tr[data-fgo-order-id="2"] [data-fgo-result]').textContent).toContain('Not processed');
        expect($('[data-fgo-zip]').classList.contains('hidden')).toBe(true);
        expect($('[data-fgo-retry]').textContent).toBe('Retry failed (1)');
    });

    it('sends no e-mail flag when the box is unticked, and offers no ZIP for cancellations', async () => {
        const root = page({ rows: [row(1, { hasInvoice: true })], action: 'cancel', zip: 'N' });
        $('[data-fgo-email]').checked = false;
        const done = root.fgoBulkPage.start();
        await until(() => ajaxCalls.length === 1);

        expect(ajaxCalls[0].params.data).toMatchObject({ action: 'cancel', send_email: 'N' });
        answer({ outcome: 'done', invoice_series: 'F', invoice_number: '0002' });
        await done;

        expect($('tr[data-fgo-order-id="1"] [data-fgo-result]').textContent).toContain('Done');
        expect($$('[data-fgo-run-chips] [data-fgo-chip]').map((c) => c.textContent)).toEqual(['1 done', '0 failed', '0 skipped']);
        expect($('[data-fgo-zip]').classList.contains('hidden')).toBe(true);
        expect($('[data-fgo-retry]').classList.contains('hidden')).toBe(true);
    });

    it('treats a transport error as a failed row with a hint', async () => {
        const root = page({ rows: [row(1)] });
        const done = root.fgoBulkPage.start();
        await until(() => ajaxCalls.length === 1);

        ajaxCalls[0].answered = true;
        ajaxCalls[0].params.error_callback({}, 'error', 'Internal Server Error');
        await done;

        const cell = $('tr[data-fgo-order-id="1"] [data-fgo-result]');
        expect(cell.textContent).toContain('Failed');
        expect(cell.textContent).toContain('No valid answer from the server');
    });

    it('links an FGO refusal to the invoice page, but not a pre-check block', async () => {
        const root = page({ rows: [row(1), row(2)] });
        const done = root.fgoBulkPage.start();
        await until(() => ajaxCalls.length === 1);
        answer({ outcome: 'failed', message: 'CIF invalid', reasons: [], view_url: 'admin.php?dispatch=fgo_invoicing.view&order_id=1' });
        await until(() => ajaxCalls.length === 2);
        answer({ outcome: 'failed', message: 'Company (PJ) without CIF', reasons: [{ code: 'pj_without_cif_required' }], view_url: 'admin.php?dispatch=fgo_invoicing.view&order_id=2' });
        await done;

        const refused = $('tr[data-fgo-order-id="1"] [data-fgo-result] a');
        expect(refused.getAttribute('href')).toBe('admin.php?dispatch=fgo_invoicing.view&order_id=1');
        expect(refused.textContent).toBe('Details');
        expect($('tr[data-fgo-order-id="2"] [data-fgo-result] a')).toBeNull();
        expect($('tr[data-fgo-order-id="2"] [data-fgo-result]').textContent).toContain('Company (PJ) without CIF');
    });

    it('does nothing without a ticked order', () => {
        const root = page({ rows: [row(1, { selected: false })] });

        expect(root.fgoBulkPage.start()).toBeNull();
        expect(ajaxCalls).toHaveLength(0);
    });
});

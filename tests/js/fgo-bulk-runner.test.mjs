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
 * Also: select-all ticks ready/retry rows only; each order carries the
 * verdict and reasons the page showed; an expired session (force_redirection,
 * which ajax.js follows without calling back) fails the order in flight and
 * stops the run instead of freezing it; "Retry failed" and the ZIP are POSTs
 * (the ZIP into a new tab); and without its script the page says so, with a
 * disabled start button, until the script removes the alert.
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
    session_expired: 'Your session expired, so the run stopped.',
    log_in: 'Log in again',
    chip_not_processed: '[count] not processed',
    leave_warning: 'Still running',
    retry_failed: 'Retry failed ([count])',
    open_pdf: 'PDF',
    details: 'Details',
};

let ajaxCalls = [];
let posts = [];
let commoninit = null;

function row(id, { actionable = true, selected = true, hasInvoice = false, verdict = actionable ? 'ready' : 'skip', reasons = '' } = {}) {
    return `
        <tr data-fgo-row data-fgo-order-id="${id}" data-fgo-actionable="${actionable ? 'Y' : 'N'}"
            data-fgo-verdict="${verdict}" data-fgo-reasons="${reasons}"
            data-fgo-has-invoice="${hasInvoice ? 'Y' : 'N'}">
            <td><input type="checkbox" value="${id}" data-fgo-select ${selected ? 'checked' : ''} ${actionable ? '' : 'disabled'} /></td>
            <td data-fgo-precheck>pre-check ${id}</td>
            <td class="hidden" data-fgo-result></td>
        </tr>`;
}

/** The page as bulk.tpl renders it, before its script has run. */
function markup({ rows, action = 'issue', interval = 0, zip = 'Y', email = true }) {
    const retryMode = action === 'issue' || action === 'retry' ? 'm_issue' : `m_${action}`;
    document.body.innerHTML = `
        <div class="fgo-bulk" id="fgo_bulk" data-ca-fgo-bulk="true"
             data-ca-fgo-action="${action}"
             data-ca-fgo-run-url="admin.php?dispatch=fgo_invoicing.bulk_run"
             data-ca-fgo-zip-url="admin.php?dispatch=fgo_invoicing.m_download_pdfs"
             data-ca-fgo-retry-url="admin.php?dispatch=fgo_invoicing.${retryMode}"
             data-ca-fgo-interval="${interval}"
             data-ca-fgo-zip="${zip}"
             data-ca-fgo-i18n='${JSON.stringify(I18N).replace(/'/g, '&#39;')}'>
            <div class="alert alert-error" data-fgo-script-missing>The page script did not load.</div>
            <div data-fgo-precheck-chips><span data-fgo-chip="to_process"></span></div>
            <table><thead><tr><th><input type="checkbox" data-fgo-select-all /></th>
                <th class="hidden" data-fgo-result-head>Result</th></tr></thead>
                <tbody>${rows.join('')}</tbody></table>
            <div data-fgo-options>${email ? '<input type="checkbox" data-fgo-email checked />' : ''}</div>
            <div data-fgo-precheck-actions><button type="button" data-fgo-start disabled="disabled">start</button></div>
            <div class="hidden" data-fgo-progress>
                <div data-fgo-progress-bar><span data-fgo-progress-fill style="width:0%"></span></div>
                <p data-fgo-progress-label></p>
                <div data-fgo-run-chips></div>
                <div class="alert alert-error hidden" data-fgo-session-expired></div>
                <div><button type="button" data-fgo-stop>stop</button></div>
            </div>
            <div class="hidden" data-fgo-finished>
                <button type="button" class="hidden" data-fgo-zip>zip</button>
                <button type="button" class="hidden" data-fgo-retry></button>
            </div>
        </div>`;
    return document.getElementById('fgo_bulk');
}

function page(options) {
    const root = markup(options);
    commoninit();
    return root;
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
            performPostRequest: (url, data, target) => {
                posts.push(target === undefined ? { url, data } : { url, data, target });
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
    it('keeps the start button count live and select-all over the ready and retry rows', () => {
        page({ rows: [row(1), row(2, { verdict: 'retry' }), row(3, { selected: false }), row(4, { actionable: false, selected: false }), row(5, { selected: false, verdict: 'warn', reasons: 'previously_canceled' })] });

        expect($('[data-fgo-start]').textContent).toBe('Issue 2 invoices');
        expect($('[data-fgo-chip="to_process"]').textContent).toBe('2 will be processed');
        expect($('[data-fgo-select-all]').indeterminate).toBe(true);

        const selectAll = $('[data-fgo-select-all]');
        selectAll.checked = true;
        selectAll.dispatchEvent(new Event('change', { bubbles: true }));
        expect($('[data-fgo-start]').textContent).toBe('Issue 3 invoices');
        expect($('tr[data-fgo-order-id="4"] input').checked).toBe(false);
        expect($('tr[data-fgo-order-id="5"] input').checked).toBe(false); // a warn row is ticked one by one
        expect(selectAll.checked).toBe(true);

        const warn = $('tr[data-fgo-order-id="5"] input');
        warn.checked = true;
        warn.dispatchEvent(new Event('change', { bubbles: true }));
        expect($('[data-fgo-start]').textContent).toBe('Issue 4 invoices');

        selectAll.checked = false;
        selectAll.dispatchEvent(new Event('change', { bubbles: true }));
        expect($('[data-fgo-start]').textContent).toBe('Issue 1 invoice');
        expect(warn.checked).toBe(true); // select-all leaves the warn row alone either way
        warn.checked = false;
        warn.dispatchEvent(new Event('change', { bubbles: true }));
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
        page({ rows: [row(1), row(2, { verdict: 'warn', reasons: 'zero_total,cif_invalid' }), row(3), row(4, { selected: false }), row(5, { actionable: false, selected: false, hasInvoice: true })] });
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
        expect(ajaxCalls[0].params).toMatchObject({ method: 'post', hidden: true, data: { action: 'issue', order_id: 1, send_email: 'Y', seen_verdict: 'ready', seen_reasons: '' } });
        expect(typeof ajaxCalls[0].params.pre_processing).toBe('function');
        expect($('[data-fgo-progress-label]').textContent).toBe('0 of 3 processed · issuing order #1');
        expect($('tr[data-fgo-order-id="1"] [data-fgo-result]').textContent).toContain('Issuing…');

        answer({ outcome: 'issued', invoice_series: 'F', invoice_number: '0003', pdf_link: 'https://api.fgo.ro/p/3', view_url: 'admin.php?dispatch=fgo_invoicing.view&order_id=1', email_status: 'sent' });
        await until(() => ajaxCalls.length === 2);
        expect(ajaxCalls.filter((c) => !c.answered)).toHaveLength(1);
        expect(ajaxCalls[1].params.data).toMatchObject({ order_id: 2, seen_verdict: 'warn', seen_reasons: 'zero_total,cif_invalid' });
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
        expect($$('[data-fgo-run-chips] [data-fgo-chip]').map((c) => c.textContent)).toEqual(['1 issued', '1 failed', '1 skipped', '2 not processed']);
        expect(removeSpy).toHaveBeenCalledWith('beforeunload', expect.any(Function));
        expect($('[data-fgo-finished]').classList.contains('hidden')).toBe(false);
        expect($('[data-fgo-stop]').parentNode.classList.contains('hidden')).toBe(true);

        // ZIP: the order issued now and the one invoiced before, into a new
        // tab so an error there never replaces these results.
        expect($('[data-fgo-zip]').classList.contains('hidden')).toBe(false);
        $('[data-fgo-zip]').click();
        expect(posts).toEqual([{ url: 'admin.php?dispatch=fgo_invoicing.m_download_pdfs', data: { order_ids: '1,5' }, target: '_blank' }]);

        // Retry failed (1): the failed order POSTed back through m_issue,
        // with the e-mail choice, for a new pre-check.
        expect($('[data-fgo-retry]').textContent).toBe('Retry failed (1)');
        $('[data-fgo-retry]').click();
        expect(posts[1]).toEqual({ url: 'admin.php?dispatch=fgo_invoicing.m_issue', data: { order_ids: '2', send_email: 'Y' } });

        addSpy.mockRestore();
        removeSpy.mockRestore();
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

    it('retries failed cancellations through m_cancel, without an e-mail flag', async () => {
        const root = page({ rows: [row(1, { hasInvoice: true }), row(2, { hasInvoice: true })], action: 'cancel', zip: 'N', email: false });
        const done = root.fgoBulkPage.start();
        await until(() => ajaxCalls.length === 1);
        answer({ outcome: 'failed', message: 'FGO down' });
        await until(() => ajaxCalls.length === 2);
        answer({ outcome: 'done' });
        await done;

        $('[data-fgo-retry]').click();
        expect(posts).toEqual([{ url: 'admin.php?dispatch=fgo_invoicing.m_cancel', data: { order_ids: '1' } }]);
    });

    it('adds an order found already invoiced at run time to the ZIP', async () => {
        const root = page({ rows: [row(1), row(2)] });
        const done = root.fgoBulkPage.start();
        await until(() => ajaxCalls.length === 1);
        answer({ outcome: 'skipped', reasons: [{ code: 'already_invoiced' }], invoice_series: 'F', invoice_number: '0002', pdf_link: 'https://api.fgo.ro/p/2' });
        await until(() => ajaxCalls.length === 2);
        answer({ outcome: 'skipped', reasons: [{ code: 'in_progress' }], pdf_link: 'https://api.fgo.ro/p/9' });
        await done;

        expect($('tr[data-fgo-order-id="1"]').getAttribute('data-fgo-has-invoice')).toBe('Y');
        expect($('tr[data-fgo-order-id="2"]').getAttribute('data-fgo-has-invoice')).toBe('N');
        $('[data-fgo-zip]').click();
        expect(posts).toEqual([{ url: 'admin.php?dispatch=fgo_invoicing.m_download_pdfs', data: { order_ids: '1' }, target: '_blank' }]);
    });

    /**
     * CS-Cart answers a logged-out AJAX request with force_redirection and
     * ajax.js follows it WITHOUT calling callback or error_callback: the row
     * used to stay "Issuing…" forever. pre_processing runs first.
     */
    it('fails the order in flight and stops when the session has expired', async () => {
        const root = page({ rows: [row(1), row(2), row(3)] });
        const removeSpy = vi.spyOn(window, 'removeEventListener');
        const done = root.fgoBulkPage.start();
        await until(() => ajaxCalls.length === 1);
        answer({ outcome: 'issued', invoice_series: 'F', invoice_number: '0003', pdf_link: 'https://api.fgo.ro/p/3' });
        await until(() => ajaxCalls.length === 2);

        const call = ajaxCalls[1];
        call.answered = true;
        const data = { force_redirection: 'admin.php?dispatch=auth.login_form&return_url=x', notifications: {} };
        call.params.pre_processing(data, call.params);
        expect(data.force_redirection).toBeUndefined(); // ajax.js must not leave the page
        expect(removeSpy).toHaveBeenCalledWith('beforeunload', expect.any(Function));
        call.params.callback(data); // ajax.js carries on to the callback: harmless
        const summary = await done;

        expect(ajaxCalls).toHaveLength(2); // order 3 is never sent
        expect(summary).toMatchObject({ processed: 2, issued: 1, failed: 1, stopped: true });
        const cell = $('tr[data-fgo-order-id="2"] [data-fgo-result]');
        expect(cell.textContent).toContain('Failed');
        expect(cell.textContent).toContain('Your session expired, so the run stopped.');
        expect(cell.querySelector('a')).toBeNull();
        expect($('tr[data-fgo-order-id="3"] [data-fgo-result]').textContent).toContain('Not processed');
        const box = $('[data-fgo-session-expired]');
        expect(box.classList.contains('hidden')).toBe(false);
        expect(box.textContent).toContain('Your session expired');
        const login = box.querySelector('a');
        expect(login.getAttribute('href')).toBe('admin.php?dispatch=auth.login_form&return_url=x');
        expect(login.getAttribute('target')).toBe('_blank');
        // Their POSTs would carry a security hash the new session does not know.
        expect($('[data-fgo-retry]').classList.contains('hidden')).toBe(true);
        expect($('[data-fgo-zip]').classList.contains('hidden')).toBe(true);
        const after = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(after);
        expect(after.defaultPrevented).toBe(false);
        removeSpy.mockRestore();
    });

    it('leaves a normal answer to the callback', async () => {
        const root = page({ rows: [row(1)] });
        const done = root.fgoBulkPage.start();
        await until(() => ajaxCalls.length === 1);
        ajaxCalls[0].params.pre_processing({ fgo_result: { outcome: 'issued' } }, ajaxCalls[0].params);
        expect($('[data-fgo-session-expired]').classList.contains('hidden')).toBe(true);
        answer({ outcome: 'issued' });
        const summary = await done;
        expect(summary.issued).toBe(1);
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

    it('removes the "script did not load" alert and enables the start button once bound', () => {
        const root = page({ rows: [row(1)] });

        expect(root.querySelector('[data-fgo-script-missing]')).toBeNull();
        expect($('[data-fgo-start]').disabled).toBe(false);
    });

    it('keeps the alert and the disabled button when the script never ran', () => {
        const root = markup({ rows: [row(1)] });

        expect(root.querySelector('[data-fgo-script-missing]')).not.toBeNull();
        expect($('[data-fgo-start]').disabled).toBe(true);
    });

    it('keeps the alert and logs when the page cannot be bound', () => {
        const root = markup({ rows: [row(1)] });
        const error = vi.spyOn(console, 'error').mockImplementation(() => {});
        const bind = vi.spyOn(api().Page.prototype, 'bind').mockImplementation(() => {
            throw new Error('boom');
        });

        expect(api().init(document)).toEqual([]);

        expect(root.querySelector('[data-fgo-script-missing]')).not.toBeNull();
        expect($('[data-fgo-start]').disabled).toBe(true);
        expect(error).toHaveBeenCalledWith('FGO bulk page could not start', expect.any(Error));
        bind.mockRestore();
        error.mockRestore();
    });

    it('does nothing without a ticked order', () => {
        const root = page({ rows: [row(1, { selected: false })] });

        expect(root.fgoBulkPage.start()).toBeNull();
        expect(ajaxCalls).toHaveLength(0);
    });
});

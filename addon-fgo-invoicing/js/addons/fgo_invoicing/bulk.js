/**
 * FGO Invoicing — bulk pre-check, progress and results page
 * (design/backend/templates/addons/fgo_invoicing/views/fgo_invoicing/bulk.tpl).
 *
 * The page lists the pre-check; this script:
 *   - enables the start button and removes the "page script did not load"
 *     alert once it is bound (both are rendered for a page WITHOUT it);
 *   - keeps the primary button's count live ("Issue 6 invoices") as rows are
 *     ticked, with a select-all over the READY and RETRY rows only: a warn
 *     row (a re-issue, an unfinished order, ...) is ticked one by one;
 *   - on start, sends the ticked orders to fgo_invoicing.bulk_run strictly
 *     ONE AT A TIME, and never starts two requests closer than the pacing
 *     interval (FGO takes about one request per second, and the PHP-side
 *     throttle only spaces calls within one process: each AJAX request is a
 *     new process, so without this the requests would hit FGO back to back);
 *   - sends with each order the verdict and reason codes the page showed
 *     for it, so the server stops an order that now warns about anything
 *     the admin did not see;
 *   - updates each row, the progress bar ("3 of 6 processed · issuing order
 *     #5") and the counts; "Stop after current order" lets the one in flight
 *     finish and starts no other; leaving the page while it runs asks first;
 *   - notices an expired session (CS-Cart answers a logged-out AJAX request
 *     with force_redirection and never calls back): the order in flight
 *     fails, the run stops, the page stays with its results and says how to
 *     go on, instead of freezing on "Issuing…" or jumping to the login form;
 *   - when done, offers the PDFs as one ZIP (posted into a new tab, so a
 *     failure never replaces the results), "Retry failed (N)" (the failed
 *     orders posted back through the same menu action, with the e-mail
 *     choice) and the way back to the orders list.
 *
 * Every server string is inserted as text (textContent), never as HTML.
 * The queue, pacing and counting are plain functions on
 * window.FgoInvoicingBulk, which tests/js/fgo-bulk-runner.test.mjs drives
 * with a fake transport and a fake clock.
 */
(function (_, $) {
    'use strict';

    var OUTCOMES = ['issued', 'done', 'failed', 'skipped'];

    /** Verdicts select-all ticks; a warn row is the admin's call, one by one. */
    var BULK_SELECTABLE = ['ready', 'retry'];

    // ── Pure helpers ─────────────────────────────────────────────────────

    /** CLDR Romanian plural rule (English is satisfied by it too). */
    function pluralForm(n) {
        if (n === 1) {
            return 'one';
        }
        var mod = Math.abs(n) % 100;
        return (n === 0 || (mod >= 1 && mod <= 19)) ? 'few' : 'many';
    }

    /** Substitute CS-Cart style [placeholders]. */
    function fill(template, values) {
        return String(template === undefined || template === null ? '' : template)
            .replace(/\[([a-z_]+)\]/g, function (all, name) {
                return Object.prototype.hasOwnProperty.call(values || {}, name) ? String(values[name]) : all;
            });
    }

    function countLabel(i18n, n) {
        return fill(i18n['count_' + pluralForm(n)], { count: n });
    }

    function startLabel(i18n, n) {
        return ((i18n.verb || '') + ' ' + countLabel(i18n, n)).trim();
    }

    /** Whatever came back, a result with a known outcome. */
    function normalizeResult(orderId, raw) {
        if (!raw || typeof raw !== 'object' || OUTCOMES.indexOf(raw.outcome) === -1) {
            return { order_id: orderId, outcome: 'failed', message: '', transport_error: true };
        }
        var result = {};
        Object.keys(raw).forEach(function (key) {
            result[key] = raw[key];
        });
        result.order_id = orderId;
        result.message = typeof raw.message === 'string' ? raw.message : '';
        return result;
    }

    function summarize(results, total) {
        var summary = {
            total: total,
            processed: results.length,
            remaining: Math.max(0, total - results.length),
            issued: 0,
            done: 0,
            failed: 0,
            skipped: 0,
            issuedIds: [],
            failedIds: [],
        };
        results.forEach(function (result) {
            summary[result.outcome] += 1;
            if (result.outcome === 'issued') {
                summary.issuedIds.push(result.order_id);
            } else if (result.outcome === 'failed') {
                summary.failedIds.push(result.order_id);
            }
        });
        return summary;
    }

    function defaultSleep(ms) {
        return new Promise(function (resolve) {
            setTimeout(resolve, ms);
        });
    }

    function noop() {}

    /**
     * Runs send(id) for each id, one after the other, at least intervalMs
     * between two request STARTS (a slow answer uses the interval up).
     *
     * options: ids, send(id) -> Promise<result>, intervalMs, now(), sleep(ms),
     * onStart(id, index), onResult(id, result), isAlive() (false once the
     * page is gone: an admin who navigated away must not keep issuing).
     */
    function Runner(options) {
        this.ids = (options.ids || []).slice();
        this.send = options.send;
        this.intervalMs = Math.max(0, Number(options.intervalMs) || 0);
        this.now = options.now || function () { return Date.now(); };
        this.sleep = options.sleep || defaultSleep;
        this.onStart = options.onStart || noop;
        this.onResult = options.onResult || noop;
        this.isAlive = options.isAlive || function () { return true; };
        this.results = [];
        this.stopRequested = false;
        this.running = false;
    }

    Runner.prototype.stop = function () {
        this.stopRequested = true;
    };

    Runner.prototype.summary = function () {
        var summary = summarize(this.results, this.ids.length);
        summary.stopped = this.stopRequested && this.results.length < this.ids.length;
        return summary;
    };

    Runner.prototype.start = function () {
        var self = this;
        var index = 0;
        var lastStart = null;

        if (self.running) {
            return Promise.reject(new Error('already running'));
        }
        self.running = true;

        function halted() {
            return self.stopRequested || !self.isAlive();
        }

        function finish() {
            self.running = false;
            return self.summary();
        }

        function next() {
            if (index >= self.ids.length || halted()) {
                return Promise.resolve(finish());
            }
            var wait = lastStart === null ? 0 : Math.max(0, self.intervalMs - (self.now() - lastStart));
            return (wait > 0 ? Promise.resolve(self.sleep(wait)) : Promise.resolve()).then(function () {
                if (halted()) {
                    return finish();
                }
                var id = self.ids[index];
                index += 1;
                lastStart = self.now();
                self.onStart(id, index - 1);
                return Promise.resolve()
                    .then(function () { return self.send(id); })
                    .then(
                        function (raw) { return normalizeResult(id, raw); },
                        function () { return normalizeResult(id, null); }
                    )
                    .then(function (result) {
                        self.results.push(result);
                        self.onResult(id, result);
                        return next();
                    });
            });
        }

        return next();
    };

    // ── The page ─────────────────────────────────────────────────────────

    function parseJson(text) {
        try {
            var value = JSON.parse(text || '{}');
            return value && typeof value === 'object' ? value : {};
        } catch (e) {
            return {};
        }
    }

    function show(el, visible) {
        if (el) {
            el.classList.toggle('hidden', !visible);
        }
    }

    function isSafeHref(url) {
        return typeof url === 'string' && url !== '' && !/^\s*(javascript|data|vbscript):/i.test(url);
    }

    function Page(root) {
        var q = function (sel) { return root.querySelector(sel); };
        this.root = root;
        this.action = root.getAttribute('data-ca-fgo-action') || '';
        this.runUrl = root.getAttribute('data-ca-fgo-run-url') || '';
        this.zipUrl = root.getAttribute('data-ca-fgo-zip-url') || '';
        this.retryUrl = root.getAttribute('data-ca-fgo-retry-url') || '';
        this.intervalMs = parseInt(root.getAttribute('data-ca-fgo-interval'), 10) || 0;
        this.zipEnabled = root.getAttribute('data-ca-fgo-zip') === 'Y';
        this.i18n = parseJson(root.getAttribute('data-ca-fgo-i18n'));
        this.rows = Array.prototype.slice.call(root.querySelectorAll('[data-fgo-row]'));
        this.selectAll = q('[data-fgo-select-all]');
        this.startBtn = q('[data-fgo-start]');
        this.stopBtn = q('[data-fgo-stop]');
        this.emailBox = q('[data-fgo-email]');
        this.zipBtn = q('[data-fgo-zip]');
        this.retryBtn = q('[data-fgo-retry]');
        this.progress = q('[data-fgo-progress]');
        this.progressBar = q('[data-fgo-progress-bar]');
        this.progressFill = q('[data-fgo-progress-fill]');
        this.progressLabel = q('[data-fgo-progress-label]');
        this.runChips = q('[data-fgo-run-chips]');
        this.sessionBox = q('[data-fgo-session-expired]');
        this.runner = null;
        this.summary = null;
        this.current = null;
        this.stopping = false;
        this.finished = false;
        this.sessionExpired = null;
        this.notQueued = 0;
        var self = this;
        this.onBeforeUnload = function (event) {
            event.preventDefault();
            event.returnValue = self.i18n.leave_warning || '';
            return event.returnValue;
        };
    }

    Page.prototype.rowFor = function (orderId) {
        for (var i = 0; i < this.rows.length; i++) {
            if (Number(this.rows[i].getAttribute('data-fgo-order-id')) === Number(orderId)) {
                return this.rows[i];
            }
        }
        return null;
    };

    Page.prototype.boxes = function () {
        return this.rows.map(function (row) {
            return row.querySelector('[data-fgo-select]');
        }).filter(function (box) {
            return box && !box.disabled;
        });
    };

    /** The boxes select-all toggles: rows whose verdict is ready or retry. */
    Page.prototype.bulkBoxes = function () {
        return this.boxes().filter(function (box) {
            var row = box.closest ? box.closest('[data-fgo-row]') : null;
            return row !== null && BULK_SELECTABLE.indexOf(row.getAttribute('data-fgo-verdict')) !== -1;
        });
    };

    Page.prototype.selectedIds = function () {
        return this.boxes().filter(function (box) {
            return box.checked;
        }).map(function (box) {
            return Number(box.value);
        });
    };

    Page.prototype.refreshSelection = function () {
        var bulk = this.bulkBoxes();
        var count = this.selectedIds().length;
        if (this.startBtn) {
            this.startBtn.textContent = startLabel(this.i18n, count);
            this.startBtn.disabled = count === 0;
        }
        var chip = this.root.querySelector('[data-fgo-chip="to_process"]');
        if (chip && this.i18n.chip_to_process) {
            chip.textContent = fill(this.i18n.chip_to_process, { count: count });
        }
        if (this.selectAll) {
            var all = bulk.length > 0 && bulk.every(function (box) { return box.checked; });
            this.selectAll.checked = all;
            this.selectAll.indeterminate = !all && count > 0;
        }
    };

    Page.prototype.bind = function () {
        var self = this;
        this.root.addEventListener('change', function (event) {
            if (self.runner) {
                return;
            }
            var target = event.target;
            if (target === self.selectAll) {
                self.bulkBoxes().forEach(function (box) {
                    box.checked = target.checked;
                });
            }
            if (target === self.selectAll || (target.hasAttribute && target.hasAttribute('data-fgo-select'))) {
                self.refreshSelection();
            }
        });
        if (this.startBtn) {
            this.startBtn.addEventListener('click', function () { self.start(); });
        }
        if (this.stopBtn) {
            this.stopBtn.addEventListener('click', function () { self.stop(); });
        }
        if (this.zipBtn) {
            this.zipBtn.addEventListener('click', function () { self.downloadZip(); });
        }
        if (this.retryBtn) {
            this.retryBtn.addEventListener('click', function () { self.retryFailed(); });
        }
        if (this.selectAll && this.bulkBoxes().length === 0) {
            this.selectAll.disabled = true;
        }
        // Enables the start button, rendered disabled for a page without
        // this script.
        this.refreshSelection();
    };

    /**
     * One bulk_run request; always resolves (a transport error is a failed
     * result, an expired session one too).
     *
     * pre_processing runs in ajax.js before it follows data.force_redirection,
     * which is how CS-Cart answers a request whose admin is logged out: it
     * then neither calls callback nor error_callback, and the row would wait
     * forever. The redirect is dropped so the results stay on screen.
     */
    Page.prototype.send = function (orderId) {
        var self = this;
        var row = this.rowFor(orderId);
        return new Promise(function (resolve) {
            if (!$ || typeof $.ceAjax !== 'function') {
                resolve(null);
                return;
            }
            $.ceAjax('request', self.runUrl, {
                method: 'post',
                hidden: true,
                data: {
                    action: self.action,
                    order_id: orderId,
                    send_email: self.emailBox && self.emailBox.checked ? 'Y' : 'N',
                    seen_verdict: row ? row.getAttribute('data-fgo-verdict') || '' : '',
                    seen_reasons: row ? row.getAttribute('data-fgo-reasons') || '' : '',
                },
                pre_processing: function (data) {
                    if (data && typeof data === 'object' && data.force_redirection) {
                        var loginUrl = String(data.force_redirection);
                        delete data.force_redirection;
                        self.onSessionExpired(loginUrl);
                        resolve({
                            outcome: 'failed',
                            message: self.i18n.session_expired || '',
                            session_expired: true,
                        });
                    }
                },
                callback: function (data) {
                    resolve(data && typeof data === 'object' ? data.fgo_result : null);
                },
                error_callback: function () {
                    resolve(null);
                },
            });
        });
    };

    /**
     * The session is gone: stop sending, stop guarding the page, and say how
     * to go on. "Log in" opens a new tab, so these results stay.
     */
    Page.prototype.onSessionExpired = function (loginUrl) {
        if (this.sessionExpired !== null) {
            return;
        }
        this.sessionExpired = loginUrl;
        window.removeEventListener('beforeunload', this.onBeforeUnload);
        if (this.runner) {
            this.stopping = true;
            this.runner.stop();
        }
        if (this.sessionBox) {
            this.sessionBox.textContent = (this.i18n.session_expired || '') + ' ';
            this.link(this.sessionBox, loginUrl, this.i18n.log_in || '', true);
            show(this.sessionBox, true);
        }
    };

    Page.prototype.setState = function (row, state, label) {
        if (!row) {
            return null;
        }
        row.setAttribute('data-fgo-run-state', state);
        var cell = row.querySelector('[data-fgo-result]');
        if (!cell) {
            return null;
        }
        cell.textContent = '';
        var badge = document.createElement('span');
        badge.className = 'fgo-bulk__state fgo-bulk__state--' + state;
        badge.textContent = label;
        cell.appendChild(badge);
        return cell;
    };

    Page.prototype.detail = function (cell, text) {
        var line = document.createElement('div');
        line.className = 'fgo-bulk__detail';
        if (text) {
            line.textContent = text;
        }
        cell.appendChild(line);
        return line;
    };

    Page.prototype.link = function (parent, href, text, newTab) {
        if (!isSafeHref(href)) {
            return;
        }
        var a = document.createElement('a');
        a.href = href;
        a.textContent = text;
        if (newTab) {
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
        }
        parent.appendChild(a);
    };

    Page.prototype.renderResult = function (orderId, result) {
        var i18n = this.i18n;
        var row = this.rowFor(orderId);
        var cell = this.setState(row, result.outcome, i18n[result.outcome] || result.outcome);
        if (!cell) {
            return;
        }
        var invoice = [result.invoice_series, result.invoice_number].filter(Boolean).join(' ');
        if (result.outcome === 'issued' || result.outcome === 'done' || (result.outcome === 'skipped' && invoice)) {
            var line = this.detail(cell, '');
            if (invoice) {
                line.appendChild(document.createTextNode(invoice + ' '));
            }
            if (/^https:\/\//i.test(result.pdf_link || '')) {
                this.link(line, result.pdf_link, i18n.open_pdf || 'PDF', true);
            }
            this.link(line, result.view_url, i18n.details || '', false);
        }
        // Issued now, or found already invoiced at run time: either way it
        // has a PDF for the ZIP.
        var alreadyInvoiced = result.outcome === 'skipped' && Array.isArray(result.reasons)
            && result.reasons.some(function (reason) { return reason && reason.code === 'already_invoiced'; });
        if ((result.outcome === 'issued' || alreadyInvoiced) && /^https:\/\//i.test(result.pdf_link || '')) {
            row.setAttribute('data-fgo-has-invoice', 'Y');
        } else if (result.outcome === 'issued') {
            row.setAttribute('data-fgo-has-invoice', 'N');
        }
        if (result.outcome === 'issued') {
            if (result.email_status === 'sent') {
                this.detail(cell, i18n.emailed || '');
            } else if (result.email_status === 'failed' || result.email_status === 'skipped') {
                this.detail(cell, i18n.email_failed || '');
            }
        }
        if (result.outcome === 'failed' || result.outcome === 'skipped') {
            var message = result.message || (result.transport_error ? i18n.transport_error : '');
            if (message) {
                this.detail(cell, message);
            }
            // The invoice page exists once FGO was asked; a pre-check block
            // (reasons) never reached it, and its page would be empty.
            var fromFgo = !Array.isArray(result.reasons) || result.reasons.length === 0;
            if (result.outcome === 'failed' && !invoice && fromFgo && !result.transport_error && !result.session_expired) {
                this.link(this.detail(cell, ''), result.view_url, i18n.details || '', false);
            }
        }
    };

    Page.prototype.renderProgress = function () {
        var summary = this.runner ? this.runner.summary() : summarize([], 0);
        var i18n = this.i18n;
        var pct = summary.total > 0 ? Math.round((summary.processed / summary.total) * 100) : 0;
        if (this.progressFill) {
            this.progressFill.style.width = pct + '%';
        }
        if (this.progressBar) {
            this.progressBar.setAttribute('aria-valuenow', String(pct));
        }
        if (this.progressLabel) {
            var label = fill(i18n.progress_count, { done: summary.processed, total: summary.total });
            if (this.stopping && this.runner && this.runner.running) {
                label += ' · ' + (i18n.progress_stopping || '');
            } else if (this.current !== null) {
                label += ' · ' + fill(i18n.current, { order: this.current });
            }
            this.progressLabel.textContent = label;
        }
        this.renderChips(summary);
    };

    Page.prototype.renderChips = function (summary) {
        if (!this.runChips) {
            return;
        }
        var i18n = this.i18n;
        var chips = [];
        var successKey = summary.issued > 0 || this.action === 'issue' || this.action === 'retry' ? 'issued' : 'done';
        chips.push([successKey, i18n['chip_' + successKey], summary[successKey]]);
        chips.push(['failed', i18n.chip_failed, summary.failed]);
        // Skipped: sent, and the server found nothing to do. Not processed:
        // never sent (left unticked, blocked, or left by a stop).
        chips.push(['skipped', i18n.chip_skipped, summary.skipped]);
        var notProcessed = this.notQueued + (this.finished ? summary.remaining : 0);
        if (notProcessed > 0) {
            chips.push(['not_processed', i18n.chip_not_processed, notProcessed]);
        }
        if (!this.finished && summary.remaining > 0) {
            chips.push(['remaining', i18n.chip_remaining, summary.remaining]);
        }
        this.runChips.textContent = '';
        var self = this;
        chips.forEach(function (chip) {
            var span = document.createElement('span');
            span.className = 'fgo-bulk__chip fgo-bulk__chip--' + chip[0];
            span.setAttribute('data-fgo-chip', chip[0]);
            span.textContent = fill(chip[1], { count: chip[2] });
            self.runChips.appendChild(span);
        });
    };

    Page.prototype.start = function () {
        if (this.runner) {
            return null;
        }
        var self = this;
        var ids = this.selectedIds();
        if (!ids.length) {
            return null;
        }
        var i18n = this.i18n;
        var queued = {};
        ids.forEach(function (id) { queued[id] = true; });
        this.notQueued = this.rows.length - ids.length;

        this.rows.forEach(function (row) {
            var box = row.querySelector('[data-fgo-select]');
            if (box) {
                box.disabled = true;
            }
            var id = Number(row.getAttribute('data-fgo-order-id'));
            self.setState(row, queued[id] ? 'queued' : 'not_processed', queued[id] ? i18n.queued : i18n.not_processed);
        });
        if (this.selectAll) {
            this.selectAll.disabled = true;
        }
        if (this.emailBox) {
            this.emailBox.disabled = true;
        }
        this.root.querySelectorAll('[data-fgo-result], [data-fgo-result-head]').forEach(function (el) {
            show(el, true);
        });
        show(this.root.querySelector('[data-fgo-precheck-actions]'), false);
        show(this.root.querySelector('[data-fgo-precheck-chips]'), false);
        show(this.root.querySelector('[data-fgo-options]'), false);
        show(this.progress, true);
        window.addEventListener('beforeunload', this.onBeforeUnload);

        this.runner = new Runner({
            ids: ids,
            intervalMs: this.intervalMs,
            send: function (id) { return self.send(id); },
            isAlive: function () { return document.body.contains(self.root); },
            onStart: function (id) {
                self.current = id;
                self.setState(self.rowFor(id), 'running', i18n.running || '');
                self.renderProgress();
            },
            onResult: function (id, result) {
                self.current = null;
                self.renderResult(id, result);
                self.renderProgress();
            },
        });
        this.renderProgress();

        return this.runner.start().then(function (summary) {
            self.finish(summary);
            return summary;
        });
    };

    Page.prototype.stop = function () {
        if (!this.runner || !this.runner.running) {
            return;
        }
        this.stopping = true;
        this.runner.stop();
        if (this.stopBtn) {
            this.stopBtn.disabled = true;
        }
        this.renderProgress();
    };

    Page.prototype.finish = function (summary) {
        var self = this;
        var i18n = this.i18n;
        this.summary = summary;
        this.current = null;
        this.finished = true;
        window.removeEventListener('beforeunload', this.onBeforeUnload);
        this.root.classList.add('fgo-bulk--finished');

        // Orders a stop left in the queue were never sent.
        this.rows.forEach(function (row) {
            if (row.getAttribute('data-fgo-run-state') === 'queued') {
                self.setState(row, 'not_processed', i18n.not_processed || '');
            }
        });

        this.renderProgress();
        if (this.progressLabel) {
            this.progressLabel.textContent = fill(summary.stopped ? i18n.progress_stopped : i18n.progress_done, {
                done: summary.processed,
                total: summary.total,
            });
        }
        show(this.stopBtn ? this.stopBtn.parentNode : null, false);

        // After an expired session both would post with a security hash the
        // new session does not know: the message says to start over instead.
        var live = this.sessionExpired === null;
        show(this.zipBtn, live && this.zipEnabled && this.zipIds().length > 0);
        if (this.retryBtn) {
            this.retryBtn.textContent = fill(i18n.retry_failed, { count: summary.failedIds.length });
            show(this.retryBtn, live && summary.failedIds.length > 0);
        }
        show(this.root.querySelector('[data-fgo-finished]'), true);
    };

    /** Orders with an invoice PDF: issued now, or already invoiced before. */
    Page.prototype.zipIds = function () {
        return this.rows.filter(function (row) {
            return row.getAttribute('data-fgo-has-invoice') === 'Y';
        }).map(function (row) {
            return Number(row.getAttribute('data-fgo-order-id'));
        });
    };

    Page.prototype.downloadZip = function () {
        var ids = this.zipIds();
        if (!ids.length) {
            return;
        }
        // A new tab: the archive downloads there, and an error page lands
        // there too instead of replacing these results.
        if ($ && typeof $.performPostRequest === 'function') {
            $.performPostRequest(this.zipUrl, { order_ids: ids.join(',') }, '_blank');
        }
    };

    /**
     * The failed orders go back through the same menu action (issue and
     * retry both through m_issue: an order may fail without a failed row,
     * which Retry would skip), as a POST: a new selection and pre-check,
     * with the admin's e-mail choice.
     */
    Page.prototype.retryFailed = function () {
        if (!this.summary || !this.summary.failedIds.length || !$ || typeof $.performPostRequest !== 'function') {
            return;
        }
        var data = { order_ids: this.summary.failedIds.join(',') };
        if (this.emailBox) {
            data.send_email = this.emailBox.checked ? 'Y' : 'N';
        }
        $.performPostRequest(this.retryUrl, data);
    };

    function init(context) {
        var scope = context && context.querySelectorAll ? context : document;
        var pages = [];
        scope.querySelectorAll('[data-ca-fgo-bulk]').forEach(function (root) {
            // ce.commoninit fires after every AJAX load, and the page may
            // also have been initialised on DOMContentLoaded.
            if (root.getAttribute('data-fgo-initialised') === 'Y') {
                return;
            }
            root.setAttribute('data-fgo-initialised', 'Y');
            var page;
            try {
                page = new Page(root);
                page.bind();
            } catch (e) {
                // The "script did not load" alert stays: the page cannot act.
                if (window.console && typeof window.console.error === 'function') {
                    window.console.error('FGO bulk page could not start', e);
                }
                return;
            }
            // Removed, not hidden: styles.css may be missing as well.
            var missing = root.querySelector('[data-fgo-script-missing]');
            if (missing && missing.parentNode) {
                missing.parentNode.removeChild(missing);
            }
            root.fgoBulkPage = page;
            pages.push(page);
        });
        return pages;
    }

    var api = {
        pluralForm: pluralForm,
        fill: fill,
        countLabel: countLabel,
        startLabel: startLabel,
        normalizeResult: normalizeResult,
        summarize: summarize,
        Runner: Runner,
        Page: Page,
        init: init,
    };
    window.FgoInvoicingBulk = api;

    if ($ && typeof $.ceEvent === 'function') {
        $.ceEvent('on', 'ce.commoninit', function () {
            init(document);
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
        });
    } else {
        init(document);
    }
}(window.Tygh, window.Tygh ? window.Tygh.$ : undefined));

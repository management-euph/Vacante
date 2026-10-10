/**
 * Eurosite search results — the "Condiții de Anulare și Plată" modal.
 *
 * Novoton's openInfoModal pattern (hidden per-offer divs + one #info-modal
 * shell), with one difference: Eurosite search responses carry no terms, so
 * the content is AJAX-fetched from eurosite_booking.offer_terms on first
 * open and cached in the offer's hidden div. All handlers are delegated to
 * document because the inline search swap replaces the results node.
 */
(function () {
    'use strict';

    function esc(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text == null ? '' : String(text)));
        return div.innerHTML;
    }

    function renderTerms(data) {
        var html = '';
        if (data.payment_lines && data.payment_lines.length) {
            html += '<div class="eurosite-modal-section"><strong>' + esc(window.EurositeI18n && EurositeI18n.payment_terms || 'Termeni de plată') + ':</strong><ul>';
            data.payment_lines.forEach(function (line) { html += '<li>' + esc(line) + '</li>'; });
            html += '</ul></div>';
        }
        if (data.cancellation && data.cancellation.length) {
            html += '<div class="eurosite-modal-section"><strong>' + esc(window.EurositeI18n && EurositeI18n.cancellation_terms || 'Condiții de anulare') + ':</strong>'
                + '<table class="eurosite-fees-table"><tbody>';
            data.cancellation.forEach(function (fee) {
                html += '<tr><td>' + esc(fee.from) + ' &ndash; ' + esc(fee.to) + '</td><td>' + esc(fee.value) + '</td></tr>';
            });
            html += '</tbody></table></div>';
        } else if (data.unavailable) {
            html += '<div class="eurosite-modal-section">' + esc(window.EurositeI18n && EurositeI18n.fees_unavailable || 'Condițiile de anulare vor fi confirmate la rezervare.') + '</div>';
        } else if (!html) {
            html += '<div class="eurosite-modal-section">' + esc(window.EurositeI18n && EurositeI18n.fees_none || 'Fără penalizări de anulare comunicate pentru această ofertă.') + '</div>';
        }
        return html;
    }

    function openModal(html) {
        var modal = document.getElementById('info-modal');
        var content = document.getElementById('info-modal-content');
        if (!modal || !content) { return; }
        content.innerHTML = html;
        modal.style.display = 'flex';
    }

    function closeModal() {
        var modal = document.getElementById('info-modal');
        if (modal) { modal.style.display = 'none'; }
    }

    document.addEventListener('click', function (e) {
        var link = e.target.closest ? e.target.closest('.eurosite-info-link') : null;
        if (link) {
            e.preventDefault();
            var modalId = link.getAttribute('data-modal-id');
            var offerKey = link.getAttribute('data-offer-key');
            var holder = document.getElementById('modal-content-' + modalId);
            if (holder && holder.innerHTML.trim() !== '') {
                openModal(holder.innerHTML);
                return;
            }
            openModal('<div class="eurosite-modal-loading">...</div>');
            var url = '/index.php?dispatch=eurosite_booking.offer_terms&offer_key=' + encodeURIComponent(offerKey || '');
            fetch(url, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var html = data && data.success
                        ? renderTerms(data)
                        : '<div class="eurosite-modal-section">' + esc(window.EurositeI18n && EurositeI18n.fees_expired || 'Oferta a expirat — reîncercați căutarea.') + '</div>';
                    if (holder) { holder.innerHTML = html; }
                    openModal(html);
                })
                .catch(function () {
                    openModal('<div class="eurosite-modal-section">Eroare de rețea.</div>');
                });
            return;
        }

        if (e.target.classList && e.target.classList.contains('eurosite-info-modal')) {
            closeModal();                                  // backdrop click
        }
        if (e.target.closest && e.target.closest('.eurosite-info-modal__close')) {
            closeModal();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closeModal(); }
    });
})();

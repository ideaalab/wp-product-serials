// Modal flow for the "Desvincular producto" link on the My Products page.
(function () {
    'use strict';

    function $(sel, root) { return (root || document).querySelector(sel); }
    function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    function onReady(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    onReady(function () {
        var modal = $('#ial-unbind-modal');
        if (!modal) return;
        if (typeof window.ialUnbind === 'undefined') return;

        var productNameEl = $('.ial-modal-product-name', modal);
        var motivoEl      = $('.ial-modal-motivo', modal);
        var errorEl       = $('.ial-modal-error', modal);
        var confirmBtn    = $('.ial-modal-confirm', modal);
        var cancelBtn     = $('.ial-modal-cancel', modal);
        var closeBtn      = $('.ial-modal-close', modal);
        var backdrop      = $('.ial-modal-backdrop', modal);

        var currentSerial = 0;
        var currentNonce  = '';
        var currentLink   = null;
        var originalConfirmLabel = confirmBtn.textContent;

        function openModal(link) {
            currentSerial = parseInt(link.dataset.serial, 10) || 0;
            currentNonce  = link.dataset.nonce || '';
            currentLink   = link;
            productNameEl.textContent = link.dataset.productName || '';
            motivoEl.value = '';
            errorEl.hidden = true;
            errorEl.textContent = '';
            confirmBtn.disabled = false;
            confirmBtn.textContent = originalConfirmLabel;
            modal.hidden = false;
            modal.classList.add('is-open');
            document.body.style.overflow = 'hidden';
            setTimeout(function () { motivoEl.focus(); }, 50);
        }

        function closeModal() {
            modal.hidden = true;
            modal.classList.remove('is-open');
            document.body.style.overflow = '';
        }

        function showError(msg) {
            errorEl.textContent = msg;
            errorEl.hidden = false;
        }

        $all('.ial-unbind-link').forEach(function (link) {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                openModal(link);
            });
        });

        cancelBtn.addEventListener('click', closeModal);
        closeBtn.addEventListener('click', closeModal);
        backdrop.addEventListener('click', closeModal);

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !modal.hidden) closeModal();
        });

        confirmBtn.addEventListener('click', function () {
            var motivo = motivoEl.value.trim();
            if (!motivo) {
                showError(window.ialUnbind.errMotivo || 'Required');
                motivoEl.focus();
                return;
            }
            if (!currentSerial) {
                showError(window.ialUnbind.errGeneric || 'Error');
                return;
            }

            confirmBtn.disabled = true;
            confirmBtn.textContent = window.ialUnbind.working || 'Working…';
            errorEl.hidden = true;

            var body = new URLSearchParams();
            body.append('action', 'ial_unbind_serial');
            body.append('serial_id', String(currentSerial));
            body.append('nonce', currentNonce);
            body.append('motivo', motivo);

            fetch(window.ialUnbind.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            })
                .then(function (r) { return r.json(); })
                .then(function (json) {
                    if (json && json.success) {
                        if (currentLink) {
                            var card = currentLink.closest('.ial-product-card');
                            if (card) {
                                card.style.transition = 'opacity .25s ease';
                                card.style.opacity = '0';
                                setTimeout(function () { card.remove(); }, 250);
                            }
                        }
                        closeModal();
                    } else {
                        confirmBtn.disabled = false;
                        confirmBtn.textContent = originalConfirmLabel;
                        var msg = (json && json.data && json.data.message)
                            ? json.data.message
                            : (window.ialUnbind.errGeneric || 'Error');
                        showError(msg);
                    }
                })
                .catch(function () {
                    confirmBtn.disabled = false;
                    confirmBtn.textContent = originalConfirmLabel;
                    showError(window.ialUnbind.errGeneric || 'Error');
                });
        });
    });
})();

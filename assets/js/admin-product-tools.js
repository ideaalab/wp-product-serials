// Per-product admin tools (currently: retroactive role application).
(function () {
    'use strict';

    function onReady(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    function formatTpl(tpl, args) {
        return tpl.replace(/%(\d+)\$d/g, function (_m, i) {
            var idx = parseInt(i, 10) - 1;
            return (idx >= 0 && idx < args.length) ? String(args[idx]) : '';
        });
    }

    onReady(function () {
        var btn = document.getElementById('ial_apply_roles_retro_btn');
        if (!btn) return;
        var result = document.getElementById('ial_apply_roles_retro_result');

        btn.addEventListener('click', function () {
            if (btn.disabled) return;

            var confirmMsg = btn.dataset.confirm || 'Are you sure?';
            if (!window.confirm(confirmMsg)) return;

            var productId = btn.dataset.product;
            var nonce     = btn.dataset.nonce;
            var running   = btn.dataset.running || 'Working…';
            var doneTpl   = btn.dataset.doneTpl || 'Done.';
            var errorMsg  = btn.dataset.error || 'Error.';

            btn.disabled = true;
            var originalLabel = btn.textContent;
            btn.textContent = running;
            if (result) {
                result.textContent = '';
                result.style.color = '';
            }

            var body = new URLSearchParams();
            body.append('action', 'ial_apply_roles_retroactively');
            body.append('product_id', productId);
            body.append('nonce', nonce);

            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            })
                .then(function (r) { return r.json(); })
                .then(function (json) {
                    btn.textContent = originalLabel;
                    if (json && json.success) {
                        var d = json.data || {};
                        var msg = formatTpl(doneTpl, [
                            d.users_updated || 0,
                            d.users_total || 0,
                            d.roles_added || 0
                        ]);
                        if (result) {
                            result.textContent = msg;
                            result.style.color = '#2271b1';
                        }
                    } else {
                        var em = (json && json.data && json.data.message) ? json.data.message : errorMsg;
                        if (result) {
                            result.textContent = em;
                            result.style.color = '#b32d2e';
                        }
                    }
                })
                .catch(function () {
                    btn.textContent = originalLabel;
                    if (result) {
                        result.textContent = errorMsg;
                        result.style.color = '#b32d2e';
                    }
                })
                .finally(function () {
                    btn.disabled = false;
                });
        });
    });
})();

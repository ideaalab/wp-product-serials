/**
 * Discount tier repeater on the plugin settings page.
 * Row indexes only need to be unique — the sanitizer re-sorts and re-indexes.
 */
(function () {
    'use strict';

    var wrapper = document.getElementById('ial-tiers');
    var template = document.getElementById('ial-tier-template');
    if (!wrapper || !template) {
        return;
    }

    var rows = wrapper.querySelector('.ial-tiers-rows');
    var addButton = document.getElementById('ial-tier-add');
    var nextIndex = rows.querySelectorAll('.ial-tier-row').length;

    function addRow() {
        var markup = template.innerHTML.replace(/__i__/g, String(nextIndex));
        nextIndex++;

        var holder = document.createElement('div');
        holder.innerHTML = markup.trim();

        var row = holder.firstElementChild;
        if (!row) {
            return;
        }

        rows.appendChild(row);
        var firstField = row.querySelector('input');
        if (firstField) {
            firstField.focus();
        }
    }

    function removeRow(row) {
        // Keep one row around so the table never collapses to nothing.
        if (rows.querySelectorAll('.ial-tier-row').length === 1) {
            row.querySelectorAll('input').forEach(function (input) {
                input.value = '';
            });
            return;
        }
        row.remove();
    }

    if (addButton) {
        addButton.addEventListener('click', addRow);
    }

    rows.addEventListener('click', function (event) {
        var button = event.target.closest('.ial-tier-remove');
        if (!button) {
            return;
        }
        event.preventDefault();
        var row = button.closest('.ial-tier-row');
        if (row) {
            removeRow(row);
        }
    });
})();

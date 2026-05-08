// Vanilla chip-style multi-select that progressively enhances a hidden
// <select multiple> inside an .ial-tokenizer wrapper. The native <select>
// remains the source of truth for form submission; the tokenizer UI only
// reads from / writes to its `selected` flags.
(function () {
    'use strict';

    function init(root) {
        if (root.dataset.ialTokenizerInit === '1') return;
        var select = root.querySelector('select[multiple]');
        if (!select) return;
        root.dataset.ialTokenizerInit = '1';

        var options = Array.prototype.map.call(select.options, function (o) {
            return { value: o.value, label: o.textContent };
        });

        select.style.display = 'none';

        var control = document.createElement('div');
        control.className = 'ial-tokenizer-control';

        var chipsBox = document.createElement('div');
        chipsBox.className = 'ial-tokenizer-chips';

        var search = document.createElement('input');
        search.type = 'text';
        search.className = 'ial-tokenizer-search';
        search.placeholder = root.dataset.placeholder || '';
        search.setAttribute('aria-label', root.dataset.placeholder || 'Add item');

        var dropdown = document.createElement('div');
        dropdown.className = 'ial-tokenizer-dropdown';
        dropdown.hidden = true;

        var list = document.createElement('ul');
        list.className = 'ial-tokenizer-options';
        dropdown.appendChild(list);

        chipsBox.appendChild(search);
        control.appendChild(chipsBox);
        control.appendChild(dropdown);
        root.appendChild(control);

        var emptyAllText = root.dataset.emptyAll || 'All items already selected';
        var emptyMatchText = root.dataset.emptyMatch || 'No matches';

        function getSelectedValues() {
            return Array.prototype.map.call(select.selectedOptions, function (o) { return o.value; });
        }

        function setSelected(value, selected) {
            for (var i = 0; i < select.options.length; i++) {
                if (select.options[i].value === value) {
                    select.options[i].selected = selected;
                    break;
                }
            }
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function makeChip(opt) {
            var chip = document.createElement('span');
            chip.className = 'ial-tokenizer-chip';
            chip.dataset.value = opt.value;
            chip.title = opt.value;

            var labelEl = document.createElement('span');
            labelEl.className = 'ial-tokenizer-chip-label';
            labelEl.textContent = opt.label;

            var removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'ial-tokenizer-chip-remove';
            removeBtn.setAttribute('aria-label', 'Remove ' + opt.label);
            removeBtn.textContent = '×';

            chip.appendChild(labelEl);
            chip.appendChild(removeBtn);
            return chip;
        }

        function makeOption(opt) {
            var li = document.createElement('li');
            li.className = 'ial-tokenizer-option';
            li.dataset.value = opt.value;
            li.title = opt.value;

            var lbl = document.createElement('span');
            lbl.textContent = opt.label;

            var code = document.createElement('code');
            code.textContent = opt.value;

            li.appendChild(lbl);
            li.appendChild(code);
            return li;
        }

        function render() {
            var selectedSet = {};
            getSelectedValues().forEach(function (v) { selectedSet[v] = true; });

            var oldChips = chipsBox.querySelectorAll('.ial-tokenizer-chip');
            for (var i = 0; i < oldChips.length; i++) oldChips[i].remove();
            options.forEach(function (opt) {
                if (selectedSet[opt.value]) {
                    chipsBox.insertBefore(makeChip(opt), search);
                }
            });

            var filter = search.value.toLowerCase().trim();
            list.innerHTML = '';
            var candidates = options.filter(function (opt) {
                if (selectedSet[opt.value]) return false;
                if (!filter) return true;
                return opt.label.toLowerCase().indexOf(filter) !== -1
                    || opt.value.toLowerCase().indexOf(filter) !== -1;
            });

            if (candidates.length === 0) {
                var empty = document.createElement('li');
                empty.className = 'ial-tokenizer-option-empty';
                empty.textContent = filter ? emptyMatchText : emptyAllText;
                list.appendChild(empty);
            } else {
                candidates.forEach(function (opt) { list.appendChild(makeOption(opt)); });
            }
        }

        function openDropdown() {
            dropdown.hidden = false;
            control.classList.add('is-open');
            render();
        }

        function closeDropdown() {
            dropdown.hidden = true;
            control.classList.remove('is-open');
            search.value = '';
        }

        search.addEventListener('focus', openDropdown);
        search.addEventListener('click', openDropdown);
        search.addEventListener('input', render);
        search.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeDropdown();
                search.blur();
            } else if (e.key === 'Backspace' && search.value === '') {
                var chips = chipsBox.querySelectorAll('.ial-tokenizer-chip');
                if (chips.length > 0) {
                    setSelected(chips[chips.length - 1].dataset.value, false);
                    render();
                }
            }
        });

        chipsBox.addEventListener('click', function (e) {
            var rm = e.target.closest && e.target.closest('.ial-tokenizer-chip-remove');
            if (rm) {
                e.preventDefault();
                var chip = rm.closest('.ial-tokenizer-chip');
                if (chip) {
                    setSelected(chip.dataset.value, false);
                    render();
                }
                return;
            }
            if (e.target === chipsBox) {
                search.focus();
            }
        });

        list.addEventListener('click', function (e) {
            var opt = e.target.closest && e.target.closest('.ial-tokenizer-option');
            if (!opt) return;
            setSelected(opt.dataset.value, true);
            search.value = '';
            render();
            search.focus();
        });

        document.addEventListener('click', function (e) {
            if (!control.contains(e.target)) {
                closeDropdown();
            }
        });

        render();
    }

    function initAll() {
        document.querySelectorAll('.ial-tokenizer').forEach(init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();

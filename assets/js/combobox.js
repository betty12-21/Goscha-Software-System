/* ============================================================
   Beauty-php-ai — searchable dropdown + dependent selects
   ------------------------------------------------------------
   A native <select> cannot be typed into, so every long list in
   the app gets upgraded to a combobox: a text box that shows the
   current choice and, when focused, filters the same options.

   The original <select> is never removed. It stays in the DOM,
   visually hidden but still rendered (so `required` validation and
   form submission keep working) and it remains the single source of
   truth for the value. Options are read live on every open, which
   matters because other scripts rewrite option lists in place.
   ============================================================ */

(function () {
    'use strict';

    var DEFAULT_MIN_OPTIONS = 5;
    var counter = 0;
    var openCombo = null;

    function optionData(opt) {
        return {
            value: opt.value,
            label: (opt.textContent || '').replace(/\s+/g, ' ').trim(),
            disabled: opt.disabled === true,
            hidden: opt.hidden === true || opt.hasAttribute('hidden'),
            selected: opt.selected === true
        };
    }

    function readTree(select) {
        var tree = [];
        Array.prototype.forEach.call(select.children, function (node) {
            if (node.tagName === 'OPTGROUP') {
                var items = [];
                Array.prototype.forEach.call(node.children, function (opt) {
                    if (opt.tagName !== 'OPTION') return;
                    var item = optionData(opt);
                    if (!item.hidden) items.push(item);
                });
                if (items.length) tree.push({ label: node.label || '', items: items });
            } else if (node.tagName === 'OPTION') {
                var one = optionData(node);
                if (!one.hidden) tree.push({ label: '', items: [one] });
            }
        });
        return tree;
    }

    function flatOptions(tree) {
        var out = [];
        tree.forEach(function (group) {
            group.items.forEach(function (item) { out.push(item); });
        });
        return out;
    }

    function findOption(select, value) {
        var found = null;
        Array.prototype.forEach.call(select.options, function (opt) {
            if (found === null && opt.value === value) found = opt;
        });
        return found;
    }

    function Combo(select) {
        this.select = select;
        this.uid = 'bsaiCombo' + (++counter);
        this.min = parseInt(select.getAttribute('data-combo-min'), 10);
        if (isNaN(this.min)) this.min = DEFAULT_MIN_OPTIONS;
        this.rows = [];
        this.activeIndex = -1;
        this.build();
        this.bind();
        this.sync();
    }

    Combo.prototype.build = function () {
        var select = this.select;

        this.wrap = document.createElement('div');
        this.wrap.className = 'bsai-combo';
        if (select.classList.contains('form-select-sm')) this.wrap.classList.add('bsai-combo--sm');

        this.display = document.createElement('input');
        this.display.type = 'text';
        this.display.className = 'bsai-combo__display';
        this.display.id = this.uid + 'Display';
        this.display.setAttribute('role', 'combobox');
        this.display.setAttribute('aria-autocomplete', 'list');
        this.display.setAttribute('aria-expanded', 'false');
        this.display.setAttribute('aria-controls', this.uid + 'List');
        this.display.setAttribute('autocomplete', 'off');

        this.arrow = document.createElement('span');
        this.arrow.className = 'bsai-combo__arrow';
        this.arrow.setAttribute('aria-hidden', 'true');
        this.arrow.innerHTML = '<i class="bi bi-chevron-down"></i>';

        this.control = document.createElement('div');
        this.control.className = 'bsai-combo__control';
        this.control.appendChild(this.display);
        this.control.appendChild(this.arrow);

        this.menu = document.createElement('div');
        this.menu.className = 'bsai-combo__menu';
        this.menu.hidden = true;

        this.filter = document.createElement('input');
        this.filter.type = 'text';
        this.filter.className = 'bsai-combo__filter';
        this.filter.placeholder = 'Type to filter…';
        this.filter.setAttribute('aria-label', 'Filter choices');
        this.filter.setAttribute('autocomplete', 'off');

        this.filterRow = document.createElement('div');
        this.filterRow.className = 'bsai-combo__filterrow';
        this.filterRow.appendChild(this.filter);

        this.list = document.createElement('div');
        this.list.className = 'bsai-combo__list';
        this.list.id = this.uid + 'List';
        this.list.setAttribute('role', 'listbox');

        this.status = document.createElement('div');
        this.status.className = 'bsai-combo__status';
        this.status.hidden = true;

        this.menu.appendChild(this.filterRow);
        this.menu.appendChild(this.list);
        this.menu.appendChild(this.status);

        this.wrap.appendChild(this.control);
        this.wrap.appendChild(this.menu);

        select.parentNode.insertBefore(this.wrap, select);
        select.classList.add('bsai-combo-native');
        select.tabIndex = -1;
        select.dataset.comboReady = '1';

        var label = select.id
            ? document.querySelector('label[for="' + select.id.replace(/"/g, '\\"') + '"]')
            : null;

        if (label) {
            if (!label.id) label.id = this.uid + 'Label';
            this.display.setAttribute('aria-labelledby', label.id);
            label.setAttribute('data-combo-for', this.uid + 'Display');
        } else {
            var hint = select.getAttribute('aria-label');
            if (!hint) {
                var first = select.options[0];
                hint = first ? (first.textContent || '').replace(/\s+/g, ' ').trim() : '';
            }
            this.display.setAttribute('aria-label', hint || 'Select');
        }
    };

    Combo.prototype.choices = function () {
        return flatOptions(readTree(this.select)).filter(function (item) { return !item.disabled; });
    };

    Combo.prototype.selectedLabel = function () {
        var opt = this.select.options[this.select.selectedIndex];
        if (!opt || this.select.selectedIndex < 0) return '';
        return (opt.textContent || '').replace(/\s+/g, ' ').trim();
    };

    Combo.prototype.setDisplay = function (text) {
        this.display.value = text;
        this.display.classList.toggle('is-placeholder', text === '');
    };

    Combo.prototype.sync = function () {
        if (document.activeElement !== this.display) {
            this.setDisplay(this.selectedLabel());
        }

        var off = this.select.disabled || this.select.readOnly;
        this.wrap.classList.toggle('is-disabled', !!off);
        this.wrap.classList.toggle('is-invalid', this.select.classList.contains('is-invalid'));
    };

    Combo.prototype.rank = function (item, q) {
        var label = item.label.toLowerCase();
        if (label.indexOf(q) === 0) return 0;
        var words = label.split(' ');
        for (var i = 0; i < words.length; i++) {
            if (words[i].indexOf(q) === 0) return 1;
        }
        if (label.indexOf(q) !== -1) return 2;
        return -1;
    };

    Combo.prototype.render = function () {
        var self = this;
        var tree = readTree(this.select);
        var q = this.filter.value.trim().toLowerCase();
        var selected = this.select.value;

        this.list.innerHTML = '';
        this.rows = [];
        this.rowSeq = 0;

        tree.forEach(function (group) {
            var items = group.items.slice();

            if (q) {
                items = items.map(function (item) {
                    return { item: item, rank: item.disabled ? -1 : self.rank(item, q) };
                }).filter(function (row) {
                    return row.rank !== -1;
                }).sort(function (a, b) { return a.rank - b.rank; })
                  .map(function (row) { return row.item; });
            }

            if (!items.length) return;

            if (group.label && !q) {
                var head = document.createElement('div');
                head.className = 'bsai-combo__group';
                head.textContent = group.label;
                self.list.appendChild(head);
            }

            items.forEach(function (item) {
                var row = document.createElement('div');
                row.className = 'bsai-combo__opt';
                row.id = self.uid + 'Opt' + (self.rowSeq++);
                row.setAttribute('role', 'option');
                row.setAttribute('data-value', item.value);
                row.textContent = item.label;
                row.setAttribute('aria-selected', item.value === selected ? 'true' : 'false');
                if (item.value === selected) row.classList.add('is-selected');
                if (item.disabled) {
                    row.classList.add('is-disabled');
                    row.setAttribute('aria-disabled', 'true');
                } else {
                    self.rows.push(row);
                    row.addEventListener('mousedown', function (ev) {
                        ev.preventDefault();
                        self.choose(item.value);
                    });
                }
                self.list.appendChild(row);
            });
        });

        var total = this.choices().length;
        this.filterRow.hidden = total <= this.min;

        if (!this.rows.length) {
            var none = document.createElement('div');
            none.className = 'bsai-combo__none';
            none.textContent = q ? 'No match for "' + this.filter.value.trim() + '"' : 'Nothing to choose from';
            this.list.appendChild(none);
        }

        var shown = this.rows.length;
        this.status.hidden = !q;
        if (q) this.status.textContent = shown + ' of ' + total + ' shown';
    };

    Combo.prototype.open = function () {
        if (this.select.disabled) return;
        if (openCombo && openCombo !== this) openCombo.close();
        openCombo = this;
        this.menu.hidden = false;
        this.display.setAttribute('aria-expanded', 'true');
        this.wrap.classList.add('is-open');
        this.filter.value = '';
        this.render();
        this.setActive(-1, false);
        if (!this.filterRow.hidden) this.filter.focus();
    };

    Combo.prototype.close = function () {
        if (!this.wrap.classList.contains('is-open')) return;
        this.menu.hidden = true;
        this.display.setAttribute('aria-expanded', 'false');
        this.display.removeAttribute('aria-activedescendant');
        this.wrap.classList.remove('is-open');
        this.activeIndex = -1;
        this.rows = [];
        this.filter.value = '';
        this.setDisplay(this.selectedLabel());
        if (openCombo === this) openCombo = null;
    };

    Combo.prototype.setActive = function (index, scroll) {
        if (!this.rows.length) return;
        if (index < 0) index = this.rows.length - 1;
        if (index >= this.rows.length) index = 0;
        this.activeIndex = index;
        this.rows.forEach(function (row, i) {
            row.classList.toggle('is-active', i === index);
        });
        var row = this.rows[index];
        this.display.setAttribute('aria-activedescendant', row.id);
        if (scroll !== false && row.scrollIntoView) {
            row.scrollIntoView({ block: 'nearest' });
        }
    };

    Combo.prototype.choose = function (value) {
        var opt = findOption(this.select, value);
        if (!opt || opt.disabled) return;
        this.select.value = value;
        this.close();
        this.select.dispatchEvent(new Event('change', { bubbles: true }));
    };

    Combo.prototype.bind = function () {
        var self = this;

        this.display.addEventListener('focus', function () {
            self.display.select();
            self.open();
        });
        this.display.addEventListener('input', function () {
            if (!self.wrap.classList.contains('is-open')) self.open();
            self.filter.value = self.display.value;
            self.render();
            self.setActive(0, false);
        });
        this.display.addEventListener('keydown', function (ev) {
            if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
                ev.preventDefault();
                if (!self.wrap.classList.contains('is-open')) { self.open(); return; }
                self.setActive(self.activeIndex + (ev.key === 'ArrowDown' ? 1 : -1));
            } else if (ev.key === 'Enter') {
                if (self.wrap.classList.contains('is-open') && self.rows.length) {
                    ev.preventDefault();
                    self.choose(self.rows[self.activeIndex].getAttribute('data-value'));
                }
            } else if (ev.key === 'Escape') {
                if (self.wrap.classList.contains('is-open')) { ev.preventDefault(); self.display.blur(); }
            }
        });
        this.display.addEventListener('blur', function () {
            setTimeout(function () {
                if (!self.wrap.contains(document.activeElement)) self.close();
            }, 120);
        });

        this.filter.addEventListener('input', function () {
            self.render();
            self.setActive(0, false);
        });
        this.filter.addEventListener('keydown', function (ev) {
            if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
                ev.preventDefault();
                self.setActive(self.activeIndex + (ev.key === 'ArrowDown' ? 1 : -1));
            } else if (ev.key === 'Enter') {
                if (self.rows.length) {
                    ev.preventDefault();
                    self.choose(self.rows[self.activeIndex].getAttribute('data-value'));
                }
            } else if (ev.key === 'Escape') {
                ev.preventDefault();
                self.display.focus();
            }
        });
        this.filter.addEventListener('blur', function () {
            setTimeout(function () {
                if (!self.wrap.contains(document.activeElement)) self.close();
            }, 120);
        });

        this.control.addEventListener('mousedown', function (ev) {
            if (ev.target === self.arrow) ev.preventDefault();
        });

        this.select.addEventListener('invalid', function () {
            self.wrap.classList.add('is-invalid');
            self.open();
            self.display.focus();
        });
        this.select.addEventListener('change', function () { self.sync(); });
    };

    Combo.prototype.observe = function () {
        var self = this;
        if (typeof MutationObserver === 'undefined') return;
        this.observer = new MutationObserver(function () { self.sync(); });
        this.observer.observe(this.select, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['disabled', 'selected']
        });
    };

    /* --------------------------------------------------------
       Dependent selects: pick a category, see only its subcategories
       -------------------------------------------------------- */
    function initCascade(select) {
        var sourceId = select.getAttribute('data-cascade');
        if (!sourceId) return;
        var source = document.getElementById(sourceId) || document.querySelector('[name="' + sourceId + '"]');
        if (!source) return;

        var hint = select.getAttribute('data-cascade-hint')
            ? document.querySelector(select.getAttribute('data-cascade-hint'))
            : null;

        function apply() {
            var want = source.value;
            var options = Array.prototype.slice.call(select.options);
            var kept = 0;
            var fallback = null;

            options.forEach(function (opt) {
                if (opt.hasAttribute('data-all')) {
                    opt.hidden = false;
                    opt.disabled = false;
                    fallback = opt.value;
                    return;
                }
                var parent = opt.getAttribute('data-parent');
                var show = !want || want === '0' || parent === want;
                opt.hidden = !show;
                opt.disabled = !show;
                if (show && parent === want) kept++;
            });

            var current = select.value;
            if (current !== '' && current !== '0') {
                var live = findOption(select, current);
                if (!live || live.disabled) {
                    select.value = fallback !== null ? fallback : '';
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }

            if (hint) {
                var text = '';
                if (want && want !== '0') {
                    text = kept
                        ? kept + ' subcategor' + (kept === 1 ? 'y' : 'ies') + ' in this category.'
                        : 'This category has no subcategories.';
                }
                hint.textContent = text;
                hint.hidden = text === '';
            }
        }

        source.addEventListener('change', function () { apply(); });
        apply();
    }

    /* --------------------------------------------------------
       Boot
       -------------------------------------------------------- */
    function boot() {
        var selector = 'select.form-select:not([data-no-search]):not([data-picker-select])';
        document.querySelectorAll(selector).forEach(function (select) {
            if (select.dataset.comboReady === '1') return;
            if (select.multiple) return;
            var combo = new Combo(select);
            combo.observe();
        });

        document.querySelectorAll('select[data-cascade]').forEach(initCascade);

        document.querySelectorAll('label[data-combo-for]').forEach(function (label) {
            label.addEventListener('click', function (ev) {
                var input = document.getElementById(label.getAttribute('data-combo-for'));
                if (!input) return;
                ev.preventDefault();
                input.focus();
                input.click();
            });
        });

        document.addEventListener('mousedown', function (ev) {
            if (openCombo && !openCombo.wrap.contains(ev.target)) openCombo.close();
        });

        var resetForms = document.querySelectorAll('form');
        Array.prototype.forEach.call(resetForms, function (form) {
            form.addEventListener('reset', function () {
                setTimeout(function () {
                    document.querySelectorAll(selector).forEach(function (select) {
                        if (select.dataset.comboReady !== '1') return;
                        var wrap = select.parentNode.querySelector('.bsai-combo');
                        if (!wrap) return;
                        var display = wrap.querySelector('.bsai-combo__display');
                        if (!display) return;
                        var opt = select.options[select.selectedIndex];
                        display.value = opt ? (opt.textContent || '').trim() : '';
                        display.classList.toggle('is-placeholder', display.value === '');
                    });
                }, 0);
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();

/* ============================================================
   Beauty-php-ai — application scripts
   ============================================================ */

(function () {
    'use strict';

    /* --------------------------------------------------------
       Sidebar toggle (mobile)
       -------------------------------------------------------- */
    function initSidebar() {
        var sidebar = document.getElementById('appSidebar');
        if (!sidebar) return;

        var toggle = document.getElementById('sidebarToggle');
        var overlay = document.createElement('div');
        overlay.className = 'sidebar-overlay';
        document.body.appendChild(overlay);

        function open() {
            sidebar.classList.add('open');
            overlay.classList.add('show');
        }
        function close() {
            sidebar.classList.remove('open');
            overlay.classList.remove('show');
        }

        if (toggle) toggle.addEventListener('click', open);
        overlay.addEventListener('click', close);
        var closeBtn = document.getElementById('sidebarClose');
        if (closeBtn) closeBtn.addEventListener('click', close);
    }

    /* --------------------------------------------------------
       Toasts
       -------------------------------------------------------- */
    function initToasts() {
        document.querySelectorAll('.toast').forEach(function (el) {
            var t = new bootstrap.Toast(el, { delay: 4500 });
            t.show();
        });
    }

    /* --------------------------------------------------------
       Confirmation dialog for delete/action links & forms
       Uses: data-confirm="message", data-confirm-action (optional)
       -------------------------------------------------------- */
    function initConfirmations() {
        var modalEl = document.getElementById('bsaiConfirmModal');
        if (!modalEl) return;
        var modal = new bootstrap.Modal(modalEl);
        var textEl = document.getElementById('bsaiConfirmText');
        var formEl = document.getElementById('bsaiConfirmForm');
        var btnEl = document.getElementById('bsaiConfirmBtn');

        document.querySelectorAll('[data-confirm]').forEach(function (el) {
            el.addEventListener('click', function (ev) {
                ev.preventDefault();
                var msg = el.getAttribute('data-confirm');
                var action = el.getAttribute('data-confirm-action');
                var label = el.getAttribute('data-confirm-label') || 'Delete';
                textEl.textContent = msg || 'Are you sure you want to delete this record?';
                btnEl.textContent = label;
                formEl.action = action || el.getAttribute('href') || '#';
                modal.show();
            });
        });
    }

    /* --------------------------------------------------------
       Inline table filtering by text input
       -------------------------------------------------------- */
    function initTableFilters() {
        document.querySelectorAll('[data-table-filter]').forEach(function (input) {
            input.addEventListener('input', function () {
                var target = document.querySelector(input.getAttribute('data-table-filter'));
                if (!target) return;
                var q = input.value.toLowerCase().trim();
                var rows = target.querySelectorAll('tbody tr');
                rows.forEach(function (row) {
                    var text = (row.textContent || '').toLowerCase();
                    row.style.display = text.indexOf(q) !== -1 ? '' : 'none';
                });
            });
        });
    }

    /* --------------------------------------------------------
       Appointment form: add/remove services, totals, availability
       -------------------------------------------------------- */
    function initAppointmentForm() {
        var container = document.getElementById('serviceRows');
        if (!container) return;

        var addBtn = document.getElementById('addServiceBtn');
        var serviceSelect = document.getElementById('serviceSelect');
        var template = document.getElementById('serviceRowTemplate');
        if (!template) return;

        /* Booking pages listen for this to recompute staff and time slots
           whenever the chosen services (and therefore the duration) change. */
        function notifyServicesChanged() {
            container.dispatchEvent(new CustomEvent('serviceschanged', { bubbles: true }));
        }

        function refreshTotals() {
            var subtotal = 0, minutes = 0;
            container.querySelectorAll('.service-row').forEach(function (row) {
                var price = parseFloat(row.dataset.price) || 0;
                var dur = parseInt(row.dataset.dur, 10) || 0;
                subtotal += price;
                minutes += dur;
            });

            var discount = parseFloat(document.getElementById('discountInput') ? document.getElementById('discountInput').value : 0) || 0;
            var taxRate = parseFloat(document.getElementById('taxRateInput') ? document.getElementById('taxRateInput').value : 0) || 0;
            var tax = (subtotal - discount) * (taxRate / 100);
            var total = subtotal - discount + tax;

            if (document.getElementById('subtotalDisplay')) document.getElementById('subtotalDisplay').textContent = subtotal.toFixed(2);
            if (document.getElementById('durationDisplay')) document.getElementById('durationDisplay').textContent = minutes + ' min';
            if (document.getElementById('taxDisplay')) document.getElementById('taxDisplay').textContent = tax.toFixed(2);
            if (document.getElementById('totalDisplay')) document.getElementById('totalDisplay').textContent = total.toFixed(2);
        }

        function addRow(serviceId, name, path, price, dur) {
            var row = document.createElement('div');
            row.className = 'service-row d-flex align-items-center gap-2 mb-2';
            row.dataset.price = price;
            row.dataset.dur = dur;
            row.innerHTML =
                '<input type="hidden" name="service_ids[]" value="' + serviceId + '">' +
                '<div class="flex-grow-1"><div class="fw-semibold small">' + name + '</div>' +
                (path ? '<div class="small text-muted">' + path + '</div>' : '') +
                '<div class="small text-muted">' + price.toFixed(2) + ' ETB · ' + dur + ' min</div></div>' +
                '<button type="button" class="btn btn-sm btn-light btn-remove-service"><i class="bi bi-x-lg"></i></button>';

            container.appendChild(row);
            refreshTotals();
            notifyServicesChanged();
        }

        if (addBtn && serviceSelect) {
            addBtn.addEventListener('click', function () {
                var opt = serviceSelect.selectedOptions[0];
                if (!opt || opt.value === '') return;
                var id = opt.value;
                var already = Array.prototype.some.call(container.querySelectorAll('.service-row input'), function (i) {
                    return i.value === id;
                });
                if (already) return;
                addRow(id, opt.dataset.name, opt.dataset.path || '', parseFloat(opt.dataset.price), opt.dataset.dur);
                serviceSelect.value = '';
            });
        }

        /* Delegated so rows rendered server-side (edit mode) also get a
           working remove button. */
        container.addEventListener('click', function (e) {
            var btn = e.target.closest('.btn-remove-service');
            if (!btn || !container.contains(btn)) return;
            var row = btn.closest('.service-row');
            if (row) row.remove();
            refreshTotals();
            notifyServicesChanged();
        });

        var discountEl = document.getElementById('discountInput');
        if (discountEl) discountEl.addEventListener('input', refreshTotals);

        /* Seed the summary from services rendered server-side. */
        refreshTotals();
    }

    /* --------------------------------------------------------
       Booking cascade: Date -> Staff -> Time

       Shared by the appointment form and the walk-in form. The
       receptionist can only ever pick a slot the server would
       accept; the server still validates on save.

       The form carries the configuration:
         data-booking-cascade
         data-availability-url   endpoint returning the JSON
         data-chosen-time        time to keep selected when editing
         data-allow-unassigned   "false" makes staff mandatory
       -------------------------------------------------------- */
    function initBookingCascade() {
        var form = document.querySelector('[data-booking-cascade]');
        if (!form) return;

        var dateInput   = document.getElementById('appointment_date');
        var timeSelect  = document.getElementById('start_time');
        if (!dateInput || !timeSelect) return;

        var endpoint    = form.dataset.availabilityUrl || 'availability.php';
        var staffSelect = document.getElementById('staff_id');
        var rowsBox     = document.getElementById('serviceRows');
        var availBox    = document.getElementById('availabilityBox');
        var staffHint   = document.getElementById('staffHint');
        var customerEl  = document.getElementById('customer_id') || document.getElementById('customerIdInput');
        var chosenTime  = form.dataset.chosenTime || '';
        var allowNone   = form.dataset.allowUnassigned !== 'false';

        /* Snapshot of every staff member, used until a date is chosen. */
        var allStaff = staffSelect
            ? Array.prototype.map.call(staffSelect.options, function (o) {
                return o.value ? { id: o.value, name: o.textContent.trim(), start: null, end: null } : null;
            }).filter(Boolean)
            : [];

        function humanTime(hhmmss) {
            if (!hhmmss) return '';
            var p = String(hhmmss).split(':');
            var h = parseInt(p[0], 10);
            return (h % 12 || 12) + ':' + p[1] + ' ' + (h >= 12 ? 'PM' : 'AM');
        }

        function serviceState() {
            var ids = [], minutes = 0;
            if (!rowsBox) return { ids: ids, minutes: minutes };
            rowsBox.querySelectorAll('.service-row').forEach(function (row) {
                var input = row.querySelector('input[name="service_ids[]"]');
                if (input && input.value) ids.push(input.value);
                minutes += parseInt(row.dataset.dur, 10) || 0;
            });
            return { ids: ids, minutes: minutes };
        }

        function paintStaff(list) {
            if (!staffSelect) return;
            var keep = staffSelect.value;
            staffSelect.innerHTML = '';

            if (allowNone) {
                var un = document.createElement('option');
                un.value = '';
                un.textContent = '— Unassigned —';
                staffSelect.appendChild(un);
            }

            list.forEach(function (m) {
                var o = document.createElement('option');
                o.value = m.id;
                o.textContent = m.start
                    ? m.name + ' (' + humanTime(m.start) + ' – ' + humanTime(m.end) + ')'
                    : m.name;
                staffSelect.appendChild(o);
            });

            staffSelect.value = keep;
            if (staffSelect.value !== keep) staffSelect.value = allowNone ? '' : (list[0] ? list[0].id : '');
        }

        function paintSlots(data) {
            timeSelect.innerHTML = '';

            if (!data.slots.length) {
                var none = document.createElement('option');
                none.value = '';
                none.textContent = data.reason || 'No available time slots';
                timeSelect.appendChild(none);
                timeSelect.disabled = true;
                if (availBox) {
                    availBox.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>' +
                        (data.reason || 'No available time slots for this date.') + '</span>';
                }
                return;
            }

            timeSelect.disabled = false;
            var placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = 'Select a time';
            timeSelect.appendChild(placeholder);

            var hasChosen = false;
            data.slots.forEach(function (slot) {
                var opt = document.createElement('option');
                opt.value = slot;
                opt.textContent = humanTime(slot);
                if (chosenTime && slot.slice(0, 5) === String(chosenTime).slice(0, 5)) {
                    opt.selected = true;
                    hasChosen = true;
                }
                timeSelect.appendChild(opt);
            });

            if (!availBox) return;
            var msg = data.slots.length + ' available slot(s)';
            if (data.open) msg += ' · salon ' + humanTime(data.open) + ' – ' + humanTime(data.close);
            availBox.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>' + msg + '</span>';

            if (chosenTime && !hasChosen) {
                availBox.innerHTML = '<span class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>The current time is not available on this date.</span>';
            }
        }

        function reset() {
            paintStaff(allStaff);
            timeSelect.innerHTML = '<option value="">Select a date first</option>';
            timeSelect.disabled = true;
            if (availBox) availBox.innerHTML = '';
            if (staffHint) {
                staffHint.textContent = allowNone
                    ? 'Narrows to staff working on the chosen date once a date is picked.'
                    : 'Only staff working on the chosen date are listed.';
            }
            notifyCascade();
        }

        /* Lets a page react to the cascade settling — the walk-in form only
           enables its pay button once a real slot is selected. */
        function notifyCascade() {
            form.dispatchEvent(new CustomEvent('cascadechange', { bubbles: true }));
        }

        function refresh(retried) {
            var date = dateInput.value;
            if (!date) { reset(); return; }

            var svc = serviceState();
            var params = new URLSearchParams({ date: date });
            params.set('duration', svc.minutes);
            params.set('services', svc.ids.join(','));
            if (staffSelect && staffSelect.value) params.set('staff_id', staffSelect.value);
            if (customerEl && customerEl.value) params.set('customer_id', customerEl.value);
            if (dateInput.dataset.exclude) params.set('exclude', dateInput.dataset.exclude);

            fetch(endpoint + '?' + params.toString(), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.ok) return;

                    var before = staffSelect ? staffSelect.value : '';
                    paintStaff(data.staff && data.staff.length ? data.staff : allStaff);
                    var after = staffSelect ? staffSelect.value : '';

                    if (staffHint) {
                        staffHint.textContent = data.staff.length
                            ? data.staff.length + ' staff member(s) work this date and perform the chosen services.'
                            : 'No staff member works this date with the chosen services.';
                    }

                    /* Slots were computed for a staff member who just dropped out
                       of the list, so ask again with the corrected selection. */
                    if (after !== before && !retried) { refresh(true); return; }

                    paintSlots(data);
                    notifyCascade();
                })
                .catch(function () { if (availBox) availBox.innerHTML = ''; });
        }

        dateInput.addEventListener('change', function () { refresh(false); });
        if (staffSelect) staffSelect.addEventListener('change', function () { refresh(false); });
        if (customerEl) customerEl.addEventListener('change', function () { refresh(false); });
        if (rowsBox) rowsBox.addEventListener('serviceschanged', function () { refresh(false); });

        refresh(false);
    }

    /* --------------------------------------------------------
       Charts
       -------------------------------------------------------- */
    window.bsaiChart = function (canvasId, type, labels, data, options) {
        var canvas = document.getElementById(canvasId);
        if (!canvas || typeof Chart === 'undefined') return;

        var palette = ['#b76e79', '#c9a44c', '#6f9f7d', '#6f7fb8', '#69a7b8', '#c76f6f', '#9b9491', '#a0545f'];

        var cfg = Object.assign({
            type: type,
            data: {
                labels: labels,
                datasets: [{
                    label: options && options.label ? options.label : 'Value',
                    data: data,
                    backgroundColor: type === 'doughnut' || type === 'pie' ? palette : palette[0] + '33',
                    borderColor: type === 'doughnut' || type === 'pie' ? palette : palette[0],
                    borderWidth: type === 'line' ? 2 : 1,
                    fill: type === 'line' ? true : undefined,
                    tension: type === 'line' ? 0.35 : undefined,
                    pointRadius: type === 'line' ? 3 : undefined
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: type === 'doughnut' || type === 'pie' }
                },
                scales: (type === 'doughnut' || type === 'pie') ? undefined : {
                    y: { beginAtZero: true, grid: { color: '#f0e6d8' } },
                    x: { grid: { display: false } }
                }
            }
        }, options || {});

        new Chart(canvas, cfg);
    };

    /* -------------------------------------------------------- */
    document.addEventListener('DOMContentLoaded', function () {
        initSidebar();
        initToasts();
        initConfirmations();
        initTableFilters();
        initAppointmentForm();
        initBookingCascade();
    });
})();

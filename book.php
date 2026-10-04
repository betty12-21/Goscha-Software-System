<?php
/**
 * Beauty-php-ai — Public booking request page.
 *
 * Customers do NOT have system accounts. A booking request is saved to the
 * waitlist and the salon administrator converts it into a
 * real appointment.
 */

require_once __DIR__ . '/includes/init.php';

/**
 * One selectable service card on the public booking page.
 */
function public_service_option(array $service): string
{
    return '<label class="d-flex align-items-center gap-3 bg-white border rounded-4 p-3 shadow-sm service-option" style="cursor:pointer;">'
        . '<input class="form-check-input service-radio" type="radio" name="service-radio"'
        . ' value="' . (int)$service['id'] . '" style="cursor:pointer;">'
        . '<div class="flex-grow-1">'
        . '<div class="fw-bold">' . e($service['name']) . '</div>'
        . '<div class="small text-muted">' . (int)$service['duration_minutes'] . ' min</div>'
        . '</div>'
        . '<span class="fw-bold text-rose">' . money($service['price']) . ' ' . e(currency()) . '</span>'
        . '</label>';
}

$serviceTree = [];
try {
    $serviceTree = service_picker_tree(true);
} catch (Throwable $e) {
    $serviceTree = [];
}

$maxDate = date('Y-m-d', strtotime('+' . (int)get_setting('appointment_advance_days', 30) . ' days'));

$success = null;
$error   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $name    = trim($_POST['name'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $service = (int)($_POST['service_id'] ?? 0);
        $date    = $_POST['date'] ?? '';
        $time    = $_POST['time'] ?? '';
        $notes   = trim($_POST['notes'] ?? '');

        if ($name === '' || $phone === '') {
            $error = 'Please enter your name and phone number.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $error = 'Please choose a valid date.';
        } elseif (!scheduling_day_info($date)['is_open']) {
            /* The slot list only offers trading days, but a hand-made request
               must not land on the waitlist for a day the salon is shut. */
            $error = 'The salon is not open on the date you chose. Please pick another day.';
        } elseif ($time === '') {
            $error = 'Please choose an available time.';
        } else {
            try {
                $stmt = db()->prepare(
                    'INSERT INTO waitlist (customer_id, name, phone, service_id, preferred_date, preferred_time, notes, status)
                     VALUES (NULL, :name, :phone, :service, :date, :time, :notes, "waiting")'
                );
                $stmt->execute([
                    'name'    => $name,
                    'phone'   => $phone,
                    'service' => $service ?: null,
                    'date'    => $date,
                    'time'    => $time,
                    'notes'   => $notes,
                ]);

                $serviceName = '';
                if ($service > 0) {
                    $lookup = db()->prepare('SELECT name FROM services WHERE id = :id');
                    $lookup->execute(['id' => $service]);
                    $serviceName = (string)$lookup->fetchColumn();
                }

                log_activity('booking_request', 'waitlist', "Public booking request from {$name}");

                $adminIds = [];
                try {
                    $stmt = db()->query('SELECT id FROM users WHERE role = "admin"');
                    foreach ($stmt->fetchAll() as $r) {
                        $adminIds[] = (int)$r['id'];
                    }
                } catch (Throwable $e) {
                }

                notify(
                    'New booking request',
                    "{$name} ({$phone}) requested {$serviceName} on " . date('M j, Y', strtotime($date)) . ' at ' . fmt_time($time),
                    'info',
                    $adminIds ?: null
                );

                $success = 'Your booking request has been received. Our team will contact you on ' . e($phone) . ' to confirm your appointment. Thank you!';
            } catch (Throwable $e) {
                $error = 'Something went wrong. Please try again.';
            }
        }
    }
}

$page_title = 'Book an Appointment';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book an Appointment · <?php echo e(salon_name()); ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>💇‍♀️</text></svg>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?php echo base_url('assets/css/style.css'); ?>" rel="stylesheet">
</head>
<body>

<nav class="site-nav sticky-top">
    <div class="container">
        <div class="d-flex align-items-center justify-content-between">
            <a href="index.php" class="d-flex align-items-center gap-2 fw-bold text-body">
                <span class="brand-mark"><i class="bi bi-scissors"></i></span>
                <span><?php echo e(salon_name()); ?></span>
            </a>
            <div class="d-flex align-items-center gap-2">
                <a href="index.php" class="btn btn-outline-secondary rounded-pill px-3"><i class="bi bi-house me-1"></i> Home</a>
                <a href="login.php" class="btn btn-rose rounded-pill px-3"><i class="bi bi-person me-1"></i> Login</a>
            </div>
        </div>
    </div>
</nav>

<section class="section">
    <div class="container">
        <div class="text-center mb-5">
            <span class="section-eyebrow">Book Appointment</span>
            <h1 class="section-title mt-2">Reserve Your Beauty Moment</h1>
            <p class="section-sub mx-auto" style="max-width:560px;">
                Choose a service, pick a preferred date and time, and we will confirm your booking with a quick call.
                No account needed.
            </p>
        </div>

        <div class="row g-5">
            <div class="col-lg-5">
                <h4 class="fw-bold mb-3">Our Services</h4>
                <div class="mb-3">
                    <label class="form-label small mb-1" for="publicCatFilter">Filter by category</label>
                    <select class="form-select form-select-sm" id="publicCatFilter">
                        <option value="">All categories</option>
                        <?php foreach ($serviceTree as $node): ?>
                            <option value="<?php echo (int)$node['category']['id']; ?>"><?php echo e($node['category']['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="d-flex flex-column gap-3" id="publicServiceList">
                    <?php foreach ($serviceTree as $node): ?>
                        <?php $catName = (string)$node['category']['name']; ?>
                        <div class="public-category" data-category="<?php echo (int)$node['category']['id']; ?>">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <i class="bi bi-folder2-open text-rose"></i>
                                <span class="fw-bold"><?php echo e($catName); ?></span>
                            </div>

                            <?php foreach ($node['subcategories'] as $subNode): ?>
                                <div class="ms-3 mb-2">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <i class="bi bi-diagram-3 text-muted small"></i>
                                        <span class="small fw-semibold text-muted"><?php echo e($subNode['subcategory']['name']); ?></span>
                                    </div>
                                    <div class="d-flex flex-column gap-2">
                                        <?php foreach ($subNode['services'] as $svc): ?>
                                            <?php echo public_service_option($svc); ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <?php if (!empty($node['direct'])): ?>
                                <div class="ms-3 mb-2">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <i class="bi bi-asterisk text-muted small"></i>
                                        <span class="small fw-semibold text-muted"><?php echo SERVICE_NO_SUBCATEGORY; ?></span>
                                    </div>
                                    <div class="d-flex flex-column gap-2">
                                        <?php foreach ($node['direct'] as $svc): ?>
                                            <?php echo public_service_option($svc); ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$serviceTree): ?>
                        <div class="empty-state bg-white border rounded-4">
                            <div class="icon"><i class="bi bi-calendar2-x"></i></div>
                            <p class="text-muted mb-0">Services are not available right now. Please call us directly.</p>
                        </div>
                    <?php endif; ?>
                </div>
                <p class="small text-muted mt-3 mb-0">Can't see what you need? Tick "Not sure yet" below and tell us in the notes.</p>
            </div>

            <div class="col-lg-7">
                <div class="card p-4">
                    <h4 class="fw-bold mb-3">Request an Appointment</h4>

                    <?php if ($success): ?>
                        <div class="alert alert-success rounded-4 shadow-sm">
                            <i class="bi bi-check-circle-fill me-2"></i><?php echo e($success); ?>
                        </div>
                        <a href="index.php" class="btn btn-rose rounded-pill px-4"><i class="bi bi-house me-1"></i>Back to Home</a>
                    <?php else: ?>

                    <?php if ($error): ?>
                        <div class="alert alert-danger rounded-3 py-2"><i class="bi bi-exclamation-circle me-2"></i><?php echo e($error); ?></div>
                    <?php endif; ?>

                    <form method="post" action="book.php" id="bookForm">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="service_id" id="serviceIdInput" value="">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="name">Full Name <span class="required">*</span></label>
                                <input type="text" class="form-control" id="name" name="name" required
                                       value="<?php echo e($_POST['name'] ?? ''); ?>" placeholder="e.g. Nardos Fikru">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="phone">Phone Number <span class="required">*</span></label>
                                <input type="tel" class="form-control" id="phone" name="phone" required
                                       value="<?php echo e($_POST['phone'] ?? ''); ?>" placeholder="e.g. 0911 234 567">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="date">Preferred Date</label>
                                <input type="date" class="form-control" id="date" name="date" required
                                       min="<?php echo date('Y-m-d'); ?>" max="<?php echo $maxDate; ?>"
                                       value="<?php echo e($_POST['date'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="time">Preferred Time</label>
                                <select class="form-select" id="time" name="time" required disabled>
                                    <option value="">Select a date first</option>
                                </select>
                                <div class="small text-muted mt-1" id="availabilityHint"></div>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="notes">Notes (optional)</label>
                                <textarea class="form-control" id="notes" name="notes" rows="3"
                                          placeholder="Any special requests or preferences"><?php echo e($_POST['notes'] ?? ''); ?></textarea>
                            </div>
                        </div>

                        <div class="form-check mt-3">
                            <input class="form-check-input" type="checkbox" id="notSureService">
                            <label class="form-check-label small" for="notSureService">
                                Not sure which service — our team will advise you.
                            </label>
                        </div>

                        <div class="mt-4">
                            <button type="submit" class="btn btn-rose btn-lg w-100 rounded-pill py-2">
                                <i class="bi bi-send me-2"></i>Submit Booking Request
                            </button>
                            <p class="small text-muted text-center mt-3 mb-0">
                                 A confirmation will be sent to you by phone. You can also call us at
                                <strong><?php echo e(get_setting('salon_phone', '+251 911 000 111')); ?></strong>.
                            </p>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<footer class="site-footer pt-4 pb-3">
    <div class="container d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span class="small">&copy; <?php echo date('Y'); ?> <?php echo e(salon_name()); ?></span>
        <a href="login.php" class="small text-decoration-none"><i class="bi bi-person-lock me-1"></i>Staff Login</a>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo base_url('assets/js/combobox.js'); ?>"></script>
<script>
    (function () {
        'use strict';

        var radios = document.querySelectorAll('.service-radio');
        var serviceInput = document.getElementById('serviceIdInput');

        radios.forEach(function (r) {
            r.addEventListener('change', function () {
                serviceInput.value = this.value;
                document.querySelectorAll('.service-option').forEach(function (opt) {
                    opt.classList.remove('border-danger');
                });
                this.closest('.service-option').classList.add('border-danger');
            });
        });

        /* Category quick-filter over the grouped menu. */
        var catFilter = document.getElementById('publicCatFilter');
        if (catFilter) {
            catFilter.addEventListener('change', function () {
                var cat = this.value;
                document.querySelectorAll('.public-category').forEach(function (block) {
                    block.style.display = (!cat || block.dataset.category === cat) ? '' : 'none';
                });
            });
        }

        /* Let visitors submit without knowing which service they want. */
        var notSure = document.getElementById('notSureService');
        if (notSure) {
            notSure.addEventListener('change', function () {
                if (!this.checked) return;
                serviceInput.value = '';
                document.querySelectorAll('.service-option').forEach(function (opt) {
                    opt.classList.remove('border-danger');
                });
            });
        }

        var dateInput = document.getElementById('date');
        var timeSelect = document.getElementById('time');
        var hint = document.getElementById('availabilityHint');

        dateInput.addEventListener('change', function () {
            var date = this.value;
            timeSelect.innerHTML = '<option value="">Loading…</option>';
            timeSelect.disabled = true;
            hint.textContent = '';

            if (!date) return;

            fetch('public-availability.php?date=' + encodeURIComponent(date), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    timeSelect.innerHTML = '';
                    if (!data.ok) {
                        hint.textContent = 'Something went wrong.';
                        return;
                    }
                    if (data.holiday) {
                        hint.innerHTML = '<span class="text-danger">The salon is closed on this date.</span>';
                        timeSelect.disabled = true;
                        return;
                    }
                    if (!data.slots.length) {
                        hint.innerHTML = '<span class="text-danger">' +
                            (data.reason || 'No available slots on this date.') + '</span>';
                        timeSelect.disabled = true;
                        return;
                    }
                    timeSelect.disabled = false;
                    var placeholder = document.createElement('option');
                    placeholder.value = '';
                    placeholder.textContent = 'Select a time';
                    timeSelect.appendChild(placeholder);
                    data.slots.forEach(function (slot) {
                        var opt = document.createElement('option');
                        opt.value = slot;
                        var parts = slot.split(':');
                        var hours = parseInt(parts[0], 10);
                        var mins = parts[1];
                        var ampm = hours >= 12 ? 'PM' : 'AM';
                        var h12 = hours % 12 || 12;
                        opt.textContent = h12 + ':' + mins + ' ' + ampm;
                        timeSelect.appendChild(opt);
                    });
                    hint.innerHTML = '<span class="text-success">' + data.slots.length + ' available slot(s).</span>';
                })
                .catch(function () {
                    hint.textContent = 'Something went wrong.';
                });
        });
    })();
</script>
</body>
</html>

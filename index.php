<?php
/**
 * Public homepage.
 * Presents the business overview and plan for the configured salon,
 * makers of an end-to-end Beauty Salon Management System.
 */

require_once __DIR__ . '/includes/init.php';

$contactPhone    = get_setting('salon_phone', '+251 911 000 111');
$contactEmail    = get_setting('salon_email', 'hello@example.com');
$contactAddress  = salon_address();
$businessHours   = get_setting('business_hours_display', 'Mon – Sat: 9:00 AM – 6:00 PM · Sun: Closed');
$fbUrl           = get_setting('facebook_url', '#');
$igUrl           = get_setting('instagram_url', '#');
$ttUrl           = get_setting('tiktok_url', '#');

$page_title = 'Welcome';

$productModules = [
    ['icon' => 'bi-calendar2-check', 'name' => 'Appointments & Calendar', 'description' => 'Smart scheduling, walk-ins, and real-time availability across staff and branches.'],
    ['icon' => 'bi-people',          'name' => 'Customer Profiles',      'description' => 'Client history, preferences, allergies, notes, and loyalty points in one profile.'],
    ['icon' => 'bi-receipt',         'name' => 'Billing & Invoicing',    'description' => 'Fast checkout with tax, discounts, partial payments, and printable invoices.'],
    ['icon' => 'bi-box-seam',        'name' => 'Inventory & Suppliers',  'description' => 'Track retail and salon products with low-stock alerts and supplier records.'],
    ['icon' => 'bi-cash-coin',       'name' => 'Payroll & Staff',        'description' => 'Commission tracking, payroll runs, and role-based access for your team.'],
    ['icon' => 'bi-graph-up-arrow',  'name' => 'Reports & Analytics',    'description' => 'Revenue, services, and performance insights to guide daily decisions.'],
];

function home_module_icon(array $module): string
{
    return $module['icon'];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(salon_name()); ?> · <?php echo e(get_setting('salon_tagline', 'Smart Software for Beautiful Businesses.')); ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>💻</text></svg>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?php echo base_url('assets/css/style.css'); ?>" rel="stylesheet">
</head>
<body>

<!-- ================= NAVBAR ================= -->
<nav class="site-nav sticky-top">
    <div class="container">
        <div class="d-flex align-items-center justify-content-between">
            <a href="#top" class="d-flex align-items-center gap-2 fw-bold text-body">
                <span class="brand-mark"><i class="bi bi-code-slash"></i></span>
                <span><?php echo e(salon_name()); ?></span>
            </a>

            <div class="d-none d-lg-flex align-items-center gap-1" id="siteNav">
                <a class="nav-link" href="#about">Overview</a>
                <a class="nav-link" href="#product">Product</a>
                <a class="nav-link" href="#why">Why Us</a>
                <a class="nav-link" href="#how">How It Works</a>
                <a class="nav-link" href="#plan">Business Plan</a>
                <a class="nav-link" href="#contact">Contact</a>
            </div>

            <div class="d-flex align-items-center gap-2">
                <?php if (is_logged_in()): ?>
                    <span class="fw-semibold small d-none d-lg-inline"><i class="bi bi-person-circle me-1"></i><?php echo e(current_user()['name']); ?></span>
                    <a href="admin/dashboard.php" class="btn btn-rose rounded-pill px-3 px-lg-4"><i class="bi bi-speedometer2 me-1"></i> Dashboard</a>
                    <a href="logout.php" class="btn btn-outline-secondary rounded-pill px-3"><i class="bi bi-box-arrow-right me-1"></i> Logout</a>
                <?php else: ?>
<a href="login.php" class="btn btn-outline-secondary rounded-pill px-3"><i class="bi bi-person me-1"></i> Login</a>
<a href="signup.php" class="btn btn-rose rounded-pill px-3 px-lg-4"><i class="bi bi-person-plus me-1"></i> Sign Up</a>
                <?php endif; ?>
                <button class="btn btn-link text-body d-lg-none p-1" type="button" data-bs-toggle="collapse" data-bs-target="#mobileNav" aria-expanded="false">
                    <i class="bi bi-list fs-3"></i>
                </button>
            </div>
        </div>

        <div class="collapse d-lg-none py-2" id="mobileNav">
            <div class="d-flex flex-column gap-1">
                <a class="nav-link" href="#about">Overview</a>
                <a class="nav-link" href="#product">Product</a>
                <a class="nav-link" href="#why">Why Us</a>
                <a class="nav-link" href="#how">How It Works</a>
                <a class="nav-link" href="#plan">Business Plan</a>
                <a class="nav-link" href="#contact">Contact</a>
            </div>
        </div>
    </div>
</nav>

<!-- ================= HERO ================= -->
<header class="hero-section" id="top">
    <div class="container">
        <?php
        $flashes = flash_get();
        if ($flashes): ?>
            <div class="row"><div class="col-lg-8 mx-auto mb-4">
                <?php foreach ($flashes as $flash): ?>
                    <div class="alert alert-<?php echo e($flash['type']); ?> alert-dismissible fade show rounded-3 shadow-sm py-2">
                        <i class="bi bi-<?php echo $flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i><?php echo e($flash['message']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endforeach; ?>
            </div></div>
        <?php endif; ?>
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="hero-eyebrow"><i class="bi bi-code-slash"></i> <?php echo e(salon_name()); ?></span>
                <h1 class="hero-title mt-4 mb-3">
                    Salon Management, <span class="accent">End to End.</span>
                </h1>
                <p class="hero-text mb-4">
                    <?php echo e(salon_name()); ?> builds an end-to-end Beauty Salon Management System —
                    appointments, clients, billing, inventory, payroll, and analytics in one
                    platform that runs the entire salon business.
                </p>
                <div class="d-flex gap-4 mt-4 pt-2 small text-muted">
                    <span><i class="bi bi-check-circle-fill text-success me-1"></i> All-in-One Platform</span>
                    <span><i class="bi bi-check-circle-fill text-success me-1"></i> Built for Salons</span>
                    <span><i class="bi bi-check-circle-fill text-success me-1"></i> Local Support</span>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="book-stage">
                    <div class="book">
                        <div class="book-cover">
                            <div class="cover-brand"><?php echo e(salon_name()); ?></div>
                            <div class="cover-title mt-2">Appointment Book</div>
                            <div class="cover-sub">2026 · Beauty &amp; Wellness</div>
                        </div>
                        <div class="book-pages">
                            <div class="book-page p1">
                                <div class="page-head"><span>Today</span><span>Schedule</span></div>
                                <div class="book-row"><span class="t">09:00</span><span class="v">Hair Styling</span><i class="bi bi-check-circle-fill chk"></i></div>
                                <div class="book-row"><span class="t">11:30</span><span class="v">Facial</span><i class="bi bi-check-circle-fill chk"></i></div>
                                <div class="book-row"><span class="t">14:00</span><span class="v">Manicure</span><span class="v" style="font-size:.72rem">pending</span></div>
                                <div class="book-row"><span class="t">16:30</span><span class="v">Bridal Makeup</span><i class="bi bi-check-circle-fill chk"></i></div>
                                <div class="book-dots"><span class="on"></span><span></span><span></span></div>
                            </div>
                            <div class="book-page p2">
                                <div class="page-head"><span>Book Now</span><span>Pick a date</span></div>
                                <div class="book-date">
                                    <div class="day">28</div>
                                    <div class="month">August</div>
                                </div>
                                <div class="book-row"><span class="t">10:00</span><span class="v">Hair Coloring</span><i class="bi bi-check-circle-fill chk"></i></div>
                                <div class="book-row"><span class="t">13:00</span><span class="v">Massage</span><span class="v" style="font-size:.72rem">free</span></div>
                                <div class="book-dots"><span></span><span class="on"></span><span></span></div>
                            </div>
                            <div class="book-page p3">
                                <div class="page-head"><span>Confirmed</span><span>Done</span></div>
                                <div class="text-center py-3">
                                    <i class="bi bi-check-circle-fill text-success" style="font-size:2.6rem"></i>
                                    <div class="fw-bold mt-2">Appointment Booked!</div>
                                    <div class="small text-muted">See you at the salon</div>
                                </div>
                                <div class="book-row"><span class="t">Service</span><span class="v">Facial + Hair</span></div>
                                <div class="book-row"><span class="t">Time</span><span class="v">10:30 AM</span></div>
                                <div class="book-dots"><span></span><span></span><span class="on"></span></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>

<!-- ================= BUSINESS OVERVIEW ================= -->
<section class="section" id="about">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="section-eyebrow">Business Overview</span>
                <h2 class="section-title mt-2 mb-3">Software That Runs the Whole Salon</h2>
                <p class="section-sub mb-4">
                    <?php echo e(salon_name()); ?> is a technology company focused on one product:
                    an end-to-end Beauty Salon Management System. We replace paper appointment
                    books, spreadsheets, and guesswork with a single platform that manages the
                    complete daily operation of a salon — from the first booking to the final report.
                </p>
                <div class="row g-3">
                    <div class="col-sm-6">
                        <div class="d-flex align-items-start gap-3">
                            <span class="service-icon mb-0" style="width:46px;height:46px;"><i class="bi bi-bullseye"></i></span>
                            <div>
                                <h6 class="mb-1 fw-bold">Mission</h6>
                                <p class="small text-muted mb-0">Make professional-grade management software accessible to every salon.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="d-flex align-items-start gap-3">
                            <span class="service-icon mb-0" style="width:46px;height:46px;"><i class="bi bi-eye"></i></span>
                            <div>
                                <h6 class="mb-1 fw-bold">Vision</h6>
                                <p class="small text-muted mb-0">Become the standard operating system for beauty businesses in the region.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="d-flex align-items-start gap-3">
                            <span class="service-icon mb-0" style="width:46px;height:46px;"><i class="bi bi-puzzle"></i></span>
                            <div>
                                <h6 class="mb-1 fw-bold">One Product, Done Right</h6>
                                <p class="small text-muted mb-0">A single focused system instead of many disconnected tools.</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="d-flex align-items-start gap-3">
                            <span class="service-icon mb-0" style="width:46px;height:46px;"><i class="bi bi-headset"></i></span>
                            <div>
                                <h6 class="mb-1 fw-bold">Close Support</h6>
                                <p class="small text-muted mb-0">Local training, setup help, and responsive customer care.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="position-relative">
                    <div class="bg-blush rounded-4 p-4 d-flex flex-column gap-3">
                        <div class="d-flex align-items-center justify-content-between bg-white rounded-4 p-3 shadow-sm">
                            <div class="d-flex align-items-center gap-3">
                                <span class="service-icon mb-0"><i class="bi bi-calendar2-week"></i></span>
                                <div>
                                    <h6 class="mb-1 fw-bold">Front Desk</h6>
                                    <p class="small text-muted mb-0">Bookings, walk-ins &amp; calendars.</p>
                                </div>
                            </div>
                            <i class="bi bi-arrow-right text-rose"></i>
                        </div>
                        <div class="d-flex align-items-center justify-content-between bg-white rounded-4 p-3 shadow-sm">
                            <div class="d-flex align-items-center gap-3">
                                <span class="service-icon mb-0"><i class="bi bi-receipt-cutoff"></i></span>
                                <div>
                                    <h6 class="mb-1 fw-bold">Money Flow</h6>
                                    <p class="small text-muted mb-0">Invoices, payments &amp; payroll.</p>
                                </div>
                            </div>
                            <i class="bi bi-arrow-right text-rose"></i>
                        </div>
                        <div class="d-flex align-items-center justify-content-between bg-white rounded-4 p-3 shadow-sm">
                            <div class="d-flex align-items-center gap-3">
                                <span class="service-icon mb-0"><i class="bi bi-graph-up-arrow"></i></span>
                                <div>
                                    <h6 class="mb-1 fw-bold">Business Insight</h6>
                                    <p class="small text-muted mb-0">Reports, audit logs &amp; analytics.</p>
                                </div>
                            </div>
                            <i class="bi bi-arrow-right text-rose"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ================= PRODUCT ================= -->
<section class="section section-alt" id="product">
    <div class="container">
        <div class="text-center mb-5">
            <span class="section-eyebrow">The Product</span>
            <h2 class="section-title mt-2">One System, Every Corner of the Salon</h2>
            <p class="section-sub mx-auto" style="max-width:620px;">Our end-to-end Beauty Salon Management System covers the full business cycle — these are the modules working together out of the box.</p>
        </div>
        <div class="row g-4">
            <?php foreach ($productModules as $mod): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="service-card">
                        <span class="service-icon"><i class="bi <?php echo e(home_module_icon($mod)); ?>"></i></span>
                        <h5 class="fw-bold mb-1"><?php echo e($mod['name']); ?></h5>
                        <p class="small text-muted mb-3"><?php echo e($mod['description']); ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="text-center mt-5">
            <a href="#plan" class="btn btn-rose btn-lg rounded-pill px-5">See the Business Plan</a>
        </div>
    </div>
</section>

<!-- ================= WHY CHOOSE US ================= -->
<section class="section" id="why">
    <div class="container">
        <div class="text-center mb-5">
            <span class="section-eyebrow">Why Choose Us</span>
            <h2 class="section-title mt-2">The <?php echo e(salon_name()); ?> Difference</h2>
        </div>
        <div class="row g-4">
            <div class="col-md-4">
                <div class="step-card">
                    <span class="step-num" style="background:linear-gradient(135deg,var(--bsai-rose),var(--bsai-rose-dark));box-shadow:0 10px 22px rgba(183,110,121,.35);"><i class="bi bi-person-check"></i></span>
                    <h5 class="fw-bold mb-2">Professional Service</h5>
                    <p class="small text-muted mb-0">Skilled beauticians dedicated to outstanding results and your comfort.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="step-card">
                    <span class="step-num"><i class="bi bi-calendar2-check"></i></span>
                    <h5 class="fw-bold mb-2">Easy Scheduling</h5>
                    <p class="small text-muted mb-0">Book your preferred date and time quickly — no accounts needed.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="step-card">
                    <span class="step-num" style="background:linear-gradient(135deg,#6f9f7d,#5a8a68);box-shadow:0 10px 22px rgba(111,159,125,.35);"><i class="bi bi-stars"></i></span>
                    <h5 class="fw-bold mb-2">Personalized Experience</h5>
                    <p class="small text-muted mb-0">We remember your preferences so every visit feels just right.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ================= HOW IT WORKS ================= -->
<section class="section section-alt" id="how">
    <div class="container">
        <div class="text-center mb-5">
            <span class="section-eyebrow">How It Works</span>
            <h2 class="section-title mt-2">Booking Made Simple</h2>
        </div>
        <div class="row g-4">
            <div class="col-md-6 col-lg-3">
                <div class="step-card">
                    <span class="step-num">1</span>
                    <h6 class="fw-bold mb-2">Choose a Service</h6>
                    <p class="small text-muted mb-0">Browse our menu and pick the service you need.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="step-card">
                    <span class="step-num">2</span>
                    <h6 class="fw-bold mb-2">Select Date &amp; Time</h6>
                    <p class="small text-muted mb-0">Request a convenient slot from our schedule.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="step-card">
                    <span class="step-num">3</span>
                    <h6 class="fw-bold mb-2">Confirm Appointment</h6>
                    <p class="small text-muted mb-0">We confirm your booking and add it to our calendar.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="step-card">
                    <span class="step-num">4</span>
                    <h6 class="fw-bold mb-2">Visit the Salon</h6>
                    <p class="small text-muted mb-0">Arrive, relax, and enjoy your beauty experience.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ================= TESTIMONIALS ================= -->
<section class="section" id="testimonials">
    <div class="container">
        <div class="text-center mb-5">
            <span class="section-eyebrow">Testimonials</span>
            <h2 class="section-title mt-2">What Our Clients Say</h2>
        </div>
        <div class="row g-4">
            <div class="col-md-4">
                <div class="testimonial-card">
                    <div class="stars mb-2"><i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i></div>
                    <p class="mb-3">"The bridal makeup was absolutely stunning. I felt beautiful all day long. Highly recommend <?php echo e(salon_name()); ?>!"</p>
                    <div class="d-flex align-items-center gap-2">
                        <span class="avatar">HS</span>
                        <div><div class="fw-bold small">Hanna Selam</div><div class="small text-muted">Bridal Makeup</div></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="testimonial-card">
                    <div class="stars mb-2"><i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i></div>
                    <p class="mb-3">"Booked my appointment in seconds and the facial was the most relaxing hour of my month."</p>
                    <div class="d-flex align-items-center gap-2">
                        <span class="avatar">LT</span>
                        <div><div class="fw-bold small">Liya Tesfaye</div><div class="small text-muted">Signature Facial</div></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="testimonial-card">
                    <div class="stars mb-2"><i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i></div>
                    <p class="mb-3">"Great hair coloring and such a warm team. They remembered my preferences on the second visit."</p>
                    <div class="d-flex align-items-center gap-2">
                        <span class="avatar">MA</span>
                        <div><div class="fw-bold small">Marta Ayele</div><div class="small text-muted">Hair Coloring</div></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ================= CONTACT ================= -->
<section class="section section-alt" id="contact">
    <div class="container">
        <div class="text-center mb-5">
            <span class="section-eyebrow">Contact</span>
            <h2 class="section-title mt-2">Get In Touch</h2>
            <p class="section-sub mx-auto" style="max-width:560px;">Have a question or special request? Reach out — we would love to hear from you.</p>
        </div>
        <div class="row g-4">
            <div class="col-md-6 col-lg-3">
                <div class="contact-box">
                    <span class="contact-icon"><i class="bi bi-telephone"></i></span>
                    <div>
                        <h6 class="fw-bold mb-1 small text-uppercase">Phone</h6>
                        <p class="small text-muted mb-0"><?php echo e($contactPhone); ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="contact-box">
                    <span class="contact-icon"><i class="bi bi-envelope"></i></span>
                    <div>
                        <h6 class="fw-bold mb-1 small text-uppercase">Email</h6>
                        <p class="small text-muted mb-0"><?php echo e($contactEmail); ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="contact-box">
                    <span class="contact-icon"><i class="bi bi-geo-alt"></i></span>
                    <div>
                        <h6 class="fw-bold mb-1 small text-uppercase">Address</h6>
                        <p class="small text-muted mb-0"><?php echo e($contactAddress); ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="contact-box">
                    <span class="contact-icon"><i class="bi bi-clock"></i></span>
                    <div>
                        <h6 class="fw-bold mb-1 small text-uppercase">Opening Hours</h6>
                        <p class="small text-muted mb-0"><?php echo e($businessHours); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <div class="text-center mt-4">
            <a href="<?php echo e($fbUrl); ?>" target="_blank" rel="noopener" class="btn btn-soft rounded-circle mx-1"><i class="bi bi-facebook"></i></a>
            <a href="<?php echo e($igUrl); ?>" target="_blank" rel="noopener" class="btn btn-soft rounded-circle mx-1"><i class="bi bi-instagram"></i></a>
            <a href="<?php echo e($ttUrl); ?>" target="_blank" rel="noopener" class="btn btn-soft rounded-circle mx-1"><i class="bi bi-tiktok"></i></a>
        </div>
    </div>
</section>

<!-- ================= CTA ================= -->
<section class="section">
    <div class="container">
        <div class="cta-section text-center">
            <h2 class="fw-bold mb-3" style="font-size:clamp(1.6rem,4vw,2.4rem);">Ready for your next beauty experience?</h2>
            <p class="mb-4" style="color:#d8cfcc;">Let us take care of the rest </p>
            <!-- <a href="book.php" class="btn btn-gold btn-lg rounded-pill px-5"><i class="bi bi-calendar2-plus me-2"></i>Book Your Appointment</a> -->
        </div>
    </div>
</section>

<!-- ================= FOOTER ================= -->
<footer class="site-footer pt-5 pb-4">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-4">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <span class="brand-mark"><i class="bi bi-scissors"></i></span>
                    <span class="fw-bold text-white"><?php echo e(salon_name()); ?></span>
                </div>
                <p class="small mb-3">Your beauty, our passion. Premium beauty services delivered with care and professionalism.</p>
                <div class="d-flex gap-2">
                    <a href="<?php echo e($fbUrl); ?>" class="btn btn-soft btn-sm rounded-circle"><i class="bi bi-facebook"></i></a>
                    <a href="<?php echo e($igUrl); ?>" class="btn btn-soft btn-sm rounded-circle"><i class="bi bi-instagram"></i></a>
                    <a href="<?php echo e($ttUrl); ?>" class="btn btn-soft btn-sm rounded-circle"><i class="bi bi-tiktok"></i></a>
                </div>
            </div>
            <div class="col-6 col-lg-2">
                <div class="footer-head">Quick Links</div>
                <ul class="footer-list">
                    <li><a href="#about">About</a></li>
                    <li><a href="#services">Services</a></li>
                    <li><a href="#how">How It Works</a></li>
                    <li><a href="#contact">Contact</a></li>
                    <li><a href="login.php">Staff Login</a></li>
                </ul>
            </div>
            <div class="col-6 col-lg-3">
                <div class="footer-head">Services</div>
                <ul class="footer-list">
                    <li>Hair Styling &amp; Coloring</li>
                    <li>Facials &amp; Skin Care</li>
                    <li>Manicure &amp; Pedicure</li>
                    <li>Makeup &amp; Bridal</li>
                    <li>Massage &amp; Spa</li>
                </ul>
            </div>
            <div class="col-lg-3">
                <div class="footer-head">Contact</div>
                <ul class="footer-list">
                    <li><i class="bi bi-telephone me-2"></i><?php echo e($contactPhone); ?></li>
                    <li><i class="bi bi-envelope me-2"></i><?php echo e($contactEmail); ?></li>
                    <li><i class="bi bi-geo-alt me-2"></i><?php echo e($contactAddress); ?></li>
                    <li><i class="bi bi-clock me-2"></i><?php echo e($businessHours); ?></li>
                </ul>
            </div>
        </div>
        <hr class="border-secondary my-4" style="opacity:.25;">
        <div class="text-center small" style="color:#b5aaa6;">
            &copy; <?php echo date('Y'); ?> <?php echo e(salon_name()); ?> · Beauty-php-ai · All rights reserved.
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo base_url('assets/js/app.js'); ?>"></script>
</body>
</html>

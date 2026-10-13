# Beauty-php-ai — Goscha Software

A complete beauty salon booking and appointment management system built with **PHP 8+, MySQL 8+/MariaDB 10.4+, Bootstrap 5, vanilla JavaScript and PDO**. Designed to run on XAMPP, WAMP or Laragon — no Composer, no npm, no Laravel, no React/Vue.

---

## Demo accounts

| Role           | Email                          | Password      |
|----------------|--------------------------------|---------------|
| Administrator  | `admin@beautyphpai.com`        | `Admin@123`   |
| Super Admin account  | `superadmin@gmail.com` | `Superadmin123`|

---


URL: http://localhost/Beauty%20salon/super-admin/login.php
Email: superadmin@gmail.com
Password: Superadmin@123


## Quick setup (XAMPP)

1. **Start Apache and MySQL** from the XAMPP Control Panel.
2. **Place the project** in `C:\xampp\htdocs\` (or any Apache htdocs directory).
3. **Import the database**: open [phpMyAdmin](http://localhost/phpmyadmin), select the `beauty_php_ai` database and import `database/beauty_php_ai.sql`.  
   *Or via command line:*
   ```
   C:\xampp\mysql\bin\mysql.exe -u root < database\beauty_php_ai.sql
   ```
4. **Open the app** in your browser:  
   `http://localhost/Beauty-php-ai/`

No additional configuration is required. The database config lives in `config/database.php` (defaults: root user, no password).

### Enabling the Super Admin control panel (existing installs)

The base schema covers the salon application. The platform control panel needs one migration and a first operator:

```
C:\xampp\mysql\bin\mysql.exe -u root beauty_php_ai < database\migrations\004_super_admin.sql

set BEAUTY_DB_ALLOW_WRITE=1
C:\xampp\php\php.exe scripts\provision_super_admin.php --email=you@example.com --name="Your Name"
```

`provision_super_admin.php` prints a one-time password to the console (or pass `--password=`). Sign in at `http://localhost/Beauty-php-ai/super-admin/login.php`.

---

## Features

### Public
- Professional animated landing page (appointment book animation)
- Public appointment booking request (waitlist) — no customer account required
- Public availability check by date and time
- Bubble-animated login page

### Administrator (full access)
- **Dashboard** — revenue trend, appointment volume, popular services, payment breakdown
- **Customers** — search, add/edit/view profiles, loyalty points, appointment history
- **Appointments** — multi-service booking, server-side conflict prevention, status workflow, waitlist
- **Calendar** — day / week / month views
- **Walk-ins** — in-person booking flow with on-the-spot payment
- **Services** — manage services + categories
- **Payments** — record, refund, bank transfer tracking
- **Invoices** — auto-generated per appointment, print-ready, mark paid/void
- **Inventory** — products, stock levels, low-stock alerts
- **Suppliers** — supplier management
- **Payroll** — salary/commission/tips/deductions per pay period
- **Staff** — manage salon employees/professionals (personal info, emergency contacts, salary, status). Staff are NOT system users — no login accounts are created for them
  - **Privacy**: salary, emergency contacts, notes and address are visible to the Administrator only; receptionists get a server-side 403 on every Staff URL
  - **Assignments**: the Administrator links staff to appointments (`appointments.staff_id`); deleting a staff member keeps history and simply unassigns them
  - **Availability roadmap (planned extension)** — the current design was prepared so these can be added later without refactoring:
    - *Working hours* → future `staff_working_hours` table (staff_id, weekday, start_time, end_time) feeding `available_slots()`
    - *Availability* → computed from working hours minus booked appointments (already queryable via `appointments.staff_id`)
    - *Leave* → future `staff_leave` table (staff_id, leave_type, start_date, end_date)
    - *Assigned appointments* → **already implemented** via the optional `appointments.staff_id` foreign key
- **Reports** — sales, appointments, customers, services, payments, inventory and payroll analytics with date range filtering
- **Users** — create/manage receptionist accounts
- **Audit Logs** — full activity trail
- **Settings** — salon info, business hours, holidays, currency (ETB), tax rate, loyalty rules, notifications, backup/restore

### Receptionist (limited)
- Dashboard, Customers, Appointments, Calendar, Walk-ins
- Services (view-only, used during booking)
- Payments, Invoices, Notifications

### Super Admin (platform operator)
A separate control panel for the software owner/vendor — **not** a salon user. It lives at `super-admin/`, uses its own `super_admins` table and session keys, and never shares a login with a salon.
- **Independent login** at `super-admin/login.php` — rate limited (5 failed attempts / 15 min per email+IP) with idle timeout
- **Dashboard** — salon counts by lifecycle status, pending registrations, recent activity
- **Salon accounts** — search/filter every salon, open a full account view
- **Registration approval workflow** — new signups arrive as `pending`; the operator can **approve, reject, suspend, restore, deactivate or cancel** a salon, with a reason. Only `active` salons can sign in, so these changes take effect immediately
- **Granular module permissions** — switch individual modules on/off per salon (15 modules), or apply a **Standard / Professional / Full** plan. Enforced server-side on the salon's next request
- **Platform audit log** — every control-panel action (login, lifecycle change, module edit) is recorded
- **My profile** — the operator maintains their own name, email and password

New salons self-register through the public signup form and **cannot sign in until approved**. Approval is done from the control panel.

### Security
- CSRF tokens on all forms
- Password hashing with `password_hash()` (bcrypt)
- Session timeout enforcement
- Remember-me with secure token storage
- Role-based access control enforced server-side on every request
- Per-salon module grants enforced server-side (second gate on top of roles)
- Login rate limiting on the Super Admin panel
- PDO prepared statements (no SQL injection)

---

## Folder structure

```
Beauty-php-ai/
├── index.php                  # Public homepage
├── login.php                  # Login (bubble animation)
├── logout.php
├── forgot-password.php
├── reset-password.php
├── book.php                   # Public appointment booking
├── availability.php           # AJAX availability (auth required)
├── public-availability.php    # AJAX availability (public)
├── config/
│   └── database.php           # PDO connection + DB_DSN constant
├── includes/
│   ├── init.php               # Bootstrap (database, functions, session)
│   ├── functions.php          # Global helpers
│   ├── auth.php               # Login, remember-me, session timeout
│   ├── permissions.php        # Role-based authorization
│   ├── tenant.php             # Multi-tenant scoping
│   ├── salon_registration.php # Public signup -> pending salon + owner
│   ├── access_control.php     # Per-salon module catalogue + grants
│   ├── super_admin.php        # Platform operator core (lifecycle, audit, login)
│   ├── alerts.php             # Flash messages
│   ├── header.php             # App shell header
│   ├── navbar.php             # Top navigation bar
│   ├── sidebar.php            # Sidebar navigation
│   ├── super-admin/           # Control-panel shell (header.php, footer.php)
│   └── footer.php             # App shell footer + confirm modal + scripts
├── assets/
│   ├── css/style.css          # Full design system
│   ├── css/super-admin.css    # Control-panel styling
│   └── js/app.js              # Shared JS (charts, table filters, sidebar)
├── database/
│   ├── beauty_php_ai.sql      # Full schema + demo data (21 tables)
│   ├── migrations/            # Incremental schema changes (e.g. 004_super_admin.sql)
│   └── test_*.php             # Acceptance suites
├── scripts/
│   └── provision_super_admin.php  # CLI: create the first operator
├── modules/
│   ├── dashboard.php
│   ├── customers.php
│   ├── appointments.php
│   ├── calendar.php
│   ├── walkins.php
│   ├── services.php
│   ├── payments.php
│   ├── invoices.php
│   ├── invoice-view.php
│   ├── inventory.php
│   ├── suppliers.php
│   ├── payroll.php
│   ├── staff.php                # Staff Management (admin-only, no logins)
│   ├── reports.php
│   ├── notifications.php
│   ├── users.php
│   ├── audit-logs.php
│   └── settings.php
├── admin/                     # Admin wrappers (require_role('admin'))
│   ├── dashboard.php
│   ├── ... (18 files)
│   └── settings.php
├── receptionist/               # Receptionist wrappers (require_role('receptionist'))
│   ├── dashboard.php
│   ├── ... (10 files)
│   └── notifications.php
├── super-admin/               # Platform control panel (require_super_admin())
│   ├── login.php
│   ├── logout.php
│   ├── dashboard.php
│   ├── accounts.php
│   ├── account-details.php
│   ├── access-control.php
│   ├── audit-logs.php
│   └── profile.php
└── uploads/                    # Logo and file uploads
```

---

## Tech stack

| Layer     | Technology                              |
|-----------|------------------------------------------|
| Backend   | PHP 8.0+ with PDO prepared statements   |
| Database  | MySQL 8 / MariaDB 10.4                   |
| Frontend  | Bootstrap 5.3, Bootstrap Icons, Chart.js |
| Styling   | Custom CSS (rose/charcoal/gold palette)  |
| Charts    | Chart.js 4.4 (via CDN)                   |
| Server    | XAMPP / WAMP / Laragon (Apache + MySQL)  |

---

## Currency

All monetary values are in **ETB (Ethiopian Birr)**. Configurable via Settings → Hours & Rules.

---

## Troubleshooting

- **Blank page / 500 error**: ensure Apache and MySQL are running, and that `config/database.php` credentials match your setup.
- **Styles not loading**: make sure the project folder is in your htdocs and you access it via `http://localhost/Beauty-php-ai/`, not directly from the file system.
- **Database error on import**: drop the `beauty_php_ai` database first, then re-import.

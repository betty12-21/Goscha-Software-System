# Beauty Salon Management System — User Guide

A step-by-step guide for day-to-day use: how to register customers, manage staff and services, book appointments, process payments, and finish with an invoice.

Open the system at: `http://localhost/Beauty%20salon/`

---

## 1. Roles

Two roles exist, and they see different menus.

| Role | What you can do |
|------|-----------------|
| **Admin** | Everything: customers, staff, services, appointments, walk-ins, payments, invoices, calendar, notifications, inventory, suppliers, payroll, reports, users, audit logs, settings. |
| **Receptionist** | Front-desk work only: dashboard, customers, appointments, calendar, walk-ins, services (view only), payments, invoices, notifications. |

Rules to remember:
- Receptionists **cannot** add or edit services — only view them.
- Only admins can manage staff, users, settings, reports, payroll, inventory and suppliers.
- Trying to open a page you don't have permission for shows a **403** page and the attempt is logged.

---

## 2. Logging in

1. Go to `http://localhost/Beauty%20salon/login.php`
2. Enter your email and password.
3. Click **Sign In**.

Demo accounts (for testing):

| Email | Password |
|-------|----------|
| `admin@beautyphpai.com` | `Admin@123` |
| `receptionist@beautyphpai.com` | `Recep@123` |

After login you land on the **Dashboard** (daily overview, today's appointments, quick stats).

- Forgot your password? Use the **Forgot Password** link on the login page.
- Always finish with **Logout** (bottom of the sidebar).

---

## 3. Adding a new customer

1. Open **Customers** from the sidebar.
2. Click **+ New Customer**.
3. Fill in:
   - **First name** (required)
   - **Last name** (required)
   - **Phone** (required)
   - **Email** (optional)
   - Any optional notes/comments and preferences.
4. Click **Save**.

You can later edit a customer, view their history (past appointments, invoices, payments), and see their **loyalty points**.

> Tip: When a customer returns, search by name or phone to find their profile instantly.

---

## 4. Managing staff (Admin only)

Staff are the people who perform services. They are **not** system log-in users.

1. Open **Staff** from the sidebar.
2. Click **+ New Staff**.
3. Fill in name, role/title, phone, email, and any details (e.g. specialties).
4. Click **Save**.

You can also:
- **Edit** staff details.
- **Archive / deactivate** staff who no longer work.
- Assign staff to appointments when booking (optional field).

> Note: System **Users** (menus, accounts) are a different thing — see section 13.

---

## 5. Setting up services (Admin only)

Services are the things you sell (e.g. Hair Wash, Hair Styling, Manicure).

1. Open **Services**.
2. Click **+ New Service**.
3. Fill in:
   - **Name** (required)
   - **Category / Subcategory** (optional grouping)
   - **Description**
   - **Duration** in minutes
   - **Price**
   - **Status** (active = bookable)
4. Click **Save**.

Keep only active services bookable. Inactive services won't appear in the booking screens.

---

## 6. Booking an appointment

Two ways: a normal booking, or a **walk-in** with immediate payment (section 8).

### A. Pre-booked appointment

1. Open **Appointments**.
2. Click **+ New Appointment**.
3. **Step 1 — Customer**: search for an existing customer or create a new one on the spot.
4. **Step 2 — Services & time**:
   - Pick one or more **services** (the total and tax update automatically).
   - Choose a **date** and an available **time**. Only free slots within business hours are offered.
   - Optionally assign a **staff member**.
5. Add a discount if needed and any **notes**.
6. Click **Save Appointment**.

The system auto-calculates: subtotal → discount → tax → total.

### Managing appointments
- **Edit / Reschedule**: change the date, time, services or status.
- **Statuses** include: pending, confirmed, completed, cancelled, no-show.
- Mark an appointment **Completed** once the service is done.
- When the client pays later, head to **Payments** (section 7).
- Conflict handling: the timeslot is checked for availability — if it's taken or outside business hours, the system tells you.

---

## 7. Processing a payment (for a booked appointment)

1. Open **Payments** from the sidebar.
2. Click **+ New Payment**.
3. Select the **appointment** (or customer).
4. Enter the **amount** (should match the appointment total).
5. Choose the **method**:
   - **Cash**
   - **Bank Transfer** — enter the **transaction reference** (e.g. TRF-1234).
6. Click **Save / Record Payment**.

When payment is recorded the appointment's payment status changes to **Paid**, and the customer may earn **loyalty points** automatically.

---

## 8. Walk-in flow (find customer → services → pay → receipt)

Use **Walk-ins** for clients who arrive with no booking and want to be served and pay right away.

1. Open **Walk-ins**.
2. **Step 1 — Customer**:
   - Search the list, **or**
   - Click **New Customer** and type first name, last name, phone (email optional).
3. **Step 2 — Services & time**:
   - Add one or more **services** with the **+** button.
   - Pick the **date** and an available **time**.
4. Payment details:
   - **Method**: Cash or Bank Transfer (+ reference).
   - Optional **Discount**.
5. Click **Complete Walk-In & Process Payment**.

The system does everything in one step: creates/links the customer → books the appointment as **confirmed + paid** → generates the **invoice** → records the **payment** → awards loyalty points → opens the receipt.

---

## 9. Invoice / receipt

1. Open **Invoices** in the sidebar.
2. Click on an invoice to view it (or you are taken straight there after a walk-in / payment).
3. The invoice shows the salon details, customer, services, subtotal, tax and grand total.
4. Click **Print** to send it to a printer or save as PDF.

> The invoice page is designed to look like a printed receipt, so what you print matches what's on screen.

---

## 10. Calendar

The **Calendar** shows all appointments at a glance in **Day**, **Week** or **Month** view.

- Use the arrows to go back/forward, or **Today** to jump to today.
- **New** opens a new appointment directly.
- Click any appointment to see or manage it.

---

## 11. Reports (Admin only)

1. Open **Reports**.
2. Pick the period and report type (revenue, payments, popular services, etc.).
3. The report shows totals and summaries you can print or export.

Use it at the end of the day/week to review income.

---

## 12. Settings (Admin only)

Open **Settings** — it has tabs:

- **General** — salon name, tagline, phone, email, address, and the **logo**.
- **Hours & Rules** — opening/closing time, currency code (e.g. ETB), **tax rate %**, loyalty points rule, booking window (how far ahead customers can book), max advance days, session timeout.
- **Holidays** — days the salon is closed (no slots are offered).
- **Notifications** — toggle email/SMS alerts (in-app alerts are always on).
- **Backup** — download a `.sql` backup, or restore one.

> After changing decimals like tax rate or currency, new bookings/payments use the new values.

---

## 13. System users & security (Admin only)

- Open **Users** to create admin accounts, activate/deactivate users, or delete them.
- To create a receptionist account, the account must be inserted with the `receptionist` role (only admins/receps can be created from the UI today; see developer notes).
- Passwords must be **at least 8 characters**.
- **Audit Logs** records every important action (who did what, when) — check it if something looks off.

---

## 14. Quick reference — full workflow

**New walk-in end to end:**
1. Log in.
2. **Walk-ins** → pick/create customer → add services → date + time → payment method → **Complete Walk-In & Process Payment**.
3. Receipt/invoice opens → **Print**.

**Booked appointment end to end:**
1. Log in.
2. **Customers** → add/search customer.
3. **Appointments** → create → link customer → services → date/time (staff optional) → save.
4. Perform the service → mark appointment **Completed**.
5. **Payments** → new payment → select appointment → amount → method (cash/bank) → save.
6. **Invoices** → open the invoice → **Print** for the customer.

---

## 15. Good habits

- Always use the search box to find existing customers before creating new ones — avoids duplicates.
- Confirm the timeslot is available before promising a client a time.
- Collect bank transfer references at the desk.
- End each day with **Reports** to reconcile money and **Invoices** to hand out receipts.
- Back up the database from **Settings → Backup** regularly.
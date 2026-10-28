# Project Architecture

## 1. Big picture

```text
 ┌──────────── Browser (student / driver / admin) ────────────┐
 │ HTML + Bootstrap  ·  JS (fetch)  ·  Leaflet + OSM tiles     │
 └───────────────┬──────────────────────────────▲─────────────┘
                 │ HTTPS/HTTP: forms (POST+CSRF) │ HTML / JSON
                 ▼  JSON APIs (X-CSRF-Token)     │
 ┌───────────────────────── Apache + PHP ─────────────────────┐
 │ public/*.php  (pages)        public/api/*  (JSON endpoints) │
 │        │ require src/bootstrap.php                          │
 │        ▼                                                    │
 │ config/  → .env, PDO, security headers, sessions, auth/RBAC │
 │ src/services → fees, passes, tracking, uploads, audit, …    │
 │ src/views    → layout, pass card, tracking panel            │
 └───────────────┬─────────────────────────────────────────────┘
                 │ PDO prepared statements
                 ▼
          MySQL / MariaDB  (sksvmacet_buspass)
   uploads/ (outside public)      storage/logs/
```

It is a classic **server-rendered multi-page app** with a few JSON endpoints where the page must update without
reloading (fee quote, GPS upload, live tracking). There is no framework: each page is a short PHP script that
1. loads `src/bootstrap.php` (config → DB → helpers → security → session → services),
2. calls `require_role(...)`,
3. handles a POST (after `require_csrf()`), then redirects (Post/Redirect/Get),
4. renders HTML with `page_header()` … `page_footer()`.

This keeps every page readable top-to-bottom – useful for explaining the code in a viva.

## 2. Request lifecycle (example: admin verifies a payment)
1. Admin clicks **VERIFY PAYMENT** → browser POSTs `id`, `action=verify`, `csrf_token` to `admin/payments.php`.
2. `bootstrap.php` sends security headers and starts the hardened session.
3. `require_role('admin')` – loads the user from DB by session ID, checks the role.
4. `require_csrf()` – compares the token with `hash_equals`.
5. Transaction: payment → `verified`, application → `pending_approval`.
6. `audit_log()` + `notify()` the student → redirect to the application page.

## 3. Layers
| Layer | Folder | Responsibility |
|---|---|---|
| Configuration | `config/` | `.env` parsing, DB connection, security constants & headers, session, authentication/RBAC |
| Domain services | `src/services/` | Business rules: fee calculation, pass issuing & expiry, tracking status, assignments, uploads, rate limiting, audit, notifications |
| Views | `src/views/` | Shared layout and partials (pass card, tracking panel) |
| Controllers/pages | `public/{student,driver,admin}/` | One file per screen |
| APIs | `public/api/` | JSON endpoints used by JavaScript |
| Assets | `public/assets/` | CSS, page scripts, the official payment QR, vendored libraries |

## 4. Status model (why three state machines instead of one long list)
The brief listed nine statuses (Pending, Payment Pending, Payment Submitted, Payment Verification Pending, Approved, Rejected,
Active, Expired, Renewal Pending). They describe **three different things**, so storing them in one column would allow
impossible combinations (e.g. "Active" with an unpaid fee). They are split:

```text
APPLICATION  payment_pending ──(student submits UTR)──► payment_submitted ──(admin verifies)──► pending_approval ──(admin approves)──► approved
                 ▲   │cancel                                 │(admin rejects payment)                 │(admin rejects)
                 └───┴──────────────────────────────────────┘                                        ▼
                                                                                                  rejected
PAYMENT      pending ──► submitted ──► verified            (rejected ──► submitted again)
PASS         active ──(valid_until passes)──► expired      active ──(admin)──► revoked
```
UI labels map back to the brief: *Payment Submitted / Verification Pending* = `payment_submitted`, *Pending* = `pending_approval`,
*Active/Expired* = pass status, *Renewal Pending* = `application_type = renewal` and not yet approved.

## 5. Key design decisions
- **Payment QR is configuration, not code.** Its path is the `payment_qr_path` row in `settings`; every page calls
  `payment_qr_url()`. Admin → Settings replaces it; the official image is never edited (byte-identical copy in `public/assets/qr`).
- **Fees are computed on the server** from `pass_fees` + stop distances; the browser's number is display-only.
- **Trust nothing from the GPS client** – the bus, route and trip are looked up from the logged-in driver.
- **"Live" is decided on the server** from the age of the last fix, so stale data can never be displayed as live.
- **All libraries vendored locally** – the app works on a college LAN without CDN access (only map tiles need internet).
- **No inline JavaScript** – allows a strict Content-Security-Policy.

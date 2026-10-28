# Security Architecture – SKSVMACET Bus Pass System

> **No PHP version and no framework makes an application "impossible to hack".** PHP 8.5 gives us a maintained,
> patched runtime; the protection comes from how the application is written and deployed. This document lists what is
> implemented, where to find it, and what a real deployment still has to add.

## 1. Threat model (what we defend against)

| Threat | Example | Defence (file) |
|---|---|---|
| SQL injection | `' OR 1=1 --` in a login box | PDO **real** prepared statements everywhere, `ATTR_EMULATE_PREPARES=false` (`config/database.php`). No user input is ever concatenated into SQL. |
| XSS | `<script>` in a name/address | Every output goes through `e()` = `htmlspecialchars(ENT_QUOTES, UTF-8)` (`src/helpers.php`). Data for JS is embedded with `json_for_html()` (hex-escaped JSON). **Content-Security-Policy** allows scripts only from our own server and forbids inline JS (`config/security.php`). JS builds DOM with `textContent` / `App.esc()`. |
| CSRF | A malicious page posts "verify payment" as the admin | 256-bit per-session token checked with `hash_equals()` on **every** POST form and every JSON API call (`X-CSRF-Token` header). Cookies are `SameSite=Lax`. Logout is POST-only. |
| Session hijacking / fixation | Stealing or planting a session ID | `session_regenerate_id(true)` on login and password change; `use_strict_mode`, `use_only_cookies`, no `trans_sid`; cookie `HttpOnly`, `SameSite=Lax`, `Secure` automatically when on HTTPS; 30-min idle + 8-h absolute timeout (`config/security.php`, `config/auth.php`). |
| Brute-force login | Password guessing | `login_attempts` table: lock after **5 failures per account+IP** or **20 per IP** in 15 min; generic error message; `password_verify` runs even for unknown users (no timing-based user enumeration) (`config/auth.php`). |
| Weak password storage | DB leak | `password_hash(PASSWORD_DEFAULT)` (bcrypt) + `password_needs_rehash` upgrade on login. Passwords are never logged. |
| Broken access control / IDOR | Student opens `payment.php?application=2` | `require_role()` at the top of **every** page and API; the role is re-read from the DB each request (deactivated users are logged out). Every query that loads a record also filters by the owner (`AND student_id = ?`). Hiding menu items is **not** relied on. |
| GPS spoofing between drivers | Driver sends `bus_id` of another bus | The GPS API **ignores** any `bus_id/driver_id/trip_id` from the browser and derives them from session → driver → active assignment → active trip (`src/services/driver_api.php`). Students/admins get 403. |
| GPS abuse | Flooding / fake teleporting | Per-driver minimum interval (2 s), lat/lng/accuracy validation, device timestamp must be within ±10 min, real-GPS jumps implying > 150 km/h are rejected and audited (`public/api/driver/update-location.php`). One active trip per driver and per bus is enforced by **unique keys** in the DB. |
| Malicious uploads | `shell.php` renamed to `.jpg` | Size limit, MIME detected from file **content** (`finfo`), must decode as an image (`getimagesize`), random 128-bit file name with extension chosen by us, stored **outside** `/public`, served only by `public/file.php` after an ownership check with `nosniff` and a locked-down CSP. `uploads/.htaccess` denies all access and turns the PHP engine off (`src/services/uploads.php`). |
| Path traversal | `file.php?name=../../config/...` | `file.php` never takes a path; it looks the file name up in the DB and `safe_upload_name()` only accepts `[a-f0-9]{32}.(jpg|png|webp)`. |
| Payment fraud | Fake / reused UTR | UTR is **unique** in the DB (duplicate attempts are audited); entering a UTR only sets status *Submitted*; only an admin can *Verify*; verification and approval are separate steps; the fee is always recomputed on the server (a tampered `fee_amount` field is ignored). The app never asks for or stores UPI PINs, OTPs or bank passwords. |
| Pass forgery / data leak via QR | Reading data from a pass QR | The QR holds only `verify-pass.php?token=<64 hex chars>` from `random_bytes(32)`. The verify page shows the minimum a conductor needs and no internal IDs; it is rate-limited and `noindex`. Revoked/expired passes show **INVALID**. |
| Information disclosure | Stack traces, DB credentials | `display_errors` off unless `APP_DEBUG=true`; exceptions are logged to `storage/logs/` with a reference code and users see a generic page. Credentials live only in `.env` (git-ignored, blocked by `.htaccess`). `config/ src/ database/ storage/ uploads/ docs/` are denied by `.htaccess`. |
| Clickjacking, MIME sniffing | Framing the admin panel | `X-Frame-Options: DENY`, `frame-ancestors 'none'`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `Permissions-Policy` (geolocation only for our own origin), HSTS when on HTTPS. |
| Open redirect | `?next=https://evil` | After login we only redirect to paths inside the user's own role folder. |
| CSV/formula injection | `=HYPERLINK(...)` as a name | CSV export prefixes cells starting with `= + - @` with `'`. |

## 2. Audit logging
`audit_logs` records logins (success/failure/lockout), logouts, registrations, applications, payment submit/verify/reject,
pass issue/revoke/extend, trip start/stop/force-end, driver/bus/route/stop/fee/settings changes, QR replacement, CSRF rejections,
access-denied events, duplicate-UTR attempts and rejected GPS jumps – with user, role, IP, user-agent and time.
Admins view them under *Admin → Audit Logs* (security events highlighted).

## 3. Role-based access matrix

| Resource | Student | Driver | Admin |
|---|---|---|---|
| Own profile / applications / payments / pass | ✔ (own only) | – | ✔ (all) |
| Fee quote API | ✔ | – | ✔ |
| Bus location API | own pass's bus only | – | any bus |
| Start/stop trip, send GPS | – | own assigned bus only | – |
| Request a stop | – | own route only | approves |
| Manage buses/routes/stops/fees/drivers/students/settings | – | – | ✔ |
| Verify payments, issue passes | – | – | ✔ |
| `verify-pass.php` | public (token required) | public | public |

## 4. What a production deployment must still do
1. Serve over **HTTPS** (required anyway for real GPS) and set `FORCE_HTTPS=true`.
2. Point Apache's DocumentRoot at `public/` (the `.htaccess` rules are a fallback for XAMPP's htdocs layout).
3. Create a dedicated MySQL user with privileges only on `sksvmacet_buspass`; never use `root` with an empty password.
4. Change every demo password; delete demo accounts; keep `APP_DEBUG=false`.
5. Keep PHP, Apache, MySQL/MariaDB patched; back up the database and `uploads/` regularly.
6. Consider 2-factor authentication for admin accounts and an official payment-gateway integration for automatic reconciliation.
7. Add a privacy notice: the system stores student contact details and the driver phone's location during trips.

## 5. Reporting a vulnerability
Report privately to the project maintainers / college transport office. Do not open a public issue with exploit details.

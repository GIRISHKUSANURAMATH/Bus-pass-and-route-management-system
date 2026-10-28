# SKSVMACET Bus Pass & Route Management System

A full-stack web application for **SKSVMACET** (Laxmeshwar) that handles the whole college-bus workflow:
student registration → bus-pass application → UPI payment with the **official college QR** → admin payment
verification → digital bus pass with QR verification → **live bus tracking** from the driver's phone GPS.

| | |
|---|---|
| **Backend** | PHP 8.1+ (written for and compatible with PHP 8.5; no deprecated functions), PDO, MySQL 8 / MariaDB 10.4+ |
| **Frontend** | HTML5, CSS3, Bootstrap 5.3, vanilla JavaScript (Fetch API) |
| **Maps** | Leaflet 1.9 + OpenStreetMap tiles (no Google API key needed) |
| **Charts / QR** | Chart.js 4, qrcode-generator – all libraries are bundled locally in `public/assets/vendor` |
| **Server** | Apache (XAMPP) – no Composer packages required |

---

## 1. Features

**Student** – register, login, profile & photo, apply/renew pass (route → boarding stop → destination → academic year → duration),
automatic server-side fee calculation, pay via official college UPI QR, submit UTR + optional screenshot, track application/payment
status, digital pass (print / save as PDF / download QR), view route & stops on a map, **live bus tracking**, notifications.

**Driver** – login, assigned bus & route, stops, **START TRIP / STOP TRIP**, live GPS sharing (`watchPosition`), GPS status
read-out, **DEMO GPS SIMULATION** mode, authorized passenger list, request a new stop on *their own* route only.

**Admin (transport office)** – dashboard with charts and recent activity, manage students / drivers / buses / routes / stops (map picker,
re-ordering) / fees, assign drivers to buses, review applications, **verify or reject payments**, approve & issue passes, revoke /
extend passes, trips with GPS path replay, live map of all buses, reports + CSV export, audit logs, settings (including **replacing the
payment QR**).

---

## 2. Quick start with XAMPP (Windows)

> Tested end-to-end on PHP 8.3 + MariaDB 10.11. The code uses only features available since PHP 8.1, so it runs on
> whatever PHP your XAMPP ships (8.2 / 8.3 / 8.4 / 8.5).

1. **Install XAMPP** from <https://www.apachefriends.org> (any version with PHP ≥ 8.1).
2. Open the **XAMPP Control Panel** → **Start Apache** → **Start MySQL**.
3. **Check the PHP version**: XAMPP Control Panel → *Shell* → `php -v` (must be 8.1 or newer).
4. **Copy the project** folder `SKSVMACET-Bus-Pass-Management` into `C:\xampp\htdocs\`
   so you have `C:\xampp\htdocs\SKSVMACET-Bus-Pass-Management\public\index.php`.
5. **Create + import the database** – open <http://localhost/phpmyadmin>:
   - *Import* tab → choose `database/schema.sql` → **Go** (this creates the database `sksvmacet_buspass`).
   - Select `sksvmacet_buspass` → *Import* → choose `database/seed.sql` → **Go**.

   Or from the XAMPP shell:
   ```bash
   cd C:\xampp\htdocs\SKSVMACET-Bus-Pass-Management
   mysql -u root < database/schema.sql
   mysql -u root < database/seed.sql
   ```
6. **Configure `.env`** – copy `.env.example` to `.env` (same folder as this README). The defaults already match XAMPP
   (`root` with an empty password). Set `APP_URL` – see *Using phones* below.
7. **Composer** – *not required*. There are no third-party PHP packages. (`composer.json` exists only to document the PHP
   requirement and two helper scripts.)
8. **Open the app:**

   **<http://localhost/SKSVMACET-Bus-Pass-Management/public/>**

   (Opening `http://localhost/SKSVMACET-Bus-Pass-Management/` also redirects there.)

### Demo logins (from `seed.sql` – fake data)

| Role | Login ID | Password | What to show |
|---|---|---|---|
| Admin | `admin` | `Admin@12345` | one payment waiting for verification |
| Student | `2KA24CS001` | `Student@123` | has an **active pass** (Hubballi → Campus) |
| Student | `2KA23EC014` | `Student@123` | payment **submitted, awaiting verification** |
| Student | `2KA25ME021` | `Student@123` | registered, no application yet |
| Driver | `driver1` | `Driver@123` | BUS001 on Route 1 (Laxmeshwar ↔ Hubballi) |
| Driver | `driver2` | `Driver@123` | BUS002 on Route 2 (Laxmeshwar ↔ Gadag) |

**Change these passwords** (Profile → Change password) on any machine other than your own laptop.

### Open in VS Code
`File → Open Folder → C:\xampp\htdocs\SKSVMACET-Bus-Pass-Management`. Recommended extensions are listed in
`.vscode/extensions.json` (PHP Intelephense).

### Without XAMPP (PHP built-in server, for quick testing)
```bash
php -S 127.0.0.1:8000 -t public
# open http://127.0.0.1:8000/   (still needs a running MySQL/MariaDB + imported schema)
```

---

### Monthly bus pass & cropped payment QR (update of 28-10-2026)
- Students can apply only for a **1 Month** pass. Its price is the setting `monthly_bus_pass_fee` (demo value `1`),
  changed in **Admin → Pass Fees**.
- The payment page shows `public/assets/qr/sksvmacet-payment-qr-cropped.png`, cropped from the unchanged original
  `sksvmacet-payment-qr.jpg`.
- If your database was imported before this update, run `database/migrations/2026-10-28-monthly-pass.sql` once
  (phpMyAdmin → select `sksvmacet_buspass` → Import). Fresh installs don't need it.

## 3. Using phones (driver GPS, scanning pass QR codes)

1. Find your PC's LAN IP (`ipconfig` → e.g. `192.168.1.10`) and put the phone on the same Wi-Fi.
2. Set in `.env`: `APP_URL=http://192.168.1.10/SKSVMACET-Bus-Pass-Management/public` – this is the address printed
   inside every bus-pass QR code.
3. Allow Apache through Windows Firewall when asked.

> ⚠️ **Real GPS needs HTTPS.** Browsers only allow `navigator.geolocation` on **https://** pages or on `localhost`.
> A phone opening `http://192.168.1.10/...` will be **blocked** from using GPS (the driver page shows a warning).
> Options:
> - For a real deployment: install an SSL certificate (e.g. Let's Encrypt) and set `FORCE_HTTPS=true`.
> - For a demo: expose XAMPP through an HTTPS tunnel such as `cloudflared tunnel --url http://localhost:80` or `ngrok http 80`,
>   and open the HTTPS address it prints on the phone.
> - Or use **DEMO SIMULATION** mode, which needs no GPS at all.

---

## 4. The demo flow (for your presentation)

1. **Student** registers → *Apply / Renew Pass* → picks route & stops → fee is calculated → *Submit*.
2. Payment page shows the **official SKSVMACET UPI QR** (exact image supplied by the college) and the amount.
   Student pays in any UPI app, enters the **UTR** and (optionally) uploads the screenshot → status becomes
   **Payment Verification Pending**. Nothing is marked paid automatically.
3. **Admin** → *Payment Verification* → checks the UTR against the college bank statement → **VERIFY PAYMENT**
   → *Approve & issue pass* (chooses bus and start date) → digital pass is generated with a unique QR token.
4. **Student** → *My Bus Pass* → print / save as PDF. Scan the QR with any phone camera → `verify-pass.php` shows **VALID**.
5. **Driver** logs in on a phone → *Live Tracking* → choose **REAL GPS** (needs HTTPS) or **DEMO SIMULATION** → **START TRIP**.
6. **Student** → *Track Bus* → live bus marker on the Leaflet map with 🟢 LIVE / 🟡 LOCATION DELAYED / 🔴 BUS LOCATION OFFLINE.
   Simulated movement is always labelled **DEMO SIMULATION**.
7. **Admin** → *Live Buses* (all buses on one map), *Trips* (replay a trip's GPS path), *Reports*, *Audit Logs*.

---

## 5. Folder structure

```text
SKSVMACET-Bus-Pass-Management/
├── README.md  SECURITY.md  LICENSE  .gitignore  .env.example  composer.json  .htaccess
├── database/            schema.sql (tables, keys, indexes) · seed.sql (demo data)
├── config/              config.php (.env loader) · database.php (PDO) · security.php (headers, sessions, limits) · auth.php (login, RBAC)
├── src/
│   ├── bootstrap.php    loaded first by every page: config, error handling, headers, session
│   ├── helpers.php      escaping, URLs, CSRF, validation, formatting
│   ├── services/        settings · audit · notifications · rate_limit · uploads · fees · passes · tracking · account · driver_api
│   └── views/           layout · pass_card · tracking_panel
├── public/              ← the ONLY web-facing folder
│   ├── index.php  login.php  register.php  logout.php  verify-pass.php  file.php
│   ├── student/         dashboard · apply · applications · payment · pass · track-bus · route · notifications · profile
│   ├── driver/          dashboard · live-tracking · route · passengers · notifications · profile
│   ├── admin/           dashboard · applications · payments · passes · students · drivers · buses · routes · stops · fees
│   │                    trips · live-map · reports · audit-logs · settings · notifications
│   ├── api/             fee-quote · driver/{start-trip,stop-trip,update-location} · tracking/bus-location · admin/live-buses
│   └── assets/          css · js · images · qr (official payment QR) · vendor (Bootstrap, Leaflet, Chart.js, qrcode)
├── uploads/             payment/ · profiles/   (outside public, never executed, served only via file.php after an auth check)
├── storage/logs/        app.log · php-error.log
└── docs/                PROJECT-ARCHITECTURE · DATABASE-DESIGN · GPS-TRACKING · SETUP-GUIDE · TESTING
```

---

## 6. Important limitations (read before presenting)

- **Payment is a submission + manual verification system, not a payment gateway.** A UTR typed by a student is *not* proof of
  payment; the admin must match it against the college's bank/UPI statement. Integrating a real gateway (Razorpay, PayU, SBI ePay…)
  would need the college's merchant credentials and that provider's official API.
- **The payment QR is the college's real merchant QR.** Money paid through it goes to the real college account. Get the
  transport/accounts office's approval before real students pay through this system, and agree who verifies payments.
- The supplied QR is a **static** merchant QR – it does not carry the amount, so the student types the amount shown on screen.
  The admin must confirm that the amount received in the bank statement equals the fee shown on the verification screen.
- **Browser GPS** works only while the driver's phone has GPS + internet + location permission and the tracking page is open.
  Phones usually pause it when the screen locks or the browser is closed (the page requests a screen wake-lock to help).
  A native Android app or a hardware GPS tracker is the production-grade option.
- **Stop coordinates in `seed.sql` are approximate demo values.** Correct them in *Admin → Bus Stops* by clicking the exact spot on the map.
- No software is "impossible to hack". See `SECURITY.md` for what is implemented and what a production deployment still needs.

---

## 7. Creating the ZIP yourself

```powershell
# Windows PowerShell, from C:\xampp\htdocs
Compress-Archive -Path SKSVMACET-Bus-Pass-Management -DestinationPath SKSVMACET-Bus-Pass-Management.zip
```
```bash
# Linux / macOS / Git Bash
zip -r SKSVMACET-Bus-Pass-Management.zip SKSVMACET-Bus-Pass-Management -x "*/.env" "*/uploads/payment/*" "*/uploads/profiles/*" "*/storage/logs/*.log"
```
Never include your real `.env` in a ZIP or a Git commit.

More: [docs/SETUP-GUIDE.md](docs/SETUP-GUIDE.md) · [docs/PROJECT-ARCHITECTURE.md](docs/PROJECT-ARCHITECTURE.md) ·
[docs/DATABASE-DESIGN.md](docs/DATABASE-DESIGN.md) · [docs/GPS-TRACKING.md](docs/GPS-TRACKING.md) · [docs/TESTING.md](docs/TESTING.md) · [SECURITY.md](SECURITY.md)

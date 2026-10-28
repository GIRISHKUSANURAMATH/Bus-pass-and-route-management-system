# Setup Guide (step by step)

## A. Requirements
- Windows/Linux/macOS with **XAMPP** (Apache + MariaDB + PHP ≥ 8.1; PHP 8.5 supported)
- PHP extensions (enabled by default in XAMPP): `pdo_mysql`, `fileinfo`, `mbstring`, `openssl`
- A browser; internet for map tiles (OpenStreetMap)
- VS Code (optional)

If `fileinfo` is disabled: open `C:\xampp\php\php.ini`, remove the `;` in front of `extension=fileinfo`, restart Apache.

## B. Install
1. Extract the ZIP into `C:\xampp\htdocs\` → `C:\xampp\htdocs\SKSVMACET-Bus-Pass-Management\`.
2. XAMPP Control Panel → Start **Apache** and **MySQL**.
3. Check PHP: Control Panel → **Shell** → `php -v`.
4. phpMyAdmin (<http://localhost/phpmyadmin>) → **Import** `database/schema.sql` → then select database
   `sksvmacet_buspass` → **Import** `database/seed.sql`.
   *(If import fails with "max_allowed_packet" or timeouts, use the shell: `mysql -u root < database\schema.sql` then `mysql -u root < database\seed.sql`.)*
5. Copy `.env.example` → `.env`. For XAMPP defaults nothing else is needed.
6. Browse to **<http://localhost/SKSVMACET-Bus-Pass-Management/public/>**.
7. Log in as `admin / Admin@12345` and change the password.

## C. First-time configuration as admin
1. **Settings & Payment QR** – confirm the official QR, merchant name and UPI ID; set the academic year.
2. **Routes** → check the two demo routes or add your own.
3. **Bus Stops** → select a route, click on the map to correct each stop's coordinates, set *km from origin* and approximate time,
   re-order with the arrows.
4. **Pass Fees** → base fee and per-km rate for semester and annual passes.
5. **Drivers** → create driver accounts (username + password); **Buses** → add buses, choose route and driver.

## D. Phones on the same Wi-Fi
- `ipconfig` → note IPv4 (e.g. 192.168.1.10). Set `APP_URL=http://192.168.1.10/SKSVMACET-Bus-Pass-Management/public` in `.env`.
- Windows Defender Firewall → allow "Apache HTTP Server" on private networks.
- Real GPS requires HTTPS – see README §3. Demo simulation works over plain HTTP.

## E. Troubleshooting
| Symptom | Fix |
|---|---|
| "The database is unavailable or not set up" | MySQL not started, wrong `DB_*` in `.env`, or `schema.sql` not imported. Details with reference code are in `storage/logs/app.log`. |
| 404 on every page | URL must include `/public/`. Folder name must match. |
| CSS missing / plain page | Open via `http://localhost/...` (not by double-clicking the file). |
| "Page expired" (419) | The form was open too long or cookies are blocked. Reload the page. |
| Map grey | No internet for OpenStreetMap tiles. Stops and the bus marker still draw. |
| Driver page: "permission denied" / insecure warning | Allow location in browser settings; use HTTPS or localhost. |
| Uploads fail | Make sure `uploads/payment` and `uploads/profiles` exist and are writable; check `upload_max_filesize` ≥ 3M in php.ini. |
| Locked out after failed logins | Wait 15 minutes, or `DELETE FROM login_attempts;` in phpMyAdmin. |

## F. Reset demo data
Re-import `schema.sql` then `seed.sql` (this wipes everything).

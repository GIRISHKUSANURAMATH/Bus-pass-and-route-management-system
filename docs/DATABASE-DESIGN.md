# Database Design

Database: `sksvmacet_buspass` · engine InnoDB · charset `utf8mb4` · compatible with MySQL 8 and MariaDB 10.4+ (XAMPP).

## 1. Entity-relationship overview

```text
users 1─1 students ─┐            routes 1─* bus_stops
users 1─1 drivers   │            routes 1─* pass_fees (semester/annual)
users 1─1 admins    │            routes 1─* buses
                    │            drivers *─* buses  via driver_bus_assignments (history, 1 active each)
students 1─* bus_pass_applications *─1 routes, *─1 bus_stops (boarding), *─1 bus_stops (destination)
bus_pass_applications 1─1 payments
bus_pass_applications 1─0..1 bus_passes ─*1 buses
trips *─1 buses, *─1 drivers, *─1 routes ;  trips 1─* bus_locations
users 1─* notifications ; users 1─* audit_logs ; drivers 1─* stop_requests
settings (key/value) · login_attempts · rate_limits
```

## 2. Tables

| Table | Purpose | Important keys / constraints |
|---|---|---|
| `users` | One login account per person, `role` = student/driver/admin | UNIQUE email, UNIQUE username, bcrypt `password_hash` |
| `students` | Student profile: USN, branch, year, academic year, address, emergency contact, photo | UNIQUE user_id, UNIQUE usn |
| `drivers` | Driver ID (DRV001), licence | UNIQUE driver_code. Phone lives in `users.phone` and is **contact info only** |
| `admins` | Admin profile | UNIQUE user_id |
| `routes` | Route code, origin, destination, round-trip flag, status | UNIQUE route_code |
| `bus_stops` | Name, lat/lng (DECIMAL 10,7), `stop_order`, direction, `distance_km` from origin (used for fare), ETA, status | INDEX (route_id, stop_order); FK route RESTRICT |
| `pass_fees` | `base_fee` + `per_km_rate` per route and duration | UNIQUE (route_id, duration) |
| `stop_requests` | Stops proposed by a driver for their own route | status pending/approved/rejected |
| `buses` | Bus ID, number, registration, capacity, route, status active/inactive/maintenance | UNIQUE bus_code, UNIQUE registration_number |
| `driver_bus_assignments` | Assignment history | Generated columns `active_driver`, `active_bus` + UNIQUE keys ⇒ **max one active assignment per driver and per bus** |
| `bus_pass_applications` | Route, stops, academic year, duration, distance, **fee snapshot**, type new/renewal, status | UNIQUE application_no; INDEX (student_id, status) |
| `payments` | Amount, method, **UTR**, screenshot file, payment date, status, verifier | UNIQUE application_id, **UNIQUE transaction_reference** (no duplicate UTRs) |
| `bus_passes` | Pass number BP2026xxxxxx, validity, status, **verification_token** (64 hex) | UNIQUE pass_number, token, application_id |
| `trips` | Bus, driver, route, `gps_mode` real/simulation, start/end time & coordinates, status | Generated `active_driver`/`active_bus` + UNIQUE ⇒ one active trip per driver and per bus |
| `bus_locations` | GPS history: lat, lng, accuracy, speed, heading, `is_simulated`, device time, server time | INDEX (trip_id,id), (bus_id,id) |
| `notifications` | In-app notifications | INDEX (user_id, is_read) |
| `settings` | Key/value configuration incl. `payment_qr_path`, GPS thresholds, college name | PK setting_key |
| `audit_logs` | Security & business audit trail | INDEX created_at, action, user_id |
| `login_attempts` | Throttling / lockout | INDEX (identifier, attempted_at), (ip_address, attempted_at) |
| `rate_limits` | Fixed-window counters for APIs, registration, verify page | PK bucket (sha256) |

All tables have `created_at` and, where rows change, `updated_at … ON UPDATE CURRENT_TIMESTAMP`.

## 3. Normalisation notes
- Name / email / phone are stored once in `users`; role tables hold only role-specific data (3NF).
- A driver's route is **derived** (driver → active assignment → bus → route) instead of being stored twice, so it can never disagree.
- `bus_pass_applications.fee_amount` and `distance_km` are deliberate **snapshots**: changing fees later must not change what a student already paid.
- `bus_passes` copies route/stops from the application so a pass stays valid evidence even if the application is edited later.

## 4. Fee formula
```
distance = | distance_km(destination) − distance_km(boarding) |
fee      = ceil( (base_fee + per_km_rate × distance) / fee_round_to ) × fee_round_to
```
Example (seed): Route 1 annual = 5500 + 110 × 52 km = ₹11,220.

## 5. Useful queries
```sql
-- Students with a currently valid pass on BUS001
SELECT u.full_name, s.usn, p.pass_number FROM bus_passes p
JOIN students s ON s.id = p.student_id JOIN users u ON u.id = s.user_id JOIN buses b ON b.id = p.bus_id
WHERE b.bus_code = 'BUS001' AND p.status = 'active' AND CURDATE() BETWEEN p.valid_from AND p.valid_until;

-- Latest position of every bus on an active trip
SELECT t.bus_id, l.latitude, l.longitude, l.received_at FROM trips t
JOIN bus_locations l ON l.id = (SELECT MAX(id) FROM bus_locations WHERE trip_id = t.id)
WHERE t.status = 'active';
```

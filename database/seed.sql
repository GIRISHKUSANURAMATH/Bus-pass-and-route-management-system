-- =====================================================================
--  SKSVMACET Bus Pass - DEMO DATA  (import AFTER schema.sql)
--
--  ALL PEOPLE, PHONE NUMBERS, EMAILS AND UTRs BELOW ARE FAKE.
--  Stop coordinates are APPROXIMATE demo values - correct them from
--  Admin > Bus Stops (click on the map) before real use.
--
--  Demo logins (change these immediately on any shared machine):
--    Admin    : admin               / Admin@12345
--    Students : 2KA24CS001 (or email) / Student@123   (same for all 3)
--    Drivers  : driver1, driver2    / Driver@123
-- =====================================================================
USE sksvmacet_buspass;
SET NAMES utf8mb4;
SET time_zone = '+05:30';

-- ---------------- Users ----------------
INSERT INTO users (id, role, email, username, password_hash, full_name, phone, status) VALUES
 (1, 'admin',   'admin@sksvmacet.demo',          'admin',   '$2y$10$ezqKwZ4oDMX53hJvJoOtdu4u0ZEUO5xURAc8U1RuMBIL/G9QK4jfe', 'Transport Office Admin', '9000000000', 'active'),
 (2, 'student', 'demo.student1@example.com',     NULL,      '$2y$10$LnRM.Cf/MWeah4ZBIw0OzuN1Ab03c/pJvGieduVKQeFPLqUnRv7kq', 'Demo Student One',       '9000000011', 'active'),
 (3, 'student', 'demo.student2@example.com',     NULL,      '$2y$10$LnRM.Cf/MWeah4ZBIw0OzuN1Ab03c/pJvGieduVKQeFPLqUnRv7kq', 'Demo Student Two',       '9000000012', 'active'),
 (4, 'student', 'demo.student3@example.com',     NULL,      '$2y$10$LnRM.Cf/MWeah4ZBIw0OzuN1Ab03c/pJvGieduVKQeFPLqUnRv7kq', 'Demo Student Three',     '9000000013', 'active'),
 (5, 'driver',  'driver1@sksvmacet.demo',        'driver1', '$2y$10$PWzsrvdw2p0kg1scn1CFeeqO5Eplpp4KoQR0mEInND1YOroIzIWRC', 'Demo Driver One',        '9000000021', 'active'),
 (6, 'driver',  'driver2@sksvmacet.demo',        'driver2', '$2y$10$PWzsrvdw2p0kg1scn1CFeeqO5Eplpp4KoQR0mEInND1YOroIzIWRC', 'Demo Driver Two',        '9000000022', 'active');

INSERT INTO admins (user_id, designation) VALUES (1, 'Transport In-charge');

INSERT INTO students (id, user_id, usn, branch, year_of_study, academic_year, address, emergency_contact) VALUES
 (1, 2, '2KA24CS001', 'CSE',        3, '2026-2027', 'Demo address, Hubballi',   '9000000031'),
 (2, 3, '2KA23EC014', 'ECE',        4, '2026-2027', 'Demo address, Gadag',      '9000000032'),
 (3, 4, '2KA25ME021', 'Mechanical', 1, '2026-2027', 'Demo address, Laxmeshwar', '9000000033');

INSERT INTO drivers (id, user_id, driver_code, license_number) VALUES
 (1, 5, 'DRV001', 'KA-DEMO-0001'),
 (2, 6, 'DRV002', 'KA-DEMO-0002');

-- ---------------- Routes + stops ----------------
INSERT INTO routes (id, route_code, name, origin, destination, is_round_trip, description, status) VALUES
 (1, 'RT01', 'Laxmeshwar → Hubballi → Laxmeshwar', 'Laxmeshwar', 'Hubballi', 1, 'Demo route via Gudageri and Kundgol', 'active'),
 (2, 'RT02', 'Laxmeshwar → Gadag → Laxmeshwar',    'Laxmeshwar', 'Gadag',    1, 'Demo route via Shirahatti',          'active');

INSERT INTO bus_stops (id, route_id, stop_code, name, latitude, longitude, stop_order, direction, distance_km, est_arrival_time, status) VALUES
 (1, 1, 'R1S1', 'SKSVMACET Campus',          15.1310000, 75.4590000, 1, 'both',  0.00, '09:15:00', 'active'),
 (2, 1, 'R1S2', 'Laxmeshwar Bus Stand',      15.1265000, 75.4700000, 2, 'both',  1.50, '09:05:00', 'active'),
 (3, 1, 'R1S3', 'Gudageri',                  15.2050000, 75.3700000, 3, 'both', 18.00, '08:35:00', 'active'),
 (4, 1, 'R1S4', 'Kundgol',                   15.2560000, 75.2480000, 4, 'both', 32.00, '08:10:00', 'active'),
 (5, 1, 'R1S5', 'Hubballi Old Bus Stand',    15.3500000, 75.1380000, 5, 'both', 52.00, '07:30:00', 'active'),
 (6, 2, 'R2S1', 'SKSVMACET Campus',          15.1310000, 75.4590000, 1, 'both',  0.00, '09:15:00', 'active'),
 (7, 2, 'R2S2', 'Laxmeshwar Bus Stand',      15.1265000, 75.4700000, 2, 'both',  1.50, '09:05:00', 'active'),
 (8, 2, 'R2S3', 'Shirahatti',                15.2310000, 75.5790000, 3, 'both', 14.00, '08:40:00', 'active'),
 (9, 2, 'R2S4', 'Gadag Old Bus Stand',       15.4300000, 75.6300000, 4, 'both', 40.00, '07:55:00', 'active');

INSERT INTO pass_fees (route_id, duration, base_fee, per_km_rate) VALUES
 (1, 'semester', 3000.00,  60.00),
 (1, 'annual',   5500.00, 110.00),
 (2, 'semester', 2500.00,  60.00),
 (2, 'annual',   4500.00, 110.00);

-- ---------------- Buses + assignments ----------------
INSERT INTO buses (id, bus_code, bus_number, registration_number, bus_name, capacity, route_id, status) VALUES
 (1, 'BUS001', 'SKSVM-01', 'KA-26-F-0001', 'Hubballi Express', 52, 1, 'active'),
 (2, 'BUS002', 'SKSVM-02', 'KA-26-F-0002', 'Gadag Shuttle',    45, 2, 'active');

INSERT INTO driver_bus_assignments (driver_id, bus_id, is_active, assigned_by) VALUES
 (1, 1, 1, 1),
 (2, 2, 1, 1);

-- ---------------- Applications, payments, passes ----------------
-- Student One: approved + active pass (Hubballi -> Campus, annual)
INSERT INTO bus_pass_applications (id, application_no, student_id, route_id, boarding_stop_id, destination_stop_id,
    academic_year, duration, distance_km, fee_amount, application_type, status, reviewed_by, reviewed_at, created_at) VALUES
 (1, 'APP2607010001', 1, 1, 5, 1, '2026-2027', 'annual', 52.00, 11220.00, 'new', 'approved', 1, '2026-07-28 11:00:00', '2026-07-25 10:00:00');

INSERT INTO payments (student_id, application_id, amount, payment_method, transaction_reference, payment_date,
    status, submitted_at, verified_by, verified_at) VALUES
 (1, 1, 11220.00, 'UPI', '412345678901', '2026-07-25', 'verified', '2026-07-25 10:20:00', 1, '2026-07-28 10:55:00');

INSERT INTO bus_passes (id, pass_number, application_id, student_id, route_id, bus_id, boarding_stop_id, destination_stop_id,
    academic_year, valid_from, valid_until, status, verification_token, issued_by, issued_at) VALUES
 (1, 'BP2026000001', 1, 1, 1, 1, 5, 1, '2026-2027', '2026-08-01', '2027-07-31', 'active',
  '4d6ffa540cfda611c0fc94d6c290fb7753d5ba264b5fd93fcec5055f58adc496', 1, '2026-07-28 11:00:00');

-- Student Two: payment submitted, waiting for admin verification (Gadag -> Campus, semester)
INSERT INTO bus_pass_applications (id, application_no, student_id, route_id, boarding_stop_id, destination_stop_id,
    academic_year, duration, distance_km, fee_amount, application_type, status, created_at) VALUES
 (2, 'APP2610200002', 2, 2, 9, 6, '2026-2027', 'semester', 40.00, 4900.00, 'new', 'payment_submitted', '2026-10-20 09:30:00');

INSERT INTO payments (student_id, application_id, amount, payment_method, transaction_reference, payment_date, status, submitted_at) VALUES
 (2, 2, 4900.00, 'UPI', '498765432109', '2026-10-20', 'submitted', '2026-10-20 09:45:00');

-- Student Three: registered, no application yet.

-- ---------------- Sample completed trip with GPS history ----------------
INSERT INTO trips (id, bus_id, driver_id, route_id, gps_mode, status, started_at, ended_at,
    start_latitude, start_longitude, end_latitude, end_longitude, last_location_at) VALUES
 (1, 1, 1, 1, 'simulation', 'completed', '2026-10-27 07:30:00', '2026-10-27 09:14:00',
  15.3500000, 75.1380000, 15.1310000, 75.4590000, '2026-10-27 09:14:00');

INSERT INTO bus_locations (trip_id, bus_id, driver_id, latitude, longitude, accuracy_m, is_simulated, recorded_at, received_at) VALUES
 (1, 1, 1, 15.3500000, 75.1380000, 10, 1, '2026-10-27 07:30:00', '2026-10-27 07:30:01'),
 (1, 1, 1, 15.2560000, 75.2480000, 10, 1, '2026-10-27 08:10:00', '2026-10-27 08:10:01'),
 (1, 1, 1, 15.2050000, 75.3700000, 10, 1, '2026-10-27 08:35:00', '2026-10-27 08:35:01'),
 (1, 1, 1, 15.1265000, 75.4700000, 10, 1, '2026-10-27 09:05:00', '2026-10-27 09:05:01'),
 (1, 1, 1, 15.1310000, 75.4590000, 10, 1, '2026-10-27 09:14:00', '2026-10-27 09:14:01');

-- ---------------- Settings (payment QR path lives ONLY here) ----------------
INSERT INTO settings (setting_key, setting_value) VALUES
 ('college_name',           'SKSVMACET'),
 ('college_full_name',      'Smt. Kamala and Sri Venkappa M. Agadi College of Engineering and Technology'),
 ('college_address',        'Laxmeshwar, Gadag District, Karnataka'),
 ('transport_office_phone', ''),
 ('payment_qr_path',        'assets/qr/sksvmacet-payment-qr-cropped.png'),
 ('payment_merchant_name',  'SK AND SVM AGADI COL.OF ENGEE'),
 ('payment_upi_id',         'SKANDSVMAGADI@SBI'),
 ('payment_instructions',   'Scan the QR with any UPI app, pay the exact amount shown, then enter the 12-digit UTR / transaction reference below.'),
 ('current_academic_year',  '2026-2027'),
 ('gps_live_seconds',       '30'),
 ('gps_offline_seconds',    '120'),
 ('gps_send_interval',      '5'),
 ('tracking_poll_seconds',  '7'),
 ('fee_round_to',           '10'),
 ('monthly_bus_pass_fee',   '1');

-- ---------------- Notifications + audit ----------------
INSERT INTO notifications (user_id, title, message, link) VALUES
 (2, 'Bus pass approved', 'Your bus pass BP2026000001 is approved and valid from 01-08-2026 to 31-07-2027.', 'student/pass.php'),
 (1, 'Payment submitted', 'Demo Student Two (2KA23EC014) submitted payment UTR 498765432109 for ₹4,900.00.', 'admin/payments.php');

INSERT INTO audit_logs (user_id, user_role, action, entity_type, entity_id, details, ip_address) VALUES
 (1, 'admin', 'payment_verified', 'payment', 1, '{"utr":"412345678901"}', '127.0.0.1'),
 (1, 'admin', 'pass_issued', 'bus_pass', 1, '{"pass_number":"BP2026000001"}', '127.0.0.1'),
 (3, 'student', 'payment_submitted', 'payment', 2, '{"utr":"498765432109"}', '127.0.0.1');

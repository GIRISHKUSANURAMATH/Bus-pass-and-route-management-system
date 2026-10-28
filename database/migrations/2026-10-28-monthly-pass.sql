-- =====================================================================
--  Minimal upgrade of an EXISTING sksvmacet_buspass database for the
--  1-month bus pass and the cropped payment QR.
--
--  Run ONCE: phpMyAdmin > select sksvmacet_buspass > Import, or
--    mysql -u root sksvmacet_buspass < database/migrations/2026-10-28-monthly-pass.sql
--  Safe to run again. Does NOT touch any other database (e.g. buspassdb),
--  and does NOT change, cancel or delete any application / payment / pass record.
-- =====================================================================
USE sksvmacet_buspass;

-- 1) Allow the value 'monthly' (appended; existing 'semester'/'annual' rows are unaffected).
ALTER TABLE bus_pass_applications
    MODIFY duration ENUM('semester','annual','monthly') NOT NULL;

-- 2) The single configurable monthly fee. Re-running never overwrites a value the admin has set.
INSERT INTO settings (setting_key, setting_value) VALUES ('monthly_bus_pass_fee', '1')
    ON DUPLICATE KEY UPDATE setting_value = setting_value;

-- 3) Show the cropped QR on the payment page - only if the original official image is the one in use.
UPDATE settings SET setting_value = 'assets/qr/sksvmacet-payment-qr-cropped.png'
 WHERE setting_key = 'payment_qr_path' AND setting_value = 'assets/qr/sksvmacet-payment-qr.jpg';

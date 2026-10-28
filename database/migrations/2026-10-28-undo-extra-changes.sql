-- =====================================================================
--  ONLY needed if you already ran the PREVIOUS version of
--  2026-10-28-monthly-pass.sql (the one that cancelled old unpaid
--  6/12-month applications and deleted the fee_round_to setting).
--  Restores exactly what that version changed. Safe to run more than once.
--  Touches only sksvmacet_buspass.
-- =====================================================================
USE sksvmacet_buspass;

-- Re-open applications that the previous migration cancelled (identified by its exact remark).
UPDATE bus_pass_applications
   SET status = 'payment_pending', admin_remarks = NULL
 WHERE status = 'cancelled'
   AND admin_remarks = 'Replaced by the monthly bus pass. Please apply again.'
   AND duration IN ('semester','annual');

-- Restore the setting it deleted (original value 10).
INSERT INTO settings (setting_key, setting_value) VALUES ('fee_round_to', '10')
    ON DUPLICATE KEY UPDATE setting_value = setting_value;

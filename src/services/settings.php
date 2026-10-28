<?php
/**
 * Central settings store (table `settings`).
 *
 * The payment QR is configured ONLY here (key `payment_qr_path`).
 * Every page that shows the QR calls payment_qr_url() - no page,
 * template or JavaScript file contains the image path.
 */
declare(strict_types=1);

const SETTING_DEFAULTS = [
    'college_name'            => 'SKSVMACET',
    'college_full_name'       => 'Smt. Kamala and Sri Venkappa M. Agadi College of Engineering and Technology',
    'college_address'         => 'Laxmeshwar, Gadag District, Karnataka',
    'transport_office_phone'  => '',
    'payment_qr_path'         => 'assets/qr/sksvmacet-payment-qr-cropped.png',   // cropped from sksvmacet-payment-qr.jpg (kept as source)
    'payment_merchant_name'   => 'SK AND SVM AGADI COL.OF ENGEE',
    'payment_upi_id'          => 'SKANDSVMAGADI@SBI',
    'payment_instructions'    => 'Scan the QR with any UPI app, pay the exact amount shown, then enter the 12-digit UTR / transaction reference below.',
    'current_academic_year'   => '',
    'gps_live_seconds'        => '30',
    'gps_offline_seconds'     => '120',
    'gps_send_interval'       => '5',
    'tracking_poll_seconds'   => '7',
    'fee_round_to'            => '10',
];

function setting(string $key, ?string $default = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (q_all('SELECT setting_key, setting_value FROM settings') as $r) {
            $cache[$r['setting_key']] = $r['setting_value'];
        }
    }
    $v = $cache[$key] ?? null;
    if ($v === null || $v === '') {
        return $default ?? (SETTING_DEFAULTS[$key] ?? null);
    }
    return $v;
}

function set_setting(string $key, string $value): void
{
    $uid = current_user()['id'] ?? null;
    q('INSERT INTO settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?)
       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)',
        [$key, $value, $uid]);
}

function college_name(): string
{
    return (string) setting('college_name', config('app.college'));
}

/** The ONE function that resolves the active payment QR image. */
function payment_qr_url(): string
{
    $path = (string) setting('payment_qr_path');
    // Only files inside public/assets/qr are allowed (defence against a tampered setting).
    if (!preg_match('#^assets/qr/[A-Za-z0-9._-]+\.(jpe?g|png|webp)$#', $path) || !is_file(PUBLIC_PATH . '/' . $path)) {
        $path = SETTING_DEFAULTS['payment_qr_path'];
    }
    return asset(substr($path, strlen('assets/')));
}

function int_setting(string $key): int
{
    return max(1, (int) setting($key));
}

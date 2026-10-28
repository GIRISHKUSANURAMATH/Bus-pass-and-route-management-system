<?php
/**
 * Bus-pass fee calculation.
 *
 * Only ONE pass type exists: a 1-month pass. Its price is a single flat fee
 * stored in the `settings` table under the key `monthly_bus_pass_fee`
 * (Admin > Pass Fees). Nothing in the source code contains the amount, so
 * changing it (e.g. 1 -> 500) needs no code change.
 *
 * @return array{ok:bool, error?:string, distance_km?:float, amount?:float, duration?:string, duration_label?:string}
 */
declare(strict_types=1);

/** The only allowed pass duration (value stored in DB => months of validity). */
const PASS_DURATIONS = ['monthly' => 1];

/** Current configured monthly fee, or null if missing / invalid. */
function monthly_pass_fee(): ?float
{
    $v = setting('monthly_bus_pass_fee', '');
    if ($v === null || !is_numeric($v) || (float) $v <= 0) {
        return null;
    }
    return round((float) $v, 2);
}

function fee_quote(int $routeId, int $boardingId, int $destinationId, string $duration): array
{
    // Backend whitelist: anything other than "monthly" (e.g. a tampered "annual") is refused.
    if (!isset(PASS_DURATIONS[$duration])) {
        return ['ok' => false, 'error' => 'Only the 1-month bus pass is available.'];
    }
    $route = q_one("SELECT id FROM routes WHERE id = ? AND status = 'active'", [$routeId]);
    if (!$route) {
        return ['ok' => false, 'error' => 'Selected route is not available.'];
    }
    if ($boardingId === $destinationId) {
        return ['ok' => false, 'error' => 'Boarding and destination stops must be different.'];
    }
    $stops = q_all("SELECT id, distance_km FROM bus_stops WHERE route_id = ? AND status = 'active' AND id IN (?, ?)",
        [$routeId, $boardingId, $destinationId]);
    if (count($stops) !== 2) {
        return ['ok' => false, 'error' => 'Selected stops do not belong to this route.'];
    }
    $fee = monthly_pass_fee();
    if ($fee === null) {
        return ['ok' => false, 'error' => 'The monthly bus-pass fee is not configured yet. Contact the transport office.'];
    }
    return [
        'ok' => true,
        'distance_km' => round(abs((float) $stops[0]['distance_km'] - (float) $stops[1]['distance_km']), 2),
        'amount' => $fee,
        'duration' => $duration,
        'duration_label' => duration_label($duration),
    ];
}

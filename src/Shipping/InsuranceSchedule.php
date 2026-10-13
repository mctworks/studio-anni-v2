<?php

declare(strict_types=1);

namespace App\Shipping;

/**
 * Extra insurance above what Priority Mail already includes, from the schedule stored in
 * settings.usps_insurance_schedule (editable, with an effective date). All values are cents.
 */
final class InsuranceSchedule
{
    /**
     * @param array{included_cents: int, tiers: list<array{0: int, 1: int}>, over_step_cents: int, over_step_fee_cents: int} $schedule
     */
    public static function feeCents(int $valueCents, array $schedule): int
    {
        if ($valueCents <= $schedule['included_cents']) {
            return 0;
        }

        $fee = 0;
        $lastUpper = $schedule['included_cents'];
        foreach ($schedule['tiers'] as [$upper, $tierFee]) {
            if ($valueCents <= $upper) {
                return $tierFee;
            }
            $fee = $tierFee;
            $lastUpper = $upper;
        }

        $step = $schedule['over_step_cents'];

        return $fee + intdiv($valueCents - $lastUpper + $step - 1, $step) * $schedule['over_step_fee_cents'];
    }
}
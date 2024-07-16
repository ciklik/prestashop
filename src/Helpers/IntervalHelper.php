<?php

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Helpers;

use Carbon\CarbonImmutable;

/**
 * Helper function for interacting with intervals
 */
class IntervalHelper
{
    public static function addIntervalToDate(CarbonImmutable $date, string $interval, int $intervalCount)
    {
        $verb = $interval === 'month' ? ucfirst((string) $interval) . 'sNoOverflow' : ucfirst((string) $interval) . 's';
        $method = 'add' . $verb;
        return $date->$method($intervalCount);
    }

}

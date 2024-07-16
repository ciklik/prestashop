<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Helpers;

use Carbon\CarbonImmutable;

if (!defined('_PS_VERSION_')) {
    exit;
}

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

<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Helpers;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Helper function for interacting with intervals
 */
class IntervalHelper
{
    /**
     * Ajoute un intervalle à une date.
     *
     * Pour 'month', utilise une logique « sans débordement » : le 31 janvier + 1 mois
     * donne le 28/29 février (et non le 2/3 mars).
     *
     * @param \DateTimeImmutable $date
     * @param string $interval 'year', 'month', 'week', 'day', 'hour', 'minute', 'second'
     * @param int $intervalCount
     *
     * @return \DateTimeImmutable
     */
    public static function addIntervalToDate(\DateTimeImmutable $date, string $interval, int $intervalCount)
    {
        if ($interval === 'month') {
            return self::addMonthsNoOverflow($date, $intervalCount);
        }

        $specs = [
            'year' => 'P%dY',
            'week' => 'P%dW',
            'day' => 'P%dD',
            'hour' => 'PT%dH',
            'minute' => 'PT%dM',
            'second' => 'PT%dS',
        ];

        if (!isset($specs[$interval])) {
            throw new \InvalidArgumentException('Unsupported interval: ' . $interval);
        }

        return $date->add(new \DateInterval(sprintf($specs[$interval], $intervalCount)));
    }

    /**
     * Ajoute un nombre de mois sans débordement de fin de mois.
     *
     * @param \DateTimeImmutable $date
     * @param int $months
     *
     * @return \DateTimeImmutable
     */
    private static function addMonthsNoOverflow(\DateTimeImmutable $date, $months)
    {
        $originalDay = (int) $date->format('j');

        // On part du 1er du mois pour éviter tout débordement lors de l'ajout des mois
        $shifted = $date->modify('first day of this month')
            ->add(new \DateInterval('P' . (int) $months . 'M'));

        $targetDay = min($originalDay, (int) $shifted->format('t'));

        return $shifted
            ->setDate((int) $shifted->format('Y'), (int) $shifted->format('n'), $targetDay)
            ->setTime((int) $date->format('H'), (int) $date->format('i'), (int) $date->format('s'));
    }
}

<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Helpers\SubscriptionTransformPlan;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Règles de « Transformer en abonnement » : refus, nombre de déclinaisons, plafond, correspondance
 */
class SubscriptionTransformPlanTest extends TestCase
{
    private function facts(array $overrides = []): array
    {
        return $overrides + ['is_pack' => false, 'is_virtual' => false, 'depends_on_stock' => false, 'has_ciklik_attributes' => false, 'config_complete' => true];
    }

    private function frequencies(): array
    {
        return [
            10 => ['interval' => 'month', 'interval_count' => '1'],
            11 => ['interval' => 'month', 'interval_count' => '2'],
            12 => ['interval' => 'week', 'interval_count' => '3'],
            13 => ['interval' => 'month', 'interval_count' => '32'],
            14 => ['interval' => 'year', 'interval_count' => '1'],
        ];
    }

    public function testEligibleProduct()
    {
        $this->assertNull(SubscriptionTransformPlan::productRefusal($this->facts()));
    }

    public function testRefusals()
    {
        $this->assertSame('already_subscription', SubscriptionTransformPlan::productRefusal($this->facts(['has_ciklik_attributes' => true, 'is_pack' => true])));
        $this->assertSame('incomplete_config', SubscriptionTransformPlan::productRefusal($this->facts(['config_complete' => false])));
        $this->assertSame('pack', SubscriptionTransformPlan::productRefusal($this->facts(['is_pack' => true])));
        $this->assertSame('virtual', SubscriptionTransformPlan::productRefusal($this->facts(['is_virtual' => true])));
        $this->assertSame('advanced_stock', SubscriptionTransformPlan::productRefusal($this->facts(['depends_on_stock' => true])));
    }

    public function testFrequencySelection()
    {
        $this->assertNull(SubscriptionTransformPlan::frequencyRefusal([10, 11, 12], $this->frequencies(), 10));
        $this->assertSame('no_frequency', SubscriptionTransformPlan::frequencyRefusal([], $this->frequencies(), 10));
        $this->assertSame('unknown_frequency', SubscriptionTransformPlan::frequencyRefusal([10, 99], $this->frequencies(), 10));
        $this->assertSame('default_frequency_missing', SubscriptionTransformPlan::frequencyRefusal([11, 12], $this->frequencies(), 10));
        $this->assertSame('frequency_out_of_bounds', SubscriptionTransformPlan::frequencyRefusal([10, 13], $this->frequencies(), 10));
        $this->assertSame('frequency_out_of_bounds', SubscriptionTransformPlan::frequencyRefusal([10, 14], $this->frequencies(), 10));
    }

    public function testTenCombinationsTimesThreeFrequencies()
    {
        $quantities = array_fill(100, 10, 12);

        $summary = SubscriptionTransformPlan::summarize($quantities, 0, 3);

        $this->assertFalse($summary['simple']);
        $this->assertSame(10, $summary['oneoff']);
        $this->assertSame(30, $summary['created']);
        $this->assertSame(40, $summary['total']);
        $this->assertSame(120, $summary['quantity_before']);
        $this->assertSame(480, $summary['quantity_after']);
        $this->assertNull($summary['refusal']);
    }

    public function testSimpleProductGetsOneOffPlusFrequencies()
    {
        $summary = SubscriptionTransformPlan::summarize([], 42, 3);

        $this->assertTrue($summary['simple']);
        $this->assertSame(1, $summary['oneoff']);
        $this->assertSame(3, $summary['created']);
        $this->assertSame(4, $summary['total']);
        $this->assertSame(42, $summary['quantity_before']);
        $this->assertSame(168, $summary['quantity_after']);
    }

    public function testCapOfOneThousandCombinations()
    {
        $this->assertNull(SubscriptionTransformPlan::summarize(array_fill(1, 250, 0), 0, 3)['refusal']);
        $this->assertSame('too_many_combinations', SubscriptionTransformPlan::summarize(array_fill(1, 143, 0), 0, 6)['refusal']);
    }
}

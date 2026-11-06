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

    // Attributs : 26 « Une fois », 27 « Abonnement », 10 et 11 fréquences, 50 et 51 tailles S et M

    public function testDefaultFrequencyIsCreatedFirst()
    {
        $this->assertSame([10, 12, 11], SubscriptionTransformPlan::creationOrder([12, 10, 11], 10));
    }

    public function testPendingPairsOnAFreshStart()
    {
        $this->assertSame(
            [[1, 10], [1, 11], [2, 10], [2, 11]],
            SubscriptionTransformPlan::pendingPairs([1 => [50], 2 => [51]], [10, 11], 27, ['26-50', '26-51'])
        );
    }

    /**
     * Reprise après une étape validée : ce qui existe déjà n'est jamais recréé, même si l'ordre
     * des attributs diffère
     */
    public function testResumeSkipsWhatAlreadyExists()
    {
        $existing = ['26-50', '26-51', SubscriptionTransformPlan::signature([10, 27, 50]), SubscriptionTransformPlan::signature([11, 50, 27])];

        $this->assertSame([[2, 10], [2, 11]], SubscriptionTransformPlan::pendingPairs([1 => [50], 2 => [51]], [10, 11], 27, $existing));
    }

    /**
     * Rejouer une étape terminée ne crée rien, et une taille ne compte jamais pour une autre
     */
    public function testReplayIsIdempotent()
    {
        $existing = ['26-50', '26-51', '10-27-50', '11-27-50', '10-27-51', '11-27-51'];

        $this->assertSame([], SubscriptionTransformPlan::pendingPairs([1 => [50], 2 => [51]], [10, 11], 27, $existing));
        $this->assertSame([[2, 11]], SubscriptionTransformPlan::pendingPairs([1 => [50], 2 => [51]], [10, 11], 27, ['10-27-50', '11-27-50', '10-27-51', '11-27-52']));
    }

    public function testSimpleProductSourceHasNoOwnAttribute()
    {
        $this->assertSame([[7, 11]], SubscriptionTransformPlan::pendingPairs([7 => []], [10, 11], 27, ['26', '10-27']));
    }

    /**
     * Annuler ne retire que les déclinaisons abonnement et la « Une fois » créée, jamais une d'origine
     */
    public function testCancelTargetsNeverIncludeOriginalCombinations()
    {
        $combinations = [1 => [26, 50], 2 => [26, 51], 3 => [27, 10, 50], 4 => [27, 11, 51]];

        $this->assertSame([3, 4], SubscriptionTransformPlan::cancelTargets($combinations, 27, 0));
        $this->assertSame([8, 9, 7], SubscriptionTransformPlan::cancelTargets([7 => [26], 8 => [27, 10], 9 => [27, 11]], 27, 7));
        $this->assertSame([], SubscriptionTransformPlan::cancelTargets([1 => [26, 50]], 27, 0));
    }
}

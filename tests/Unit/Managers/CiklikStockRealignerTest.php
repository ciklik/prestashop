<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Managers;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Managers\CiklikStockRealigner;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * « Réaligner le stock » : le stock d'une déclinaison « Une fois » ne va que sur les
 * déclinaisons abonnement de la même combinaison
 */
class CiklikStockRealignerTest extends TestCase
{
    // Attributs : 1 « Une fois », 2 « Abonnement », 10 et 11 fréquences, 50 et 51 tailles S et M
    const ONEOFF = 1;
    const FREQUENCIES = [10, 11];
    const CIKLIK = [1, 2, 10, 11];

    private function combination(int $id, array $attributes, int $quantity): array
    {
        return ['id_product_attribute' => $id, 'attributes' => $attributes, 'quantity' => $quantity];
    }

    public function testSignatureIgnoresCiklikAttributesAndOrder()
    {
        $this->assertSame('50-60', CiklikStockRealigner::signature([60, 1, 50], self::CIKLIK));
        $this->assertSame('50-60', CiklikStockRealigner::signature([50, 2, 11, 60], self::CIKLIK));
        $this->assertSame('', CiklikStockRealigner::signature([1], self::CIKLIK));
    }

    public function testEachSizeOnlyReceivesItsOwnStock()
    {
        $plan = CiklikStockRealigner::plan([
            $this->combination(1, [50, 1], 7),
            $this->combination(2, [51, 1], 3),
            $this->combination(3, [50, 2, 10], 0),
            $this->combination(4, [50, 2, 11], 7),
            $this->combination(5, [51, 2, 10], 9),
            $this->combination(6, [51, 2, 11], 0),
        ], self::ONEOFF, self::FREQUENCIES, self::CIKLIK);

        $this->assertSame([
            ['id_product_attribute' => 3, 'from' => 0, 'to' => 7],
            ['id_product_attribute' => 5, 'from' => 9, 'to' => 3],
            ['id_product_attribute' => 6, 'from' => 0, 'to' => 3],
        ], $plan['changes']);
        $this->assertSame(1, $plan['unchanged']);
        $this->assertSame([], $plan['orphans']);
    }

    public function testSimpleProductTransformed()
    {
        $plan = CiklikStockRealigner::plan([
            $this->combination(1, [1], 42),
            $this->combination(2, [2, 10], 40),
        ], self::ONEOFF, self::FREQUENCIES, self::CIKLIK);

        $this->assertSame([['id_product_attribute' => 2, 'from' => 40, 'to' => 42]], $plan['changes']);
    }

    public function testOrphanSubscriptionIsLeftAsIs()
    {
        $plan = CiklikStockRealigner::plan([
            $this->combination(1, [50, 1], 7),
            $this->combination(3, [51, 2, 10], 4),
            $this->combination(9, [52], 5),
        ], self::ONEOFF, self::FREQUENCIES, self::CIKLIK);

        $this->assertSame([], $plan['changes']);
        $this->assertSame([3], $plan['orphans']);
    }
}

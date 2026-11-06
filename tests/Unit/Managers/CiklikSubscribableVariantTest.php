<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Managers;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Managers\CiklikSubscribableVariant;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Envoi du catalogue vers Ciklik par lots de 100 variantes
 */
class CiklikSubscribableVariantTest extends TestCase
{
    protected function setUp(): void
    {
        \PrestaShopLogger::resetLogs();
    }

    private function variants(int $count): array
    {
        $variants = [];
        for ($i = 1; $i <= $count; ++$i) {
            $variants[] = ['external_id' => (string) $i];
        }

        return $variants;
    }

    /**
     * @return array Tailles des lots envoyés
     */
    private function send(int $count, array $failingBatches = []): array
    {
        $sizes = [];
        $result = CiklikSubscribableVariant::sendInBatches($this->variants($count), function (array $batch) use (&$sizes, $failingBatches) {
            $sizes[] = count($batch);

            return ['status' => !in_array(count($sizes), $failingBatches, true), 'httpCode' => 422];
        });

        return [$sizes, $result];
    }

    public function testBatchesOfOneHundred()
    {
        $this->assertSame([99], $this->send(99)[0]);
        $this->assertSame([100], $this->send(100)[0]);
        $this->assertSame([100, 1], $this->send(101)[0]);
        $this->assertSame(array_fill(0, 10, 100), $this->send(1000)[0]);
    }

    public function testFailedBatchDoesNotStopTheNextOnes()
    {
        list($sizes, $result) = $this->send(250, [2]);

        $this->assertSame([100, 100, 50], $sizes);
        $this->assertSame(['sent' => 150, 'failed' => 100], $result);
        $this->assertCount(1, \PrestaShopLogger::$logs);
        $this->assertStringContainsString('101,102', \PrestaShopLogger::$logs[0]['message']);
    }

    /**
     * Une exception de l'envoi (Ciklik injoignable) remonte à l'appelant, qui note l'échec du produit
     */
    public function testExceptionStopsTheBatchesAndPropagates()
    {
        $sizes = [];
        try {
            CiklikSubscribableVariant::sendInBatches($this->variants(250), function (array $batch) use (&$sizes) {
                $sizes[] = count($batch);
                if (2 === count($sizes)) {
                    throw new \RuntimeException('unreachable');
                }

                return ['status' => true];
            });
            $this->fail('exception attendue');
        } catch (\RuntimeException $e) {
            $this->assertSame('unreachable', $e->getMessage());
        }

        $this->assertSame([100, 100], $sizes);
    }
}

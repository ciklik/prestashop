<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Managers;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Managers\CiklikSubscriptionTransformer;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CiklikSubscriptionTransformerTest extends TestCase
{
    /**
     * Verrou propre au produit et à la base, dans la limite de 64 caractères des verrous nommés
     */
    public function testLockNameIsPerProductAndDatabase()
    {
        $this->assertNotSame(CiklikSubscriptionTransformer::lockName(1), CiklikSubscriptionTransformer::lockName(2));
        $this->assertSame(CiklikSubscriptionTransformer::lockName(1), CiklikSubscriptionTransformer::lockName(1));
        $this->assertMatchesRegularExpression('/^ciklik_transform_[a-f0-9]{32}$/', CiklikSubscriptionTransformer::lockName(1));
        $this->assertLessThanOrEqual(64, strlen(CiklikSubscriptionTransformer::lockName(1)));
    }
}

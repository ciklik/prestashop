<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Helpers\ModuleConfigDeclaration;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Configuration déclarée à Ciklik : liste fermée de réglages non sensibles, jamais de jeton ni de texte libre.
 */
class ModuleConfigDeclarationTest extends TestCase
{
    protected function setUp(): void
    {
        \Configuration::resetMocks();
        \Shop::$featureActive = false;
    }

    protected function tearDown(): void
    {
        \Configuration::resetMocks();
    }

    public function testTheDeclarationCarriesTheModuleSettings()
    {
        \Configuration::updateValue('CIKLIK_FREQUENCY_MODE', '1');
        \Configuration::updateValue('CIKLIK_ENABLE_UPSELL', '0');
        \Configuration::updateValue('CIKLIK_FREQUENCY_PRICE_BASE', 'net');
        \Configuration::updateValue('CIKLIK_ENGAGEMENT_INTERVAL', 'month');
        \Configuration::updateValue('CIKLIK_ENGAGEMENT_INTERVAL_COUNT', '3');
        \Shop::$featureActive = true;

        $config = ModuleConfigDeclaration::build(true);

        $this->assertSame(1, $config['v']);
        $this->assertTrue($config['frequency_mode']);
        $this->assertFalse($config['enable_upsell']);
        $this->assertSame('net', $config['frequency_price_base']);
        $this->assertSame('month', $config['engagement_interval']);
        $this->assertSame(3, $config['engagement_interval_count']);
        $this->assertSame(_PS_VERSION_, $config['ps_version']);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $config['php_version']);
        $this->assertTrue($config['multishop']);
        $this->assertTrue($config['webservice_combinations']);
    }

    public function testNoSecretNorFreeTextIsDeclared()
    {
        \Configuration::updateValue(\Ciklik::CONFIG_API_TOKEN, 'jeton-secret');
        \Configuration::updateValue('CIKLIK_CART_FOOTER_MESSAGE', 'Message libre');
        \Configuration::updateValue('CIKLIK_CUSTOMER_GROUP_TO_ASSIGN', '7');

        $metadata = ModuleConfigDeclaration::metadata(false);
        $json = $metadata[ModuleConfigDeclaration::META_KEY];

        $this->assertSame([ModuleConfigDeclaration::META_KEY], array_keys($metadata));
        $this->assertIsString($json);
        $this->assertLessThan(5000, strlen($json));
        $this->assertStringNotContainsString('jeton-secret', $json);
        $this->assertStringNotContainsString('Message libre', $json);
        $this->assertSame(
            ['v', 'frequency_mode', 'enable_upsell', 'enable_change_interval', 'allow_change_next_billing',
                'enable_skip_next_delivery', 'enable_engagement', 'fallback_to_default_attribute',
                'delegate_options_display', 'enable_subscription_consent', 'cart_footer_enabled', 'debug_logs_enabled',
                'engagement_interval_count', 'ps_version', 'php_version', 'multishop', 'webservice_combinations'],
            array_keys(json_decode($json, true))
        );
    }

    public function testUnexpectedChoicesAreLeftOut()
    {
        \Configuration::updateValue('CIKLIK_FREQUENCY_PRICE_BASE', 'autre');
        \Configuration::updateValue('CIKLIK_ENGAGEMENT_INTERVAL', 'decade');
        \Configuration::updateValue('CIKLIK_ENGAGEMENT_INTERVAL_COUNT', '9999');

        $config = ModuleConfigDeclaration::build(false);

        $this->assertArrayNotHasKey('frequency_price_base', $config);
        $this->assertArrayNotHasKey('engagement_interval', $config);
        $this->assertSame(365, $config['engagement_interval_count']);
    }
}

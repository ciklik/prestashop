<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Helpers\CheckoutUrlSigner;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CheckoutUrlSignerTest extends TestCase
{
    const SECRET = 'ws-key-de-test';
    const NOW = 1700000000;

    /**
     * Vecteur de référence : Ciklik vérifie la même valeur pour les mêmes données
     */
    public function testSignatureMatchesTheReferenceVector()
    {
        $params = CheckoutUrlSigner::sign(28, 285, 'PayPlugCreditCard', self::SECRET, self::NOW);

        $this->assertSame([
            'expires' => self::NOW + 1800,
            'signature' => '719b5f1bf4338d0c1a4ebb47616bb8d868d3f09b620fdd97aec63e303312d6f2',
        ], $params);
    }

    /**
     * Chaque donnée liée change la signature : client, panier, moyen de paiement, clé
     */
    public function testSignatureIsBoundToCustomerCartPaymentMethodAndKey()
    {
        $reference = CheckoutUrlSigner::sign(28, 285, 'PayPlugCreditCard', self::SECRET, self::NOW)['signature'];

        $this->assertNotSame($reference, CheckoutUrlSigner::sign(29, 285, 'PayPlugCreditCard', self::SECRET, self::NOW)['signature']);
        $this->assertNotSame($reference, CheckoutUrlSigner::sign(28, 286, 'PayPlugCreditCard', self::SECRET, self::NOW)['signature']);
        $this->assertNotSame($reference, CheckoutUrlSigner::sign(28, 285, 'StripeCreditCard', self::SECRET, self::NOW)['signature']);
        $this->assertNotSame($reference, CheckoutUrlSigner::sign(28, 285, 'PayPlugCreditCard', 'autre-cle', self::NOW)['signature']);
        $this->assertNotSame($reference, CheckoutUrlSigner::sign(28, 285, 'PayPlugCreditCard', self::SECRET, self::NOW + 1)['signature']);
    }

    /**
     * Les paramètres se posent tels quels dans la requête du lien
     */
    public function testParamsBuildTheQueryString()
    {
        $query = http_build_query(CheckoutUrlSigner::sign(28, 285, 'PayPlugCreditCard', self::SECRET, self::NOW));

        $this->assertMatchesRegularExpression('/^expires=1700001800&signature=[a-f0-9]{64}$/', $query);
    }
}

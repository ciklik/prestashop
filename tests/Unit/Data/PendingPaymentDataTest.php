<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Data;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Data\PendingPaymentData;

if (!defined('_PS_VERSION_')) {
    exit;
}

class PendingPaymentDataTest extends TestCase
{
    const UUID = '0f8fad5b-d9cb-469f-a165-70867728950e';

    const OWNER = '6ba7b810-9dad-41d1-80b4-00c04fd430c8';

    const LINK = 'https://abo.boutique.test/commande/42/compte/7?token=pi_123_secret_456';

    /**
     * Abonnement actif du client avec un paiement en attente : montant, devise et lien
     */
    public function testReadsPendingPaymentOfActiveSubscription()
    {
        $payments = PendingPaymentData::collection([$this->subscription()], self::OWNER);

        $this->assertSame([self::UUID], array_keys($payments));
        $this->assertSame('Croquettes chien 12 kg', $payments[self::UUID]->label);
        $this->assertSame(29.9, $payments[self::UUID]->amount);
        $this->assertSame('EUR', $payments[self::UUID]->currency);
        $this->assertSame(self::LINK, $payments[self::UUID]->retry_link);
    }

    /**
     * Le lien finit dans une redirection : https absolu uniquement
     */
    public function testRejectsLinksThatAreNotHttps()
    {
        foreach (['javascript:alert(1)', 'http://abo.boutique.test/commande/1', '//abo.boutique.test/commande/1', '/commande/1', 'https:///chemin', '', null, 42] as $link) {
            $this->assertSame([], PendingPaymentData::collection([$this->subscription(['retry_link' => $link])], self::OWNER), var_export($link, true));
        }
    }

    /**
     * Le lien connecte le client à son compte : l'abonnement d'un autre client est écarté
     */
    public function testRejectsSubscriptionOfAnotherCustomer()
    {
        $other = $this->subscription();
        $other['user_uuid'] = '9b2f1c3e-1d2a-4b6c-8e7f-0a1b2c3d4e5f';
        $missing = $this->subscription();
        unset($missing['user_uuid']);

        $this->assertSame([], PendingPaymentData::collection([$other, $missing], self::OWNER));
        $this->assertSame([], PendingPaymentData::collection([$this->subscription()], ''));
        $this->assertCount(1, PendingPaymentData::collection([$this->subscription()], strtoupper(self::OWNER)));
    }

    /**
     * Une vieille commande en attente d'un abonnement résilié ne s'affiche pas
     */
    public function testRejectsInactiveSubscription()
    {
        $inactive = $this->subscription();
        $inactive['active'] = false;

        $this->assertSame([], PendingPaymentData::collection([$inactive], self::OWNER));
    }

    /**
     * Sans paiement en attente ou sans montant, rien à régler
     */
    public function testRejectsMissingPaymentOrAmount()
    {
        $none = $this->subscription();
        $none['pending_payment'] = null;

        $this->assertSame([], PendingPaymentData::collection([$none, 'texte', null], self::OWNER));

        foreach ([null, 'abc', 0, -5] as $amount) {
            $this->assertSame([], PendingPaymentData::collection([$this->subscription(['amount' => $amount])], self::OWNER), var_export($amount, true));
        }
    }

    private function subscription(array $pendingPayment = []): array
    {
        return [
            'uuid' => self::UUID,
            'user_uuid' => self::OWNER,
            'active' => true,
            'display_content' => 'Croquettes chien 12 kg',
            'retry_link' => self::LINK,
            'pending_payment' => array_merge(['order_id' => 42, 'amount' => 29.9, 'currency' => 'EUR', 'retry_link' => self::LINK], $pendingPayment),
        ];
    }
}

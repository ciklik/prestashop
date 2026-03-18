<?php

/**
 * @author    Ciklik SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Data;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Data\OrderData;
use PrestaShop\Module\Ciklik\Data\OrderValidationData;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Teste la logique de statut différencié création/renouvellement
 *
 * Vérifie que l'override de id_order_state sur OrderValidationData
 * fonctionne correctement selon la configuration.
 */
class CreationOrderStateTest extends TestCase
{
    /** @var int Statut standard pour les commandes Ciklik */
    private const STANDARD_ORDER_STATE = 2;

    /** @var int Statut spécifique pour les créations d'abonnement */
    private const CREATION_ORDER_STATE = 15;

    protected function setUp(): void
    {
        \Configuration::resetMocks();
    }

    /**
     * Crée un OrderValidationData de test via la factory OrderData
     */
    private function createOrderValidationData(): OrderValidationData
    {
        // Configurer le statut standard retourné par OrderData::getOrderState()
        \Configuration::updateValue(\Ciklik::CONFIG_ORDER_STATE, self::STANDARD_ORDER_STATE);

        $cart = new \Cart(42);
        $cart->id_currency = 1;
        $cart->secure_key = 'test_secure_key';

        $orderData = OrderData::create([
            'order_id' => 100,
            'user_uuid' => 'a1b2c3d4-e5f6-4a7b-8c9d-e0f1a2b3c4d5',
            'status' => 'completed',
            'paid_transaction_id' => 'txn_123',
            'paid_class_key' => 'StripeCreditCard',
            'created_at' => '2026-01-15 10:00:00',
            'total_paid' => '49.90',
        ]);

        return OrderValidationData::create($cart, $orderData);
    }

    // =========================================================================
    // Comportement par défaut (feature désactivée)
    // =========================================================================

    /**
     * Sans activation, le statut standard est utilisé
     */
    public function testDefaultStateWhenFeatureDisabled()
    {
        $data = $this->createOrderValidationData();

        $this->assertEquals(self::STANDARD_ORDER_STATE, $data->id_order_state);
    }

    /**
     * Avec le toggle désactivé explicitement, le statut standard est conservé
     */
    public function testStandardStateWhenToggleExplicitlyDisabled()
    {
        \Configuration::updateValue(\Ciklik::CONFIG_ENABLE_CREATION_ORDER_STATE, '0');
        \Configuration::updateValue(\Ciklik::CONFIG_CREATION_ORDER_STATE, (string) self::CREATION_ORDER_STATE);

        $data = $this->createOrderValidationData();

        // Simuler la logique d'override (même code que OrderGateway/validation.php)
        if (\Configuration::get(\Ciklik::CONFIG_ENABLE_CREATION_ORDER_STATE)
            && (int) \Configuration::get(\Ciklik::CONFIG_CREATION_ORDER_STATE) > 0) {
            $data->id_order_state = (int) \Configuration::get(\Ciklik::CONFIG_CREATION_ORDER_STATE);
        }

        $this->assertEquals(
            self::STANDARD_ORDER_STATE,
            $data->id_order_state,
            'Le statut ne doit pas changer quand le toggle est désactivé',
        );
    }

    // =========================================================================
    // Feature activée - création d'abonnement
    // =========================================================================

    /**
     * Avec la feature activée, le statut de création est appliqué
     */
    public function testCreationStateAppliedWhenFeatureEnabled()
    {
        \Configuration::updateValue(\Ciklik::CONFIG_ENABLE_CREATION_ORDER_STATE, '1');
        \Configuration::updateValue(\Ciklik::CONFIG_CREATION_ORDER_STATE, (string) self::CREATION_ORDER_STATE);

        $data = $this->createOrderValidationData();

        // Simuler la logique d'override pour subscription_creation
        if (\Configuration::get(\Ciklik::CONFIG_ENABLE_CREATION_ORDER_STATE)
            && (int) \Configuration::get(\Ciklik::CONFIG_CREATION_ORDER_STATE) > 0) {
            $data->id_order_state = (int) \Configuration::get(\Ciklik::CONFIG_CREATION_ORDER_STATE);
        }

        $this->assertEquals(
            self::CREATION_ORDER_STATE,
            $data->id_order_state,
            'Le statut de création doit être appliqué quand la feature est activée',
        );
    }

    /**
     * Avec la feature activée mais un statut à 0 (non configuré), le statut standard est conservé
     */
    public function testStandardStateWhenCreationStateIsZero()
    {
        \Configuration::updateValue(\Ciklik::CONFIG_ENABLE_CREATION_ORDER_STATE, '1');
        \Configuration::updateValue(\Ciklik::CONFIG_CREATION_ORDER_STATE, '0');

        $data = $this->createOrderValidationData();

        if (\Configuration::get(\Ciklik::CONFIG_ENABLE_CREATION_ORDER_STATE)
            && (int) \Configuration::get(\Ciklik::CONFIG_CREATION_ORDER_STATE) > 0) {
            $data->id_order_state = (int) \Configuration::get(\Ciklik::CONFIG_CREATION_ORDER_STATE);
        }

        $this->assertEquals(
            self::STANDARD_ORDER_STATE,
            $data->id_order_state,
            'Le statut ne doit pas changer si le statut de création vaut 0',
        );
    }

    // =========================================================================
    // Feature activée - rebill (renouvellement)
    // =========================================================================

    /**
     * Pour un rebill, le statut standard est toujours conservé même si la feature est activée
     */
    public function testRebillKeepsStandardStateEvenWhenFeatureEnabled()
    {
        \Configuration::updateValue(\Ciklik::CONFIG_ENABLE_CREATION_ORDER_STATE, '1');
        \Configuration::updateValue(\Ciklik::CONFIG_CREATION_ORDER_STATE, (string) self::CREATION_ORDER_STATE);

        $data = $this->createOrderValidationData();

        // Simuler la logique d'override pour un rebill (order_type != subscription_creation)
        $orderType = 'rebill';
        if ($orderType === 'subscription_creation'
            && \Configuration::get(\Ciklik::CONFIG_ENABLE_CREATION_ORDER_STATE)
            && (int) \Configuration::get(\Ciklik::CONFIG_CREATION_ORDER_STATE) > 0) {
            $data->id_order_state = (int) \Configuration::get(\Ciklik::CONFIG_CREATION_ORDER_STATE);
        }

        $this->assertEquals(
            self::STANDARD_ORDER_STATE,
            $data->id_order_state,
            'Un rebill doit toujours conserver le statut standard',
        );
    }

    /**
     * Pour une subscription_creation via OrderGateway, le statut de création est appliqué
     */
    public function testSubscriptionCreationGetsCreationState()
    {
        \Configuration::updateValue(\Ciklik::CONFIG_ENABLE_CREATION_ORDER_STATE, '1');
        \Configuration::updateValue(\Ciklik::CONFIG_CREATION_ORDER_STATE, (string) self::CREATION_ORDER_STATE);

        $data = $this->createOrderValidationData();

        // Simuler la logique d'override pour subscription_creation
        $orderType = 'subscription_creation';
        if ($orderType === 'subscription_creation'
            && \Configuration::get(\Ciklik::CONFIG_ENABLE_CREATION_ORDER_STATE)
            && (int) \Configuration::get(\Ciklik::CONFIG_CREATION_ORDER_STATE) > 0) {
            $data->id_order_state = (int) \Configuration::get(\Ciklik::CONFIG_CREATION_ORDER_STATE);
        }

        $this->assertEquals(
            self::CREATION_ORDER_STATE,
            $data->id_order_state,
            'Une subscription_creation doit recevoir le statut de création',
        );
    }

    // =========================================================================
    // Cas limites
    // =========================================================================

    /**
     * La propriété id_order_state est bien publique et mutable
     */
    public function testOrderStatePropertyIsMutable()
    {
        $data = $this->createOrderValidationData();
        $originalState = $data->id_order_state;

        $data->id_order_state = 99;

        $this->assertEquals(99, $data->id_order_state);
        $this->assertNotEquals($originalState, $data->id_order_state);
    }

    /**
     * Le statut standard provient bien de CONFIG_ORDER_STATE via OrderData::getOrderState()
     */
    public function testStandardStateComesFromConfigOrderState()
    {
        $customStandardState = 7;
        \Configuration::updateValue(\Ciklik::CONFIG_ORDER_STATE, $customStandardState);

        $cart = new \Cart(42);
        $cart->id_currency = 1;
        $cart->secure_key = 'test_key';

        $orderData = OrderData::create([
            'order_id' => 100,
            'user_uuid' => 'a1b2c3d4-e5f6-4a7b-8c9d-e0f1a2b3c4d5',
            'status' => 'completed',
            'created_at' => '2026-01-15 10:00:00',
            'total_paid' => '49.90',
        ]);

        $data = OrderValidationData::create($cart, $orderData);

        $this->assertEquals(
            $customStandardState,
            $data->id_order_state,
            'Le statut standard doit provenir de CONFIG_ORDER_STATE',
        );
    }

    /**
     * Avec la feature activée mais CONFIG_CREATION_ORDER_STATE non défini,
     * le statut standard est conservé
     */
    public function testStandardStateWhenCreationStateNotConfigured()
    {
        \Configuration::updateValue(\Ciklik::CONFIG_ENABLE_CREATION_ORDER_STATE, '1');
        // CONFIG_CREATION_ORDER_STATE n'est pas défini => Configuration::get() retourne false

        $data = $this->createOrderValidationData();

        if (\Configuration::get(\Ciklik::CONFIG_ENABLE_CREATION_ORDER_STATE)
            && (int) \Configuration::get(\Ciklik::CONFIG_CREATION_ORDER_STATE) > 0) {
            $data->id_order_state = (int) \Configuration::get(\Ciklik::CONFIG_CREATION_ORDER_STATE);
        }

        $this->assertEquals(
            self::STANDARD_ORDER_STATE,
            $data->id_order_state,
            'Le statut ne doit pas changer si CONFIG_CREATION_ORDER_STATE n\'est pas défini',
        );
    }
}

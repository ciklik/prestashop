<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Managers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Api\Subscription;
use PrestaShop\Module\Ciklik\Data\CartFingerprintData;
use PrestaShop\Module\Ciklik\Managers\CiklikSubscriptionFrequency;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Changement de fréquence partagé entre Mes abonnements et le back-office :
 * choix proposés, corps envoyé à Ciklik dans les deux modes, refus.
 */
class CiklikSubscriptionFrequencyTest extends TestCase
{
    const UUID = '550e8400-e29b-41d4-a716-446655440000';

    /** @var array Requêtes envoyées à l'API */
    private $history = [];

    private function api(array $responses)
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new Subscription(new \Link(), new Client([
            'handler' => $stack,
            'base_uri' => 'https://mock.test/api/v3/',
            'http_errors' => false,
        ]));
    }

    private function ok()
    {
        return new Response(200, [], json_encode(['data' => ['uuid' => self::UUID, 'display_interval' => 'Tous les 2 mois']]));
    }

    private function fingerprint($frequencyId = 1)
    {
        return CartFingerprintData::create([
            'id_customer' => 42,
            'id_address_delivery' => 10,
            'id_address_invoice' => 11,
            'id_lang' => 1,
            'id_currency' => 1,
            'id_carrier_reference' => 5,
            'frequency_id' => $frequencyId,
        ]);
    }

    /**
     * Lignes en mode fréquences : choix de SubscriptionData (getFrequenciesForProduct)
     */
    private function frequencyContents()
    {
        $choices = [
            ['frequency_id' => '1', 'display_name' => 'Fréquence : Mensuel', 'interval' => 'month', 'interval_count' => '1'],
            ['frequency_id' => '2', 'display_name' => 'Fréquence : Tous les 2 mois', 'interval' => 'month', 'interval_count' => '2'],
        ];

        return [
            ['external_id' => '12:0', 'quantity' => 2, 'other_combinations' => $choices],
            ['external_id' => '13:0', 'quantity' => 1, 'other_combinations' => []],
        ];
    }

    /**
     * Lignes en mode déclinaisons : choix de CiklikCombination::getOtherCombinations
     */
    private function combinationContents()
    {
        $choices = [
            ['id_product_attribute' => '102', 'frequency_id' => '22', 'interval_id' => '4', 'display_name' => 'Fréquence : 2 mois'],
            ['id_product_attribute' => '103', 'frequency_id' => '23', 'interval_id' => '4', 'display_name' => 'Fréquence : 3 mois'],
        ];

        return [
            ['external_id' => '101', 'quantity' => 2, 'other_combinations' => $choices],
            ['external_id' => '201', 'quantity' => 1, 'other_combinations' => []],
        ];
    }

    private function sentBody()
    {
        $this->assertCount(1, $this->history);

        return json_decode((string) $this->history[0]['request']->getBody(), true);
    }

    public function testChoicesAreThoseOfTheFirstLineKeyedByTheValueOfTheMode()
    {
        $this->assertSame([1, 2], array_keys(CiklikSubscriptionFrequency::getChoices($this->frequencyContents(), true)));
        $this->assertSame([102, 103], array_keys(CiklikSubscriptionFrequency::getChoices($this->combinationContents(), false)));
        $this->assertSame([], CiklikSubscriptionFrequency::getChoices([], true));
    }

    public function testFrequencyModeSendsFingerprintAndIntervalWithoutNextBilling()
    {
        $fingerprint = $this->fingerprint(1);

        $result = CiklikSubscriptionFrequency::change($this->api([$this->ok()]), self::UUID, $this->frequencyContents(), $fingerprint, true, '2');

        $this->assertTrue($result['success']);
        $this->assertSame('Tous les 2 mois', $result['response']['body']['display_interval']);

        $request = $this->history[0]['request'];
        $this->assertSame('PUT', $request->getMethod());
        $this->assertStringEndsWith('subscriptions/' . self::UUID, $request->getUri()->getPath());

        $body = $this->sentBody();
        $this->assertSame(['metadata', 'interval', 'interval_count'], array_keys($body));
        $this->assertSame('month', $body['interval']);
        $this->assertSame(2, $body['interval_count']);
        $this->assertSame(2, json_decode($body['metadata']['prestashop_fingerprint'], true)['frequency_id']);
        $this->assertSame(42, json_decode($body['metadata']['prestashop_fingerprint'], true)['id_customer']);

        // L'empreinte de l'abonnement chargé n'est pas modifiée
        $this->assertSame(1, $fingerprint->frequency_id);
    }

    public function testChoiceNotProposedIsRefusedWithoutCallingCiklik()
    {
        // Fréquence absente, valeur vide, identifiant de fréquence posté en mode déclinaisons
        foreach ([[true, '7'], [true, ''], [true, false], [false, '22']] as $case) {
            list($useFrequencyMode, $value) = $case;
            $contents = $useFrequencyMode ? $this->frequencyContents() : $this->combinationContents();

            $result = CiklikSubscriptionFrequency::change($this->api([$this->ok()]), self::UUID, $contents, $this->fingerprint(), $useFrequencyMode, $value);

            $this->assertSame(CiklikSubscriptionFrequency::ERROR_INVALID_CHOICE, $result['error']);
            $this->assertCount(0, $this->history);
        }
    }

    public function testCombinationModeSwitchesEveryLineToItsCombination()
    {
        $calls = [];
        $matcher = function (array $choice, $externalId) use (&$calls) {
            $calls[] = [$choice['frequency_id'], $externalId];

            return ['id_product_attribute' => $externalId + 2, 'interval' => 'month', 'interval_count' => 3];
        };

        $result = CiklikSubscriptionFrequency::change($this->api([$this->ok()]), self::UUID, $this->combinationContents(), $this->fingerprint(), false, '103', $matcher);

        $this->assertTrue($result['success']);
        $this->assertSame([['23', 101], ['23', 201]], $calls);
        $this->assertSame(['content' => [
            ['external_id' => 103, 'quantity' => 2, 'interval' => 'month', 'interval_count' => 3],
            ['external_id' => 203, 'quantity' => 1, 'interval' => 'month', 'interval_count' => 3],
        ]], $this->sentBody());
    }

    public function testCombinationModeRefusesWhenALineHasNoCombinationAtTheNewFrequency()
    {
        // Envoyé sans la seconde ligne, le contenu retirerait ce produit de l'abonnement
        $matcher = function (array $choice, $externalId) {
            return 101 === $externalId ? ['id_product_attribute' => 103, 'interval' => 'month', 'interval_count' => 3] : null;
        };

        $result = CiklikSubscriptionFrequency::change($this->api([$this->ok()]), self::UUID, $this->combinationContents(), $this->fingerprint(), false, '103', $matcher);

        $this->assertFalse($result['success']);
        $this->assertSame(CiklikSubscriptionFrequency::ERROR_NO_MATCH, $result['error']);
        $this->assertCount(0, $this->history);
    }

    public function testCombinationModeRefusesACustomizedLine()
    {
        // L'identifiant d'une ligne personnalisée a perdu sa personnalisation
        $contents = $this->combinationContents();
        $contents[1]['is_customization'] = true;
        $matcher = function (array $choice, $externalId) {
            return ['id_product_attribute' => $externalId + 2, 'interval' => 'month', 'interval_count' => 3];
        };

        $result = CiklikSubscriptionFrequency::change($this->api([$this->ok()]), self::UUID, $contents, $this->fingerprint(), false, '103', $matcher);

        $this->assertSame(CiklikSubscriptionFrequency::ERROR_NO_MATCH, $result['error']);
        $this->assertCount(0, $this->history);
    }

    public function testCiklikRefusalOrServerErrorIsNotASuccess()
    {
        $refusal = new Response(422, [], json_encode(['message' => 'Invalid', 'errors' => ['interval' => ['Unité inconnue']]]));
        $result = CiklikSubscriptionFrequency::change($this->api([$refusal]), self::UUID, $this->frequencyContents(), $this->fingerprint(), true, '2');

        $this->assertSame(CiklikSubscriptionFrequency::ERROR_API, $result['error']);
        $this->assertSame(['interval' => ['Unité inconnue']], $result['response']['errors']);

        // Réponse non JSON d'un serveur en erreur
        $result = CiklikSubscriptionFrequency::change($this->api([new Response(502, [], 'Bad gateway')]), self::UUID, $this->frequencyContents(), $this->fingerprint(), true, '2');

        $this->assertFalse($result['success']);
        $this->assertSame(CiklikSubscriptionFrequency::ERROR_API, $result['error']);
    }
}

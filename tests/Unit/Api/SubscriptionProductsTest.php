<?php

/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 *
 * Tests unitaires pour la gestion des produits d'abonnement.
 * Vérifie la conformité avec l'OpenAPI spec (api/openapi.yaml) :
 *   - PATCH /subscriptions/{id}/products/{external_id}
 *   - POST  /subscriptions/{id}/products
 *   - DELETE /subscriptions/{id}/products/{external_id}
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Api;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Api\Subscription;

if (!defined('_PS_VERSION_')) {
    exit;
}

class SubscriptionProductsTest extends TestCase
{
    /** UUID v4 valide pour les tests */
    private const VALID_UUID = '550e8400-e29b-41d4-a716-446655440000';

    /** external_id standard (id_product:id_product_attribute) */
    private const VALID_EXTERNAL_ID = '123:456';

    /**
     * Crée une instance Subscription avec un mock Guzzle
     *
     * @param Response[] $responses Réponses à retourner dans l'ordre
     * @param array $history Historique des requêtes (passé par référence)
     *
     * @return Subscription
     */
    private function createSubscription(array $responses, array &$history = [])
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        $client = new Client([
            'handler' => $stack,
            'base_uri' => 'https://mock.test/api/v3/',
            'http_errors' => false,
        ]);

        return new Subscription(new \Link(), $client);
    }

    /**
     * Réponse API réussie (200 ou 201)
     */
    private function successResponse(int $statusCode = 200, array $data = [])
    {
        return new Response($statusCode, [], json_encode([
            'data' => array_merge(['uuid' => self::VALID_UUID, 'active' => true], $data),
        ]));
    }

    /**
     * Réponse API en erreur (422, 404, etc.)
     */
    private function errorResponse(int $statusCode = 422, string $message = 'Validation error', array $errors = [])
    {
        return new Response($statusCode, [], json_encode([
            'message' => $message,
            'errors' => $errors,
        ]));
    }

    // =========================================================================
    // PATCH /subscriptions/{id}/products/{external_id} — updateProductQuantity
    // =========================================================================

    /**
     * Vérifie que la méthode HTTP est PATCH (OpenAPI: operationId updateSubscriptionProduct)
     */
    public function testUpdateProductQuantitySendsPatchMethod()
    {
        $history = [];
        $api = $this->createSubscription([$this->successResponse()], $history);

        $api->updateProductQuantity(self::VALID_UUID, self::VALID_EXTERNAL_ID, 3);

        $this->assertEquals('PATCH', $history[0]['request']->getMethod());
    }

    /**
     * Vérifie la route : /subscriptions/{uuid}/products/{external_id}
     * L'external_id est URL-encodé (le ":" devient "%3A") pour éviter les
     * problèmes de parse_url qui interprète "123:456" comme host:port
     */
    public function testUpdateProductQuantityUsesCorrectRoute()
    {
        $history = [];
        $api = $this->createSubscription([$this->successResponse()], $history);

        $api->updateProductQuantity(self::VALID_UUID, '123:456', 3);

        $path = $history[0]['request']->getUri()->getPath();
        $this->assertEquals(
            '/api/v3/subscriptions/' . self::VALID_UUID . '/products/123%3A456',
            $path
        );
    }

    /**
     * Vérifie que le body contient la quantité demandée
     */
    public function testUpdateProductQuantitySendsQuantityInBody()
    {
        $history = [];
        $api = $this->createSubscription([$this->successResponse()], $history);

        $api->updateProductQuantity(self::VALID_UUID, self::VALID_EXTERNAL_ID, 5);

        $body = json_decode($history[0]['request']->getBody()->getContents(), true);
        $this->assertEquals(5, $body['quantity']);
    }

    /**
     * OpenAPI : le body PATCH ne contient QUE le champ "quantity" (required: [quantity])
     */
    public function testUpdateProductQuantityBodyContainsOnlyQuantity()
    {
        $history = [];
        $api = $this->createSubscription([$this->successResponse()], $history);

        $api->updateProductQuantity(self::VALID_UUID, self::VALID_EXTERNAL_ID, 3);

        $body = json_decode($history[0]['request']->getBody()->getContents(), true);
        $this->assertEquals(['quantity'], array_keys($body));
    }

    /**
     * OpenAPI : quantity minimum = 1
     */
    public function testUpdateProductQuantityAcceptsMinimumQuantity()
    {
        $history = [];
        $api = $this->createSubscription([$this->successResponse()], $history);

        $result = $api->updateProductQuantity(self::VALID_UUID, self::VALID_EXTERNAL_ID, 1);

        $this->assertTrue($result['status']);
        $body = json_decode($history[0]['request']->getBody()->getContents(), true);
        $this->assertEquals(1, $body['quantity']);
    }

    /**
     * OpenAPI : quantity maximum = 9999
     */
    public function testUpdateProductQuantityAcceptsMaximumQuantity()
    {
        $history = [];
        $api = $this->createSubscription([$this->successResponse()], $history);

        $result = $api->updateProductQuantity(self::VALID_UUID, self::VALID_EXTERNAL_ID, 9999);

        $this->assertTrue($result['status']);
        $body = json_decode($history[0]['request']->getBody()->getContents(), true);
        $this->assertEquals(9999, $body['quantity']);
    }

    /**
     * OpenAPI : retourne 200 avec SubscriptionEntity en cas de succès
     */
    public function testUpdateProductQuantityReturnsSuccessOn200()
    {
        $api = $this->createSubscription([$this->successResponse(200)]);

        $result = $api->updateProductQuantity(self::VALID_UUID, self::VALID_EXTERNAL_ID, 3);

        $this->assertTrue($result['status']);
        $this->assertEquals(200, $result['httpCode']);
    }

    /**
     * OpenAPI : retourne 422 pour quantité invalide / produit personnalisé / abo inactif
     */
    public function testUpdateProductQuantityReturnsErrorOn422()
    {
        $api = $this->createSubscription([
            $this->errorResponse(422, 'Validation error', [
                'quantity' => ['The quantity must be between 1 and 9999.'],
            ]),
        ]);

        $result = $api->updateProductQuantity(self::VALID_UUID, self::VALID_EXTERNAL_ID, 0);

        $this->assertFalse($result['status']);
        $this->assertEquals(422, $result['httpCode']);
        $this->assertNotEmpty($result['errors']);
    }

    // =========================================================================
    // POST /subscriptions/{id}/products — addProduct
    // =========================================================================

    /**
     * Vérifie que la méthode HTTP est POST (OpenAPI: operationId addSubscriptionProduct)
     */
    public function testAddProductSendsPostMethod()
    {
        $history = [];
        $api = $this->createSubscription([$this->successResponse(201)], $history);

        $api->addProduct(self::VALID_UUID, [
            'external_id' => '42:0',
            'name' => 'Produit test',
            'quantity' => 1,
            'tax' => 0.2,
        ]);

        $this->assertEquals('POST', $history[0]['request']->getMethod());
    }

    /**
     * Vérifie la route : /subscriptions/{uuid}/products (sans external_id dans le path)
     */
    public function testAddProductUsesCorrectRoute()
    {
        $history = [];
        $api = $this->createSubscription([$this->successResponse(201)], $history);

        $api->addProduct(self::VALID_UUID, ['external_id' => '42:0', 'name' => 'Test']);

        $path = $history[0]['request']->getUri()->getPath();
        $this->assertEquals('/api/v3/subscriptions/' . self::VALID_UUID . '/products', $path);
    }

    /**
     * OpenAPI : la route POST n'inclut PAS l'external_id dans le path (il est dans le body)
     */
    public function testAddProductRouteDoesNotContainExternalId()
    {
        $history = [];
        $api = $this->createSubscription([$this->successResponse(201)], $history);

        $api->addProduct(self::VALID_UUID, ['external_id' => '999:888', 'name' => 'Test']);

        $path = $history[0]['request']->getUri()->getPath();
        $this->assertStringNotContainsString('999:888', $path);
    }

    /**
     * Vérifie que tous les champs sont transmis dans le body JSON
     */
    public function testAddProductSendsAllFieldsInBody()
    {
        $history = [];
        $api = $this->createSubscription([$this->successResponse(201)], $history);

        $data = [
            'external_id' => '42:108',
            'name' => 'Produit mensuel',
            'quantity' => 2,
            'tax' => 0.2,
        ];

        $api->addProduct(self::VALID_UUID, $data);

        $body = json_decode($history[0]['request']->getBody()->getContents(), true);
        $this->assertEquals($data, $body);
    }

    /**
     * OpenAPI : les champs requis (external_id, name) sont transmis
     */
    public function testAddProductSendsRequiredFields()
    {
        $history = [];
        $api = $this->createSubscription([$this->successResponse(201)], $history);

        $api->addProduct(self::VALID_UUID, [
            'external_id' => '42:0',
            'name' => 'Produit requis',
        ]);

        $body = json_decode($history[0]['request']->getBody()->getContents(), true);
        $this->assertArrayHasKey('external_id', $body);
        $this->assertArrayHasKey('name', $body);
    }

    /**
     * OpenAPI : 201 pour un nouvel ajout de produit
     */
    public function testAddProductHandles201Created()
    {
        $api = $this->createSubscription([$this->successResponse(201)]);

        $result = $api->addProduct(self::VALID_UUID, ['external_id' => '1:0', 'name' => 'Nouveau']);

        $this->assertTrue($result['status']);
        $this->assertEquals(201, $result['httpCode']);
    }

    /**
     * OpenAPI : 200 pour un upsert (produit déjà rattaché → mise à jour quantité)
     */
    public function testAddProductHandles200Upsert()
    {
        $api = $this->createSubscription([$this->successResponse(200)]);

        $result = $api->addProduct(self::VALID_UUID, ['external_id' => '1:0', 'name' => 'Existant']);

        $this->assertTrue($result['status']);
        $this->assertEquals(200, $result['httpCode']);
    }

    /**
     * OpenAPI : 422 pour champs manquants, produit personnalisé, abo inactif
     */
    public function testAddProductReturnsErrorOn422()
    {
        $api = $this->createSubscription([
            $this->errorResponse(422, 'Validation error', [
                'name' => ['The name field is required.'],
            ]),
        ]);

        $result = $api->addProduct(self::VALID_UUID, ['external_id' => '1:0']);

        $this->assertFalse($result['status']);
        $this->assertEquals(422, $result['httpCode']);
    }

    // =========================================================================
    // DELETE /subscriptions/{id}/products/{external_id} — removeProduct
    // =========================================================================

    /**
     * Vérifie que la méthode HTTP est DELETE (OpenAPI: operationId removeSubscriptionProduct)
     */
    public function testRemoveProductSendsDeleteMethod()
    {
        $history = [];
        $api = $this->createSubscription([$this->successResponse()], $history);

        $api->removeProduct(self::VALID_UUID, self::VALID_EXTERNAL_ID);

        $this->assertEquals('DELETE', $history[0]['request']->getMethod());
    }

    /**
     * Vérifie la route : /subscriptions/{uuid}/products/{external_id} (URL-encodé)
     */
    public function testRemoveProductUsesCorrectRoute()
    {
        $history = [];
        $api = $this->createSubscription([$this->successResponse()], $history);

        $api->removeProduct(self::VALID_UUID, '42:108');

        $path = $history[0]['request']->getUri()->getPath();
        $this->assertEquals(
            '/api/v3/subscriptions/' . self::VALID_UUID . '/products/42%3A108',
            $path
        );
    }

    /**
     * OpenAPI : la requête DELETE n'a pas de body
     */
    public function testRemoveProductSendsNoBody()
    {
        $history = [];
        $api = $this->createSubscription([$this->successResponse()], $history);

        $api->removeProduct(self::VALID_UUID, self::VALID_EXTERNAL_ID);

        $body = $history[0]['request']->getBody()->getContents();
        $this->assertEmpty($body);
    }

    /**
     * OpenAPI : retourne 200 avec SubscriptionEntity en cas de succès
     */
    public function testRemoveProductReturnsSuccessOn200()
    {
        $api = $this->createSubscription([$this->successResponse(200)]);

        $result = $api->removeProduct(self::VALID_UUID, self::VALID_EXTERNAL_ID);

        $this->assertTrue($result['status']);
        $this->assertEquals(200, $result['httpCode']);
    }

    /**
     * OpenAPI : 422 si dernier produit / produit personnalisé / abo inactif
     */
    public function testRemoveProductReturnsErrorOn422LastProduct()
    {
        $api = $this->createSubscription([
            $this->errorResponse(422, 'Cannot remove last product', [
                'external_id' => ['Cannot remove the last product from a subscription.'],
            ]),
        ]);

        $result = $api->removeProduct(self::VALID_UUID, self::VALID_EXTERNAL_ID);

        $this->assertFalse($result['status']);
        $this->assertEquals(422, $result['httpCode']);
    }

    // =========================================================================
    // Validation UUID — commune aux 3 méthodes
    // =========================================================================

    public function testUpdateProductQuantityRejectsInvalidUuid()
    {
        $api = $this->createSubscription([]);

        $result = $api->updateProductQuantity('not-a-uuid-v4', self::VALID_EXTERNAL_ID, 3);

        $this->assertFalse($result['status']);
        $this->assertContains('Invalid subscription UUID format', $result['errors']);
    }

    public function testAddProductRejectsInvalidUuid()
    {
        $api = $this->createSubscription([]);

        $result = $api->addProduct('invalid', ['external_id' => '1:0', 'name' => 'Test']);

        $this->assertFalse($result['status']);
        $this->assertContains('Invalid subscription UUID format', $result['errors']);
    }

    public function testRemoveProductRejectsInvalidUuid()
    {
        $api = $this->createSubscription([]);

        $result = $api->removeProduct('bad-uuid', self::VALID_EXTERNAL_ID);

        $this->assertFalse($result['status']);
        $this->assertContains('Invalid subscription UUID format', $result['errors']);
    }

    /**
     * Aucune requête HTTP ne doit partir si le UUID est invalide
     */
    public function testNoHttpRequestOnInvalidUuid()
    {
        $history = [];
        $api = $this->createSubscription([$this->successResponse()], $history);

        $api->updateProductQuantity('invalid', self::VALID_EXTERNAL_ID, 1);

        $this->assertCount(0, $history);
    }

    // =========================================================================
    // Validation external_id — formats invalides (data provider)
    // =========================================================================

    /**
     * @dataProvider invalidExternalIdProvider
     */
    public function testRejectsInvalidExternalIdFormats(string $externalId)
    {
        $api = $this->createSubscription([]);

        $result = $api->updateProductQuantity(self::VALID_UUID, $externalId, 1);

        $this->assertFalse($result['status']);
        $this->assertContains('Invalid product identifier format', $result['errors']);
    }

    public function invalidExternalIdProvider(): array
    {
        return [
            'vide' => [''],
            'sans colon' => ['123456'],
            'lettres' => ['abc:def'],
            'injection SQL' => ['1:1; DROP TABLE'],
            'path traversal' => ['../../../etc:passwd'],
            'hash trop court (31 chars)' => ['1:2_' . str_repeat('a', 31)],
            'hash trop long (33 chars)' => ['1:2_' . str_repeat('a', 33)],
            'double colon' => ['1:2:3'],
            'colon seul' => [':'],
            'espaces' => ['1 : 2'],
            'underscore sans hash' => ['1:2_'],
            'slash' => ['1:2/3'],
        ];
    }

    /**
     * Aucune requête HTTP ne doit partir si l'external_id est invalide
     */
    public function testNoHttpRequestOnInvalidExternalId()
    {
        $history = [];
        $api = $this->createSubscription([$this->successResponse()], $history);

        $api->updateProductQuantity(self::VALID_UUID, 'invalid', 1);

        $this->assertCount(0, $history);
    }

    /**
     * La même validation s'applique sur removeProduct
     */
    public function testRemoveProductRejectsInvalidExternalId()
    {
        $api = $this->createSubscription([]);

        $result = $api->removeProduct(self::VALID_UUID, '../../etc:passwd');

        $this->assertFalse($result['status']);
        $this->assertContains('Invalid product identifier format', $result['errors']);
    }

    // =========================================================================
    // Validation external_id — formats valides (data provider)
    // =========================================================================

    /**
     * @dataProvider validExternalIdProvider
     */
    public function testAcceptsValidExternalIdFormats(string $externalId)
    {
        $history = [];
        $api = $this->createSubscription([$this->successResponse()], $history);

        $result = $api->updateProductQuantity(self::VALID_UUID, $externalId, 1);

        $this->assertTrue($result['status']);
        $this->assertCount(1, $history);
    }

    public function validExternalIdProvider(): array
    {
        return [
            'standard' => ['123:456'],
            'produit simple (attr 0)' => ['42:0'],
            'grands IDs' => ['99999:88888'],
            'hash MD5 lowercase' => ['1:2_' . str_repeat('a', 32)],
            'hash MD5 uppercase' => ['1:2_' . str_repeat('A', 32)],
            'hash MD5 mixte' => ['123:456_aAbBcCdD1234567890eEfF1234567890'],
            'hash MD5 réaliste' => ['9669:134853_e4e9ce5b2c8e9b5f7d3a1c4b6f8e2d0a'],
        ];
    }

    // =========================================================================
    // Conformité OpenAPI — structure des réponses
    // =========================================================================

    /**
     * OpenAPI : la réponse contient body (= data de SubscriptionEntity)
     */
    public function testSuccessResponseContainsBody()
    {
        $api = $this->createSubscription([
            $this->successResponse(200, ['uuid' => self::VALID_UUID, 'content' => []]),
        ]);

        $result = $api->updateProductQuantity(self::VALID_UUID, self::VALID_EXTERNAL_ID, 2);

        $this->assertArrayHasKey('body', $result);
        $this->assertArrayHasKey('uuid', $result['body']);
    }

    /**
     * OpenAPI : la réponse d'erreur contient le champ errors
     */
    public function testErrorResponseContainsErrors()
    {
        $api = $this->createSubscription([
            $this->errorResponse(422, 'Error', ['quantity' => ['Invalid']]),
        ]);

        $result = $api->removeProduct(self::VALID_UUID, self::VALID_EXTERNAL_ID);

        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('quantity', $result['errors']);
    }

    /**
     * OpenAPI : les 3 méthodes retournent un format de réponse cohérent
     */
    public function testAllMethodsReturnStandardResponseFormat()
    {
        $expectedKeys = ['status', 'httpCode', 'body', 'meta', 'links', 'message', 'errors'];

        // updateProductQuantity
        $api = $this->createSubscription([$this->successResponse()]);
        $result = $api->updateProductQuantity(self::VALID_UUID, self::VALID_EXTERNAL_ID, 1);
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $result, "updateProductQuantity: clé '$key' manquante");
        }

        // addProduct
        $api = $this->createSubscription([$this->successResponse(201)]);
        $result = $api->addProduct(self::VALID_UUID, ['external_id' => '1:0', 'name' => 'T']);
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $result, "addProduct: clé '$key' manquante");
        }

        // removeProduct
        $api = $this->createSubscription([$this->successResponse()]);
        $result = $api->removeProduct(self::VALID_UUID, self::VALID_EXTERNAL_ID);
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $result, "removeProduct: clé '$key' manquante");
        }
    }

    /**
     * Les réponses d'erreur de validation (sans appel HTTP) suivent le même format
     */
    public function testValidationErrorsReturnStandardFormat()
    {
        $api = $this->createSubscription([]);
        $result = $api->updateProductQuantity('invalid', self::VALID_EXTERNAL_ID, 1);

        $expectedKeys = ['status', 'httpCode', 'body', 'meta', 'links', 'message', 'errors'];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $result, "Validation error: clé '$key' manquante");
        }
        $this->assertFalse($result['status']);
        $this->assertEquals(400, $result['httpCode']);
    }
}

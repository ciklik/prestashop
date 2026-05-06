<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Helpers\ProductPriceResolver;

if (!defined('_PS_VERSION_')) {
    exit;
}

class ProductPriceResolverTest extends TestCase
{
    // =========================================================================
    // Tests normalizeMode
    // =========================================================================

    public function testNormalizeModeReturnsGrossForGross()
    {
        $this->assertEquals(
            ProductPriceResolver::MODE_GROSS,
            ProductPriceResolver::normalizeMode('gross')
        );
    }

    public function testNormalizeModeReturnsNetForNet()
    {
        $this->assertEquals(
            ProductPriceResolver::MODE_NET,
            ProductPriceResolver::normalizeMode('net')
        );
    }

    public function testNormalizeModeFallsBackToNetForEmpty()
    {
        $this->assertEquals(
            ProductPriceResolver::MODE_NET,
            ProductPriceResolver::normalizeMode('')
        );
    }

    public function testNormalizeModeFallsBackToNetForNull()
    {
        $this->assertEquals(
            ProductPriceResolver::MODE_NET,
            ProductPriceResolver::normalizeMode(null)
        );
    }

    public function testNormalizeModeFallsBackToNetForUnknownValue()
    {
        $this->assertEquals(
            ProductPriceResolver::MODE_NET,
            ProductPriceResolver::normalizeMode('whatever')
        );
    }

    /**
     * Conversion des anciennes valeurs legacy : tout ce qui n'est pas
     * exactement "gross" doit fallback en net (comportement historique)
     */
    public function testNormalizeModeRejectsCaseVariations()
    {
        $this->assertEquals(
            ProductPriceResolver::MODE_NET,
            ProductPriceResolver::normalizeMode('GROSS')
        );
        $this->assertEquals(
            ProductPriceResolver::MODE_NET,
            ProductPriceResolver::normalizeMode('Gross')
        );
    }

    // =========================================================================
    // Tests buildCombinationPricesMap
    // =========================================================================

    /**
     * Cas de base : produit sans combinaison → seule l'entrée 0 est présente
     */
    public function testBuildMapWithNoCombinations()
    {
        $fetcherCalled = 0;
        $fetcher = function ($idPa) use (&$fetcherCalled) {
            ++$fetcherCalled;

            return 0.0;
        };

        $result = ProductPriceResolver::buildCombinationPricesMap(149.94, [], $fetcher);

        $this->assertEquals([0 => 149.94], $result);
        $this->assertEquals(0, $fetcherCalled, 'Fetcher ne doit pas être appelé sans combinaisons');
    }

    /**
     * Cas standard : 3 combinaisons, chacune avec son prix propre
     */
    public function testBuildMapWithThreeCombinations()
    {
        $prices = [7 => 159.50, 8 => 169.00, 12 => 200.00];
        $fetcher = function ($idPa) use ($prices) {
            return $prices[$idPa];
        };

        $result = ProductPriceResolver::buildCombinationPricesMap(
            149.94,
            [7, 8, 12],
            $fetcher
        );

        $this->assertEquals([
            0 => 149.94,
            7 => 159.50,
            8 => 169.00,
            12 => 200.00,
        ], $result);
    }

    /**
     * IDs séquentiels : le fetcher doit quand même être appelé pour chacun
     * (pas d'optimisation trompeuse basée sur la séquence)
     */
    public function testBuildMapWithSequentialIds()
    {
        $calls = [];
        $fetcher = function ($idPa) use (&$calls) {
            $calls[] = $idPa;

            return 100.0 + $idPa;
        };

        $result = ProductPriceResolver::buildCombinationPricesMap(99.99, [1, 2, 3], $fetcher);

        $this->assertEquals([0 => 99.99, 1 => 101.0, 2 => 102.0, 3 => 103.0], $result);
        $this->assertEquals([1, 2, 3], $calls);
    }

    /**
     * Les IDs invalides (0, négatifs, non numériques) sont ignorés
     */
    public function testBuildMapSkipsInvalidIds()
    {
        $fetcher = function ($idPa) {
            return 100.0;
        };

        $result = ProductPriceResolver::buildCombinationPricesMap(
            50.0,
            [0, -1, 'abc', 5],
            $fetcher
        );

        $this->assertArrayHasKey(0, $result);
        $this->assertArrayHasKey(5, $result);
        $this->assertArrayNotHasKey(-1, $result);
        $this->assertEquals(50.0, $result[0]);
        $this->assertEquals(100.0, $result[5]);
    }

    /**
     * Dédoublonnage : le fetcher n'est appelé qu'une seule fois par ID unique
     */
    public function testBuildMapDeduplicatesIds()
    {
        $callCount = 0;
        $fetcher = function ($idPa) use (&$callCount) {
            ++$callCount;

            return 42.0;
        };

        $result = ProductPriceResolver::buildCombinationPricesMap(
            10.0,
            [5, 5, 5, 8, 8],
            $fetcher
        );

        $this->assertEquals(2, $callCount, 'Fetcher doit être appelé une fois par ID unique');
        $this->assertCount(3, $result, '0, 5 et 8 uniquement');
    }

    /**
     * IDs en string numérique : sont cast en int et utilisés
     */
    public function testBuildMapCastsStringNumericIds()
    {
        $fetcher = function ($idPa) {
            return 75.0;
        };

        $result = ProductPriceResolver::buildCombinationPricesMap(
            50.0,
            ['7', '12'],
            $fetcher
        );

        $this->assertEquals([0 => 50.0, 7 => 75.0, 12 => 75.0], $result);
    }

    /**
     * Le prix retourné par le fetcher est cast en float
     */
    public function testBuildMapCastsFetcherReturnToFloat()
    {
        $fetcher = function ($idPa) {
            return '42.5'; // string, simule un retour getPriceStatic parfois string
        };

        $result = ProductPriceResolver::buildCombinationPricesMap(
            50.0,
            [1],
            $fetcher
        );

        $this->assertIsFloat($result[1]);
        $this->assertEquals(42.5, $result[1]);
    }

    /**
     * L'entrée 0 (produit sans combinaison) a toujours le baseProductPrice,
     * même si 0 est présent dans la liste des combinaisons
     */
    public function testBuildMapBaseEntryIsAlwaysBaseProductPrice()
    {
        $fetcher = function ($idPa) {
            return 999.0;
        };

        $result = ProductPriceResolver::buildCombinationPricesMap(
            149.94,
            [0, 1], // 0 devrait être ignoré
            $fetcher
        );

        $this->assertEquals(149.94, $result[0], 'Entrée 0 = baseProductPrice, pas fetcher(0)');
        $this->assertEquals(999.0, $result[1]);
    }

    /**
     * Simulation du mode gross complet : le fetcher ne retourne PAS le prix
     * avec réductions PS. Cas typique : 149.94 → 159.50 pour la L
     */
    public function testBuildMapGrossModeScenario()
    {
        // Le fetcher mock simule Product::getPriceStatic avec $use_reduc = false
        $grossPrices = [
            11 => 159.50, // Taille L
            12 => 169.00, // Taille XL
        ];
        $grossFetcher = function ($idPa) use ($grossPrices) {
            return $grossPrices[$idPa];
        };

        $result = ProductPriceResolver::buildCombinationPricesMap(
            149.94, // Prix brut catalogue
            [11, 12],
            $grossFetcher
        );

        // Aucune règle de prix -5% n'a été appliquée
        $this->assertEquals(149.94, $result[0]);
        $this->assertEquals(159.50, $result[11]);
        $this->assertEquals(169.00, $result[12]);
    }

    /**
     * Simulation du mode net complet : le fetcher retourne le prix avec
     * réductions PS appliquées
     */
    public function testBuildMapNetModeScenario()
    {
        // Le fetcher mock simule Product::getPriceStatic() classique
        // avec la règle de prix -5% appliquée
        $netPrices = [
            11 => 151.52, // 159.50 * 0.95
            12 => 160.55, // 169.00 * 0.95
        ];
        $netFetcher = function ($idPa) use ($netPrices) {
            return $netPrices[$idPa];
        };

        $result = ProductPriceResolver::buildCombinationPricesMap(
            142.44, // Prix catalogue 149.94 - 5%
            [11, 12],
            $netFetcher
        );

        $this->assertEquals(142.44, $result[0]);
        $this->assertEquals(151.52, $result[11]);
        $this->assertEquals(160.55, $result[12]);
    }

    // =========================================================================
    // Tests encodeCombinationPricesJson
    // =========================================================================

    /**
     * Map non séquentielle : json_encode produit naturellement un objet
     */
    public function testEncodeJsonNonSequentialKeys()
    {
        $json = ProductPriceResolver::encodeCombinationPricesJson([
            0 => 149.94,
            7 => 159.50,
            12 => 200.00,
        ]);

        $this->assertEquals('{"0":149.94,"7":159.5,"12":200}', $json);

        // Vérifie que c'est bien un objet JS et pas un array
        $decoded = json_decode($json);
        $this->assertIsObject($decoded);
    }

    /**
     * Map séquentielle : json_encode produirait un array, JSON_FORCE_OBJECT
     * force un objet. C'est le cas critique qui justifie l'utilisation de
     * JSON_FORCE_OBJECT — sans ça, le JS ferait prices[2] au lieu de
     * prices[idPa] et l'accès serait cassé.
     */
    public function testEncodeJsonSequentialKeysAreForcedToObject()
    {
        $json = ProductPriceResolver::encodeCombinationPricesJson([
            0 => 149.94,
            1 => 159.50,
            2 => 200.00,
        ]);

        $this->assertEquals('{"0":149.94,"1":159.5,"2":200}', $json);

        // Crucial : c'est un objet, pas un array
        $decoded = json_decode($json);
        $this->assertIsObject($decoded);
        $this->assertFalse(is_array($decoded));
    }

    /**
     * Map vide : retourne un objet vide, pas un array vide
     */
    public function testEncodeJsonEmptyMap()
    {
        $json = ProductPriceResolver::encodeCombinationPricesJson([]);

        $this->assertEquals('{}', $json);
    }

    /**
     * Map avec une seule entrée (produit sans combinaison)
     */
    public function testEncodeJsonSingleEntry()
    {
        $json = ProductPriceResolver::encodeCombinationPricesJson([0 => 149.94]);

        $this->assertEquals('{"0":149.94}', $json);

        $decoded = json_decode($json);
        $this->assertIsObject($decoded);
    }

    /**
     * Le JSON peut être parsé et relu par un code JS équivalent : on vérifie
     * qu'on peut accéder par clé d'id de combinaison
     */
    public function testEncodeJsonCanBeAccessedByKey()
    {
        $json = ProductPriceResolver::encodeCombinationPricesJson([
            0 => 149.94,
            42 => 200.00,
        ]);

        $decoded = json_decode($json, true);

        // C'est un array associatif en PHP (equivalent à un objet JS)
        $this->assertEquals(200.00, $decoded[42]);
        $this->assertEquals(149.94, $decoded[0]);
    }

    /**
     * Les prix préservent leur précision dans le JSON
     */
    public function testEncodeJsonPreservesPricePrecision()
    {
        $json = ProductPriceResolver::encodeCombinationPricesJson([
            0 => 149.94,
            1 => 142.443, // 3 décimales
        ]);

        $decoded = json_decode($json, true);
        $this->assertEquals(149.94, $decoded[0]);
        $this->assertEquals(142.443, $decoded[1]);
    }

    // =========================================================================
    // Tests scénario d'intégration (build + encode)
    // =========================================================================

    /**
     * Scénario complet : produit 149,94 € avec 3 combinaisons en mode gross
     * → JSON attendu pour le JS
     */
    public function testFullScenarioGrossMode()
    {
        $mode = ProductPriceResolver::normalizeMode('gross');
        $this->assertEquals(ProductPriceResolver::MODE_GROSS, $mode);

        $fetcher = function ($idPa) {
            $prices = [10 => 149.94, 11 => 159.50, 12 => 169.00];

            return $prices[$idPa];
        };

        $map = ProductPriceResolver::buildCombinationPricesMap(
            149.94,
            [10, 11, 12],
            $fetcher
        );

        $json = ProductPriceResolver::encodeCombinationPricesJson($map);

        // Format exact attendu par le JS (objet, pas array)
        $this->assertEquals(
            '{"0":149.94,"10":149.94,"11":159.5,"12":169}',
            $json
        );
    }

    /**
     * Scénario complet : produit 142,44 € (après règle -5%) avec 3
     * combinaisons en mode net
     */
    public function testFullScenarioNetMode()
    {
        $mode = ProductPriceResolver::normalizeMode('net');
        $this->assertEquals(ProductPriceResolver::MODE_NET, $mode);

        $fetcher = function ($idPa) {
            $netPrices = [10 => 142.44, 11 => 151.52, 12 => 160.55];

            return $netPrices[$idPa];
        };

        $map = ProductPriceResolver::buildCombinationPricesMap(
            142.44,
            [10, 11, 12],
            $fetcher
        );

        $json = ProductPriceResolver::encodeCombinationPricesJson($map);

        $this->assertEquals(
            '{"0":142.44,"10":142.44,"11":151.52,"12":160.55}',
            $json
        );
    }
}

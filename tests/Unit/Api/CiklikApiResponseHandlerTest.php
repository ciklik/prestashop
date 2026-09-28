<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Api\CiklikApiResponseHandler;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Message d'erreur montré au client quand l'API refuse une modification
 * (alert() de Mes abonnements)
 */
class CiklikApiResponseHandlerTest extends TestCase
{
    private const FALLBACK = 'Une erreur est survenue.';

    /**
     * Validation de l'API : erreurs rangées par champ, premier message du premier champ
     */
    public function testFieldErrorsGiveFirstMessageOfFirstField()
    {
        $response = [
            'status' => false,
            'errors' => [
                'product' => ['Impossible de retirer le dernier produit.', 'Second message'],
                'quantity' => ['Autre champ'],
            ],
        ];

        $this->assertSame(
            'Impossible de retirer le dernier produit.',
            CiklikApiResponseHandler::customerErrorMessage($response, self::FALLBACK)
        );
    }

    /**
     * Texte de l'API échappé en HTML, comme les autres messages de l'API affichés au client
     */
    public function testApiMessageIsHtmlEscaped()
    {
        $response = ['errors' => ['product' => ["<b>Ce produit</b> n'est pas rattaché"]]];

        $this->assertSame(
            '&lt;b&gt;Ce produit&lt;/b&gt; n&#039;est pas rattach&eacute;',
            CiklikApiResponseHandler::customerErrorMessage($response, self::FALLBACK)
        );
    }

    /**
     * Réponse sans message exploitable : repli sur le message générique
     *
     * @dataProvider responsesWithoutMessageProvider
     */
    public function testResponseWithoutMessageFallsBack($response)
    {
        $this->assertSame(
            self::FALLBACK,
            CiklikApiResponseHandler::customerErrorMessage($response, self::FALLBACK)
        );
    }

    public function responsesWithoutMessageProvider(): array
    {
        return [
            'errors vide (404, 500 sans détail)' => [['status' => false, 'errors' => []]],
            'sans clé errors' => [['status' => false]],
            'champ sans message' => [['errors' => ['product' => []]]],
            'messages vides' => [['errors' => ['product' => ['  '], 'quantity' => ['']]]],
            'erreur réseau du client (texte technique)' => [['errors' => ['message' => 'cURL error 28: Operation timed out']]],
            'errors non tableau' => [['errors' => 'Server Error']],
            'réponse non tableau' => [null],
            'UTF-8 invalide' => [['errors' => ['product' => ["\xC3\x28"]]]],
        ];
    }

    /**
     * Formes déjà lues avant : liste de messages (buildErrorResponse) et liste de listes
     */
    public function testListShapesStillGiveFirstMessage()
    {
        $this->assertSame(
            'Invalid subscription UUID format',
            CiklikApiResponseHandler::firstErrorMessage(['Invalid subscription UUID format'])
        );
        $this->assertSame('Premier', CiklikApiResponseHandler::firstErrorMessage([['Premier', 'Second']]));
    }

    /**
     * Refus connus de l'API (champ product, texte français) reconnus
     */
    public function testKnownProductRefusalsAreRecognized()
    {
        $cases = [
            'not_attached' => "Ce produit n'est pas rattaché à cet abonnement.",
            'last_product' => "Impossible de retirer le dernier produit d'un abonnement.",
            'unknown_product' => "Le produit n'existe pas \u{2014} name et price sont requis pour le créer.",
            'other_tenant' => "Ce produit n'appartient pas au même tenant que l'abonnement.",
        ];

        foreach ($cases as $key => $message) {
            $this->assertSame($key, CiklikApiResponseHandler::knownProductRefusal(['errors' => ['product' => [$message]]]), $key);
        }

        // Autre champ, autre texte, forme inattendue : aucun refus connu
        $this->assertNull(CiklikApiResponseHandler::knownProductRefusal(['errors' => ['quantity' => [$cases['last_product']]]]));
        $this->assertNull(CiklikApiResponseHandler::knownProductRefusal(['errors' => ['product' => ['Autre refus.']]]));
        $this->assertNull(CiklikApiResponseHandler::knownProductRefusal(['errors' => [$cases['last_product']]]));
        $this->assertNull(CiklikApiResponseHandler::knownProductRefusal(['errors' => 'Server Error']));
        $this->assertNull(CiklikApiResponseHandler::knownProductRefusal(null));
    }

    /**
     * Refus connu : traduction du module, quelle que soit la langue du
     * client ; refus inconnu : texte de l'API pour un client francophone,
     * message générique traduit sinon
     */
    public function testKnownRefusalTranslatedAndApiTextOnlyForFrenchCustomers()
    {
        $translations = ['last_product' => 'The last product of a subscription cannot be removed.'];
        $known = ['errors' => ['product' => ["Impossible de retirer le dernier produit d'un abonnement."]]];
        $unknown = ['errors' => ['product' => ['Refus inattendu de la plateforme.']]];

        $this->assertSame($translations['last_product'], CiklikApiResponseHandler::customerErrorMessage($known, self::FALLBACK, $translations, false));
        $this->assertSame($translations['last_product'], CiklikApiResponseHandler::customerErrorMessage($known, self::FALLBACK, $translations, true));

        $this->assertSame(self::FALLBACK, CiklikApiResponseHandler::customerErrorMessage($unknown, self::FALLBACK, $translations, false));
        $this->assertSame('Refus inattendu de la plateforme.', CiklikApiResponseHandler::customerErrorMessage($unknown, self::FALLBACK, $translations, true));

        // Refus connu sans traduction fournie : règle générale
        $this->assertSame(self::FALLBACK, CiklikApiResponseHandler::customerErrorMessage($known, self::FALLBACK, [], false));
    }

    /**
     * Réponse non JSON de l'API (429 en texte brut, page HTML d'un proxy,
     * JSON scalaire) : refus propre, sans TypeError, message de repli
     *
     * @dataProvider nonJsonResponsesProvider
     */
    public function testNonJsonResponseIsHandledWithoutTypeError($statusCode, $body)
    {
        $result = (new CiklikApiResponseHandler())->handleResponse(new \GuzzleHttp\Psr7\Response($statusCode, ['Content-Type' => 'text/plain'], $body));

        $this->assertFalse($result['status']);
        $this->assertSame($statusCode, $result['httpCode']);
        $this->assertSame([], $result['body']);
        $this->assertSame([], $result['errors']);
        $this->assertNull($result['meta']);
        $this->assertSame(self::FALLBACK, CiklikApiResponseHandler::customerErrorMessage($result, self::FALLBACK));
    }

    public function nonJsonResponsesProvider(): array
    {
        return [
            '429 en texte brut' => [429, 'Too Many Attempts.'],
            '502 page HTML' => [502, '<html><body>Bad Gateway</body></html>'],
            '200 illisible' => [200, 'OK'],
            '200 JSON scalaire' => [200, 'true'],
            'corps vide' => [500, ''],
        ];
    }

    /**
     * Réponses JSON : lecture inchangée
     */
    public function testJsonResponsesStillParsed()
    {
        $handler = new CiklikApiResponseHandler();

        $ok = $handler->handleResponse(new \GuzzleHttp\Psr7\Response(200, [], json_encode(['data' => ['uuid' => 'x'], 'meta' => ['total' => 1]])));
        $this->assertTrue($ok['status']);
        $this->assertSame(['uuid' => 'x'], $ok['body']);
        $this->assertSame(['total' => 1], $ok['meta']);

        $refused = $handler->handleResponse(new \GuzzleHttp\Psr7\Response(422, [], json_encode(['message' => 'x', 'errors' => ['product' => ['Refus.']]])));
        $this->assertFalse($refused['status']);
        $this->assertSame(['product' => ['Refus.']], $refused['errors']);

        $this->assertTrue($handler->handleResponse(new \GuzzleHttp\Psr7\Response(204))['status']);
    }

    /**
     * Premier champ sans message exploitable : on passe au suivant
     */
    public function testSkipsFieldWithoutUsableMessage()
    {
        $this->assertSame(
            'La quantité doit être entre 1 et 9999.',
            CiklikApiResponseHandler::firstErrorMessage([
                'product' => [''],
                'quantity' => ['La quantité doit être entre 1 et 9999.'],
            ])
        );
    }
}

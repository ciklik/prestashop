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

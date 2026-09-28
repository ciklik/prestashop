<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Managers;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Helpers\RelaySelectionSigner;
use PrestaShop\Module\Ciklik\Managers\CiklikSubscriptionRelay;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Chaîne de confirmation d'un relais côté client (action saverelay) :
 * signature au rendu, décision à la confirmation
 */
class CiklikSubscriptionRelaySelectionTest extends TestCase
{
    private const SECRET = 'cle-serveur-de-test';

    /** Client 42, Mondial Relay, boutique 1 */
    private const CONTEXT = '42|mondialrelay|1';

    private const NOW = 1790000000;

    /**
     * Relais tel que proposé au rendu (résultat de recherche)
     */
    private function relay(string $relayId = '012345', string $country = 'FR'): array
    {
        return [
            'relay_id' => $relayId,
            'name' => 'TABAC DU CENTRE',
            'address1' => '12 RUE DE LA PAIX',
            'zipcode' => '75002',
            'city' => 'PARIS',
            'country_iso' => $country,
            'latitude' => 48.87,
            'longitude' => 2.33,
            'distance' => 850,
        ];
    }

    /**
     * Jeton émis au rendu, comme signRelays() : identifiant et payload
     */
    private function tokenFor(array $relay, string $context = self::CONTEXT, int $issuedAt = self::NOW): string
    {
        return RelaySelectionSigner::sign(
            CiklikSubscriptionRelay::selectionData($relay),
            self::SECRET,
            $context,
            $issuedAt
        );
    }

    private function decide($choice, $data, string $country = 'FR', string $context = self::CONTEXT): array
    {
        return CiklikSubscriptionRelay::decideSelection($choice, $data, 'mondialrelay', $country, self::SECRET, $context, self::NOW + 60);
    }

    /**
     * Cas nominal : le relais signé est enregistrable, payload issu du jeton
     */
    public function testSignedRelayIsAccepted()
    {
        $decision = $this->decide('012345', ['012345' => $this->tokenFor($this->relay())]);

        $this->assertSame(CiklikSubscriptionRelay::SELECTION_OK, $decision['status']);
        $this->assertSame('012345', $decision['relay_id']);
        $this->assertSame([
            'name' => 'TABAC DU CENTRE',
            'address1' => '12 RUE DE LA PAIX',
            'zipcode' => '75002',
            'city' => 'PARIS',
            'country_iso' => 'FR',
        ], $decision['payload']);

        // Plusieurs relais proposés : seul le jeton du choix compte
        $decision = $this->decide(' 012346 ', [
            '012345' => $this->tokenFor($this->relay()),
            '012346' => $this->tokenFor($this->relay('012346')),
        ]);
        $this->assertSame(CiklikSubscriptionRelay::SELECTION_OK, $decision['status']);
        $this->assertSame('012346', $decision['relay_id']);
    }

    /**
     * Jeton absent : aucun jeton, ou pas de jeton pour le relais choisi
     */
    public function testMissingTokenIsRefused()
    {
        foreach ([false, [], ['012346' => $this->tokenFor($this->relay('012346'))], 'chaine'] as $data) {
            $decision = $this->decide('012345', $data);
            $this->assertSame(CiklikSubscriptionRelay::SELECTION_INVALID_TOKEN, $decision['status']);
            $this->assertNull($decision['payload']);
        }
    }

    /**
     * Jeton altéré : signature ou contenu modifiés dans le formulaire
     */
    public function testTamperedTokenIsRefused()
    {
        $token = $this->tokenFor($this->relay());
        list($blob, $signature) = explode('.', $token);

        $forgedRelay = ['relay_id' => '012345', 'name' => 'AUTRE ADRESSE', 'country_iso' => 'FR'];
        $forgedBlob = rtrim(strtr(base64_encode((string) json_encode(['iat' => self::NOW, 'relay' => $forgedRelay])), '+/', '-_'), '=');

        foreach ([
            $blob . '.' . strrev($signature),
            $forgedBlob . '.' . $signature,
            $blob,
            $token . 'x',
        ] as $tampered) {
            $this->assertSame(
                CiklikSubscriptionRelay::SELECTION_INVALID_TOKEN,
                $this->decide('012345', ['012345' => $tampered])['status']
            );
        }
    }

    /**
     * Jeton expiré : émis il y a plus d'une heure, signalé comme tel (la page
     * relais est proposée de nouveau) ; forgé, il reste un jeton refusé
     */
    public function testExpiredTokenIsRefused()
    {
        $token = $this->tokenFor($this->relay(), self::CONTEXT, self::NOW + 60 - RelaySelectionSigner::TTL - 1);

        $decision = $this->decide('012345', ['012345' => $token]);

        $this->assertSame(CiklikSubscriptionRelay::SELECTION_EXPIRED, $decision['status']);
        $this->assertNull($decision['payload']);

        // Encore valide à la dernière seconde
        $token = $this->tokenFor($this->relay(), self::CONTEXT, self::NOW + 60 - RelaySelectionSigner::TTL);
        $this->assertSame(CiklikSubscriptionRelay::SELECTION_OK, $this->decide('012345', ['012345' => $token])['status']);

        // Ancien jeton d'un autre client : refus, pas expiration
        $token = $this->tokenFor($this->relay(), '43|mondialrelay|1', self::NOW - 7200);
        $this->assertSame(CiklikSubscriptionRelay::SELECTION_INVALID_TOKEN, $this->decide('012345', ['012345' => $token])['status']);
    }

    /**
     * Jeton d'un autre client, d'un autre transporteur ou d'une autre boutique
     */
    public function testTokenFromAnotherContextIsRefused()
    {
        foreach (['43|mondialrelay|1', '42|colissimo|1', '42|mondialrelay|2'] as $otherContext) {
            $token = $this->tokenFor($this->relay(), $otherContext);

            $this->assertSame(
                CiklikSubscriptionRelay::SELECTION_INVALID_TOKEN,
                $this->decide('012345', ['012345' => $token])['status'],
                $otherContext
            );
        }
    }

    /**
     * Choix différent du relais porté par le jeton : un jeton valide recopié
     * sous un autre identifiant ne vaut rien
     */
    public function testChoiceMustMatchTheSignedRelay()
    {
        $tokenOf012345 = $this->tokenFor($this->relay('012345'));

        $this->assertSame(
            CiklikSubscriptionRelay::SELECTION_INVALID_TOKEN,
            $this->decide('012399', ['012399' => $tokenOf012345])['status']
        );
    }

    /**
     * Relais d'un autre pays que l'adresse de livraison des rebills : refusé,
     * même correctement signé
     */
    public function testForeignRelayIsNotSelectable()
    {
        $decision = $this->decide('012345', ['012345' => $this->tokenFor($this->relay('012345', 'BE'))], 'FR');

        $this->assertSame(CiklikSubscriptionRelay::SELECTION_NOT_SELECTABLE, $decision['status']);
        $this->assertNull($decision['payload']);

        // Même relais pour une adresse belge : accepté
        $this->assertSame(
            CiklikSubscriptionRelay::SELECTION_OK,
            $this->decide('012345', ['012345' => $this->tokenFor($this->relay('012345', 'BE'))], 'BE')['status']
        );
    }

    /**
     * Aucun choix, ou identifiant invalide pour le transporteur (le jeton
     * n'est alors même pas lu)
     */
    public function testMissingOrInvalidChoice()
    {
        foreach (['', '   ', false, null, ['012345']] as $choice) {
            $this->assertSame(
                CiklikSubscriptionRelay::SELECTION_MISSING,
                $this->decide($choice, ['012345' => $this->tokenFor($this->relay())])['status'],
                var_export($choice, true)
            );
        }

        foreach (['0123456', '01 345', 'ABC$', '../12'] as $choice) {
            $this->assertSame(
                CiklikSubscriptionRelay::SELECTION_INVALID_ID,
                $this->decide($choice, [$choice => $this->tokenFor($this->relay())])['status'],
                var_export($choice, true)
            );
        }
    }
}

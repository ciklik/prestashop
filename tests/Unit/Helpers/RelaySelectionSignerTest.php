<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Helpers\RelaySelectionSigner;

if (!defined('_PS_VERSION_')) {
    exit;
}

class RelaySelectionSignerTest extends TestCase
{
    const SECRET = 'cookie-key-de-test';
    const CONTEXT = '42|mondialrelay|1';
    const NOW = 1700000000;

    private function relay(): array
    {
        return [
            'relay_id' => '012345',
            'name' => 'Tabac "Le Cèdre"',
            'address1' => '12 rue de la Paix',
            'zipcode' => '75002',
            'city' => 'Paris',
            'country_iso' => 'FR',
            'latitude' => 48.87,
        ];
    }

    /**
     * Jeton forge a la main : blob JSON signe avec des donnees arbitraires
     */
    private function forge(string $json, string $signedPrefix): string
    {
        $blob = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        return $blob . '.' . hash_hmac('sha256', $signedPrefix . $blob, self::SECRET);
    }

    /**
     * Un jeton signe puis verifie restitue le relais a l'identique
     */
    public function testSignThenVerifyRoundTrip()
    {
        $token = RelaySelectionSigner::sign($this->relay(), self::SECRET, self::CONTEXT, self::NOW);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+\.[a-f0-9]{64}$/', $token);
        $this->assertSame($this->relay(), RelaySelectionSigner::verify($token, self::SECRET, self::CONTEXT, self::NOW + 10));
    }

    /**
     * Le blob porte l'horodatage d'emission et le relais
     */
    public function testTokenCarriesIssuedAt()
    {
        $token = RelaySelectionSigner::sign($this->relay(), self::SECRET, self::CONTEXT, self::NOW);
        list($blob) = explode('.', $token);
        $data = json_decode(base64_decode(strtr($blob, '-_', '+/')), true);

        $this->assertSame(self::NOW, $data['iat']);
        $this->assertSame($this->relay(), $data['relay']);
    }

    /**
     * Un jeton de plus d'une heure est refuse ; a une heure pile il passe encore
     */
    public function testExpiredTokenIsRejected()
    {
        $token = RelaySelectionSigner::sign($this->relay(), self::SECRET, self::CONTEXT, self::NOW);

        $this->assertSame(3600, RelaySelectionSigner::TTL);
        $this->assertNotNull(RelaySelectionSigner::verify($token, self::SECRET, self::CONTEXT, self::NOW + 3600));
        $this->assertNull(RelaySelectionSigner::verify($token, self::SECRET, self::CONTEXT, self::NOW + 3601));
        $this->assertNull(RelaySelectionSigner::verify($token, self::SECRET, self::CONTEXT, self::NOW + 86400));
    }

    /**
     * check() distingue l'expiration du refus, sans rendre le relais d'un
     * jeton expire ; un jeton altere ou d'un autre contexte n'est jamais
     * annonce comme expire
     */
    public function testCheckDistinguishesExpiryFromRefusal()
    {
        $token = RelaySelectionSigner::sign($this->relay(), self::SECRET, self::CONTEXT, self::NOW);

        $this->assertSame(
            ['status' => RelaySelectionSigner::VALID, 'relay' => $this->relay()],
            RelaySelectionSigner::check($token, self::SECRET, self::CONTEXT, self::NOW + 3600)
        );
        $this->assertSame(
            ['status' => RelaySelectionSigner::EXPIRED, 'relay' => null],
            RelaySelectionSigner::check($token, self::SECRET, self::CONTEXT, self::NOW + 3601)
        );
        $this->assertSame(
            RelaySelectionSigner::INVALID,
            RelaySelectionSigner::check($token, self::SECRET, '43|mondialrelay|1', self::NOW + 7200)['status']
        );
        $this->assertSame(
            RelaySelectionSigner::INVALID,
            RelaySelectionSigner::check($token . 'x', self::SECRET, self::CONTEXT, self::NOW + 7200)['status']
        );
        $this->assertSame(
            RelaySelectionSigner::INVALID,
            RelaySelectionSigner::check(null, self::SECRET, self::CONTEXT, self::NOW)['status']
        );

        // Daté dans le futur : refus, pas expiration
        $future = RelaySelectionSigner::sign($this->relay(), self::SECRET, self::CONTEXT, self::NOW + 3600);
        $this->assertSame(RelaySelectionSigner::INVALID, RelaySelectionSigner::check($future, self::SECRET, self::CONTEXT, self::NOW)['status']);
    }

    /**
     * Un jeton date dans le futur au-dela de la tolerance d'horloge est refuse
     */
    public function testFutureTokenIsRejected()
    {
        $token = RelaySelectionSigner::sign($this->relay(), self::SECRET, self::CONTEXT, self::NOW + 3600);

        $this->assertNull(RelaySelectionSigner::verify($token, self::SECRET, self::CONTEXT, self::NOW));
        $this->assertNotNull(RelaySelectionSigner::verify(
            RelaySelectionSigner::sign($this->relay(), self::SECRET, self::CONTEXT, self::NOW + 30),
            self::SECRET,
            self::CONTEXT,
            self::NOW
        ));
    }

    /**
     * Un jeton signe sans le domaine « ciklik-relay » (clef nue, ancien format
     * ou autre usage de _COOKIE_KEY_) est refuse
     */
    public function testTokenWithoutDomainIsRejected()
    {
        $json = json_encode(['iat' => self::NOW, 'relay' => $this->relay()]);

        $this->assertNull(RelaySelectionSigner::verify(
            $this->forge($json, self::CONTEXT . '|'),
            self::SECRET,
            self::CONTEXT,
            self::NOW
        ));
        $this->assertNull(RelaySelectionSigner::verify(
            $this->forge($json, 'autre-domaine|' . self::CONTEXT . '|'),
            self::SECRET,
            self::CONTEXT,
            self::NOW
        ));
        $this->assertSame($this->relay(), RelaySelectionSigner::verify(
            $this->forge($json, RelaySelectionSigner::DOMAIN . '|' . self::CONTEXT . '|'),
            self::SECRET,
            self::CONTEXT,
            self::NOW
        ));
    }

    /**
     * Un blob bien signe mais sans horodatage exploitable est refuse
     */
    public function testTokenWithoutIssuedAtIsRejected()
    {
        $prefix = RelaySelectionSigner::DOMAIN . '|' . self::CONTEXT . '|';

        foreach ([
            json_encode($this->relay()),
            json_encode(['relay' => $this->relay()]),
            json_encode(['iat' => 'hier', 'relay' => $this->relay()]),
            json_encode(['iat' => self::NOW, 'relay' => 'chaine']),
            '"chaine"',
        ] as $json) {
            $this->assertNull(RelaySelectionSigner::verify($this->forge($json, $prefix), self::SECRET, self::CONTEXT, self::NOW));
        }
    }

    /**
     * Un payload modifie apres signature est refuse
     */
    public function testTamperedPayloadIsRejected()
    {
        $token = RelaySelectionSigner::sign($this->relay(), self::SECRET, self::CONTEXT, self::NOW);
        list($blob, $mac) = explode('.', $token);

        $forged = rtrim(strtr(base64_encode(json_encode(['iat' => self::NOW, 'relay' => ['relay_id' => '999999']])), '+/', '-_'), '=');

        $this->assertNull(RelaySelectionSigner::verify($forged . '.' . $mac, self::SECRET, self::CONTEXT, self::NOW));
    }

    /**
     * Une signature modifiee est refusee
     */
    public function testTamperedSignatureIsRejected()
    {
        $token = RelaySelectionSigner::sign($this->relay(), self::SECRET, self::CONTEXT, self::NOW);
        list($blob, $mac) = explode('.', $token);
        $mac[0] = '0' === $mac[0] ? '1' : '0';

        $this->assertNull(RelaySelectionSigner::verify($blob . '.' . $mac, self::SECRET, self::CONTEXT, self::NOW));
    }

    /**
     * Un jeton signe pour un autre client, un autre transporteur ou une autre
     * boutique est refuse
     */
    public function testTokenBoundToContext()
    {
        $token = RelaySelectionSigner::sign($this->relay(), self::SECRET, self::CONTEXT, self::NOW);

        $this->assertNull(RelaySelectionSigner::verify($token, self::SECRET, '43|mondialrelay|1', self::NOW));
        $this->assertNull(RelaySelectionSigner::verify($token, self::SECRET, '42|colissimo|1', self::NOW));
        $this->assertNull(RelaySelectionSigner::verify($token, self::SECRET, '42|mondialrelay|2', self::NOW));
    }

    /**
     * Un jeton signe avec une autre cle est refuse
     */
    public function testTokenBoundToSecret()
    {
        $token = RelaySelectionSigner::sign($this->relay(), self::SECRET, self::CONTEXT, self::NOW);

        $this->assertNull(RelaySelectionSigner::verify($token, 'autre-cle', self::CONTEXT, self::NOW));
    }

    /**
     * Valeurs de formulaire inexploitables : absent, vide, tableau, format inattendu, trop long
     */
    public function testMalformedTokensAreRejected()
    {
        $this->assertNull(RelaySelectionSigner::verify(null, self::SECRET, self::CONTEXT, self::NOW));
        $this->assertNull(RelaySelectionSigner::verify('', self::SECRET, self::CONTEXT, self::NOW));
        $this->assertNull(RelaySelectionSigner::verify(['a' => 'b'], self::SECRET, self::CONTEXT, self::NOW));
        $this->assertNull(RelaySelectionSigner::verify('sans-point', self::SECRET, self::CONTEXT, self::NOW));
        $this->assertNull(RelaySelectionSigner::verify('a.b.c', self::SECRET, self::CONTEXT, self::NOW));
        $this->assertNull(RelaySelectionSigner::verify(str_repeat('a', 9000) . '.' . str_repeat('0', 64), self::SECRET, self::CONTEXT, self::NOW));
    }

    /**
     * Jeton de formulaire : stable pour un contexte, different par contexte et
     * par cle, distinct d'une signature de relais sous le meme domaine
     */
    public function testFormToken()
    {
        $token = RelaySelectionSigner::formToken(self::SECRET, '42|1');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $this->assertSame($token, RelaySelectionSigner::formToken(self::SECRET, '42|1'));
        $this->assertNotSame($token, RelaySelectionSigner::formToken(self::SECRET, '43|1'));
        $this->assertNotSame($token, RelaySelectionSigner::formToken(self::SECRET, '42|2'));
        $this->assertNotSame($token, RelaySelectionSigner::formToken('autre-cle', '42|1'));
        $this->assertNotSame($token, hash_hmac('sha256', '42|1', self::SECRET));
    }

    /**
     * Contexte du jeton de formulaire : client, boutique et hash du mot de
     * passe ; un changement de mot de passe invalide le jeton
     */
    public function testFormTokenIsBoundToPasswordHash()
    {
        $context = RelaySelectionSigner::formContext(42, 1, '$2y$10$ancien');

        $this->assertSame('42|1|$2y$10$ancien', $context);
        $this->assertSame('42|1|', RelaySelectionSigner::formContext('42', '1', null));

        $token = RelaySelectionSigner::formToken(self::SECRET, $context);
        $this->assertSame($token, RelaySelectionSigner::formToken(self::SECRET, RelaySelectionSigner::formContext(42, 1, '$2y$10$ancien')));
        $this->assertNotSame($token, RelaySelectionSigner::formToken(self::SECRET, RelaySelectionSigner::formContext(42, 1, '$2y$10$nouveau')));
        $this->assertNotSame($token, RelaySelectionSigner::formToken(self::SECRET, RelaySelectionSigner::formContext(43, 1, '$2y$10$ancien')));
        $this->assertNotSame($token, RelaySelectionSigner::formToken(self::SECRET, RelaySelectionSigner::formContext(42, 2, '$2y$10$ancien')));
    }
}

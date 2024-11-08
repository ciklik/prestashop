<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Data;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use PrestaShop\Module\Ciklik\Managers\CiklikCombination;

if (!defined('_PS_VERSION_')) {
    exit;
}

class SubscriptionData
{
    /**
     * @var string
     */
    public $uuid;
    /**
     * @var bool
     */
    public $active;
    /**
     * @var string
     */
    public $display_content;
    /**
     * @var string
     */
    public $display_interval;
    /**
     * @var SubscriptionDeliveryAddressData
     */
    public $address;
    /**
     * @var DateTimeImmutable
     */
    public $next_billing;
    /**
     * @var DateTimeImmutable
     */
    public $created_at;
    /**
     * @var DateTimeImmutable
     */
    public $end_date;

    /**
     * @var string
     */
    public $external_fingerprint;

    /**
     * @var array
     */
    public $contents;

    private function __construct(string $uuid,
        bool $active,
        string $display_content,
        string $display_interval,
        SubscriptionDeliveryAddressData $address,
        DateTimeImmutable $next_billing,
        DateTimeImmutable $created_at,
        DateTimeImmutable $end_date,
        CartFingerprintData $external_fingerprint,
        array $contents = []
    ) {
        $this->uuid = $uuid;
        $this->active = $active;
        $this->display_content = $display_content;
        $this->display_interval = $display_interval;
        $this->address = $address;
        $this->next_billing = $next_billing;
        $this->created_at = $created_at;
        $this->end_date = $end_date;
        $this->external_fingerprint = $external_fingerprint;
        $this->contents = $contents;
    }

    public static function create(array $data): SubscriptionData
    {
        // Extrait les données d'empreinte à partir de l'empreinte externe
        $fingerprint = CartFingerprintData::extractDatas($data['external_fingerprint']);

        // Crée et retourne une nouvelle instance d'abonnement
        return new self(
            $data['uuid'],
            $data['active'], 
            $data['display_content'],
            $data['display_interval'],
            SubscriptionDeliveryAddressData::create($fingerprint->id_address_delivery),
            new DateTimeImmutable($data['next_billing']),
            CarbonImmutable::parse($data['created_at']), 
            CarbonImmutable::parse($data['end_date']),
            $fingerprint,
            self::processContents($data['content'])
        );
    }

    /**
     * Traite le contenu d'un abonnement en ajoutant les combinaisons alternatives pour chaque article
     * 
     * Pour chaque article dans le contenu de l'abonnement, cette méthode récupère les autres combinaisons
     * possibles ayant les mêmes attributs non-fréquentiels. Par exemple, pour un "T-shirt Rouge Mensuel",
     * elle récupérera "T-shirt Rouge Hebdomadaire" et "T-shirt Rouge Trimestriel".
     *
     * @param array $contents Le tableau des contenus de l'abonnement à traiter
     * @return array Le tableau des contenus enrichi avec les combinaisons alternatives
     */
    private static function processContents(array $contents): array 
    {
        if (empty($contents)) {
            return [];
        }

        $processedContents = [];
        foreach ($contents as $item) {
            $processedContents[] = array_merge($item, [
                'other_combinations' => CiklikCombination::getOtherCombinations((int)$item['external_id'])
            ]);
        }

        return $processedContents;
    }

    public static function collection(array $data): array
    {
        $collection = [];

        foreach ($data as $item) {
            $collection[] = self::create($item);
        }

        return $collection;
    }
}

<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Data;

use Carbon\CarbonImmutable;
use Ciklik;
use Configuration;
use Context;
use DateTimeImmutable;
use PrestaShop\Module\Ciklik\Managers\CiklikCombination;
use PrestaShop\Module\Ciklik\Managers\CiklikFrequency;

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

    /**
     * @var array
     */
    public $upsells;

    /**
     * @var array
     */
    public $customizations;

    private function __construct(string $uuid,
        bool $active,
        string $display_content,
        string $display_interval,
        SubscriptionDeliveryAddressData $address,
        DateTimeImmutable $next_billing,
        DateTimeImmutable $created_at,
        DateTimeImmutable $end_date,
        CartFingerprintData $external_fingerprint,
        array $contents = [],
        array $upsells = []
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
        $this->upsells = $upsells;
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
            self::processContents($data['content']),
            isset($fingerprint->upsells) ? self::processUpsells($fingerprint->upsells) : []
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
            $otherCombinations = [];
            
            // Si l'option de fréquence est activée, on récupère les fréquences disponibles
            if (Configuration::get(Ciklik::CONFIG_USE_FREQUENCY_MODE) === '1') {
                // Récupère les fréquences disponibles pour ce produit
                $parts = explode(':', $item['external_id']);
                $id_product = $parts[0];
                $frequencies = CiklikFrequency::getFrequenciesForProduct((int)$id_product);
                
                foreach ($frequencies as $frequency) {
                    $otherCombinations[] = [
                        'frequency_id' => $frequency['id_frequency'],
                        'display_name' => 'Fréquence : ' . $frequency['name'],
                        'interval' => $frequency['interval'],
                        'interval_count' => $frequency['interval_count'],
                        'discount_percent' => $frequency['discount_percent'],
                        'discount_price' => $frequency['discount_price'],
                    ];
                }
            } else {
                // Comportement existant pour le mode décli
                $otherCombinations = CiklikCombination::getOtherCombinations((int)$item['external_id']);
            }

            $processedContents[] = array_merge($item, [
                'other_combinations' => $otherCombinations
            ]);
        }

        return $processedContents;
    }

    /**
     * Traite les produits additionnels (upsells) d'un abonnement en ajoutant un nom d'affichage
     * pour chaque produit.
     * 
     * Pour chaque upsell, cette méthode récupère le nom du produit et, s'il existe, le nom de sa
     * combinaison (ex: taille, couleur...) pour créer un nom d'affichage complet. Par exemple,
     * "T-shirt - Rouge, XL".
     *
     * @param array $upsells Le tableau des produits additionnels à traiter
     * @return array Le tableau des produits additionnels enrichi avec les noms d'affichage
     */
    private static function processUpsells(array $upsells): array
    {
        if (empty($upsells)) {
            return [];
        }
        $processedUpsells = [];
        foreach ($upsells as $upsell) {
            // Récupère le nom du produit
            $product = new \Product((int)$upsell['product_id'], false, \Context::getContext()->language->id);
            $productName = $product->name;

            // Récupère le nom de la combinaison si elle existe
            $combinationName = '';
            if (!empty($upsell['product_attribute_id'])) {
                $combination = new \Combination((int)$upsell['product_attribute_id']);
                $attributes = $combination->getAttributesName(\Context::getContext()->language->id);
                
                // Si des attributs existent, on les concatène avec des virgules
                if (!empty($attributes)) {
                    $combinationName = ' - ' . implode(', ', array_column($attributes, 'name'));
                }
            }

            // Ajoute le nom d'affichage aux données du produit additionnel
            $upsell['display_name'] = $productName . $combinationName;
            $processedUpsells[] = $upsell;
        }
        $upsells = $processedUpsells;

        return $upsells;
    }   

    /**
     * Crée une collection d'instances de SubscriptionData à partir d'un tableau de données.
     * 
     * @param array $data Le tableau de données à partir duquel les instances seront créées
     * @return array Un tableau contenant les instances de SubscriptionData 
     */
    public static function collection(array $data): array
    {
        $collection = [];

        foreach ($data as $item) {
            $collection[] = self::create($item);
        }

        return $collection;
    }
}

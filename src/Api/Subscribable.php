<?php

/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Api;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Subscribable extends CiklikApiClient
{
    /**
     * Clés autorisées pour les données produit envoyées à l'API
     */
    private const ALLOWED_PRODUCT_KEYS = [
        'name',
        'short_description',
        'description',
        'meta_title',
        'meta_description',
        'price',
        'tax',
        'active',
        'ref',
        'external_id',
        'frequencies',
    ];

    /**
     * Clés autorisées pour les données de fréquence
     */
    private const ALLOWED_FREQUENCY_KEYS = [
        'interval',
        'interval_count',
    ];

    public function push(array $data)
    {
        $this->setRoute('products');

        $sanitizedData = $this->sanitizePayload($data);

        return $this->post([
            'json' => $sanitizedData,
        ]);
    }

    /**
     * Nettoie le payload pour ne conserver que les clés autorisées
     *
     * @param array $data Données brutes à nettoyer
     *
     * @return array Données nettoyées avec uniquement les clés autorisées
     */
    private function sanitizePayload(array $data): array
    {
        if (!isset($data['products']) || !is_array($data['products'])) {
            return $data;
        }

        $sanitizedProducts = [];
        foreach ($data['products'] as $product) {
            $sanitizedProduct = array_intersect_key(
                $product,
                array_flip(self::ALLOWED_PRODUCT_KEYS),
            );

            // Sanitize frequencies if present
            if (isset($sanitizedProduct['frequencies']) && is_array($sanitizedProduct['frequencies'])) {
                $sanitizedFrequencies = [];
                foreach ($sanitizedProduct['frequencies'] as $frequency) {
                    $sanitizedFrequencies[] = array_intersect_key(
                        $frequency,
                        array_flip(self::ALLOWED_FREQUENCY_KEYS),
                    );
                }
                $sanitizedProduct['frequencies'] = $sanitizedFrequencies;
            }

            $sanitizedProducts[] = $sanitizedProduct;
        }

        return ['products' => $sanitizedProducts];
    }
}

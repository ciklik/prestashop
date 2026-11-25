<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Helpers;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Configuration du module déclarée à Ciklik (PUT whoiam, métadonnée module_config) : le support y lit les réglages
 * sans demander de capture, et Ciklik sait si la boutique est en mode fréquences.
 *
 * Liste fermée de réglages non sensibles : jamais de jeton, de clé ou d'identifiant du webservice, d'adresse, de texte
 * libre (messages du panier), ni d'identifiant de groupe de clients ou d'état de commande. Envoyée en chaîne JSON d'un
 * objet plat, avec le numéro de schéma v.
 */
class ModuleConfigDeclaration
{
    const META_KEY = 'module_config';

    const SCHEMA_VERSION = 1;

    // Réglage booléen déclaré => clé de configuration du module
    const BOOLEAN_SETTINGS = [
        'frequency_mode' => 'CIKLIK_FREQUENCY_MODE',
        'enable_upsell' => 'CIKLIK_ENABLE_UPSELL',
        'enable_change_interval' => 'CIKLIK_ENABLE_CHANGE_INTERVAL',
        'allow_change_next_billing' => 'CIKLIK_ALLOW_CHANGE_NEXT_BILLING',
        'enable_skip_next_delivery' => 'CIKLIK_ENABLE_SKIP_NEXT_DELIVERY',
        'enable_engagement' => 'CIKLIK_ENABLE_ENGAGEMENT',
        'fallback_to_default_attribute' => 'CIKLIK_FALLBACK_TO_DEFAULT_ATTRIBUTE',
        'delegate_options_display' => 'CIKLIK_DELEGATE_OPTIONS_DISPLAY',
        'enable_subscription_consent' => 'CIKLIK_ENABLE_SUBSCRIPTION_CONSENT',
        'cart_footer_enabled' => 'CIKLIK_CART_FOOTER_ENABLED',
        'debug_logs_enabled' => 'CIKLIK_DEBUG_LOGS_ENABLED',
    ];

    const PRICE_BASES = ['gross', 'net'];

    const ENGAGEMENT_INTERVALS = ['day', 'week', 'month'];

    /**
     * @param bool $webserviceCombinations la clé webservice du module a le droit de lire les déclinaisons
     *
     * @return array métadonnée à joindre à une déclaration PUT whoiam
     */
    public static function metadata($webserviceCombinations)
    {
        return [self::META_KEY => json_encode(self::build($webserviceCombinations))];
    }

    /**
     * @param bool $webserviceCombinations
     *
     * @return array
     */
    public static function build($webserviceCombinations)
    {
        $config = ['v' => self::SCHEMA_VERSION];

        foreach (self::BOOLEAN_SETTINGS as $name => $key) {
            $config[$name] = (bool) \Configuration::get($key);
        }

        $priceBase = (string) \Configuration::get('CIKLIK_FREQUENCY_PRICE_BASE');
        if (in_array($priceBase, self::PRICE_BASES, true)) {
            $config['frequency_price_base'] = $priceBase;
        }

        $interval = (string) \Configuration::get('CIKLIK_ENGAGEMENT_INTERVAL');
        if (in_array($interval, self::ENGAGEMENT_INTERVALS, true)) {
            $config['engagement_interval'] = $interval;
        }

        $config['engagement_interval_count'] = max(0, min(365, (int) \Configuration::get('CIKLIK_ENGAGEMENT_INTERVAL_COUNT')));
        $config['ps_version'] = (string) _PS_VERSION_;
        $config['php_version'] = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;
        $config['multishop'] = (bool) \Shop::isFeatureActive();
        $config['webservice_combinations'] = (bool) $webserviceCombinations;

        return $config;
    }
}

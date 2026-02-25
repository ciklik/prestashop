<?php

/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Helpers;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Helper pour la validation et la manipulation des UUIDs
 */
class UuidHelper
{
    /**
     * Pattern regex pour la validation d'un UUID v4
     */
    public const UUID_V4_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    /**
     * Vérifie si une chaîne est un UUID v4 valide
     *
     * @param string $uuid La chaîne à valider
     *
     * @return bool True si UUID v4 valide, false sinon
     */
    public static function isValid(string $uuid): bool
    {
        return (bool) preg_match(self::UUID_V4_PATTERN, $uuid);
    }

    /**
     * Valide un UUID et retourne une valeur nettoyée ou null si invalide
     *
     * @param mixed $uuid La valeur à valider
     *
     * @return string|null L'UUID nettoyé ou null si invalide
     */
    public static function sanitize($uuid): ?string
    {
        if (!is_string($uuid) && !is_numeric($uuid)) {
            return null;
        }

        $uuid = trim((string) $uuid);

        return self::isValid($uuid) ? $uuid : null;
    }

    /**
     * Valide un UUID depuis une requête (Tools::getValue)
     * Retourne l'UUID validé ou null si invalide/absent
     *
     * @param string $key La clé du paramètre à récupérer
     *
     * @return string|null L'UUID validé ou null
     */
    public static function getFromRequest(string $key): ?string
    {
        $value = \Tools::getValue($key);

        if (empty($value)) {
            return null;
        }

        return self::sanitize($value);
    }
}

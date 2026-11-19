<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Managers;

use PrestaShop\Module\Ciklik\Api\Subscription;
use PrestaShop\Module\Ciklik\Data\CartFingerprintData;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Changement de fréquence d'un abonnement, partagé entre « Mes abonnements »
 * (le client) et la fiche commande du back-office (le marchand).
 *
 * Mode fréquences : la fréquence est portée par l'abonnement (frequency_id de
 * l'empreinte, interval et interval_count), les produits ne changent pas.
 * Mode déclinaisons : chaque ligne passe à la déclinaison du même produit à
 * la nouvelle fréquence, donc à son prix au renouvellement.
 * La date de la prochaine commande n'est jamais envoyée : elle ne change pas.
 */
class CiklikSubscriptionFrequency
{
    /** Abonnement désactivé : sa fréquence ne se modifie pas */
    const ERROR_INACTIVE = 'inactive';

    /** Choix absent de ceux proposés pour l'abonnement */
    const ERROR_INVALID_CHOICE = 'invalid_choice';

    /** Mode déclinaisons : une ligne n'a pas de déclinaison à la nouvelle fréquence */
    const ERROR_NO_MATCH = 'no_match';

    /** Refus ou erreur de l'API Ciklik */
    const ERROR_API = 'api';

    /**
     * Choix proposés : ceux de la première ligne (other_combinations de
     * SubscriptionData), comme la fenêtre de Mes abonnements, indexés par la
     * valeur postée (frequency_id en mode fréquences, id_product_attribute
     * en mode déclinaisons).
     *
     * @param array $contents Lignes de SubscriptionData::$contents
     * @param bool $useFrequencyMode
     *
     * @return array
     */
    public static function getChoices(array $contents, $useFrequencyMode)
    {
        $first = reset($contents);
        $key = $useFrequencyMode ? 'frequency_id' : 'id_product_attribute';
        $choices = [];

        if (is_array($first) && !empty($first['other_combinations']) && is_array($first['other_combinations'])) {
            foreach ($first['other_combinations'] as $choice) {
                if (!empty($choice[$key])) {
                    $choices[(int) $choice[$key]] = $choice;
                }
            }
        }

        return $choices;
    }

    /**
     * Change la fréquence d'un abonnement actif chez Ciklik, si la valeur
     * postée est un des choix proposés.
     *
     * @param Subscription $api
     * @param string $uuid
     * @param bool $active Abonnement actif (SubscriptionData::$active)
     * @param array $contents Lignes de SubscriptionData::$contents
     * @param CartFingerprintData $fingerprint Empreinte de l'abonnement
     * @param bool $useFrequencyMode
     * @param mixed $value Valeur postée (voir getChoices)
     * @param callable|null $matcher Déclinaison correspondante d'une ligne
     *                               (CiklikCombination::getMatchingCombinations par défaut)
     *
     * @return array{success: bool, error: string|null, choice: array|null, response: array|null}
     */
    public static function change(Subscription $api, $uuid, $active, array $contents, CartFingerprintData $fingerprint, $useFrequencyMode, $value, $matcher = null)
    {
        if (true !== $active) {
            return self::result(self::ERROR_INACTIVE, null);
        }

        $choices = self::getChoices($contents, $useFrequencyMode);
        if (!isset($choices[(int) $value])) {
            return self::result(self::ERROR_INVALID_CHOICE, null);
        }

        $choice = $choices[(int) $value];

        if ($useFrequencyMode) {
            $updated = clone $fingerprint;
            $updated->frequency_id = (int) $choice['frequency_id'];
            $update = [
                'metadata' => ['prestashop_fingerprint' => $updated->encodeDatas()],
                'interval' => $choice['interval'],
                'interval_count' => (int) $choice['interval_count'],
            ];
        } else {
            $update = self::buildContentUpdate($contents, $choice, $matcher ?: [CiklikCombination::class, 'getMatchingCombinations']);
            if (null === $update) {
                return self::result(self::ERROR_NO_MATCH, $choice);
            }
        }

        $response = $api->update((string) $uuid, $update);
        $failed = empty($response['status']) || !empty($response['errors']);

        return self::result($failed ? self::ERROR_API : null, $choice, $response);
    }

    /**
     * Mode déclinaisons : chaque ligne passe à la déclinaison du même produit
     * à la nouvelle fréquence. Une ligne sans correspondance arrête tout :
     * envoyé sans elle, le contenu retirerait ce produit de l'abonnement. Une
     * ligne personnalisée aussi : l'identifiant reçu a perdu sa
     * personnalisation, l'envoyer la remplacerait par le produit standard.
     *
     * @param array $contents
     * @param array $choice
     * @param callable $matcher
     *
     * @return array|null
     */
    private static function buildContentUpdate(array $contents, array $choice, $matcher)
    {
        $updatedContents = [];
        foreach ($contents as $content) {
            $matching = empty($content['is_customization'])
                ? call_user_func($matcher, $choice, (int) $content['external_id'])
                : null;
            if (empty($matching)) {
                return null;
            }

            $updatedContents[] = [
                'external_id' => $matching['id_product_attribute'],
                'quantity' => $content['quantity'],
                'interval' => $matching['interval'],
                'interval_count' => $matching['interval_count'],
            ];
        }

        return empty($updatedContents) ? null : ['content' => $updatedContents];
    }

    /**
     * @param string|null $error
     * @param array|null $choice
     * @param array|null $response
     *
     * @return array
     */
    private static function result($error, $choice, $response = null)
    {
        return [
            'success' => null === $error,
            'error' => $error,
            'choice' => $choice,
            'response' => $response,
        ];
    }
}

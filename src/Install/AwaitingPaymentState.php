<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Install;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * État de commande « en attente de paiement » du module : commande créée pour un paiement pas encore encaissé
 * (PayPal par prélèvement bancaire). Non payé, non validé, sans facture ni mail : la boutique ne la prépare pas
 * avant la confirmation de Ciklik, qui la passe alors à l'état payé configuré ou l'annule.
 *
 * Idempotent : l'état déjà créé (identifiant en configuration, ou retrouvé par module et libellé après une
 * réinstallation) est réutilisé, jamais dupliqué.
 */
class AwaitingPaymentState
{
    // Libellés par code ISO de langue ; l'anglais sert pour toute autre langue.
    const NAMES = [
        'en' => 'Awaiting payment (Ciklik)',
        'fr' => 'En attente de paiement (Ciklik)',
        'de' => 'Zahlung ausstehend (Ciklik)',
        'es' => 'En espera de pago (Ciklik)',
        'it' => 'In attesa di pagamento (Ciklik)',
        'pl' => 'Oczekiwanie na płatność (Ciklik)',
    ];

    const COLOR = '#34209E';

    /**
     * @return int identifiant de l'état, 0 en cas d'échec
     */
    public static function install()
    {
        $existing = self::findExisting();
        if ($existing > 0) {
            \Configuration::updateGlobalValue(\Ciklik::CONFIG_OS_AWAITING_PAYMENT, $existing);

            return $existing;
        }

        $state = new \OrderState();
        $state->name = [];
        foreach (\Language::getLanguages(false) as $language) {
            $iso = isset($language['iso_code']) ? strtolower($language['iso_code']) : 'en';
            $state->name[(int) $language['id_lang']] = isset(self::NAMES[$iso]) ? self::NAMES[$iso] : self::NAMES['en'];
        }
        $state->module_name = 'ciklik';
        $state->color = self::COLOR;
        $state->send_email = false;
        $state->invoice = false;
        $state->logable = false;
        $state->paid = false;
        $state->shipped = false;
        $state->delivery = false;
        $state->hidden = false;
        $state->unremovable = true;
        $state->pdf_invoice = false;
        $state->pdf_delivery = false;

        if (!$state->add()) {
            return 0;
        }

        \Configuration::updateGlobalValue(\Ciklik::CONFIG_OS_AWAITING_PAYMENT, (int) $state->id);

        return (int) $state->id;
    }

    /**
     * État déjà créé par le module : celui de la configuration s'il existe encore, sinon un état du module
     * portant l'un de ses libellés (réinstallation, configuration perdue).
     *
     * @return int
     */
    private static function findExisting()
    {
        $configured = (int) \Configuration::getGlobalValue(\Ciklik::CONFIG_OS_AWAITING_PAYMENT);
        if ($configured > 0) {
            $state = new \OrderState($configured);
            if (\Validate::isLoadedObject($state) && !$state->deleted && !$state->paid) {
                return $configured;
            }
        }

        $names = implode(', ', array_map(function ($name) {
            return '\'' . pSQL($name) . '\'';
        }, array_values(self::NAMES)));

        return (int) \Db::getInstance()->getValue(
            'SELECT os.id_order_state FROM `' . _DB_PREFIX_ . 'order_state` os'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'order_state_lang` osl ON osl.id_order_state = os.id_order_state'
            . ' WHERE os.module_name = \'ciklik\' AND os.deleted = 0 AND os.paid = 0'
            . ' AND osl.name IN (' . $names . ')'
            . ' ORDER BY os.id_order_state DESC'
        );
    }
}

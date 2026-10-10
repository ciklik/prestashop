<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

use PrestaShop\Module\Ciklik\Api\Shop;
use PrestaShop\Module\Ciklik\Install\AwaitingPaymentState;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Mise à niveau 1.26.0, pour les installations existantes : paiement PayPal en attente.
 *
 * Crée l'état de commande « en attente de paiement (Ciklik) » : une commande dont le paiement PayPal par
 * prélèvement bancaire n'est pas encore encaissé est créée dans cet état, puis passée à l'état payé configuré
 * ou en erreur de paiement quand Ciklik reçoit la réponse de PayPal (requête ciklik/gateway/payments).
 *
 * Annonce aussi à Ciklik que ce module traite la confirmation de paiement (supports_payment_updates) : la
 * plateforme n'envoie la requête qu'aux sites qui l'annoncent.
 *
 * Comme les scripts précédents, un échec ne fait pas échouer la mise à jour (PrestaShop désactiverait le
 * module de paiement sans jamais rejouer le script) : sans l'état, ces commandes sont créées comme avant, et
 * le journal porte l'erreur. Une réinstallation ou un nouvel appel de AwaitingPaymentState::install() la répare.
 */
function upgrade_module_1_26_0($module)
{
    try {
        if (AwaitingPaymentState::install() === 0) {
            PrestaShopLogger::addLog('Ciklik upgrade 1.26.0 - création de l\'état « en attente de paiement » échouée', 3);
        }
    } catch (Throwable $e) {
        PrestaShopLogger::addLog('Ciklik upgrade 1.26.0 - ' . $e->getMessage(), 3);
    }

    $token = Configuration::get(Ciklik::CONFIG_API_TOKEN);

    if (empty($token)) {
        return true;
    }

    try {
        $link = (isset($module->context->link) && $module->context->link) ? $module->context->link : new Link();
        (new Shop($link))->metadata([
            'supports_payment_updates' => '1',
            'module_version' => Ciklik::VERSION,
        ]);
    } catch (Throwable $e) {
        // Ne pas bloquer la mise à jour si l'appel API échoue
        // Le flag sera renvoyé lors de la prochaine sauvegarde de configuration
        PrestaShopLogger::addLog(
            'Ciklik upgrade 1.26.0: échec envoi supports_payment_updates - ' . $e->getMessage(),
            2,
            null,
            'Module',
            $module->id
        );
    }

    return true;
}

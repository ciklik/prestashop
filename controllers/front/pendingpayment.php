<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

use PrestaShop\Module\Ciklik\Helpers\UuidHelper;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Bouton « Régler ce paiement » : redirige le client connecté vers le lien de
 * reprise de son abonnement, relu sur l'API. Le lien connecte le client à son
 * compte Ciklik : il ne figure jamais dans le HTML, où un outil de mesure ou
 * d'enregistrement de session pourrait le relever.
 */
class CiklikPendingpaymentModuleFrontController extends ModuleFrontController
{
    /**
     * {@inheritdoc}
     */
    public $auth = true;

    /**
     * {@inheritdoc}
     *
     * La redirection porte le jeton du lien de reprise : jamais en http.
     */
    public $ssl = true;

    /**
     * {@inheritdoc}
     */
    public function postProcess()
    {
        $uuid = UuidHelper::getFromRequest('uuid');
        $payments = null === $uuid ? [] : $this->module->getPendingPayments();

        // Abonnement d'un autre client, inactif ou sans paiement en attente
        if (null === $uuid || !isset($payments[$uuid])) {
            Tools::redirect($this->context->link->getModuleLink($this->module->name, 'account', [], true));
        }

        Tools::redirect($payments[$uuid]->retry_link, __PS_BASE_URI__, null, [
            'Cache-Control: no-store',
            'Referrer-Policy: no-referrer',
        ]);
    }
}

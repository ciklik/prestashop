<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Addons;

use ModuleAdminController;

if (!defined('_PS_VERSION_')) {
    exit;
}

trait Account
{
    public static function injectAccount(ModuleAdminController $controller)
    {
        /*********************
         * PrestaShop Account *
         * *******************/
        /** @var PrestaShop\Module\PsAccounts\Service\PsAccountsService $accountsService */
        $accountsService = null;
        try {
            $accountsFacade = $controller->getService('prestashop.module.ciklik.ps_accounts_facade');
            $accountsService = $accountsFacade->getPsAccountsService();
        } catch (PrestaShop\PsAccountsInstaller\Installer\Exception\InstallerException $e) {
            $accountsInstaller = $controller->getService('prestashop.module.ciklik.ps_accounts_installer');
            $accountsInstaller->install();
            $accountsFacade = $controller->getService('prestashop.module.ciklik.ps_accounts_facade');
            $accountsService = $accountsFacade->getPsAccountsService();
        }

        Media::addJsDef([
            'contextPsAccounts' => $accountsFacade->getPsAccountsPresenter()
                ->present($controller->module->name),
        ]);

        // Retrieve the PrestaShop Account CDN
        $controller->context->smarty->assign('urlAccountsCdn', $accountsService->getAccountsCdn());

        /**********************
         * PrestaShop Billing *
         * *******************/
        // Load the context for PrestaShop Billing
        $billingFacade = $controller->getService('prestashop.module.ciklik.ps_billings_facade');
        $partnerLogo = 'https://www.ciklik.co/uploads/2022/05/logo-ciklik-dark.svg';

        // PrestaShop Billing
        Media::addJsDef($billingFacade->present([
            'logo' => $partnerLogo,
            'tosLink' => 'https://www.ciklik.co/',
            'privacyLink' => 'https://www.ciklik.co/',
            // This field is deprecated but a valid email must be provided to ensure backward compatibility
            'emailSupport' => 'some@email.com',
        ]));

        $currentSubscription = $controller->getService('prestashop.module.ciklik.ps_billings_service')->getCurrentSubscription();
        $subscription = [];
        // We test here the success of the request in the response's body.
        if (!empty($currentSubscription['success'])) {
            $subscription = $currentSubscription['body'];
        }

        $controller->context->smarty->assign('urlBilling', 'https://unpkg.com/@prestashopcorp/billing-cdc/dist/bundle.js');
        $controller->context->smarty->assign('hasSubscription', !empty($subscription));

        $controller->context->smarty->assign('usePsAccounts', true);
    }
}

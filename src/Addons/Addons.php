<?php

/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Addons;

if (!defined('_PS_VERSION_')) {
    exit;
}

trait Account
{
    public static function isCiklikAddonsBuild()
    {
        return true;
    }

    public static function injectAccount(\ModuleAdminController $controller, $context)
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

        \Media::addJsDef([
            'contextPsAccounts' => $accountsFacade->getPsAccountsPresenter()
                ->present($controller->module->name),
        ]);

        // Retrieve the PrestaShop Account CDN
        $context->smarty->assign('urlAccountsCdn', $accountsService->getAccountsCdn());

        $context->smarty->assign('usePsAccounts', true);
    }
}

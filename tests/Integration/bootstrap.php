<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

// Charge une boutique PrestaShop réelle (PS_ROOT) et le module installé dans modules/ciklik.
// Les tests écrivent dans la base : ils exigent CIKLIK_INTEGRATION_WRITES=1, à ne poser que sur une
// boutique de développement.
if ('1' !== getenv('CIKLIK_INTEGRATION_WRITES')) {
    fwrite(STDERR, "Ces tests créent et suppriment des produits : lancez-les sur une boutique de développement avec CIKLIK_INTEGRATION_WRITES=1.\n");
    exit(1);
}
$psRoot = getenv('PS_ROOT');
if (!$psRoot || !is_file($psRoot . '/config/config.inc.php')) {
    fwrite(STDERR, "PS_ROOT doit désigner la racine d'une boutique PrestaShop de développement.\n");
    exit(1);
}

require_once $psRoot . '/config/config.inc.php';
require_once _PS_MODULE_DIR_ . 'ciklik/ciklik.php';

// Contexte d'un employé super-administrateur, boutique par défaut
$context = Context::getContext();
$idEmployee = (int) Db::getInstance()->getValue('SELECT id_employee FROM `' . _DB_PREFIX_ . 'employee` WHERE id_profile = ' . (int) _PS_ADMIN_PROFILE_ . ' AND active = 1 ORDER BY id_employee');
$context->employee = new Employee($idEmployee);
$context->shop = new Shop((int) Configuration::get('PS_SHOP_DEFAULT'));
Shop::setContext(Shop::CONTEXT_SHOP, (int) $context->shop->id);
$context->language = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
$context->currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));

require_once __DIR__ . '/IntegrationTestCase.php';

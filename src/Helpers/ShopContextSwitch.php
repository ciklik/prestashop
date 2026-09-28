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
 * Bascule temporaire du contexte boutique (multiboutique) : les appels qui
 * lisent la configuration d'une autre boutique que celle de la requête
 * (credentials transporteur, plafond de recherche) se font dans son
 * contexte, puis le contexte d'origine est restauré avant le rendu de la
 * page, dont les hooks et la configuration doivent rester ceux de la
 * boutique visitée.
 */
class ShopContextSwitch
{
    /** @var array|null Contexte d'origine ['type' => int, 'id' => int|null], null sans bascule */
    private $previous;

    /**
     * Pose le contexte de la boutique $idShop, en mémorisant le contexte
     * d'origine lors de la première bascule. Sans multiboutique, rien à faire.
     *
     * @param int $idShop
     *
     * @return bool La bascule a eu lieu
     */
    public function switchTo(int $idShop): bool
    {
        if ($idShop <= 0 || !\Shop::isFeatureActive()) {
            return false;
        }

        if (null === $this->previous) {
            $type = (int) \Shop::getContext();
            $id = null;

            if (\Shop::CONTEXT_SHOP === $type) {
                $id = (int) \Shop::getContextShopID();
            } elseif (\Shop::CONTEXT_GROUP === $type) {
                $id = (int) \Shop::getContextShopGroupID();
            }

            $this->previous = ['type' => $type, 'id' => $id];
        }

        \Shop::setContext(\Shop::CONTEXT_SHOP, $idShop);

        return true;
    }

    /**
     * Restaure le contexte d'origine s'il y a eu bascule. Idempotent.
     */
    public function restore()
    {
        if (null === $this->previous) {
            return;
        }

        \Shop::setContext($this->previous['type'], $this->previous['id']);
        $this->previous = null;
    }
}

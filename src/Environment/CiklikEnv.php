<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Environment;

use Ciklik;
use Configuration;
use Dotenv\Dotenv;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CiklikEnv
{
    /**
     * Const that define all environment possible to use.
     * Top of the list are taken in first if they exist in the project.
     * eg: If .env.test is present in the module it will be loaded, if not present
     * we try to load the next one etc ...
     *
     * @var array
     */
    const FILE_ENV_LIST = [
        'test' => '.env.test',
        'prod' => '.env',
    ];

    /**
     * Environment name: can be 'prod' or 'test'
     *
     * @var string
     */
    protected $name;

    /**
     * Environment mode: can be 'live' or 'sandbox'
     *
     * @var string
     */
    protected $mode;

    /**
     * Url api Ciklik (production live by default)
     *
     * @var string
     */
    private $ciklikApiUrl;

    public function __construct()
    {
        foreach (self::FILE_ENV_LIST as $env => $fileName) {
            if (!file_exists(_PS_MODULE_DIR_ . 'ciklik/' . $fileName)) {
                continue;
            }

            $dotenv = Dotenv::create(_PS_MODULE_DIR_ . 'ciklik/', $fileName);
            $dotenv->load();

            $this->setName($env);

            break;
        }

        if (true === $this->isLive()) {
            $this->setMode('live');
        } else {
            $this->setMode('sandbox');
        }

        $this->setEnvDependingOnMode();
    }

    /**
     * getter for name
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * getter for mode
     */
    public function getMode()
    {
        return $this->mode;
    }

    /**
     * getter for ciklikApiUrl
     */
    public function getCiklikApiUrl()
    {
        return $this->ciklikApiUrl;
    }

    /**
     * Check if the module is in SANDBOX or LIVE mode
     *
     * @return bool true if the module is in LIVE mode
     */
    private function isLive()
    {
        $mode = Configuration::get(Ciklik::CONFIG_MODE);

        if ('LIVE' === $mode) {
            return true;
        }

        return false;
    }

    private function setEnvDependingOnMode()
    {
        $this->setCiklikApiUrl($_ENV['CIKLIK_API_URL_LIVE']);

        if ('sandbox' === $this->mode) {
            $this->setCiklikApiUrl($_ENV['CIKLIK_API_URL_SANDBOX']);
        }
    }

    /**
     * setter for name
     *
     * @param string $name
     */
    private function setName($name)
    {
        $this->name = $name;
    }

    /**
     * setter for mode
     *
     * @param string $mode
     */
    private function setMode($mode)
    {
        $this->mode = $mode;
    }

    /**
     * setter for ciklikApiUrl
     *
     * @param string $url
     */
    private function setCiklikApiUrl($url)
    {
        $this->ciklikApiUrl = $url;
    }
}

<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Factory;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;

class CiklikLogger
{
    const MAX_FILES = 15;

    public static function create()
    {
        $rotatingFileHandler = new RotatingFileHandler(
            _PS_ROOT_DIR_ . '/var/logs/ciklik',
            static::MAX_FILES
        );
        $logger = new Logger('ciklik');
        $logger->pushHandler($rotatingFileHandler);

        return $logger;
    }
}

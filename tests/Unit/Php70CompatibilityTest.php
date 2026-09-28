<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit;

use PHPUnit\Framework\TestCase;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Le code livré doit tourner sous PHP 7.0. La lane php -l de la CI ne voit
 * pas tout : « : void », « : iterable » ou « object » passent l'analyse
 * syntaxique de PHP 7.0, qui les lit comme des noms de classe, puis lèvent
 * une TypeError à l'exécution.
 */
class Php70CompatibilityTest extends TestCase
{
    public function testShippedCodeAvoidsTypesUnknownToPhp70()
    {
        $root = dirname(__DIR__, 2);
        $files = [$root . '/ciklik.php'];

        foreach (['src', 'controllers', 'upgrade'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($iterator as $file) {
                if ($file->isFile() && 'php' === $file->getExtension()) {
                    $files[] = $file->getPathname();
                }
            }
        }

        $patterns = [
            'type de retour void, iterable, object ou mixed' => '/\)\s*:\s*\??(void|iterable|object|mixed)\b/i',
            'paramètre typé iterable, object ou mixed' => '/[(,]\s*(iterable|object|mixed)\s+&?\$/i',
            'type nullable' => '/([(,]\s*\?[A-Za-z_\\\\][A-Za-z0-9_\\\\]*\s+&?\$)|(\)\s*:\s*\?[A-Za-z_\\\\])/',
            'visibilité de constante' => '/^\s*(public|protected|private)\s+const\b/m',
        ];

        $violations = [];
        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            foreach ($patterns as $label => $pattern) {
                if (preg_match_all($pattern, $code, $matches)) {
                    $violations[] = substr($file, strlen($root) + 1) . ' : ' . $label . ' (' . trim($matches[0][0]) . ')';
                }
            }
        }

        $this->assertGreaterThan(50, count($files));
        $this->assertSame([], $violations);
    }
}

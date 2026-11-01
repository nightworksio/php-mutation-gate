<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Warm;

use function dirname;
use function function_exists;
use function is_file;
use function mb_strlen;
use function mb_substr;

use NightWorksIO\MutationGate\Core\NotGiven;

use function spl_autoload_register;
use function sprintf;
use function str_replace;
use function str_starts_with;

/**
 * The gate's own classes, as a Pest run's warm worker and its clients load
 * them (ADR-0023): from the gate's source, by their names, without the
 * project's autoloader, whose files run code of the project's packages as
 * it loads, such as a printer that registers itself only where Pest's
 * script asked for it first. Each child a worker forks loads the project's
 * autoloader as Pest's script does in a fresh process. On PHP 8.4, which has
 * no `array_first` and `array_last`, it loads the polyfill the gate requires
 * for them, from the vendor directory the gate is installed in or its own.
 */
final readonly class GateClasses
{
    private const string NAMESPACE = 'NightWorksIO\MutationGate\\';

    private const string FILE = '%s/%s.php';

    /** Where the polyfill of PHP 8.5's array functions starts, under a vendor directory. */
    private const string POLYFILL = '%s/symfony/polyfill-php85/bootstrap.php';

    /** The class the polyfill's functions call, beside where it starts, which no autoloader here finds. */
    private const string POLYFILLED = '%s/Php85.php';

    /** The vendor directories the gate's source can sit beside: its own, and the project's it is installed in. */
    private const array VENDORS = ['%s/vendor', '%s/../..'];

    /** Each class of the gate's namespace loaded from the file its name names under this source directory. */
    public static function register(string $source): void
    {
        spl_autoload_register(static function (string $class) use ($source): void {
            $file = sprintf(
                self::FILE,
                $source,
                str_replace('\\', '/', mb_substr($class, mb_strlen(self::NAMESPACE))),
            );

            if (str_starts_with($class, self::NAMESPACE) && is_file($file)) {
                require $file;
            }
        });

        $root = dirname($source);
        $polyfilled = function_exists('array_last') ? NotGiven::value() : self::polyfilledFrom($root);
    }

    /**
     * PHP 8.5's array functions, loaded from the first vendor directory beside
     * the gate's root that holds their polyfill: the file it loaded, or none.
     */
    public static function polyfilledFrom(string $root): string|NotGiven
    {
        foreach (self::VENDORS as $vendor) {
            $polyfill = sprintf(self::POLYFILL, sprintf($vendor, $root));

            if (is_file($polyfill)) {
                require_once sprintf(self::POLYFILLED, dirname($polyfill));
                require_once $polyfill;

                return $polyfill;
            }
        }

        return NotGiven::value();
    }
}

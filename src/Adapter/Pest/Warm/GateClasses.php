<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Warm;

use function is_file;
use function mb_strlen;
use function mb_substr;
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
 * autoloader as Pest's script does in a fresh process.
 */
final readonly class GateClasses
{
    private const string NAMESPACE = 'NightWorksIO\MutationGate\\';

    private const string FILE = '%s/%s.php';

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
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function file_put_contents;
use function hrtime;
use function sprintf;

/**
 * A class declared while a test runs, from a file of its own in a scratch
 * directory, under a name no other test declares: for a test of what loading
 * a class does.
 */
final class Declared
{
    /** A class name no other test declares, starting so. */
    public static function name(string $prefix): string
    {
        return sprintf('%s%d', $prefix, hrtime(as_number: true));
    }

    /** Declares a class of this name. */
    public static function class(string $name): void
    {
        $file = sprintf('%s/%s.php', Scratch::directory(), $name);
        file_put_contents($file, sprintf("<?php\n\nfinal class %s {}\n", $name));

        require $file;
    }
}

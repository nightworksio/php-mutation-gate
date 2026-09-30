<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function chmod;
use function realpath;
use function sprintf;

/**
 * A PHP binary that prints text a test gives it, as a real one prints its
 * description, and writes the arguments each run was started with, one run
 * to a line, and the environment of the last, beside itself, in
 * `arguments.txt` and `environment.txt`.
 */
final class FakePhp
{
    /** A fake PHP in a new directory, which is where it writes what it was started with; the binary is `<directory>/php`. */
    public static function printing(string $output, int $exit = 0): string
    {
        $directory = (string) realpath(Scratch::directory());
        Scratch::write($directory, 'output.txt', $output);
        Scratch::write($directory, 'php', sprintf(<<<'SH'
            #!/bin/sh
            printf '%%s\n' "$*" >> "%1$s/arguments.txt"
            env > "%1$s/environment.txt"
            cat "%1$s/output.txt"
            exit %2$d
            SH, $directory, $exit));
        chmod(sprintf('%s/php', $directory), 0o755);

        return $directory;
    }
}

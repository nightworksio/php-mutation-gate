<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function chmod;
use function realpath;
use function sprintf;

/**
 * A PHP binary that answers `-m` and `-i` as a real one does, from text a
 * test gives it, and writes the arguments and the environment it was started
 * with beside itself, in `arguments.txt` and `environment.txt`.
 */
final class FakePhp
{
    /** A fake PHP in a new directory, which is where it writes what it was started with; the binary is `<directory>/php`. */
    public static function answering(string $modules, string $info, int $exit = 0): string
    {
        $directory = (string) realpath(Scratch::directory());
        Scratch::write($directory, 'modules.txt', $modules);
        Scratch::write($directory, 'info.txt', $info);
        Scratch::write($directory, 'php', sprintf(<<<'SH'
            #!/bin/sh
            printf '%%s\n' "$*" > "%1$s/arguments.txt"
            env > "%1$s/environment.txt"
            for last in "$@"; do :; done
            if [ "$last" = "-m" ]; then cat "%1$s/modules.txt"; else cat "%1$s/info.txt"; fi
            exit %2$d
            SH, $directory, $exit));
        chmod(sprintf('%s/php', $directory), 0o755);

        return $directory;
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function dirname;
use function file_put_contents;
use function json_decode;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

use function sprintf;

use Symfony\Component\Process\Process;

use function var_export;

/**
 * A PHP program run in a process of its own, with the gate's autoloader
 * loaded and no descriptor past the standard three inherited, for what a
 * test runner's process cannot show: what a process holds open from its
 * start, which no earlier test's leftovers and no runner's files reach.
 */
final readonly class FreshProcess
{
    /** The shell that closes every descriptor past the standard three it inherited, then becomes PHP. */
    private const string CLEARED = <<<'SH'
        for fd in $(ls /dev/fd); do
            if [ "$fd" -gt 2 ]; then
                eval "exec $fd>&-"
            fi
        done 2>/dev/null
        exec "$FRESH_PHP" "$FRESH_PROGRAM"
        SH;

    /** What the program printed, read as JSON. */
    public static function answer(string $program): mixed
    {
        $file = sprintf('%s/fresh.php', Scratch::directory());
        file_put_contents($file, sprintf(
            "<?php\n\ndeclare(strict_types=1);\n\nrequire %s;\n\n%s\n",
            var_export(sprintf('%s/vendor/autoload.php', dirname(__DIR__, 2)), return: true),
            $program,
        ));
        $process = Process::fromShellCommandline(self::CLEARED, env: ['FRESH_PHP' => PHP_BINARY, 'FRESH_PROGRAM' => $file]);
        $process->mustRun();

        return json_decode($process->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);
    }
}

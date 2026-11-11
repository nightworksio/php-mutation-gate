<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function ctype_digit;
use function dirname;
use function escapeshellarg;
use function fclose;
use function file_put_contents;
use function is_array;
use function is_resource;
use function json_decode;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

use function proc_close;
use function proc_open;

use RuntimeException;

use function scandir;
use function sprintf;
use function stream_get_contents;
use function var_export;

/**
 * A PHP program run in a process of its own, with the gate's autoloader
 * loaded and no descriptor past the standard three inherited, for what a
 * test runner's process cannot show: what a process holds open from its
 * start, which no earlier test's leftovers and no runner's files reach.
 */
final readonly class FreshProcess
{
    /** Where the system lists the descriptors this process holds. */
    private const string HELD = '/dev/fd';

    /** The first descriptor past standard input, output and error. */
    private const int FIRST = 3;

    /** What the program printed, read as JSON. */
    public static function answer(string $program): mixed
    {
        $file = sprintf('%s/fresh.php', Scratch::directory());
        file_put_contents($file, sprintf(
            "<?php\n\ndeclare(strict_types=1);\n\nrequire %s;\n\n%s\n",
            var_export(sprintf('%s/vendor/autoload.php', dirname(__DIR__, 2)), return: true),
            $program,
        ));
        $command = sprintf(
            'exec %s -d display_errors=stderr -d error_reporting=-1 %s%s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($file),
            self::closing(),
        );
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException(sprintf('PHP could not be started: %s', $command));
        }

        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        if ($exit !== 0) {
            throw new RuntimeException(sprintf("%s exited %d:\n%s%s", $command, $exit, $output, $errors));
        }

        return json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);
    }

    /** A redirection that closes each descriptor past the standard three this process holds, for the program's PHP. */
    private static function closing(): string
    {
        $closing = '';
        $held = scandir(self::HELD);

        foreach (is_array($held) ? $held : [] as $descriptor) {
            if (ctype_digit($descriptor) && (int) $descriptor >= self::FIRST) {
                $closing .= sprintf(' %d>&-', (int) $descriptor);
            }
        }

        return $closing;
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Control;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MaxRss;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;

use function sprintf;
use function trim;

/**
 * The script every runner starts an unmutated control through (ADR-0004,
 * decision 9): it runs the control's command with its own input and output,
 * waits for it, writes the most memory any process under it held, as
 * `getrusage` counts the processes it waited for, to the file it is told
 * first, and exits as the command did. It knows nothing of the runner, so
 * each measures alike (see MaxRss).
 */
final readonly class PeakLauncher
{
    /** The script, as each runner writes it among its own files. */
    public const string SCRIPT = <<<'PHP'
        <?php

        declare(strict_types=1);

        // The mutation gate runs an unmutated control through this: the command
        // after the file it is told, with this process's input and output; then it
        // writes there the most memory any process under it held, and exits as the
        // command did.
        $process = proc_open(array_slice($argv, 2), [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
        $code = is_resource($process) ? proc_close($process) : 127;
        file_put_contents($argv[1], (string) getrusage(1)['ru_maxrss']);
        exit($code < 0 ? 255 : $code);

        PHP;
    /** Where a runner writes the script, in a directory of its controls. */
    private const string FILE = '%s/launcher.php';

    /**
     * The peak the script wrote, on an operating system of this family; none
     * where it wrote nothing that reads as one.
     */
    public static function peakIn(string $written, string $family): MemoryCap|NotGiven
    {
        $peak = trim($written);

        return (string) (int) $peak === $peak && (int) $peak > 0
            ? MemoryCap::atLeast(MaxRss::bytes((int) $peak, $family))
            : NotGiven::value();
    }

    /**
     * What a control found, with the peak the script wrote, where it wrote one
     * that reads as such on an operating system of this family.
     */
    public static function measured(ControlRun $run, string $written, string $family): ControlRun
    {
        $peak = self::peakIn($written, $family);

        return $peak instanceof MemoryCap ? $run->withPeak($peak) : $run;
    }

    /** Where a runner writes the script in this directory of its controls. */
    public static function in(string $directory): string
    {
        return sprintf(self::FILE, $directory);
    }
}

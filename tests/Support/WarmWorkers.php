<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_values;

use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\Forking;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\Job;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\WarmRun;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Warm\Workplace;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\SilenceLimit;

use function pcntl_exec;
use function pcntl_fork;
use function sprintf;

/** The warm workers the tests of the PHPUnit adapter fork, and what they run. */
final readonly class WarmWorkers
{
    /**
     * Forking that makes each child a shell running this script, told what the
     * run tells it, with the worker's standard output file as its argument: it
     * stands in for PHPUnit, which only a worker's own script runs. The child
     * replaces itself at once, so nothing of the test's process runs in it.
     */
    public static function forking(string $script, string $out): Forking
    {
        return new readonly class ($script, $out) implements Forking {
            public function __construct(private string $script, private string $out)
            {
            }

            public function forked(WarmRun $run): int
            {
                $child = pcntl_fork();

                if ($child === 0) {
                    pcntl_exec('/bin/sh', ['-c', $this->script, 'sh', $this->out], $run->environment());
                    exit(127);
                }

                return $child;
            }
        };
    }

    /**
     * A workplace holding a job of these runs, with this config, which no run starts in after the end, of a run that
     * mutates these files.
     *
     * @param list<WarmRun> $runs
     */
    public static function place(
        array $runs,
        string|NotGiven $config = new NotGiven(),
        float|NotGiven $end = new NotGiven(),
        string ...$mutated,
    ): Workplace {
        $workplace = Workplace::at(sprintf('%s/warm', Scratch::directory()));
        $workplace->opened(Job::of(Tree::at('vendor/autoload.php'), $config, array_values($mutated), $end, $runs));

        return $workplace;
    }

    /** @param array<string, string> $told */
    public static function run(array $told, float $limit = 30.0, SilenceLimit|NotGiven $silence = new NotGiven()): WarmRun
    {
        return WarmRun::of([], $told, $limit, '', '', '', $silence);
    }
}

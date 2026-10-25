<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_filter;
use function array_push;
use function min;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\StartUpSamples;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;

/**
 * How long a mutant's own run takes to start on this machine: the fastest of
 * a few runs of no test, each started as the runner starts a mutant's run
 * of a file, its mutant the file unchanged (ADR-0006). A plan
 * weighs units by it; a run lays each mutant's limit on it (ADR-0008),
 * measuring once, before its first mutant, on the machine its mutants run
 * on.
 */
final readonly class StartUpTiming
{
    public function __construct(private Adapters $adapters, private StartUpSamples $samples)
    {
    }

    /**
     * The start-up of a run of these units, served the first file they hold:
     * a unit's own file, or the first file of the map a held unit holds;
     * unmeasured where they hold none, or a run of no test cannot run, so
     * each limit keeps the start-up the rule gives where nothing measured one.
     */
    public function of(CoverageMap $map, Units $units): Seconds|Unmeasured
    {
        $file = $this->firstFileOf($map, $units);
        $fastest = $file instanceof Path ? $this->fastest($file) : Unmeasured::duration();

        return $fastest instanceof Seconds ? $fastest : Unmeasured::duration();
    }

    /** The fastest of the runs of no test, each serving this file unchanged, or why one could not run. */
    public function fastest(Path $file): Seconds|CannotJudge
    {
        $fastest = $this->adapters->runner->startUp($file, $this->adapters->withheld);

        for ($run = 1; $run < $this->samples->runs() && $fastest instanceof Seconds; $run++) {
            $again = $this->adapters->runner->startUp($file, $this->adapters->withheld);
            $fastest = $again instanceof Seconds ? Seconds::of(min($fastest->seconds(), $again->seconds())) : $again;
        }

        return $fastest;
    }

    /** Each unit's own path, and every file of the map a held unit's path holds. */
    public static function filesOf(CoverageMap $map, Units $units): Paths
    {
        $files = [];

        foreach ($units as $unit) {
            array_push($files, $unit->path(), ...($unit->isHeld() ? self::heldBy($map, $unit) : []));
        }

        return Paths::of(...$files);
    }

    /** The first file these units hold: a unit's own path, or the first file of the map a held unit holds. */
    private function firstFileOf(CoverageMap $map, Units $units): Path|NotGiven
    {
        foreach ($units as $unit) {
            $files = $unit->isHeld() ? self::heldBy($map, $unit) : [$unit->path()];

            if ($files !== []) {
                return $files[0];
            }
        }

        return NotGiven::value();
    }

    /**
     * Every file of the map a held unit's path holds, other than the path itself.
     *
     * @return list<Path>
     */
    private static function heldBy(CoverageMap $map, Unit $unit): array
    {
        return [...array_filter(
            [...$map->files()],
            static fn(Path $file): bool => $file->within($unit->path()) && ! $file->equals($unit->path()),
        )];
    }
}

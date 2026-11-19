<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_filter;
use function array_keys;
use function array_map;

use Closure;

use function count;
use function intdiv;
use function max;

use NightWorksIO\MutationGate\Core\Config\Toggle;
use NightWorksIO\MutationGate\Core\Runner\ProcessEnds;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

use function sprintf;
use function usort;

/**
 * Fresh runs (ADR-0148) run side by side, and under `pest.freshRace` raced
 * by parts of their covering tests on the places they leave idle
 * (ADR-0203; see FreshRace).
 *
 * @internal
 */
final readonly class FreshRacing
{
    /** The name of a part of a mutant's own directory that a run beside its fresh run keeps its files in. */
    private const string PART = 'race-%d';

    public function __construct(private Shell $shell, private FreshRunPreparing $preparing, private Patching $patching)
    {
    }

    /**
     * Each run and how it ended, by its position: the runs side by side, and,
     * under `pest.freshRace`, where they leave places idle, parts of their
     * covering tests beside them (ADR-0203). A mutant is settled once its run
     * ends, or once a part kills it by a named test: its run and its other
     * parts are then let go, and the part runs again with its file unmutated.
     * Where that passes, the part stands for the run; where it fails, the run
     * runs again in full.
     *
     * @param  list<FreshRun>                 $runs
     * @param  Closure(): (Seconds|Unlimited) $left
     * @return array{list<FreshRun>, array<int, Ran>}
     */
    public function raced(WorkerSlots $slots, Closure $left, array $runs, FreshBatch $batch): array
    {
        $shell = $this->shell;
        $alone = fn(): array => [$runs, [...$this->sideBySide($slots, $left(), FreshRace::commandsOf($runs))]];

        if (! $shell instanceof StartsCommands || ! $this->patching->steps()->on(Toggle::PestFreshRace)) {
            return $alone();
        }

        $racers = $this->racers($runs, $batch, $slots);

        if ($racers === []) {
            return $alone();
        }

        $race = FreshRace::of($runs, $racers, $batch->request->memory());
        $started = $shell->started($slots, $left(), ...$race->commands());
        $ended = [...$started->endsSettling($race->settled(...))];
        $vouched = [...$this->sideBySide($slots, $left(), $race->controls())];
        $again = $race->lost($vouched);
        $commands = array_map(static fn(int $at): Command => $runs[$at]->command(), $again);
        $rerun = [...$this->sideBySide($slots, $left(), $commands)];

        return $race->settledRuns($ended, $again, $rerun);
    }

    /**
     * The parts of their covering tests that run beside these runs on the
     * places they leave idle, by each run's position: the longest-limited
     * runs first, each given its share of the idle places, a part for each,
     * its covering tests dealt to its parts fastest first; none for a run
     * whose share is under two parts, or whose part cannot be written.
     *
     * @param  list<FreshRun>              $runs
     * @return array<int, list<FreshRun>>
     */
    private function racers(array $runs, FreshBatch $batch, WorkerSlots $slots): array
    {
        $idle = count([...$slots]) - count($runs);
        $longest = array_keys($runs);
        $limit = static fn(int $at): float => $runs[$at]->limit()->seconds();
        usort($longest, static fn(int $one, int $other): int => $limit($other) <=> $limit($one));
        $racers = [];

        foreach ($longest as $turn => $at) {
            $share = intdiv(max(0, $idle), count($longest) - $turn);
            $parts = RaceParts::dealt($runs[$at]->covering(), $batch->map->durations(), $share);
            $made = [];

            foreach ($parts as $part => $tests) {
                $racer = $this->preparing->racing($runs[$at], $tests, sprintf(self::PART, $part), $batch);
                $made = [...$made, ...$racer instanceof FreshRun ? [$racer] : []];
            }

            $racers[$at] = count($made) === count($parts) ? $made : [];
            $idle -= count($racers[$at]);
        }

        return array_filter($racers, static fn(array $made): bool => $made !== []);
    }

    /**
     * These commands run side by side in these places, none started once
     * this time has run out; none where there are none.
     *
     * @param list<Command> $commands
     */
    private function sideBySide(WorkerSlots $slots, Seconds|Unlimited $left, array $commands): ProcessEnds
    {
        return $commands === []
            ? ProcessEnds::of()
            : $this->shell->sideBySide($slots, $left, ...$commands);
    }
}

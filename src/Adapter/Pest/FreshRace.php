<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_diff_key;
use function array_filter;
use function array_flip;
use function array_intersect_key;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_replace;
use function array_values;
use function count;

use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\Ran;

/**
 * Fresh runs raced by parts of their covering tests on idle places
 * (ADR-0203): the runs first and their parts after them, as one set of
 * commands. A mutant is settled once its run ends, or once one of its parts
 * kills it by a named test before that: the part wins, and the run and its
 * other parts are let go. A part's win stands only where the part, run again
 * with its file unmutated, passes; otherwise the run runs again in full.
 *
 * @internal
 */
final class FreshRace
{
    /** @var array<int, int> each part's run's position, by the part's position among the commands */
    private array $partOf = [];

    /** @var array<int, FreshRun> each part, by its position among the commands */
    private array $parts = [];

    /** @var array<int, int> each run a part won, by the run's position: the part's position among the commands */
    private array $won = [];

    /**
     * @param list<FreshRun>             $runs
     * @param array<int, list<FreshRun>> $racers each run's parts, by the run's position
     */
    private function __construct(private readonly array $runs, array $racers, private readonly MemoryCap $memory)
    {
        $position = count($runs);

        foreach ($racers as $at => $parts) {
            foreach ($parts as $part) {
                $this->partOf[$position] = $at;
                $this->parts[$position] = $part;
                $position++;
            }
        }
    }

    /**
     * @param list<FreshRun>             $runs
     * @param array<int, list<FreshRun>> $racers each run's parts, by the run's position
     */
    public static function of(array $runs, array $racers, MemoryCap $memory): self
    {
        return new self($runs, $racers, $memory);
    }

    /**
     * The commands of these runs, in their order.
     *
     * @param  list<FreshRun> $runs
     * @return list<Command>
     */
    public static function commandsOf(array $runs): array
    {
        return array_map(static fn(FreshRun $run): Command => $run->command(), $runs);
    }

    /**
     * The commands of the race: every run, in its position, then every part.
     *
     * @return list<Command>
     */
    public function commands(): array
    {
        return [
            ...self::commandsOf($this->runs),
            ...self::commandsOf(array_values($this->parts)),
        ];
    }

    /**
     * The positions to let go, by the ends so far: each part of a mutant its
     * run or one of its parts has settled, and each run a part has won.
     *
     * @param  array<int, Ran> $ended by position
     * @return list<int>
     */
    public function settled(array $ended): array
    {
        foreach ($this->partOf as $position => $at) {
            $this->won += $this->wins($position, $at, $ended) ? [$at => $position] : [];
        }

        $letGo = [];

        foreach ($this->partOf as $position => $at) {
            $settled = array_key_exists($at, $ended) || array_key_exists($at, $this->won);
            $letGo = [...$letGo, ...$settled && ! array_key_exists($position, $ended) ? [$position] : []];
        }

        $stopped = array_filter(array_keys($this->won), static fn(int $at): bool => ! array_key_exists($at, $ended));

        return [...$letGo, ...array_values($stopped)];
    }

    /**
     * The unmutated run of each winning part, which its win stands on, in the
     * order of the runs they won.
     *
     * @return list<Command>
     */
    public function controls(): array
    {
        return array_values(array_map(fn(int $position): Command => $this->parts[$position]->control(), $this->won));
    }

    /**
     * The runs to run again in full, by their positions: each whose winning
     * part's unmutated run, in the order of {@see controls()}, did not pass,
     * or never started.
     *
     * @param  list<Ran> $vouched
     * @return list<int>
     */
    public function lost(array $vouched): array
    {
        $won = array_keys($this->won);
        $passed = array_keys(array_filter($vouched, static fn(Ran $ran): bool => $ran->succeeded()));

        return array_values(array_diff_key($won, array_flip($passed)));
    }

    /**
     * Each run and how it ended, by its position: a run whose part's win
     * stands as that part, a run that ran again as it ended then, and one
     * whose second run never started left out, as a run that never started
     * is.
     *
     * @param  array<int, Ran> $ended how each command of the race ended, by position
     * @param  list<int>       $again the positions of the runs run again
     * @param  list<Ran>       $rerun how each ended, in the order of $again
     * @return array{list<FreshRun>, array<int, Ran>}
     */
    public function settledRuns(array $ended, array $again, array $rerun): array
    {
        $stands = array_diff_key($this->won, array_flip($again));
        $runs = array_map(
            fn(int $at): FreshRun => array_key_exists($at, $stands) ? $this->parts[$stands[$at]] : $this->runs[$at],
            array_keys($this->runs),
        );
        $ends = array_replace(
            array_diff_key(array_intersect_key($ended, $this->runs), array_flip($again)),
            array_map(static fn(int $position): Ran => $ended[$position], $stands),
        );

        foreach ($again as $turn => $at) {
            $ends += array_key_exists($turn, $rerun) ? [$at => $rerun[$turn]] : [];
        }

        return [$runs, $ends];
    }

    /**
     * Whether the part at this position, of the run at that one, wins it by the ends so far.
     *
     * @param array<int, Ran> $ended by position
     */
    private function wins(int $position, int $at, array $ended): bool
    {
        return ! array_key_exists($at, $this->won)
            && ! array_key_exists($at, $ended)
            && array_key_exists($position, $ended)
            && $this->kills($this->parts[$position], $ended[$position]);
    }

    /** Whether a part that ended so kills its mutant by a named covering test. */
    private function kills(FreshRun $part, Ran $ran): bool
    {
        return FreshVerdict::of($ran, $part->files()->guarded(), $part->logged(), $part->covering(), $this->memory)
            ->killsByName();
    }
}

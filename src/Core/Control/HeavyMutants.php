<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Control;

use function array_filter;
use function array_key_exists;
use function array_map;
use function array_merge;
use function array_slice;
use function array_values;

use Closure;

use function count;
use function is_string;
use function max;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Hold\HeldCovered;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Reach\TestPlaces;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\RunSelection;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function usort;

/**
 * The mutants whose judging tests are too many to run in one run under
 * `timeouts.most` (ADR-0008): each that timed out at the most, where the
 * standard limit of those tests' own time is above it. Each is judged in
 * parts that each fit instead (see MutantParts and PartedMutant), each part
 * allowed the standard limit of its own tests and stopped where no test
 * finishes for the silence limit of its slowest, so a line many tests cover
 * is judged by every one of them, and a mutant whose text the runner cannot
 * give is left to its control. A round runs what each waits on, and fills the
 * rest of its pool with their parts ahead (ADR-0114): what a part ahead finds
 * is kept until its turn, so the pool's width changes when each is judged and
 * never how.
 *
 * @internal
 */
final readonly class HeavyMutants
{
    /**
     * @param array<string, PartedMutant> $parted each heavy mutant, by its id's key
     * @param ControlRuns                 $found  what the rounds so far found of them
     */
    private function __construct(private array $parted, private ControlRuns $found)
    {
    }

    /**
     * The heavy mutants among these, each with its parts.
     *
     * @param Closure(Mutant): (Contents|CannotJudge) $served the text each mutant is served as, or why it cannot be
     * @param Closure(): TestPlaces                    $places the file each test is in, read where a mutant is heavy
     */
    public static function of(
        Mutants $mutants,
        CoverageMap $map,
        HeldCovered $held,
        RunSelection $run,
        LimitBounds $bounds,
        Closure $served,
        Closure $places,
    ): self {
        $parts = MutantParts::under($map, $bounds);
        $judging = static fn(Mutant $mutant): TestIds => self::atMost($mutant, $bounds)
            ? Control::judging($mutant, $map, $held, $run, NotGiven::value())->tests()
            : TestIds::none();
        $heavy = self::heavy($mutants, $parts, $judging);
        $placed = $heavy === [] ? TestPlaces::none() : $places();
        $parted = [];

        foreach ($heavy as $key => [$mutant, $tests]) {
            $text = $served($mutant);
            $split = $parts->of($mutant->location()->file(), $tests, $placed);
            $parted += $text instanceof Contents && $split !== []
                ? [$key => PartedMutant::of($mutant, PartControls::of($mutant, $text, $map, $bounds, $placed), $split)]
                : [];
        }

        return new self($parted, ControlRuns::none());
    }

    /** These, each judged by this many parts at most; by all of them where none is given. */
    public function judgedByAtMost(int|NotGiven $parts): self
    {
        return new self(
            array_map(static fn(PartedMutant $parted): PartedMutant => $parted->atMost($parts), $this->parted),
            $this->found,
        );
    }

    /** Those of these mutants that are not heavy, which their own controls judge. */
    public function without(Mutants $mutants): Mutants
    {
        return Mutants::of(...array_filter(
            [...$mutants],
            fn(Mutant $mutant): bool => ! array_key_exists($mutant->id()->key(), $this->parted),
        ));
    }

    /** The run each heavy mutant not yet judged waits on. */
    public function asked(): Controls
    {
        $asked = Controls::none();

        foreach ($this->parted as $parted) {
            $next = $parted->next();
            $asked = $next instanceof Control ? $asked->with($next) : $asked;
        }

        return $asked;
    }

    /**
     * The parts ahead that run beside what they wait on, in a pool this wide:
     * as many as it has room for, each mutant's next part ahead in turn.
     *
     * @return list<Control>
     */
    public function ahead(ProcessCount $slots): array
    {
        return array_merge(...array_values($this->filling($slots)));
    }

    /**
     * What each heavy mutant not yet judged waits on, and the parts ahead
     * beside it in a pool this wide, said in a log.
     *
     * @return list<string>
     */
    public function progress(ProcessCount $slots): array
    {
        $filling = $this->filling($slots);
        $said = [];

        foreach ($this->parted as $key => $parted) {
            $progress = $parted->progress(count($filling[$key]));
            $said = is_string($progress) ? [...$said, $progress] : $said;
        }

        return $said;
    }

    /** These, each judged as far as what its run found takes it, or unjudged where the time left had no room for it. */
    public function after(ControlRuns $runs, Controls $left): self
    {
        $found = $this->found->and($runs);
        $after = [];

        foreach ($this->parted as $key => $parted) {
            $next = $parted->next();
            $after[$key] = match (true) {
                ! $next instanceof Control => $parted,
                $left->has($next) => $parted->outOfTime(),
                default => $parted->after($runs->of($next))->past($found),
            };
        }

        return new self($after, $found);
    }

    /** These mutants, each heavy one as its parts judged it. */
    public function applied(Mutants $mutants): Mutants
    {
        return $mutants->replacing(Mutants::of(...array_map(
            static fn(PartedMutant $parted): Mutant => $parted->judged(),
            $this->parted,
        )));
    }

    /**
     * The parts ahead a pool this wide has room for, by each mutant's key.
     *
     * @return array<string, list<Control>>
     */
    private function filling(ProcessCount $slots): array
    {
        $queue = [];

        foreach ($this->parted as $key => $parted) {
            foreach ($parted->ahead($this->found) as $depth => $part) {
                $queue[] = [$depth, $key, $part];
            }
        }

        usort($queue, static fn(array $one, array $other): int => $one[0] <=> $other[0]);
        $filling = array_map(static fn(): array => [], $this->parted);

        foreach (array_slice($queue, 0, max(0, $slots->count() - count($this->asked()))) as [, $key, $part]) {
            $filling[$key][] = $part;
        }

        return $filling;
    }

    /**
     * The heavy mutants among these, each with its judging tests, by its id's key.
     *
     * @param  Closure(Mutant): TestIds              $judging the tests that judge a mutant timed out at the most
     * @return array<string, array{Mutant, TestIds}>
     */
    private static function heavy(Mutants $mutants, MutantParts $parts, Closure $judging): array
    {
        $heavy = [];

        foreach ($mutants as $mutant) {
            $tests = $judging($mutant);
            $overflows = count($tests) > 1 && $parts->overflow($tests);
            $heavy += $overflows ? [$mutant->id()->key() => [$mutant, $tests]] : [];
        }

        return $heavy;
    }

    /**
     * Whether a mutant timed out at the most its bounds allow, that limit and
     * not its silence limit or a test's own having stopped it (ADR-0108).
     */
    private static function atMost(Mutant $mutant, LimitBounds $bounds): bool
    {
        $limit = $mutant->limit();
        $reason = $mutant->reason();

        return $mutant->status() === MutantStatus::TimedOut
            && $limit instanceof Seconds
            && $limit->seconds() >= $bounds->most()->seconds()
            && (!$reason instanceof Reason || !$reason->stoppedEarly());
    }
}

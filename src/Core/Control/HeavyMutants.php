<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Control;

use function array_filter;
use function array_key_exists;
use function array_map;

use Closure;

use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\OwnTime;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Hold\HeldCovered;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\RunnerMutatorName;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Reach\TestPlaces;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MutantLimit;
use NightWorksIO\MutationGate\Core\Runner\RunSelection;
use NightWorksIO\MutationGate\Core\Runner\RunTests;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * The mutants whose judging tests are too many to run in one run under
 * `timeouts.most` (ADR-0008): each that timed out at the most, where the
 * standard limit of those tests' own time is above it. Each is judged in
 * parts that each fit instead (see MutantParts and PartedMutant), each part
 * allowed the standard limit of its own tests and stopped where no test
 * finishes for the silence limit of its slowest, so a line many tests cover
 * is judged by every one of them, and a mutant whose text the runner cannot
 * give is left to its control.
 */
final readonly class HeavyMutants
{
    /** @param array<string, PartedMutant> $parted each heavy mutant, by its id's key */
    private function __construct(private array $parted)
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
                ? [$key => PartedMutant::of($mutant, self::controls($mutant, $split, $text, $map, $bounds))]
                : [];
        }

        return new self($parted);
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

    /** These, each judged as far as what its run found takes it, or unjudged where the time left had no room for it. */
    public function after(ControlRuns $runs, Controls $left): self
    {
        $after = [];

        foreach ($this->parted as $key => $parted) {
            $next = $parted->next();
            $after[$key] = match (true) {
                ! $next instanceof Control => $parted,
                $left->has($next) => $parted->outOfTime(),
                default => $parted->after($runs->of($next)),
            };
        }

        return new self($after);
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

    /** Whether a mutant timed out at the most its bounds allow. */
    private static function atMost(Mutant $mutant, LimitBounds $bounds): bool
    {
        $limit = $mutant->limit();

        return $mutant->status() === MutantStatus::TimedOut
            && $limit instanceof Seconds
            && $limit->seconds() >= $bounds->most()->seconds();
    }

    /**
     * Each part, served the mutant under the standard limit of its tests and
     * the silence limit of its slowest.
     *
     * @param  non-empty-list<RunTests> $parts
     * @return non-empty-list<Control>
     */
    private static function controls(
        Mutant $mutant,
        array $parts,
        Contents $text,
        CoverageMap $map,
        LimitBounds $bounds,
    ): array {
        $file = $mutant->location()->file();
        $silent = $bounds->silenceOf(RunnerMutatorName::of($mutant->mutation()->mutator()));

        return array_map(
            static fn(RunTests $part): Control => Control::running(
                $file,
                $part,
                MutantLimit::standard()->of(OwnTime::of($map, $part->tests()), $bounds),
            )->serving($text, MutantLimit::standard()->of(OwnTime::slowest($map, $part->tests()), $silent)),
            $parts,
        );
    }
}

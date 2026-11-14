<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function implode;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\LooseValues;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Config\Toggle;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Ratchet;
use NightWorksIO\MutationGate\Core\Verdict\SecurityVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

use function sprintf;

/**
 * What a verdict warns of beside what it fails: each tree held to no floor
 * outside CI, the runner's own ignore markers the config lets through, each
 * mutator set a preset turns on that is not installed, and what the shards warn
 * of; and what it notes (ADR-0111): the files most of the suite runs through
 * that nothing holds, where the gate suggests changes (ADR-0104), or why the
 * kill matrix holds killers alone; why the tests go by their ids; and the suites
 * whose tests the gate runs none of, as they serve the application from another
 * process; unless `suggest.emptyHolding` is off, what `tests.holding`
 * written as an empty list leaves unjudged (ADR-0116); and, unless
 * `suggest.looseValues` is off, each loose value in force (ADR-0142).
 *
 * @internal
 */
final readonly class VerdictWarnings
{
    private const string NO_MATRIX = 'The kill matrix holds each mutant\'s killers alone. %s';

    private const string UNNAMED = 'The reports name each test by its coverage id. %s';

    private const string UNFLOORED = '%s has no floor yet. Run %s and commit %s.';

    private const string ELSEWHERE = <<<'SAID'
        The suites %s serve the application from another process, where a mutant cannot be served,
        so the gate runs none of their tests: a line only they run reads as uncovered.
        SAID;

    private const string HOLDS_NONE = <<<'SAID'
        tests.holding is an empty list, as if it were left out: the tests of each suite tests.suites leaves out
        judge no mutant, not even of the code they hold with #[Holds] or a holds: group. Name the suites whose
        tests hold code, or set suggest.emptyHolding to false to keep it so without this note.
        SAID;

    private const string SECURITY_UNFLOORED
        = 'The security set of %s has no floor yet. Run %s and commit %s.';

    public function __construct(private Adapters $adapters, private Settings $settings, private Setup $setup)
    {
    }

    /** Every warning of a verdict over these trees, from the plan, what the shards warn of, and the map it read. */
    public function of(Plan $plan, TreeVerdicts $verdicts, Warnings $shards, CoverageMap|CannotJudge $map): Warnings
    {
        $suggests = $this->settings->effective()->output()->reports()->suggest()->enabled();
        $raised = match (true) {
            $map instanceof CannotJudge => $shards->with(Warning::noting(sprintf(self::NO_MATRIX, $map->why()))),
            $suggests => $this->hotPathsIn($plan, $map, $shards),
            default => $shards,
        };

        return $this->listed($plan, $verdicts, $raised);
    }

    /** Outside CI, a warning for each security set held to no floor (ADR-0021). */
    public function unfloored(SecurityVerdicts $security): Warnings
    {
        $warnings = Warnings::none();

        foreach ($this->adapters->environment->inCi() ? [] : Ratchet::securityUnfloored($security) as $package) {
            $warnings = $warnings->with(Warning::that(sprintf(
                self::SECURITY_UNFLOORED,
                $package->value(),
                $this->setup->command->baselineWritten(),
                $this->settings->floors()->baseline()->value(),
            )));
        }

        return $warnings;
    }

    /**
     * Each tree held to no floor, the runner's own ignore markers the config
     * lets through, the suites the gate runs no test of, why the tests go by
     * their ids where the plan holds no names for them, and what the shards
     * warn of.
     */
    private function listed(Plan $plan, TreeVerdicts $verdicts, Warnings $shards): Warnings
    {
        $warnings = new RunnerMarkers($this->adapters, $this->settings)->allowed($plan)
            ->and($this->adapters->skippedSets);
        $elsewhere = $this->adapters->suite->elsewhere();
        $warnings = $elsewhere === []
            ? $warnings
            : $warnings->with(Warning::noting(sprintf(self::ELSEWHERE, implode(', ', $elsewhere))));
        $warnings = $this->holdsNone() ? $warnings->with(Warning::noting(self::HOLDS_NONE)) : $warnings;
        $warnings = $this->looseIn($warnings);
        $names = $plan->names();
        $warnings = $names instanceof CannotJudge
            ? $warnings->with(Warning::noting(sprintf(self::UNNAMED, $names->why())))
            : $warnings;

        foreach ($shards as $warning) {
            $warnings = $warnings->with($warning);
        }

        foreach ($this->adapters->environment->inCi() ? [] : Ratchet::unfloored($verdicts) as $tree) {
            $warnings = $warnings->with(Warning::that(sprintf(
                self::UNFLOORED,
                $tree->value(),
                $this->setup->command->baselineWritten(),
                $this->settings->floors()->baseline()->value(),
            )));
        }

        return $warnings;
    }

    /**
     * These warnings, and a note of each loose value in force (ADR-0142), unless `suggest.looseValues` or
     * `suggest.enabled` is off.
     */
    private function looseIn(Warnings $warnings): Warnings
    {
        $noted = $this->settings->effective()->output()->reports()->suggest()->enabled()
            && $this->settings->toggles()->on(Toggle::SuggestLooseValues);

        foreach ($noted ? LooseValues::in($this->settings) : [] as $loose) {
            $warnings = $warnings->with(Warning::noting($loose->noted()));
        }

        return $warnings;
    }

    /**
     * Whether `tests.holding` is written as an empty list while `tests.suites`
     * names the suites that judge, and the verdict notes what that means.
     */
    private function holdsNone(): bool
    {
        $suites = $this->settings->triage()->suites();

        return $suites->holdsNone()
            && ! $suites->everyUnit()->isAll()
            && $this->settings->effective()->output()->reports()->suggest()->emptyHolding();
    }

    /**
     * These warnings, and one for each file most of the suite runs through
     * that no held unit of the plan holds, past `holds.hotPath` of its tests
     * (ADR-0005): each of its mutants runs most of the suite.
     */
    private function hotPathsIn(Plan $plan, CoverageMap $map, Warnings $warnings): Warnings
    {
        $units = [...$plan->considered()->proved(), ...$plan->considered()->carried()];

        foreach ($plan as $shard) {
            $units = [...$units, ...$shard->units()];
        }

        foreach ($this->settings->reach()->held()->hotPaths()->in($map, Units::of(...$units)) as $hot) {
            $warnings = $warnings->with($hot);
        }

        return $warnings;
    }
}

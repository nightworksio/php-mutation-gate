<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Settings;
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
 * What a verdict warns of beside what it fails: the files most of the suite
 * runs through that nothing holds, or why the kill matrix holds killers
 * alone; each tree held to no floor outside CI; the runner's own ignore
 * markers the config lets through; why the tests go by their ids; each
 * mutator set a preset turns on that is not installed; and what the shards
 * warn of.
 */
final readonly class VerdictWarnings
{
    private const string NO_MATRIX = 'The kill matrix holds each mutant\'s killers alone. %s';

    private const string UNNAMED = 'The reports name each test by its coverage id. %s';

    private const string UNFLOORED = '%s has no floor yet. Run mutation-gate baseline --write and commit %s.';

    private const string SECURITY_UNFLOORED
        = 'The security set of %s has no floor yet. Run mutation-gate baseline --write and commit %s.';

    public function __construct(private Adapters $adapters, private Settings $settings)
    {
    }

    /** Every warning of a verdict over these trees, from the plan, what the shards warn of, and the map it read. */
    public function of(Plan $plan, TreeVerdicts $verdicts, Warnings $shards, CoverageMap|CannotJudge $map): Warnings
    {
        $raised = $map instanceof CannotJudge
            ? $shards->with(Warning::that(sprintf(self::NO_MATRIX, $map->why())))
            : $this->hotPathsIn($plan, $map, $shards);

        return $this->listed($plan, $verdicts, $raised);
    }

    /** Outside CI, a warning for each security set held to no floor (ADR-0021, decision 17). */
    public function unfloored(SecurityVerdicts $security): Warnings
    {
        $warnings = Warnings::none();

        foreach ($this->adapters->environment->inCi() ? [] : Ratchet::securityUnfloored($security) as $package) {
            $warnings = $warnings->with(Warning::that(sprintf(
                self::SECURITY_UNFLOORED,
                $package->value(),
                $this->settings->floors()->baseline()->value(),
            )));
        }

        return $warnings;
    }

    /**
     * Each tree held to no floor, the runner's own ignore markers the config
     * lets through, why the tests go by their ids where the plan holds no
     * names for them, and what the shards warn of.
     */
    private function listed(Plan $plan, TreeVerdicts $verdicts, Warnings $shards): Warnings
    {
        $warnings = new RunnerMarkers($this->adapters, $this->settings)->allowed($plan)
            ->and($this->adapters->skippedSets);
        $names = $plan->names();
        $warnings = $names instanceof CannotJudge
            ? $warnings->with(Warning::that(sprintf(self::UNNAMED, $names->why())))
            : $warnings;

        foreach ($shards as $warning) {
            $warnings = $warnings->with($warning);
        }

        foreach ($this->adapters->environment->inCi() ? [] : Ratchet::unfloored($verdicts) as $tree) {
            $warnings = $warnings->with(Warning::that(sprintf(
                self::UNFLOORED,
                $tree->value(),
                $this->settings->floors()->baseline()->value(),
            )));
        }

        return $warnings;
    }

    /**
     * These warnings, and one for each file most of the suite runs through
     * that no held unit of the plan holds, past `holds.hotPath` of its tests
     * (ADR-0005, decision 11): each of its mutants runs most of the suite.
     */
    private function hotPathsIn(Plan $plan, CoverageMap $map, Warnings $warnings): Warnings
    {
        $units = [...$plan->considered()->proved(), ...$plan->considered()->carried()];

        foreach ($plan as $shard) {
            $units = [...$units, ...$shard->units()];
        }

        foreach ($this->settings->reach()->hotPaths()->in($map, Units::of(...$units)) as $hot) {
            $warnings = $warnings->with($hot);
        }

        return $warnings;
    }
}

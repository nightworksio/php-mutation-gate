<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function count;
use function implode;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\NativeMarkers;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

use function sprintf;

/**
 * The runner's own ignore markers in the files a run mutates (ADR-0008,
 * decision 4). They hide mutants with no reason and no end, so under
 * `ignores.native: refuse` a plan stops before anything is mutated, listing
 * each with the `ignores.entries` entry that replaces it; under `allow` the
 * verdict says how many there are.
 */
final readonly class RunnerMarkers
{
    private const string REFUSED = <<<'SAID'
        The runner's own ignore markers hide mutants with no reason and no end,
        so the run cannot go ahead:
        %s
        Replace each with its entry in ignores.entries,
        or set ignores.native: allow while the project moves them there.
        SAID;

    private const string MARKER = "  %s\n    replaced by %s";

    private const string ALLOWED = <<<'SAID'
        %d of the runner's own ignore markers hide mutants in the files this run mutated,
        as ignores.native: allow lets them. The gate cannot count the mutants they hide.
        Move them into ignores.entries.
        SAID;

    private const string UNCOUNTED = 'The runner\'s own ignore markers could not be counted. %s';

    public function __construct(private Adapters $adapters, private Settings $settings)
    {
    }

    /** The units, where the runner has no marker in their files or the config allows those it has. */
    public function refusing(Units $toRun): Units|CannotJudge
    {
        $markers = $this->in($toRun);

        return match (true) {
            $markers instanceof CannotJudge => $markers,
            count($markers) === 0 || $this->settings->ignores()->native() === NativeMarkers::Allow => $toRun,
            default => CannotJudge::because(sprintf(self::REFUSED, $this->listed($markers))),
        };
    }

    /** How many markers the run's shards let through under `allow`; nothing under `refuse`. */
    public function allowed(Plan $plan): Warnings
    {
        $units = Units::none();

        foreach ($plan as $shard) {
            foreach ($shard->units() as $unit) {
                $units = $units->with($unit);
            }
        }

        $markers = $this->settings->ignores()->native() === NativeMarkers::Allow ? $this->in($units) : Markers::none();

        return match (true) {
            $markers instanceof CannotJudge => Warnings::of(Warning::that(sprintf(self::UNCOUNTED, $markers->why()))),
            count($markers) === 0 => Warnings::none(),
            default => Warnings::of(Warning::that(sprintf(self::ALLOWED, count($markers)))),
        };
    }

    /** The markers in the units' files; none where no unit runs. */
    private function in(Units $units): Markers|CannotJudge
    {
        $files = Paths::none();

        foreach ($units as $unit) {
            $files = $files->with($unit->path());
        }

        return count($units) === 0 ? Markers::none() : $this->adapters->runner->markers($files);
    }

    private function listed(Markers $markers): string
    {
        $listed = [];

        foreach ($markers as $marker) {
            $listed[] = sprintf(self::MARKER, $marker->described(), $marker->replacement());
        }

        return implode("\n", $listed);
    }
}

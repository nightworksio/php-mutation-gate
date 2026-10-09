<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Init;

use function count;
use function implode;

use NightWorksIO\MutationGate\Cli\Command\FlowOptions;
use NightWorksIO\MutationGate\Cli\Command\LineCountEstimate;
use NightWorksIO\MutationGate\Cli\Command\Output;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Cli\Flow\Handoff;
use NightWorksIO\MutationGate\Cli\Flow\Mode;
use NightWorksIO\MutationGate\Cli\Flow\PlanMade;
use NightWorksIO\MutationGate\Cli\Flow\Planning;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Hold\HotPaths;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\PlanEstimates;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Extension\Extensions;

use function sprintf;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * What `init` says a full run takes (ADR-0017, decision 4): measured by one
 * coverage run of the suite and cut into shards as `shards.seconds` or
 * `shards.target` asks, with the hot paths that weigh on it and the
 * `#[Holds]` that cuts each. The same run shows the suite passes under
 * coverage, so a failure is said now, with why, rather than on a first run.
 * With `--no-measure`, or where `init` runs nothing, as `--dry-run` does, the
 * estimate comes from lines of code.
 */
final readonly class FullRunEstimate
{
    private const string NO_MEASURE = 'no-measure';

    private const string MEASURED
        = 'One coverage run of the suite measured a full run. Shards the config cuts it into: %d.';

    private const string GUESSED = <<<'SAID'
        A full run is estimated at about %s in one job, from its lines of code. A coverage run of the suite,
        which init runs without --no-measure, measures it.
        SAID;

    private const string UNMEASURED = 'A full run could not be estimated: %s';

    private const string INVALID = 'the config does not read back. mutation-gate doctor says why.';

    public function __construct(
        private Composition $composition,
        private Extensions $extensions,
        private string $project,
    ) {
    }

    public static function options(Command $command): Command
    {
        return $command->addOption(
            self::NO_MEASURE,
            mode: InputOption::VALUE_NONE,
            description: 'Estimate a full run from lines of code, rather than from a coverage run of the suite',
        );
    }

    /**
     * What a full run of a project whose config is new is expected to take,
     * said: measured where `init` writes what it made, and from lines of code
     * where it prints it, or where `--no-measure` asks for no run. Nothing
     * where the config was here before.
     */
    public function said(InputInterface $input, Settings $settings, Output $output, bool $fresh): string
    {
        return match (true) {
            ! $fresh => '',
            $output === Output::Written && $input->getOption(self::NO_MEASURE) !== true => $this->measured($input),
            default => $this->guessed($settings),
        };
    }

    /** The estimate one coverage run measures, with the hot paths it finds; or why there is none. */
    private function measured(InputInterface $input): string
    {
        $composed = $this->composition->compose($input);
        $made = match (true) {
            $composed instanceof Invalid => CannotJudge::because(self::INVALID),
            $composed instanceof CannotJudge => $composed,
            default => new Planning($composed->adapters, $composed->settings, $composed->setup)->plan(
                Mode::full(),
                CoverageRun::of(WholeSuite::tests(), Workspace::coverage()),
                FlowOptions::configuredCut($composed->settings),
                MatrixKind::FirstKiller,
            ),
        };

        return $made instanceof PlanMade && $composed instanceof Composed
            ? $this->lines($composed, $made->plan())
            : sprintf(self::UNMEASURED, $made->why());
    }

    /** The plan's estimate, what it rests on, and each hot path with the `#[Holds]` that cuts it. */
    private function lines(Composed $composed, Plan $plan): string
    {
        $shards = $composed->settings->shards();
        $estimates = PlanEstimates::of($plan, $shards->setup());
        $lines = [
            sprintf(self::MEASURED, count($plan)),
            $estimates->total(),
            ...$estimates->assumed($composed->adapters->processes()),
        ];

        foreach ($estimates->unmet($shards->target(), $shards->max()) as $warning) {
            $lines[] = $warning->text();
        }

        $map = new Handoff($composed->adapters->project, Handoff::limits())->forVerdict();

        foreach ($map instanceof CoverageMap ? $this->hot($composed, $plan, $map) : [] as $line) {
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Each file most of the suite runs through that no unit of the plan
     * holds, with the `#[Holds]` that stops it being hot.
     *
     * @return list<string>
     */
    private function hot(Composed $composed, Plan $plan, CoverageMap $map): array
    {
        $units = Units::of(...$plan->considered()->proved(), ...$plan->considered()->carried());

        foreach ($plan as $shard) {
            $units = Units::of(...$units, ...$shard->units());
        }

        $lines = [];

        foreach ($composed->settings->reach()->hotPaths()->hot($map, $units) as $file) {
            $lines[] = HotPaths::said($map, $file);
            $lines[] = HotPaths::holding($file);
        }

        return $lines;
    }

    /** The estimate from lines of code, or why there is none. */
    private function guessed(Settings $settings): string
    {
        $estimate = LineCountEstimate::of($this->extensions, $this->project, $settings);

        return $estimate instanceof Seconds
            ? sprintf(self::GUESSED, $estimate->text())
            : sprintf(self::UNMEASURED, $estimate->why());
    }
}

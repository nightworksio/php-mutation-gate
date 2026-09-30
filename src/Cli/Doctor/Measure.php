<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Doctor;

use NightWorksIO\MutationGate\Adapter\Runtime\ChildMemory;
use NightWorksIO\MutationGate\Cli\Flow\Adapters;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Cli\Flow\Inventory;
use NightWorksIO\MutationGate\Cli\Flow\Ledgers;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Doctor\Measurement;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Proof\Timings;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Unit\Units;
use Symfony\Component\Console\Input\InputInterface;

/**
 * What `doctor --measure` adds by running the project's suite (ADR-0017,
 * decisions 4 and 9): the adapters a run builds, the units a run finds, one
 * coverage run of the whole suite, withholding what a run withholds, and the
 * timings the ledgers learned, with the most resident memory the suite's
 * processes held. It writes no coverage map. Where a run could
 * not get as far as the coverage run, the measurement says why; an invalid
 * config it leaves to the check that reports it.
 */
final readonly class Measure
{
    public function __construct(private Composition $composition)
    {
    }

    /** These observations, with what running the suite measured, where the config lets a run be built. */
    public function into(Observations $observed, InputInterface $input): Observations
    {
        $composed = $this->composition->compose($input);

        return match (true) {
            $composed instanceof Composed => $observed->withAsked(
                $observed->asked()->withMeasurement($this->measured($composed->adapters, $composed->settings)),
            ),
            $composed instanceof CannotJudge => $observed->withAsked(
                $observed->asked()->withMeasurement($this->failed($composed)),
            ),
            default => $observed,
        };
    }

    private function measured(Adapters $adapters, Settings $settings): Measurement
    {
        $inventory = Inventory::of($adapters, $settings);

        if ($inventory instanceof CannotJudge) {
            return $this->failed($inventory);
        }

        $request = CoverageRun::of(WholeSuite::tests(), Workspace::coverage())->withholding($adapters->withheld);

        $measured = Measurement::of(
            $adapters->runner->coverage($request),
            $inventory->units,
            Ledgers::read($adapters->proofs, $inventory->standing, Writing::Never)->timings(),
        );
        $peak = ChildMemory::peak();

        return $peak instanceof MemoryCap ? $measured->peakingAt($peak) : $measured;
    }

    private function failed(CannotJudge $why): Measurement
    {
        return Measurement::of($why, Units::none(), Timings::none());
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Cost\FirstRun;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\NewestProofs;
use NightWorksIO\MutationGate\Core\Proof\TakenProofs;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Reach\Reasons;
use NightWorksIO\MutationGate\Core\Sample\Candidate;
use NightWorksIO\MutationGate\Core\Sample\ExpectedWork;
use NightWorksIO\MutationGate\Core\Sample\SampledRun;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Unit\Units;

use function sprintf;

/**
 * Whether a run samples of its own accord (ADR-0086): a run of a kind
 * `sampling.autoRuns` lists, that asked for no sample and takes proofs, whose
 * plan expects more work beyond its new code, in the trees a sample may
 * leave out, than `sampling.auto` allows, samples the units it runs that hold
 * no line of new code, reached or not.
 *
 * @internal
 */
final readonly class AutoSampling
{
    private const string SAID
        = 'Beyond its new code the change reaches %s, over sampling.auto\'s %s, so the plan samples it (ADR-0086).';

    private function __construct(private Mode $mode, private ExpectedWork|NotGiven $work, private string $above)
    {
    }

    /** What a run of this mode decides, running these units, with these as its new code. */
    public static function of(
        Adapters $adapters,
        Settings $settings,
        Setup $setup,
        Mode $mode,
        Inventory $inventory,
        Ledgers $ledgers,
        Units $toRun,
        Changes|CannotJudge $newCode,
        NewestProofs $newest,
    ): self {
        $sampling = $settings->reach()->sampling();
        $auto = $sampling->auto();
        $kind = SampledRun::of($inventory->standing->runOn());
        $takesNone = $mode->takes($settings->proofs()->taken()) === TakenProofs::None;
        $asked = $mode->isSampled() || $takesNone || ! $newCode instanceof Changes;

        if ($auto->isOff() || $asked || ! $kind instanceof SampledRun || ! $sampling->samplesOfItself($kind)) {
            return new self($mode, NotGiven::value(), $auto->said());
        }

        $beyond = new Sampled($adapters, $settings, $setup)
            ->samplable($inventory, $toRun->except(Sampled::holdingNewCode($inventory, $newCode)));
        $work = self::workOf(
            $adapters,
            $settings,
            $setup,
            $ledgers,
            $beyond instanceof Units ? $beyond : Units::none(),
            $newest,
        );

        return $auto->samples($work)
            ? new self($mode->sampledByItself(), $work, $auto->said())
            : new self($mode, NotGiven::value(), $auto->said());
    }

    /** The mode the run takes: sampled of its own accord, or as it was asked. */
    public function mode(): Mode
    {
        return $this->mode;
    }

    /** These reasons, and one saying why the plan samples, where it does. */
    public function said(Reasons $reasons): Reasons
    {
        return $this->work instanceof ExpectedWork
            ? $reasons->with(Reason::that(sprintf(self::SAID, $this->work->said(), $this->above)))
            : $reasons;
    }

    /** How many mutants, and how much runner time, these units are expected to take. */
    private static function workOf(
        Adapters $adapters,
        Settings $settings,
        Setup $setup,
        Ledgers $ledgers,
        Units $units,
        NewestProofs $newest,
    ): ExpectedWork {
        $estimated = UnitMutants::of($adapters, $settings, $newest);
        $costs = $ledgers->costs($setup->gate);
        $mutants = 0;
        $seconds = 0.0;

        foreach ($units as $unit) {
            $candidate = $estimated->candidate($unit);
            $mutants += $candidate instanceof Candidate ? $candidate->mutants() : 0;
            $cost = $costs->estimated($adapters->costs, $unit, FirstRun::unmeasured());
            $seconds += $cost->seconds()->seconds();
        }

        return ExpectedWork::of($mutants, Seconds::of($seconds));
    }
}

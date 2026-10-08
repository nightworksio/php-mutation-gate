<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Verdict\Doom;
use NightWorksIO\MutationGate\Core\Verdict\Ignoring;
use NightWorksIO\MutationGate\Core\Verdict\Undoomed;
use Psr\Clock\ClockInterface;

/**
 * Whether a shard of a plan stops once its run cannot pass (ADR-0008,
 * decision 6), and by what it judges that: a pull request's run, which the
 * verdict holds to its trees' floors and the new code's, judged over the
 * trees, the committed baseline, the lines the plan's change reached, the
 * new-code floor and the config's ignores, as the verdict judges them. A
 * survivor static analysis clears dooms nothing (see Batched). A run of the
 * security mutators alone or of one suite's tests alone holds no tree to a
 * floor, so it runs to its end, as any other run does, and as one does whose
 * trees or baseline cannot be read.
 */
final readonly class Dooming
{
    public function __construct(private Adapters $adapters, private Settings $settings, private ClockInterface $clock)
    {
    }

    public function of(Plan $plan): Doom|Undoomed
    {
        if (! $plan->runOn()->isPullRequest() || ! $this->holdsTrees()) {
            return Undoomed::run();
        }

        $trees = $this->adapters->trees->trees();
        $baseline = new Baselines($this->adapters, $this->settings->floors()->baseline())->committed();

        return $trees instanceof CannotJudge || $baseline instanceof CannotJudge ? Undoomed::run() : Doom::over(
            $trees,
            $baseline,
            $plan->considered()->lines(Packages::of($trees)),
            $this->settings->floors()->newCode(),
            Ignoring::of($this->settings->ignores()->entries(), $this->clock->now()),
        );
    }

    /** Whether the verdict holds the trees to their floors: no run of the security mutators or one suite alone. */
    private function holdsTrees(): bool
    {
        return ! $this->adapters->isSecurityOnly() && ! $this->adapters->isSuiteOnly();
    }
}

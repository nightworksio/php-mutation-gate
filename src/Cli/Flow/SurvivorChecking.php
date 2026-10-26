<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_filter;

use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\NoAnalyser;
use NightWorksIO\MutationGate\Core\Analysis\SurvivorChecks;
use NightWorksIO\MutationGate\Core\Analysis\Unchecked;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Time\Deadline;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Port\StaticChecker;
use Psr\Clock\ClockInterface;

/**
 * Static analysis's check of a shard's survivors, after their tests
 * (ADR-0020). The analyser runs once over the
 * original files, then checks the survivors not passed over side by side on
 * the machine's cores, each in place of its file, as its runner gives it,
 * with the files it can break where it changes what its file declares and
 * the analyser reads them. A
 * survivor with an error its original does not have is killed by static
 * analysis. One the analyser cannot check, or the time budget has no room
 * for, stays a survivor, and the checks say why. A survivor printed as its
 * runner prints its mutants is judged against its original printed the same
 * way, which must first analyse as the file itself does. The checks record
 * their time alone (ADR-0020), and each round of them is timed
 * on the shard's stopwatch.
 */
final readonly class SurvivorChecking
{
    public function __construct(
        private Adapters $adapters,
        private ClockInterface $clock,
        private Deadline|Unlimited $deadline,
        private Seconds $perCheck,
        private Stopwatch $stopwatch,
        private AnalyserWarmUp $warmUp = new AnalyserWarmUp(),
    ) {
    }

    /**
     * These mutants, each survivor the analyser rejects killed by static
     * analysis, with what the checks came to. A survivor among those passed
     * over, flaky or already checked, is not checked.
     */
    public function checked(Mutants $mutants, MutantIds $passedOver): Checked
    {
        $survivors = Mutants::of(...array_filter(
            [...$mutants],
            static fn(Mutant $mutant): bool => $mutant->status() === MutantStatus::Survived
                && ! $passedOver->has($mutant->id()),
        ));
        $checker = $this->adapters->checker;
        $from = $this->stopwatch->now();
        $checked = $checker instanceof NoAnalyser || $survivors->count() === 0
            ? new Checked($mutants, SurvivorChecks::none(), $survivors)
            : $this->warmedUp($checker, $mutants, $survivors);
        $this->stopwatch->handled(Step::StaticCheck, $from, $survivors->count());

        return $checked;
    }

    /**
     * The survivors checked, where the analyser said who it is and the time
     * left has room for its run over the originals; or every one left.
     */
    private function warmedUp(StaticChecker $checker, Mutants $mutants, Mutants $survivors): Checked
    {
        $identity = $this->adapters->analyser;

        return match (true) {
            ! $identity instanceof AnalyserIdentity => Checked::leaving($mutants, $survivors, Unchecked::Unidentified),
            $this->deadline instanceof Deadline && $this->deadline->hasPassed($this->clock->now())
                => Checked::leaving($mutants, $survivors, Unchecked::OutOfTime),
            default => $this->warmed($checker, $identity, $mutants, $survivors),
        };
    }

    /**
     * The analyser's one run over the originals, timed, made once for every
     * check of the shard (see AnalyserWarmUp), then each survivor checked; or
     * every one left.
     */
    private function warmed(
        StaticChecker $checker,
        AnalyserIdentity $identity,
        Mutants $mutants,
        Mutants $survivors,
    ): Checked {
        $warm = $this->warmUp->of($checker, $identity, $this->adapters, $this->clock);

        return $warm instanceof WarmedUp
            ? new SurvivorBatch($this->adapters, $this->clock, $this->deadline, $this->perCheck)
                ->checked($warm, $mutants, $survivors)
            : Checked::leaving($mutants, $survivors, $warm);
    }
}

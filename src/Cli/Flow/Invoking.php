<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function max;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Time\Deadline;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use Psr\Clock\ClockInterface;

/**
 * One runner invocation of a shard, as the shard makes it: each mutant
 * whose time ran out at the configured cap run once more with the cap
 * doubled, where the runner can raise it, and each survivor run once more,
 * where `flaky.confirmSurvivors` asks for it (ADR-0008). Under a budget, a
 * mutant whose second run would not fit in the time left, at its whole
 * limit, is not run again, and is unjudged.
 */
final readonly class Invoking
{
    /** A retried timeout's cap is the configured one doubled. */
    private const int DOUBLED = 2;

    public function __construct(
        private Adapters $adapters,
        private Settings $settings,
        private ClockInterface $clock,
        private Deadline|Unlimited $deadline,
    ) {
    }

    /** One invocation, its timeouts retried and its survivors run once more; or the first cannot judge. */
    public function invoked(MutationRequest $request, int $retries): Invoked|CannotJudge
    {
        $result = $this->adapters->runner->mutate($request);
        $retried = $result instanceof CannotJudge ? $result : $this->retried($result->mutants(), $request, $retries);
        $again = $retried instanceof Mutants ? $this->confirmed($retried, $request) : $retried;

        return match (true) {
            $result instanceof CannotJudge => $result,
            $retried instanceof CannotJudge => $retried,
            $again instanceof CannotJudge => $again,
            default => new Invoked(
                MutationResult::of($again->mutants, $result->skipped()),
                $again->flaky,
                count($this->capped($result->mutants())),
            ),
        };
    }

    /**
     * Timeout retry (ADR-0008): each mutant whose time ran out at the cap, up
     * to the retries left, run once more with the cap doubled, and the rest as
     * they were. A runner whose limit cannot be raised retries nothing.
     */
    private function retried(Mutants $mutants, MutationRequest $request, int $retries): Mutants|CannotJudge
    {
        $taken = array_slice($this->capped($mutants), 0, max(0, $retries));

        if ($taken === [] || ! $this->adapters->runner->behaviour()->raisesLimits()) {
            return $mutants;
        }

        $limit = Seconds::of($this->settings->triage()->limit()->seconds() * self::DOUBLED);
        $fitting = $this->fitting(count($taken), $limit);
        $left = $this->unjudged(array_slice($taken, $fitting), OutOfTime::BeforeRetrying);
        $again = $fitting === 0 ? Mutants::none() : $this->adapters->runner->retry(
            $this->retimed($request),
            Mutants::of(...array_slice($taken, 0, $fitting)),
            $limit,
        );

        return $again instanceof CannotJudge ? $again : $mutants->replacing(Mutants::of(...$again, ...$left));
    }

    /**
     * The request, timed by what the budget has left now, so a run again
     * ends by the deadline the invocation had to; as it was without a budget.
     */
    private function retimed(MutationRequest $request): MutationRequest
    {
        return $this->deadline instanceof Deadline
            ? $request->within($this->deadline->left($this->clock->now()))
            : $request;
    }

    /** How many runs, each of which may take this long, fit in the time left: every one without a budget. */
    private function fitting(int $wanted, Seconds $each): int
    {
        return $this->deadline instanceof Deadline
            ? $this->deadline->fitting($wanted, $each, $this->clock->now())
            : $wanted;
    }

    /**
     * These mutants, each unjudged by the budget running out before this.
     *
     * @param list<Mutant> $mutants
     */
    private function unjudged(array $mutants, OutOfTime $before): Mutants
    {
        return Mutants::of(...array_map(static fn(Mutant $mutant): Mutant => $mutant->unjudged($before), $mutants));
    }

    /**
     * The mutants whose time ran out at the configured cap: those whose
     * limit came from the runner's own formula would not change with it.
     *
     * @return list<Mutant>
     */
    private function capped(Mutants $mutants): array
    {
        $cap = $this->settings->triage()->limit()->seconds();

        return array_values(array_filter([...$mutants], static function (Mutant $mutant) use ($cap): bool {
            $limit = $mutant->limit();

            return $mutant->status()->ranOutOfTime() && $limit instanceof Seconds && $limit->seconds() >= $cap;
        }));
    }

    /**
     * Survivor confirmation (ADR-0008): each survivor run once more, alone and
     * by the same tests, where `flaky.confirmSurvivors` asks for it. Those
     * killed the second time are flaky, and those the time left had no room
     * for are unjudged.
     */
    private function confirmed(Mutants $mutants, MutationRequest $request): Confirmed|CannotJudge
    {
        $survivors = array_values(array_filter(
            [...$mutants],
            static fn(Mutant $mutant): bool => $mutant->status() === MutantStatus::Survived,
        ));

        if (! $this->settings->triage()->confirmSurvivors() || $survivors === []) {
            return new Confirmed($mutants, MutantIds::none());
        }

        $fitting = $this->fitting(count($survivors), $this->settings->triage()->limit());
        $left = $this->unjudged(array_slice($survivors, $fitting), OutOfTime::BeforeConfirming);
        $again = $fitting === 0 ? Mutants::none() : $this->adapters->runner->retry(
            $this->retimed($request),
            Mutants::of(...array_slice($survivors, 0, $fitting)),
            $this->settings->triage()->limit(),
        );

        return $again instanceof CannotJudge
            ? $again
            : new Confirmed($mutants->replacing($left), $this->killed($again));
    }

    /** The ids of those of these mutants that were killed, by a test or by a static analyser. */
    private function killed(Mutants $mutants): MutantIds
    {
        return MutantIds::of(...array_map(
            static fn(Mutant $mutant): MutantId => $mutant->id(),
            array_filter(
                [...$mutants],
                static fn(Mutant $mutant): bool => $mutant->status()->answer() === MutantStatus::Killed,
            ),
        ));
    }
}

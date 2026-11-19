<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use DateTimeImmutable;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\Expiry;
use NightWorksIO\MutationGate\Core\Config\Ignored;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Key\RunInputs;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\ReusablePass;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Time\Day;
use NightWorksIO\MutationGate\Core\Time\Instant;

use function sprintf;

/**
 * What a plan that runs nothing stands on: a commit whose verdict passed, at
 * the instant it passed, why that verdict stands ({@see StandsBecause}), and,
 * for a verdict reused, where its pass was read (ADR-0198). Its verdict stands
 * for the commit the plan was made on, while no ignore that applied when it
 * passed has expired since.
 */
final readonly class Unchanged
{
    private const string REUSED_FROM = '%s The pass was read from %s, and judged in %s (inputs %s).';

    private function __construct(
        private Passed $base,
        private Instant $at,
        private StandsBecause $because,
        private string $from = '',
    ) {
    }

    /** The verdict of a commit that passed at this instant, standing because nothing the gate judges changed. */
    public static function since(Passed $base, Instant $at): self
    {
        return new self($base, $at, StandsBecause::NothingJudgedChanged);
    }

    /** The verdict of a commit that passed at this instant, standing for this reason, its pass read where it says. */
    public static function standing(
        StandsBecause $because,
        Passed $base,
        Instant $at,
        string $from = '',
    ): self {
        return new self($base, $at, $because, $from);
    }

    /** The verdict of a pass whose run read inputs identical to the run's, read from where it says (ADR-0198). */
    public static function reusing(ReusablePass $pass, Instant $at): self
    {
        return new self($pass->passed(), $at, StandsBecause::IdenticalInputs, $pass->from());
    }

    /** Where the pass whose verdict is reused was read; empty where the verdict is not reused. */
    public function from(): string
    {
        return $this->from;
    }

    /** Why the verdict stands. */
    public function because(): StandsBecause
    {
        return $this->because;
    }

    /** The commit whose verdict passed and stands. */
    public function base(): Passed
    {
        return $this->base;
    }

    /** Why the plan runs nothing. */
    public function reason(): Reason
    {
        $said = sprintf($this->because->template(), $this->base->commit()->name(), $this->at->value());
        $judging = $this->base->judging();
        $inputs = $judging->inputs();

        return Reason::that($this->because === StandsBecause::IdenticalInputs && $inputs instanceof Digest
            ? sprintf(
                self::REUSED_FROM,
                $said,
                $this->from === '' ? 'its own scope' : $this->from,
                $judging->address() === '' ? 'a run its record does not name' : $judging->address(),
                RunInputs::short($inputs),
            )
            : $said);
    }

    /** What every report says in place of the project's score: whose verdict stands. */
    public function headline(): string
    {
        return sprintf($this->because->headline(), $this->base->commit()->name());
    }

    /**
     * The commit the plan was made on, recorded as passed at this instant
     * under the check the commit it stands on passed under, which is the
     * run's own, by the release that judged that commit, which is the run's
     * own too, using what of its scope that commit used; for a verdict
     * reused, judged by the run that judged that commit.
     */
    public function passing(Revision $head, Instant $at): Passed
    {
        $units = $this->base->ownScopeUnits();
        $passed = Passed::of($head, $this->base->check(), $this->base->ownScopeProofs())
            ->passedAt($at)
            ->judgedBy($this->base->gate());

        $passed = $units instanceof Paths ? $passed->takingOwnScope($units) : $passed;
        $passed = $this->because === StandsBecause::IdenticalInputs
            ? $passed->judgedIn($this->base->judging())
            : $passed;

        return $this->base->measuredOnOwnScope() ? $passed->onOwnScopeCoverage() : $passed;
    }

    /**
     * The first of these ignores that applied when the verdict passed and has
     * expired by now, each judged on the days of the clock that reads now;
     * none where every one stands as it stood.
     *
     * @param Listed<Ignored> $ignores
     */
    public function expiredBy(Listed $ignores, DateTimeImmutable $now): Ignored|NotGiven
    {
        $passed = $this->at->moment();

        foreach ($ignores as $ignore) {
            $expires = $ignore->expires();
            $applied = $passed instanceof CannotJudge || ! $expires instanceof Day
                || Expiry::of($expires, $passed->setTimezone($now->getTimezone())) !== Expiry::Expired;

            if ($expires instanceof Day && $applied && Expiry::of($expires, $now) === Expiry::Expired) {
                return $ignore;
            }
        }

        return NotGiven::value();
    }
}

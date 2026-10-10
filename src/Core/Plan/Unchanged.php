<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use DateTimeImmutable;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\Expiry;
use NightWorksIO\MutationGate\Core\Config\Ignored;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Time\Day;
use NightWorksIO\MutationGate\Core\Time\Instant;

use function sprintf;

/**
 * What a plan that runs nothing stands on: nothing the gate judges changed
 * since the newest commit of the run's scope whose verdict passed, at the
 * instant it passed. Its verdict stands for the commit the plan was made on,
 * while no ignore that applied when it passed has expired since.
 */
final readonly class Unchanged
{
    private const string REASON
        = 'Nothing the gate judges changed since %s, whose verdict passed at %s, so its verdict stands.';

    private function __construct(private Passed $base, private Instant $at)
    {
    }

    /** The verdict of a commit that passed at this instant, standing. */
    public static function since(Passed $base, Instant $at): self
    {
        return new self($base, $at);
    }

    /** The newest commit of the scope whose verdict passed. */
    public function base(): Passed
    {
        return $this->base;
    }

    /** When its verdict passed. */
    public function at(): Instant
    {
        return $this->at;
    }

    /** Why the plan runs nothing. */
    public function reason(): Reason
    {
        return Reason::that(sprintf(self::REASON, $this->base->commit()->name(), $this->at->value()));
    }

    /**
     * The commit the plan was made on, recorded as passed at this instant
     * under the check the commit it stands on passed under, which is the
     * run's own, using what of its scope that commit used.
     */
    public function passing(Revision $head, Instant $at): Passed
    {
        $passed = Passed::of($head, $this->base->check(), $this->base->ownScopeProofs())->passedAt($at);

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
            $applied = ! $passed instanceof DateTimeImmutable || ! $expires instanceof Day
                || Expiry::of($expires, $passed->setTimezone($now->getTimezone())) !== Expiry::Expired;

            if ($expires instanceof Day && $applied && Expiry::of($expires, $now) === Expiry::Expired) {
                return $ignore;
            }
        }

        return NotGiven::value();
    }
}

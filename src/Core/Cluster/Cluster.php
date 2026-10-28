<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cluster;

use NightWorksIO\MutationGate\Core\GateCommand;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\Survivors;

use function usort;

/**
 * Survivors with one cause, shown as one item: its members by line then id,
 * the first of them its representative, whose hint it gives and for which
 * `stub` writes the one test (ADR-0022). Every member
 * still counts in the score.
 */
final readonly class Cluster
{
    private function __construct(
        private Membership $membership,
        private JudgedMutant $representative,
        private Survivors $members,
    ) {
    }

    public static function of(Membership $membership, JudgedMutant $first, JudgedMutant ...$others): self
    {
        $members = [$first, ...$others];
        usort(
            $members,
            static fn(JudgedMutant $one, JudgedMutant $other): int => self::order($one) <=> self::order($other),
        );

        return new self($membership, $members[0], Survivors::of(...$members));
    }

    public function id(): ClusterId
    {
        return $this->membership->id();
    }

    public function kind(): ClusterKind
    {
        return $this->membership->kind();
    }

    /** Its first member, by line then id. */
    public function representative(): JudgedMutant
    {
        return $this->representative;
    }

    /** Every member, by line then id. */
    public function members(): Survivors
    {
        return $this->members;
    }

    /** Whether any member is on a line the change added or modified. */
    public function isOnChangedLine(): bool
    {
        foreach ($this->members as $member) {
            if ($member->isOnChangedLine()) {
                return true;
            }
        }

        return false;
    }

    /** The command that writes one test for the cluster. */
    public function stub(GateCommand $gate): string
    {
        return $gate->stub($this->id());
    }

    /** The command that explains the cluster, running nothing. */
    public function explain(GateCommand $gate): string
    {
        return $gate->explain($this->id());
    }

    /** @return array{int, string} where a member stands: its line, then its id */
    private static function order(JudgedMutant $judged): array
    {
        return [$judged->mutant()->location()->start()->number(), $judged->mutant()->id()->value()];
    }
}

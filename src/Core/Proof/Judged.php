<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Instant;

/**
 * The newest run of the default branch that wrote its ledger, passed or failed (ADR-0141): the commit it judged,
 * the check-run it reported under, when it ended, and the units it considered and left open, holding no proof
 * under their key at that commit. Every other unit it considered it judged fully there.
 */
final readonly class Judged
{
    private function __construct(
        private Revision $commit,
        private string $check,
        private Instant $at,
        private Paths $open,
    ) {
    }

    public static function of(Revision $commit, string $check, Instant $at, Paths $open): self
    {
        return new self($commit, $check, $at, $open);
    }

    public function commit(): Revision
    {
        return $this->commit;
    }

    /** The check-run the run's verdict reported under. */
    public function check(): string
    {
        return $this->check;
    }

    public function at(): Instant
    {
        return $this->at;
    }

    /** The units it considered and left open: unjudged, flaky, unjudgeable, stopped by a budget, or sampled out. */
    public function open(): Paths
    {
        return $this->open;
    }

    /** Whether it is newer than this pass: it judged another commit after the pass was recorded. */
    public function isNewerThan(Passed|CannotTell $passed): bool
    {
        $at = $passed instanceof Passed ? $passed->at() : NotGiven::value();

        return match (true) {
            ! $passed instanceof Passed => true,
            $passed->commit()->name() === $this->commit->name() => false,
            $at instanceof Instant => $this->at->isAfter($at),
            default => true,
        };
    }
}

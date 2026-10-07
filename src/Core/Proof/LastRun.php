<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\Change\JudgedCommit;

/**
 * The newest commit of a scope whose verdict judged every unit its plan
 * considered, passing or not, with the tree it held and the commits it was
 * made from, so a gone merge commit can be made again: the check-run it
 * reported under and the kind of run it was. The scope's next run of the same kind reads its change
 * since this commit, and carries the results of every unit that change does
 * not reach (ADR-0005, decision 2; ADR-0008, decision 1).
 */
final readonly class LastRun
{
    private function __construct(private JudgedCommit $judged, private string $check, private RunProfile $kind)
    {
    }

    public static function of(JudgedCommit $judged, string $check, RunProfile $kind): self
    {
        return new self($judged, $check, $kind);
    }

    /** The commit the verdict judged, with its tree and parents. */
    public function judged(): JudgedCommit
    {
        return $this->judged;
    }

    /** The name of the check-run the verdict reported under. */
    public function check(): string
    {
        return $this->check;
    }

    public function profile(): RunProfile
    {
        return $this->kind;
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\Change\Revision;

/**
 * The newest commit of a scope whose verdict judged every unit its plan
 * considered, passing or not: the check-run it reported under and the kind
 * of run it was. The scope's next run of the same kind reads its change
 * since this commit, and carries the results of every unit that change does
 * not reach (ADR-0005, decision 2; ADR-0008, decision 1).
 */
final readonly class LastRun
{
    private function __construct(private Revision $commit, private string $check, private RunProfile $kind)
    {
    }

    public static function of(Revision $commit, string $check, RunProfile $kind): self
    {
        return new self($commit, $check, $kind);
    }

    public function commit(): Revision
    {
        return $this->commit;
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

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Time\Instant;

/**
 * The run that established a proof, as its CI names it, such as
 * `github:<run id>/<attempt>`, when it did, the base it keyed its units at:
 * the digest of everything every key of the run reads, and its kind: how
 * much of the kill matrix it recorded, whether it made mutants with the
 * security mutators alone, and the one suite whose tests alone judged them.
 */
final readonly class Run
{
    private function __construct(
        private string $id,
        private Instant $at,
        private Digest $base,
        private RunProfile $profile,
    ) {
    }

    public static function of(string $id, Instant $at, Digest $base): self
    {
        return new self($id, $at, $base, RunProfile::standard());
    }

    /** This run, recording this much of the kill matrix. */
    public function recording(MatrixKind $matrix): self
    {
        return new self($this->id, $this->at, $this->base, $this->profile->recording($matrix));
    }

    /** This run, of this kind: its matrix, its mutators and its suite. */
    public function profiled(RunProfile $profile): self
    {
        return new self($this->id, $this->at, $this->base, $profile);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function at(): Instant
    {
        return $this->at;
    }

    public function base(): Digest
    {
        return $this->base;
    }

    public function matrix(): MatrixKind
    {
        return $this->profile->matrix();
    }

    /** The kind of run it was, which decides what its proofs stand for. */
    public function profile(): RunProfile
    {
        return $this->profile;
    }
}

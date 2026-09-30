<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** Where proofs are kept, and what they leave out (ADR-0007): `proofs.store`, `proofs.ignore`, `proofs.write`. */
final readonly class Proofs
{
    /** @param Listed<string> $ignore */
    public function __construct(private Choice $store, private Listed $ignore, private ProofWriting $write)
    {
    }

    public function store(): Choice
    {
        return $this->store;
    }

    /** @return Listed<string> the globs of the files no test reads */
    public function ignore(): Listed
    {
        return $this->ignore;
    }

    public function write(): ProofWriting
    {
        return $this->write;
    }
}

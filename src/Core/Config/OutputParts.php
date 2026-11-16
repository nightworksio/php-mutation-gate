<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/**
 * The parts of one layer of config that decide what a run leaves behind:
 * its reports, what it publishes, and the proofs it keeps.
 */
final readonly class OutputParts
{
    public function __construct(
        private Reports $reports,
        private Publishing $publishing,
        private Proofs $proofs,
    ) {
    }

    public function reports(): Reports
    {
        return $this->reports;
    }

    public function publishing(): Publishing
    {
        return $this->publishing;
    }

    public function proofs(): Proofs
    {
        return $this->proofs;
    }
}

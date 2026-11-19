<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fakes;

use function array_column;
use function array_flip;
use function array_intersect_key;
use function array_key_exists;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Mutant\BeforeRunProofs;
use NightWorksIO\MutationGate\Core\Mutant\ProvesBeforeRun;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * What proves mutants before they run as a test says: the proofs and digests
 * it was handed, for the mutants it is asked of, and the declarations it was
 * handed for each program.
 */
final readonly class ProvesBeforeRunFake implements ProvesBeforeRun
{
    /**
     * @param array<string, Reason> $proofs       each proof of equivalence, by the mutant's key
     * @param array<string, string> $programs     each digest of a mutant's op arrays, by its key
     * @param array<string, string> $declarations what each program declares, by its text
     */
    public function __construct(private array $proofs = [], private array $programs = [], private array $declarations = [])
    {
    }

    public function proven(array $pairs): BeforeRunProofs
    {
        $asked = array_flip(array_column($pairs, 0));

        return new BeforeRunProofs(array_intersect_key($this->proofs, $asked), array_intersect_key($this->programs, $asked));
    }

    public function declarations(Contents $program): string|NotGiven
    {
        return array_key_exists($program->text(), $this->declarations) ? $this->declarations[$program->text()] : NotGiven::value();
    }
}

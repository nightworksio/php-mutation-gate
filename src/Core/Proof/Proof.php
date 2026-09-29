<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;

/**
 * A unit's result, stored under the content key of everything its verdict
 * read, with the run that established it. Its mutants carry their statuses
 * before ignores and floors apply.
 */
final readonly class Proof
{
    private function __construct(private Digest $key, private Path $unit, private Mutants $mutants, private Run $run)
    {
    }

    public static function of(Digest $key, Path $unit, Mutants $mutants, Run $run): self
    {
        return new self($key, $unit, $mutants, $run);
    }

    public function key(): Digest
    {
        return $this->key;
    }

    public function unit(): Path
    {
        return $this->unit;
    }

    public function mutants(): Mutants
    {
        return $this->mutants;
    }

    public function run(): Run
    {
        return $this->run;
    }
}

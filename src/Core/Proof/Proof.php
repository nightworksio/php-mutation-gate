<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;

/**
 * A unit's result, stored under the content key of everything its verdict
 * read. Its mutants carry their statuses before ignores and floors apply.
 */
final readonly class Proof
{
    private function __construct(private Digest $key, private Path $unit, private Mutants $mutants) {}

    public static function of(Digest $key, Path $unit, Mutants $mutants): self
    {
        return new self($key, $unit, $mutants);
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
}

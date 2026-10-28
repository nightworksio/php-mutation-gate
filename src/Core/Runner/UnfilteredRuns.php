<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

/**
 * The test files each mutant's own run loaded with no filter, or every one,
 * where the runner left the filter of its tests out (ADR-0008), by the
 * mutant's id: its controls load them the same way.
 */
final readonly class UnfilteredRuns
{
    /** @param array<string, Paths|WholeSuite> $files by each mutant's key */
    private function __construct(private array $files)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** These runs, with a mutant's run that loaded these test files, or every one, with no filter. */
    public function with(MutantId $id, Paths|WholeSuite $files): self
    {
        return new self([...$this->files, $id->key() => $files]);
    }

    /** The test files a mutant's own run loaded with no filter, or every one; not given where it ran a filter. */
    public function of(MutantId $id): Paths|WholeSuite|NotGiven
    {
        return array_key_exists($id->key(), $this->files) ? $this->files[$id->key()] : NotGiven::value();
    }
}

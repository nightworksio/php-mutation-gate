<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Unexecutable;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\Php\Codebase;
use NightWorksIO\MutationGate\Core\Php\References;
use NightWorksIO\MutationGate\Core\Php\Symbol;
use NightWorksIO\MutationGate\Core\Php\Unnamed;

/**
 * Where a codebase reads each value, followed once for each symbol and kept
 * while the judging that read the codebase runs: every mutant of one constant
 * asks the same question.
 */
final class Followed
{
    /**
     * @var array<string, References> where each symbol is read, by the symbol as it describes itself, which
     *                                no unnamed symbol shares with a named one
     */
    private array $references = [];

    public function __construct(private readonly Codebase $codebase)
    {
    }

    /** Where the codebase reads a value, followed through the declarations that read it. */
    public function references(Symbol|Unnamed $symbol): References
    {
        $key = $symbol->described();

        if (! array_key_exists($key, $this->references)) {
            $this->references[$key] = $this->codebase->references($symbol);
        }

        return $this->references[$key];
    }
}

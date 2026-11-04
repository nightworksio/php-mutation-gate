<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

/**
 * An import a file declares at its top: where it stands, spelt as a
 * statement, and the name each of its clauses gives the file, its alias or
 * the last segment of what it imports.
 *
 * @internal
 */
final readonly class Import
{
    /** @param list<string> $names */
    public function __construct(private Declared $declared, private array $names)
    {
    }

    public function declared(): Declared
    {
        return $this->declared;
    }

    /** @return list<string> */
    public function names(): array
    {
        return $this->names;
    }
}

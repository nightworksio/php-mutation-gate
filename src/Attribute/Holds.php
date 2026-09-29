<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Attribute;

use Attribute;

/**
 * Declares that the tests of a class, or one test method, hold a path: a tree,
 * or a file or directory inside one, spelt as the repository spells it. The
 * path is then mutated against the tests that hold it alone, once they are
 * shown to cover all of it. The gate reads it from the test file's tokens and
 * never loads the file.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Holds
{
    public function __construct(private string $path)
    {
    }

    /** The path held, as the test spells it. */
    public function path(): string
    {
        return $this->path;
    }
}

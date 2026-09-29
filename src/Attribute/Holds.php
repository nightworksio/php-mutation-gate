<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Attribute;

use Attribute;

/**
 * Declares that the tests of a class, one test method, or the test a closure
 * becomes, hold a path: a tree, or a file or directory inside one, spelt as
 * the repository spells it. The path is then mutated against the tests that
 * hold it alone, once they are shown to cover all of it.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
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

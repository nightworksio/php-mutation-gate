<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator;

use PhpParser\Node\Name;

/**
 * The full name a class name in code stands for. Both runners resolve names
 * without replacing them, so a node keeps the name as written and holds the
 * resolved one in an attribute, which a mutator matches on.
 */
final readonly class ResolvedName
{
    /** The attribute php-parser's name resolver keeps a class name's full name in. */
    public const string ATTRIBUTE = 'resolvedName';

    /** The full name, without a leading backslash: the resolved one, or the name as written where none was resolved. */
    public static function of(Name $name): string
    {
        $resolved = $name->getAttribute(self::ATTRIBUTE);

        return $resolved instanceof Name ? $resolved->toString() : $name->toString();
    }
}

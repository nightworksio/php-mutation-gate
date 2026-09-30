<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator;

use LogicException;

use function sprintf;

/**
 * A mutator was named with a set or a name that is not written as one. A
 * mutator names itself in its own code, so reaching this is a mistake in it.
 */
final class NotAMutatorName extends LogicException
{
    public static function of(string $set, string $own): self
    {
        return new self(sprintf(
            '"%s/%s" is not a mutator name: a set is lower case letters, digits and hyphens, and a mutator a %s',
            $set,
            $own,
            'letter in upper case, then letters and digits.',
        ));
    }
}

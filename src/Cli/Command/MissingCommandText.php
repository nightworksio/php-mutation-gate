<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use LogicException;

use function sprintf;

/**
 * An option or an argument whose definition always gives text held none:
 * reaching this is a mistake in the definition of the command that read it.
 */
final class MissingCommandText extends LogicException
{
    public static function option(string $name): self
    {
        return new self(sprintf('The option --%s holds no text, though its definition always gives some.', $name));
    }

    public static function argument(Argument $argument): self
    {
        return new self(sprintf(
            'The argument %s holds no text, though its definition always gives some.',
            $argument->value,
        ));
    }
}

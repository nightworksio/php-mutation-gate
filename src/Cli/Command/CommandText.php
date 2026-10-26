<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function is_string;

use Symfony\Component\Console\Input\InputInterface;

/**
 * What the command line wrote for an option that takes a value and has a
 * default of text, or for an argument that is required or has a default of
 * text, which Symfony always gives as text.
 */
final readonly class CommandText
{
    public static function option(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);

        return is_string($value) ? $value : throw MissingCommandText::option($name);
    }

    public static function argument(InputInterface $input, Argument $argument): string
    {
        $value = $input->getArgument($argument->value);

        return is_string($value) ? $value : throw MissingCommandText::argument($argument);
    }
}

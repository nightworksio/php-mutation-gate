<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

/**
 * An option an adapter cannot do without, read as text or as a name its
 * service allows: its value, or the problem of options that give none.
 */
final readonly class Required
{
    private const string NOTHING = 'expected the %s, got nothing';

    public static function text(Options $options, string $option): string|Problem
    {
        $value = $options->text(Key::of($option));

        return $value instanceof NotGiven ? Problem::at($option, sprintf(self::NOTHING, $option)) : $value;
    }

    /** A name the option must hold, as its service allows it (see StoreName). */
    public static function named(Options $options, StoreOption $option, StoreName $name): string|Problem
    {
        $value = $name->in($options, $option);

        return $value instanceof NotGiven
            ? Problem::at($option->value, sprintf(self::NOTHING, $option->value))
            : $value;
    }
}

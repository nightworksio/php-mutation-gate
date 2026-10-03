<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

/** An option an adapter cannot do without, read as text: its value, or the problem of options that give none. */
final readonly class Required
{
    private const string NOTHING = 'expected the %s, got nothing';

    public static function text(Options $options, string $option): string|Problem
    {
        $value = $options->text(Key::of($option));

        return $value instanceof NotGiven ? Problem::at($option, sprintf(self::NOTHING, $option)) : $value;
    }
}

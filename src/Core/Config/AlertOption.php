<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * The options the alert reporters `slack`, `discord` and `webhook` take under a `reports` entry's `with`, each
 * naming the environment variable a credential is read from (ADR-0016).
 */
enum AlertOption: string
{
    /** The variable the channel's URL is read from. */
    case UrlEnv = 'urlEnv';

    /** The variable the webhook's signing secret is read from. */
    case SecretEnv = 'secretEnv';

    /**
     * The variables these options name, in the order of the cases; none for an option they leave out or set to
     * anything but text.
     *
     * @return list<string>
     */
    public static function named(Options $options): array
    {
        $named = [];

        foreach (self::cases() as $option) {
            $variable = $options->text(Key::of($option->value));
            $named = $variable instanceof NotGiven || $variable instanceof Problem ? $named : [...$named, $variable];
        }

        return $named;
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function preg_match;

/**
 * The environment a child process starts in without these variables
 * (ADR-0004, decision 3; ADR-0020, decision 18): every variable it would
 * inherit, each withheld one as false, which is how Symfony's process leaves
 * a variable out. Every variable is named, since Symfony's process on its
 * own inherits only those PHP started with.
 */
final readonly class Withholding
{
    /**
     * @param  array<string, string>       $inherited the environment the child would inherit
     * @return array<string, string|false>
     */
    public static function of(Withheld $withheld, array $inherited): array
    {
        $environment = [];

        foreach ($inherited as $name => $value) {
            $environment[$name] = preg_match($withheld->pattern(), $name) === 1 ? false : $value;
        }

        return $environment;
    }
}

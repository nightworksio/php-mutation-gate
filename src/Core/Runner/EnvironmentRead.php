<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/** What a process is told, read from its variables by name, as a runner's shell holds them. */
final readonly class EnvironmentRead
{
    /**
     * Each variable told its value, or unset where it is false.
     *
     * @param array<string, string|false> $variables by name, false where unset
     */
    public static function of(array $variables): Environment
    {
        $environment = Environment::none();

        foreach ($variables as $name => $value) {
            $environment = $environment->and(
                $value === false ? Environment::unsetting($name) : Environment::telling($name, $value),
            );
        }

        return $environment;
    }
}

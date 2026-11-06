<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Serve;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\Serve\ServedVariables;

use function putenv;
use function sprintf;

/**
 * A daemon child's environment: the daemon's own, with each variable a
 * client sends taken from the client, or unset where the client has none
 * (ADR-0078).
 *
 * @internal
 */
final readonly class ChildEnvironment
{
    /** @param array<string, string> $sent each variable the client sent, by its name */
    public static function madeAs(array $sent): void
    {
        foreach (ServedVariables::names() as $name) {
            putenv(array_key_exists($name, $sent) ? sprintf('%s=%s', $name, $sent[$name]) : $name);
        }

        putenv(sprintf('%s=1', ServedVariables::SERVED));
    }
}

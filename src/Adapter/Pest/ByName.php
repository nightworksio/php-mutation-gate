<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function is_callable;
use function is_object;
use function is_string;

/**
 * A call to a framework the gate does not require, by the method's name.
 *
 * @internal
 */
final readonly class ByName
{
    /** What a method of an object, or a static one of a class, answers; nothing where there is no such method. */
    public static function called(mixed $on, string $method, mixed ...$arguments): mixed
    {
        $callable = [$on, $method];

        return (is_object($on) || is_string($on)) && is_callable($callable) ? $callable(...$arguments) : null;
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Rules\SharedLiterals;

use PHPStan\Reflection\ClassReflection;

/** Where a method is declared: the line of its signature, or of its class where PHP declares it, as for an enum's `from`. */
final readonly class Declaration
{
    public static function lineOf(ClassReflection $class, string $method): int
    {
        $native = $class->getNativeReflection();
        $line = $native->hasMethod($method) ? $native->getMethod($method)->getStartLine() : false;

        return $line === false ? $native->getStartLine() : $line;
    }
}

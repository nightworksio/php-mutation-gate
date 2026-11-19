<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_all;

use Closure;

use function count;

use Error;

use function is_array;
use function is_object;

use ReflectionFunction;

use function restore_error_handler;
use function set_error_handler;

/**
 * Whether a pure expression's mutated code gives this execution's original
 * value (ADR-0117): read only where the variables it reads hold no object,
 * whose methods the operators could reach, under a handler that keeps its
 * errors from the run; a throw, an error or a different value is a change,
 * and so is code that reads an object.
 *
 * @internal
 */
final readonly class Unchanged
{
    /** The most items an array the code reads may hold for the check to look through it. */
    private const int ITEMS = 64;

    /** @param Closure(): mixed $mutated */
    public static function by(mixed $original, Closure $mutated): bool
    {
        foreach (new ReflectionFunction($mutated)->getClosureUsedVariables() as $read) {
            if (! self::holdsNoObject($read)) {
                return false;
            }
        }

        $erred = false;
        set_error_handler(static function () use (&$erred): bool {
            $erred = true;

            return true;
        });

        try {
            $value = $mutated();
        } catch (Error) {
            return false;
        } finally {
            restore_error_handler();
        }

        return ! $erred && $value === $original;
    }

    private static function holdsNoObject(mixed $value): bool
    {
        return match (true) {
            is_object($value) => false,
            ! is_array($value) => true,
            count($value) > self::ITEMS => false,
            default => self::noneHoldsAnObject($value),
        };
    }

    /** @param array<mixed> $items */
    private static function noneHoldsAnObject(array $items): bool
    {
        return array_all($items, static fn(mixed $item): bool => self::holdsNoObject($item));
    }
}

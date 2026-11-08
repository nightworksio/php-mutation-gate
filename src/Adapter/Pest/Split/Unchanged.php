<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use Closure;

use function count;
use function is_array;
use function is_object;

use ReflectionFunction;

use function restore_error_handler;
use function set_error_handler;

use Throwable;

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
        } catch (Throwable) {
            return false;
        } finally {
            restore_error_handler();
        }

        return ! $erred && $value === $original;
    }

    private static function holdsNoObject(mixed $value): bool
    {
        if (is_object($value)) {
            return false;
        }

        if (! is_array($value)) {
            return true;
        }

        if (count($value) > self::ITEMS) {
            return false;
        }

        foreach ($value as $item) {
            if (! self::holdsNoObject($item)) {
                return false;
            }
        }

        return true;
    }
}

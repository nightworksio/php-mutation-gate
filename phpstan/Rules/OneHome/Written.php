<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Rules\OneHome;

use function count;
use function in_array;
use function mb_strlen;

use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;

use function str_starts_with;
use function var_export;

/**
 * A constant value as PHP writes it, the same for every constant that holds
 * it, and whether it is too plain to have a home: nothing, zero, one, a
 * boolean, a single character or an empty array say nothing a reader would
 * look up.
 */
final readonly class Written
{
    /** The values too plain to have a home, as they are written. */
    private const array PLAIN = ["''", '0', '1', '0.0', '1.0', 'true', 'false', 'NULL', 'array{}'];

    /** One character between its quotes. */
    private const int ONE_CHARACTER = 3;

    public static function of(Type $type): string
    {
        $scalars = $type->getConstantScalarValues();

        return count($scalars) === 1 ? var_export($scalars[0], return: true) : $type->describe(VerbosityLevel::precise());
    }

    public static function isPlain(string $written): bool
    {
        return in_array($written, self::PLAIN, strict: true)
            || (mb_strlen($written) === self::ONE_CHARACTER && str_starts_with($written, "'"));
    }
}

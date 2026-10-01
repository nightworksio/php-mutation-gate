<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Order;

use NightWorksIO\MutationGate\Core\Runner\PhpUnitOption;

use function sprintf;
use function str_starts_with;

/**
 * An option of PHPUnit's that decides where a mutant's own process reads its
 * test run history, in which order it runs its tests, or whether it records
 * the history: each one the rewrite drops before it adds its own.
 */
enum Option: string
{
    case CacheDirectory = '--cache-directory';
    case OrderBy = '--order-by';
    case Record = '--record-test-run-history';
    case DoNotRecord = '--do-not-record-test-run-history';
    case CacheResult = '--cache-result';
    case DoNotCacheResult = PhpUnitOption::DoNotCacheResult->value;

    /** The option an argument is, written alone or with its value after `=`, where it is one. */
    public static function in(string $argument): self|NotAnOption
    {
        foreach (self::cases() as $option) {
            if ($argument === $option->value || str_starts_with($argument, sprintf('%s=', $option->value))) {
                return $option;
            }
        }

        return NotAnOption::Argument;
    }

    /** Whether its value follows as the next argument where it is written alone. */
    public function takesAValue(): bool
    {
        return match ($this) {
            self::CacheDirectory, self::OrderBy => true,
            self::Record, self::DoNotRecord, self::CacheResult, self::DoNotCacheResult => false,
        };
    }
}

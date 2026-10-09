<?php

declare(strict_types=1);

namespace Tests;

use function getenv;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function touch;

// A test of a data set with a named row, which leaves CONTRACT_RAN, where the
// variable names a file, only when that row runs. Every row passes, and none
// reads src/, so no mutant's tests change.
final class RowsSpec extends TestCase
{
    /** @return array<string, array{bool}> */
    public static function rows(): array
    {
        return ['384 bits' => [true], 'any other' => [false]];
    }

    #[Test]
    #[DataProvider('rows')]
    public function marksItsNamedRow(bool $marks): void
    {
        if ($marks && getenv('CONTRACT_RAN') !== false) {
            touch((string) getenv('CONTRACT_RAN'));
        }

        self::assertIsBool($marks);
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

/** The programs the tests of the opcache prover prove. */
final readonly class ProverPrograms
{
    public const string PROVED = <<<'PHP'
        <?php

        final class Prices
        {
            public const MOST = 10;

            public function total(int $a): int
            {
                return $a * 2;
            }
        }
        PHP;

    /** A program whose opcodes print some of its values only in part: an array and a float. */
    public const string VALUED = <<<'PHP'
        <?php

        final class Rates
        {
            public function rate(string $code, int $a): float
            {
                if (in_array($code, ['', '0'], true)) {
                    return 0.1234567;
                }

                return $a * 2 / 3;
            }

            public function flags(): array
            {
                return ['pinned' => false];
            }
        }
        PHP;
}

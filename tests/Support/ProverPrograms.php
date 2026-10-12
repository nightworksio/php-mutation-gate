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
}

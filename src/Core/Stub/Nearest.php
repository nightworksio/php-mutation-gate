<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Stub;

use function array_count_values;
use function array_filter;
use function array_key_first;
use function arsort;
use function ksort;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Matrix\KillMatrix;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;

use function sprintf;

/**
 * The test file a stub follows (ADR-0015): of the files whose
 * tests cover the mutant, the one named for its class, as `MoneyTest.php`
 * is for `Money.php`; else the one that holds the most of them; the first
 * by path among equals. A test its runner names no file for is in none.
 */
final readonly class Nearest
{
    /** What a covering test file is named for. */
    private const string FOR = '%sTest';

    public static function file(KillMatrix $matrix, JudgedMutant $mutant): Path|Nameless
    {
        $named = sprintf(self::FOR, $mutant->mutant()->location()->file()->stem());
        $files = [];
        $paths = [];

        foreach ($matrix->coveredBy($mutant) as $test) {
            $name = $matrix->names()->testOf($test);

            if ($name instanceof TestName) {
                $files[] = $name->file()->value();
                $paths[$name->file()->value()] = $name->file();
            }
        }

        $held = array_count_values($files);
        ksort($held, SORT_STRING);
        arsort($held);
        $forTheClass = array_filter(
            $held,
            static fn(int|string $file): bool => $paths[$file]->stem() === $named,
            ARRAY_FILTER_USE_KEY,
        );

        return match (true) {
            $forTheClass !== [] => $paths[array_key_first($forTheClass)],
            $held !== [] => $paths[array_key_first($held)],
            default => Nameless::code(),
        };
    }
}

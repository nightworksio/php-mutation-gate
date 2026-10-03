<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Stub;

use function array_key_exists;
use function array_values;
use function min;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Matrix\KillMatrix;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;

use function sprintf;

/**
 * The test file a stub follows (ADR-0015, decision 2): of the files whose
 * tests cover the mutant, the one named for its class, as `MoneyTest.php`
 * is for `Money.php`; else the one that holds the most of them; the first
 * by path among equals. A test its runner names no file for is in none.
 */
final readonly class Nearest
{
    /** What a covering test file is named for. */
    private const string FOR = '%sTest';

    /** Where no file covers the mutant: after every file that does. */
    private const array NONE = [2, 0, ''];

    public static function file(KillMatrix $matrix, JudgedMutant $mutant): Path|Nameless
    {
        $named = sprintf(self::FOR, $mutant->mutant()->location()->file()->stem());
        $ranked = [];

        foreach ($matrix->coveredBy($mutant) as $test) {
            $name = $matrix->names()->testOf($test);
            $file = $name instanceof TestName ? $name->file()->value() : '';
            $tests = array_key_exists($file, $ranked) ? $ranked[$file][1] - 1 : -1;
            $ranked[$file] = [Path::of($file)->stem() === $named ? 0 : 1, $tests, $file];
        }

        unset($ranked['']);
        $nearest = min([self::NONE, ...array_values($ranked)])[2];

        return $nearest === '' ? Nameless::code() : Path::of($nearest);
    }
}

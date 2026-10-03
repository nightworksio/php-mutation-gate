<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Matrix\KillMatrix;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Stub\Nearest;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

/**
 * The file a stub for the survivor of `src/Money.php`'s seventh line
 * follows, where each of these tests covers it, named in a file each.
 *
 * @param array<string, string> $tests each test's file, by its id
 */
function nearestFor(array $tests): string
{
    $map = CoverageMap::empty();
    $names = TestNames::none();

    foreach ($tests as $id => $file) {
        $map = $map->covered(Path::of('src/Money.php'), Line::of(7), TestId::of($id));
        $names = $file === '' ? $names : $names->with(TestId::of($id), TestName::in(Path::of($file), $id));
    }

    $mutant = JudgedMutant::of(
        Verdicts::mutant('src/Money.php:7', 'LessThan', MutatorFamily::Boundary, Verdicts::diff('return $a < $b;', 'return $a <= $b;')),
        MutantJudgement::Survived,
    );
    $nearest = Nearest::file(KillMatrix::of(MatrixKind::FirstKiller, $map)->named($names), $mutant);

    return $nearest instanceof Nameless ? 'none' : $nearest->value();
}

it('follows the covering file named for the class, however few of the tests it holds', function (): void {
    expect(nearestFor(['a' => 'tests/OtherTest.php', 'b' => 'tests/OtherTest.php', 'c' => 'tests/Unit/MoneyTest.php']))
        ->toBe('tests/Unit/MoneyTest.php');
});

it('follows the file that holds the most covering tests where none is named for the class, the first by path among equals', function (): void {
    expect(nearestFor(['a' => 'tests/BTest.php', 'b' => 'tests/CTest.php', 'c' => 'tests/CTest.php']))->toBe('tests/CTest.php')
        ->and(nearestFor(['a' => 'tests/CTest.php', 'b' => 'tests/BTest.php']))->toBe('tests/BTest.php');
});

it('follows no file where no covering test is named in one', function (): void {
    expect(nearestFor([]))->toBe('none')
        ->and(nearestFor(['a' => '']))->toBe('none');
});

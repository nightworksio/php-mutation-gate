<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\TestParts;
use NightWorksIO\MutationGate\Core\Mutant\TestShare;
use NightWorksIO\MutationGate\Core\Test\TestPart;

/**
 * Test files, each declaring these tests and loading itself alone.
 *
 * @param  array<string, list<string>>                                    $tests by file
 * @return array<string, array{files: list<string>, tests: list<string>}>
 */
function partedFiles(array $tests): array
{
    $units = [];

    foreach ($tests as $file => $declared) {
        $units[$file] = ['files' => [$file], 'tests' => $declared];
    }

    return $units;
}

$files = partedFiles([
    'tests/A.php' => ['A::one', 'A::two'],
    'tests/B.php' => ['B::one'],
    'tests/C.php' => ['C::one'],
    'tests/D.php' => ['D::one'],
]);
$times = ['A::one' => 3.0, 'A::two' => 2.0, 'B::one' => 4.0, 'C::one' => 1.0, 'D::one' => 1.0];

it('gives each test file, longest first, to the part with least time so far, ties broken by path', function () use ($files, $times): void {
    expect(TestParts::assigned($files, $times, 2))->toBe(['tests/A.php' => 0, 'tests/B.php' => 1, 'tests/C.php' => 1, 'tests/D.php' => 0])
        ->and(TestParts::assigned($files, $times, 3))->toBe(['tests/A.php' => 0, 'tests/B.php' => 1, 'tests/C.php' => 2, 'tests/D.php' => 2])
        ->and(TestParts::assigned($files, [], 2))->toBe(['tests/A.php' => 0, 'tests/B.php' => 1, 'tests/C.php' => 0, 'tests/D.php' => 1])
        ->and(TestParts::assigned($files, $times, 9))->toBe(['tests/A.php' => 0, 'tests/B.php' => 1, 'tests/C.php' => 2, 'tests/D.php' => 3]);
});

it('cuts alike however the files and their times came', function () use ($files, $times): void {
    expect(TestParts::assigned(array_reverse($files, preserve_keys: true), array_reverse($times, preserve_keys: true), 3))
        ->toBe(TestParts::assigned($files, $times, 3));
});

it('holds the tests of its own part\'s files, and every test as the one part of one', function () use ($files, $times): void {
    $second = TestParts::cut(TestPart::of(2, 2), $files, $times);

    expect($second->within(['A::one', 'B::one', 'C::one', 'D::one']))->toBe(['B::one', 'C::one'])
        ->and($second->holds('A::two'))->toBeFalse()
        ->and(TestParts::whole()->within(['A::one', 'B::one']))->toBe(['A::one', 'B::one']);
});

it('owns a mutant whose first covering test it holds, and, of none, as the first part', function () use ($files, $times): void {
    $first = TestParts::cut(TestPart::of(1, 2), $files, $times);
    $second = TestParts::cut(TestPart::of(2, 2), $files, $times);

    expect([$first->owns(['B::one', 'A::one']), $second->owns(['B::one', 'A::one'])])->toBe([false, true])
        ->and([$first->owns([]), $second->owns([])])->toBe([true, false]);
});

it('says how much of a mutant\'s covering tests its part holds', function () use ($files, $times): void {
    $first = TestParts::cut(TestPart::of(1, 2), $files, $times);

    expect($first->shareOf(['A::one', 'D::one']))->toBe(TestShare::All)
        ->and($first->shareOf(['A::one', 'B::one']))->toBe(TestShare::OwnedPart)
        ->and($first->shareOf(['B::one', 'A::one']))->toBe(TestShare::OtherPart)
        ->and(TestParts::whole()->shareOf(['A::one', 'B::one']))->toBe(TestShare::All);
});

it('puts every test in the first part where a test is declared in no file it could cut', function () use ($times): void {
    $first = TestParts::cut(TestPart::of(1, 2), [], $times);
    $second = TestParts::cut(TestPart::of(2, 2), [], $times);

    expect($first->within(['A::one', 'B::one']))->toBe(['A::one', 'B::one'])
        ->and($second->within(['A::one', 'B::one']))->toBe([]);
});

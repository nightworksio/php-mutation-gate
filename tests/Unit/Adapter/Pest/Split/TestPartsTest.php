<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\TestParts;
use NightWorksIO\MutationGate\Core\Config\StreamJudging;
use NightWorksIO\MutationGate\Core\Mutant\TestShare;
use NightWorksIO\MutationGate\Core\Test\TestPart;

$files = [
    'tests/A.php' => ['files' => ['tests/A.php'], 'tests' => ['A::one', 'A::two']],
    'tests/B.php' => ['files' => ['tests/B.php'], 'tests' => ['B::one']],
    'tests/C.php' => ['files' => ['tests/C.php'], 'tests' => ['C::one']],
    'tests/D.php' => ['files' => ['tests/D.php'], 'tests' => ['D::one']],
];
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
    $second = TestParts::cut(TestPart::of(2, 2), $files, $times, StreamJudging::Parted);

    expect($second->within(['A::one', 'B::one', 'C::one', 'D::one']))->toBe(['B::one', 'C::one'])
        ->and($second->holds('A::two'))->toBeFalse()
        ->and(TestParts::whole()->within(['A::one', 'B::one']))->toBe(['A::one', 'B::one']);
});

it('owns a mutant whose first covering test it holds, whatever its id', function () use ($files, $times): void {
    $first = TestParts::cut(TestPart::of(1, 2), $files, $times, StreamJudging::Parted);
    $second = TestParts::cut(TestPart::of(2, 2), $files, $times, StreamJudging::Parted);

    expect([$first->owns(['B::one', 'A::one'], 'a', ''), $second->owns(['B::one', 'A::one'], 'a', '')])->toBe([false, true])
        ->and([$first->owns(['A::one', 'B::one'], 'a', ''), $second->owns(['A::one', 'B::one'], 'a', '')])->toBe([true, false]);
});

it('owns a mutant no test covers in the one part its id falls to', function () use ($files, $times): void {
    $parts = array_map(
        static fn(int $number): TestParts => TestParts::cut(TestPart::of($number, 3), $files, $times, StreamJudging::Parted),
        [1, 2, 3],
    );
    $owners = static fn(string $mutant): array => array_map(
        static fn(TestParts $part): bool => $part->owns([], $mutant, ''),
        $parts,
    );

    expect($owners('a'))->toBe([true, false, false])
        ->and($owners('b'))->toBe([false, false, true])
        ->and(TestParts::whole()->owns([], 'b', ''))->toBeTrue();
});

it('says how much of a mutant\'s covering tests its part holds, judging parted', function () use ($files, $times): void {
    $first = TestParts::cut(TestPart::of(1, 2), $files, $times, StreamJudging::Parted);

    expect($first->shareOf(['A::one', 'D::one'], 'a', ''))->toBe(TestShare::All)
        ->and($first->shareOf(['A::one', 'B::one'], 'a', ''))->toBe(TestShare::OwnedPart)
        ->and($first->shareOf(['B::one', 'A::one'], 'a', ''))->toBe(TestShare::OtherPart)
        ->and(TestParts::whole()->shareOf(['A::one', 'B::one'], 'a', ''))->toBe(TestShare::All);
});

it('puts a test no file it cut declares in the first part, and cuts the rest as ever', function () use ($files, $times): void {
    $first = TestParts::cut(TestPart::of(1, 2), $files, $times, StreamJudging::Parted);
    $second = TestParts::cut(TestPart::of(2, 2), $files, $times, StreamJudging::Parted);

    expect($first->within(['A::one', 'B::one', 'X::one']))->toBe(['A::one', 'X::one'])
        ->and($second->within(['A::one', 'B::one', 'X::one']))->toBe(['B::one'])
        ->and(TestParts::cut(TestPart::of(2, 2), [], $times, StreamJudging::Parted)->within(['A::one']))->toBe([]);
});

it('judges whole, by every covering test, a mutant in the one part of its likely killer alone', function () use ($files, $times): void {
    $first = TestParts::cut(TestPart::of(1, 2), $files, $times, StreamJudging::Whole);
    $second = TestParts::cut(TestPart::of(2, 2), $files, $times, StreamJudging::Whole);
    $covering = ['A::one', 'B::one', 'C::one'];

    expect([$first->owns($covering, 'a', 'C::one'), $second->owns($covering, 'a', 'C::one')])->toBe([false, true])
        ->and($first->judgedBy($covering, 'a', 'C::one'))->toBe([])
        ->and($second->judgedBy($covering, 'a', 'C::one'))->toBe($covering)
        ->and($second->shareOf($covering, 'a', 'C::one'))->toBe(TestShare::All)
        ->and($second->killerAmong($covering))->toBe($covering);
});

it('judges whole in the part of its first covering test a mutant the ledger names no killer of', function () use ($files, $times): void {
    $first = TestParts::cut(TestPart::of(1, 2), $files, $times, StreamJudging::Whole);
    $second = TestParts::cut(TestPart::of(2, 2), $files, $times, StreamJudging::Whole);
    $covering = ['A::one', 'B::one'];

    expect($first->judgedBy($covering, 'a', ''))->toBe($covering)
        ->and($second->judgedBy($covering, 'a', ''))->toBe([])
        ->and($first->owns([], 'a', ''))->toBeFalse()
        ->and($second->owns([], 'a', ''))->toBeTrue();
});

it('judges parted by its own part\'s covering tests, and seeks a killer among those alone', function () use ($files, $times): void {
    $second = TestParts::cut(TestPart::of(2, 2), $files, $times, StreamJudging::Parted);
    $covering = ['A::one', 'B::one', 'C::one'];

    expect($second->judgedBy($covering, 'a', 'B::one'))->toBe(['B::one', 'C::one'])
        ->and($second->killerAmong($covering))->toBe(['B::one', 'C::one'])
        ->and($second->owns($covering, 'a', 'B::one'))->toBeFalse()
        ->and(TestParts::whole()->judgedBy($covering, 'a', 'B::one'))->toBe($covering);
});

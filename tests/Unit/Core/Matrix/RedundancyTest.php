<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Matrix\KeptKill;
use NightWorksIO\MutationGate\Core\Matrix\KillingTest;
use NightWorksIO\MutationGate\Core\Matrix\KillingTests;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Matrix\MustStay;
use NightWorksIO\MutationGate\Core\Matrix\Redundancy;
use NightWorksIO\MutationGate\Core\Matrix\RemovableTest;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Tests\Support\Killings;

it('keeps what must stay, then the test that keeps the most new kills per second, until every kill is kept', function (): void {
    $redundancy = Redundancy::of(Killings::verdict(MatrixKind::Full));

    expect(array_map(static fn(TestName|TestId $test): string => $test->value(), iterator_to_array($redundancy->kept(), preserve_keys: false)))
        ->toBe(['tests/ETest.php::it does E', 'tests/ATest.php::it does A', 'tests/DTest.php::it does D']);
});

it('lists every other test, with its time and the kept test that makes each of its kills', function (): void {
    $removable = array_map(
        static fn(RemovableTest $test): array => [
            $test->test()->value(),
            $test->seconds(),
            array_map(
                static fn(KeptKill $kill): array => [$kill->mutant()->value(), $kill->keptBy()->value()],
                iterator_to_array($test, preserve_keys: false),
            ),
        ],
        iterator_to_array(Redundancy::of(Killings::verdict(MatrixKind::Full))->removable(), preserve_keys: false),
    );

    expect($removable)->toEqual([
        ['tests/BTest.php::it does B', Seconds::of(1.0), [[Killings::mutantAt(2)->value(), 'tests/ATest.php::it does A']]],
        ['tests/CTest.php::it does C', Seconds::of(0.2), []],
    ]);
});

it('keeps a test that alone covers a line, or alone among its group a line of what the group holds', function (): void {
    $stay = MustStay::of(Killings::verdict(MatrixKind::Full));
    $held = MustStay::of(Killings::heldVerdict());

    expect($stay->has(Killings::name('E')))->toBeTrue()
        ->and($stay->has(Killings::name('A')))->toBeFalse()
        ->and($stay->has(Killings::name('C')))->toBeFalse()
        ->and($held->has(Killings::name('A')))->toBeTrue()
        ->and($held->has(Killings::name('C')))->toBeFalse();
});

it('keeps an untimed test last among equals, as long as the slowest timed one', function (): void {
    $tests = KillingTests::of(Killings::verdict(MatrixKind::Full));
    $byName = [];

    foreach ($tests as $test) {
        $byName[$test->test()->value()] = $test;
    }

    expect($tests->slowest())->toEqual(Seconds::of(1.0))
        ->and($byName['tests/DTest.php::it does D']->seconds())->toEqual(Unmeasured::duration())
        ->and($byName['tests/DTest.php::it does D']->secondsOr(Seconds::of(1.0)))->toEqual(Seconds::of(1.0))
        ->and($byName['tests/BTest.php::it does B']->seconds())->toEqual(Seconds::of(1.0))
        ->and($byName['tests/BTest.php::it does B']->kills())->toEqual(MutantIds::of(Killings::mutantAt(2)));
});

it('sums a test\'s rows, a timed row and an untimed one alike', function (): void {
    $test = KillingTest::of(Killings::name('B'))
        ->withRow(MutantIds::of(Killings::mutantAt(1)), Unmeasured::duration())
        ->withRow(MutantIds::none(), Seconds::of(0.5))
        ->withRow(MutantIds::of(Killings::mutantAt(2)), Seconds::of(0.25))
        ->withRow(MutantIds::none(), Unmeasured::duration());

    expect($test->seconds())->toEqual(Seconds::of(0.75))
        ->and($test->kills())->toEqual(MutantIds::of(Killings::mutantAt(1), Killings::mutantAt(2)))
        ->and(KillingTests::of(Killings::heldVerdict())->slowest())->toEqual(Seconds::of(0.0));
});

it('keeps the faster of two tests that keep kills at the same rate, and the first by name of two alike', function (): void {
    $redundancy = Redundancy::of(Killings::tiedVerdict());

    $removable = iterator_to_array($redundancy->removable(), preserve_keys: false);

    expect(array_map(static fn(TestName|TestId $test): string => $test->value(), iterator_to_array($redundancy->kept(), preserve_keys: false)))
        ->toBe(['tests/GTest.php::it does G', 'tests/FTest.php::it does F'])
        ->and($removable)->toHaveCount(1)
        ->and($removable[0]->test()->value())->toBe('tests/HTest.php::it does H')
        ->and(iterator_to_array($removable[0], preserve_keys: false))->toEqual([KeptKill::of(Killings::mutantAt(3), Killings::name('G'))]);
});

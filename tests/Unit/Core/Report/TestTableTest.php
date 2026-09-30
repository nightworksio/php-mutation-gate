<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Report\TestTable;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('lists every test a mutant names once, in the order the mutants first name them', function (): void {
    $table = TestTable::of(Verdicts::failing()->withMatrix(Verdicts::matrix(MatrixKind::FirstKiller)));
    $values = array_map(static fn(TestId $test): string => $test->value(), iterator_to_array($table->tests(), preserve_keys: false));

    expect($values)->toBe(['MoneyTest::fits', 'MoneyTest::refuses', 'PriceTest::adds'])
        ->and($table->placesOf(TestIds::of(TestId::of('PriceTest::adds'), TestId::of('MoneyTest::fits'))))->toBe([2, 0])
        ->and($table->placesOf(TestIds::none()))->toBe([]);
});

it('lists no test of a verdict whose mutants name none', function (): void {
    expect(TestTable::of(Verdicts::passing())->tests())->toHaveCount(0);
});

<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\IdsFile;
use NightWorksIO\MutationGate\Adapter\Pest\Selection;
use NightWorksIO\MutationGate\Adapter\Pest\Suite;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** Tests whose ids test a filter: data sets, quotes, spaces, `::`, `=`, namespaces that share a class name. */
const SET_POOL = [
    'P\Tests\MoneySpec::__pest_evaluable_it_adds_two__numbers',
    'P\Tests\MoneySpec::__pest_evaluable_it_adds#dataset "one"',
    'P\Tests\MoneySpec::__pest_evaluable_it_adds#dataset "two::three"',
    'P\Tests\Other\MoneySpec::__pest_evaluable_it_adds#dataset "one"',
    'P\Tests\Contract\AdapterTest::__pest_evaluable_it_answers#dataset "a = b"',
    'Tests\MoneyTest::testAdds#1',
    'Tests\MoneyTest::testAdds#2',
    'Tests\MoneyTest::testSubtracts',
    'Tests\Legacy_Test::testDecrements',
    'Tests\Unit\RateTest::test_rounds with spaces',
];

it('selects by a set of ids exactly the tests the covering filter is built for, each of them the filter can name selected by it too', function (): void {
    mt_srand(138);
    $pool = TestIds::of(...array_map(TestId::of(...), SET_POOL));

    for ($round = 0; $round < 200; $round++) {
        $chosen = array_values(array_filter(SET_POOL, static fn(): bool => mt_rand(0, 1) === 1)) ?: [SET_POOL[0]];
        $covering = TestIds::of(...array_map(TestId::of(...), $chosen));
        $file = sprintf('%s/round-%d.ids', Scratch::directory(), $round);
        $written = IdsFile::written($covering, $file);
        $selection = Selection::covering($chosen, Suite::none());
        $byFilter = array_map(static fn(TestId $test): string => $test->value(), [...$selection->among($pool)]);
        $unnamed = array_map(static fn(TestId $test): string => $test->value(), [...$selection->unselected()]);

        expect($written)->toBe($file)
            ->and(explode("\n", rtrim((string) file_get_contents($file), "\n")))->toBe($chosen)
            ->and(array_values(array_diff($chosen, $byFilter)))->toBe($unnamed);
    }
});

<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\EveryRow;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\RunTests;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;

it('runs the tests of the map that judge a mutant, every row of their data sets, and replays their kills', function (): void {
    $map = CoverageMap::of()
        ->covered(Path::of('src/Money.php'), Line::of(3), TestId::of('P\Tests\MoneySpec::__pest_evaluable_it_adds#dataset "a"'), TestId::of('P\Tests\MoneySpec::__pest_evaluable_it_adds#dataset "b"'))
        ->covered(Path::of('src/Money.php'), Line::of(3), TestId::of('P\Tests\MoneySpec::__pest_evaluable_it_takes'));
    $judging = TestIds::of(TestId::of('P\Tests\MoneySpec::__pest_evaluable_it_adds#dataset "a"'), TestId::of('P\Tests\Elsewhere::gone'));

    expect(new EveryRow()->of($judging, $map, NotGiven::value()))->toEqual(RunTests::of(TestIds::of(
        TestId::of('P\Tests\MoneySpec::__pest_evaluable_it_adds#dataset "a"'),
        TestId::of('P\Tests\MoneySpec::__pest_evaluable_it_adds#dataset "b"'),
    )))
        ->and(new EveryRow()->replaysKills())->toBeTrue();
});

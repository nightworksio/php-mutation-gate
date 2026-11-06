<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\Overrun;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('names the test that ran past its own limit, and that limit', function (): void {
    $overrun = Overrun::of(TestId::of('Money::adds'), Seconds::of(3.0));

    expect($overrun->test())->toEqual(TestId::of('Money::adds'))
        ->and($overrun->limit())->toEqual(Seconds::of(3.0));
});

<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\TimedTest;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('holds a test and how long it took', function (): void {
    $timed = TimedTest::of('MoneyTest::adds', 0.25);

    expect($timed->test())->toEqual(TestId::of('MoneyTest::adds'))
        ->and($timed->seconds())->toEqual(Seconds::of(0.25));
});

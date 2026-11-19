<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Time\Budgets;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

it('gives a run no budget, watch half a minute and pre-push two', function (): void {
    $budgets = Budgets::standard();

    expect($budgets->run())->toEqual(Unlimited::time())
        ->and($budgets->watch())->toEqual(Seconds::of(30.0))
        ->and($budgets->prePush())->toEqual(Seconds::of(120.0));
});

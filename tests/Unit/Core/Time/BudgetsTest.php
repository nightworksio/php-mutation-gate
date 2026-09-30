<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Time\Budgets;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

it('gives a run no budget, watch a minute and pre-push five', function (): void {
    $budgets = Budgets::standard();

    expect($budgets->run())->toEqual(Unlimited::time())
        ->and($budgets->watch())->toEqual(Seconds::of(60.0))
        ->and($budgets->prePush())->toEqual(Seconds::of(300.0));
});

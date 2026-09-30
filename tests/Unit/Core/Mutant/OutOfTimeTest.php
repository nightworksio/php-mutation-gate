<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Mutant\Reason;

it('says what the budget ran out before, and the command that judges it', function (OutOfTime $before, string $said): void {
    expect($before->reason())->toEqual(Reason::that(
        sprintf('The time budget ran out before %s. More time judges it: vendor/bin/mutation-gate run --budget=<duration>', $said),
    ));
})->with([
    'mutating' => [OutOfTime::BeforeMutating, 'this run mutated its unit'],
    'retrying' => [OutOfTime::BeforeRetrying, 'it could run again with a doubled limit'],
    'confirming' => [OutOfTime::BeforeConfirming, 'its survival could be confirmed'],
]);

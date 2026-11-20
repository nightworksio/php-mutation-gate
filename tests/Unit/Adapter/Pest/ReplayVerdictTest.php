<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\ReplayVerdict;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\NotGiven;

it('leaves a kill whose replay had no time unjudged by the time left, so a later job judges it, and one its replay judged for its own reason', function (ReplayVerdict $verdict, OutOfTime|false $before): void {
    $reason = $verdict->reason();
    $outOfTime = $reason instanceof Reason ? $reason->outOfTime() : $reason;

    expect($reason)->toBeInstanceOf(Reason::class)
        ->and($outOfTime instanceof OutOfTime ? $outOfTime : false)->toBe($before);
})->with([
    'no time' => [ReplayVerdict::NoTime, OutOfTime::BeforeVouching],
    'over its limit' => [ReplayVerdict::OverLimit, false],
    'unserved' => [ReplayVerdict::Unserved, false],
    'another count' => [ReplayVerdict::OtherCount, false],
    'a test failing unmutated' => [ReplayVerdict::Failed, false],
    'a failed run' => [ReplayVerdict::FailedRun, false],
    'another order' => [ReplayVerdict::OtherOrder, false],
]);

it('gives a kill its replay lets stand no reason', function (): void {
    expect(ReplayVerdict::Stands->reason())->toBeInstanceOf(NotGiven::class);
});

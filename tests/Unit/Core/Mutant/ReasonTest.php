<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Mutant\Unreported;

it('holds the sentence a report prints beside the mutant', function (): void {
    expect(Reason::that('Pest cannot name LegacySpec::decrements in a filter.')->text())
        ->toBe('Pest cannot name LegacySpec::decrements in a filter.');
});

it('records what a time budget ran out before where one did, and nothing where a runner gave the reason', function (): void {
    $budget = Reason::ranOutOf(OutOfTime::BeforeConfirming, 'The time budget ran out.');

    expect($budget->text())->toBe('The time budget ran out.')
        ->and($budget->outOfTime())->toBe(OutOfTime::BeforeConfirming)
        ->and(Reason::that('The time budget ran out.')->outOfTime())->toEqual(Unreported::reason());
});

it('tells the reason no test reaches a value from any other, by its words', function (): void {
    expect(Reason::that(Reason::UNREACHED)->isUnreached())->toBeTrue()
        ->and(Reason::that('the budget ran out')->isUnreached())->toBeFalse();
});

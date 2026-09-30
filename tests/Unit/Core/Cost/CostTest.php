<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\Cost;
use NightWorksIO\MutationGate\Core\Cost\Money;
use NightWorksIO\MutationGate\Core\Cost\Rate;
use NightWorksIO\MutationGate\Core\Cost\RunTime;
use NightWorksIO\MutationGate\Core\Cost\Unpriced;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('holds what a run planned, measured and spared, unpriced until a team gives a rate', function (): void {
    $planned = RunTime::estimated(Seconds::of(420.0), Seconds::of(900.0));
    $measured = RunTime::measured(Seconds::of(360.0), Seconds::of(840.0));
    $cost = Cost::of($planned, $measured, Seconds::of(2_460.0), Seconds::of(60.0));
    $price = Rate::perMinute(0.2, 'EUR');

    expect($cost->planned())->toBe($planned)
        ->and($cost->measured())->toBe($measured)
        ->and($cost->spared())->toEqual(Seconds::of(2_460.0))
        ->and($cost->setup())->toEqual(Seconds::of(60.0))
        ->and($cost->price())->toEqual(Unpriced::time())
        ->and($cost->isSetupEstimated())->toBeFalse()
        ->and($cost->pricedAt($price)->price())->toBe($price)
        ->and(Cost::of($planned, $planned, Seconds::of(0.0), Seconds::of(60.0))->isSetupEstimated())->toBeTrue();
});

it('prices runner time at the team\'s rate, and says an amount with two decimals and its currency', function (): void {
    $price = Rate::perMinute(0.2, 'EUR');

    expect($price->perRunnerMinute())->toEqual(Money::of(0.2, 'EUR'))
        ->and($price->of(Seconds::of(840.0)))->toEqual(Money::of(2.8, 'EUR'))
        ->and($price->of(Seconds::of(840.0))->text())->toBe('2.80 EUR')
        ->and(Money::of(3.0, 'USD')->text())->toBe('3.00 USD')
        ->and([Money::of(3.0, 'USD')->amount(), Money::of(3.0, 'USD')->currency()])->toBe([3.0, 'USD']);
});

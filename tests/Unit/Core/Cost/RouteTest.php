<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\Route;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\NotGiven;

it('routes a kill static analysis made to the static check, whatever its runner named', function (): void {
    expect(Route::of(MutantStatus::KilledByStaticAnalysis, Route::FreshRun, streamed: true))->toBe(Route::StaticCheck);
});

it('takes the route its runner named, then the first pass for a streamed mutant, then its own run', function (): void {
    expect(Route::of(MutantStatus::Killed, Route::StreamSecondPass, streamed: true))->toBe(Route::StreamSecondPass)
        ->and(Route::of(MutantStatus::Survived, NotGiven::value(), streamed: true))->toBe(Route::StreamFirstPass)
        ->and(Route::of(MutantStatus::TimedOut, NotGiven::value(), streamed: false))->toBe(Route::OwnRun);
});

it('gives a route only to a mutant some route ran to its verdict', function (): void {
    $routed = [];

    foreach (MutantStatus::cases() as $status) {
        $routed[$status->value] = Route::of($status, Route::OwnRun, streamed: false) instanceof Route;
    }

    expect(array_keys(array_filter($routed)))->toBe([
        'killed',
        'killed-by-static-analysis',
        'survived',
        'timed-out',
        'errored',
        'out-of-memory',
        'fails-unmutated',
    ]);
});

it('times a route by the step that is its alone, and own runs by none', function (): void {
    $timed = [];

    foreach (Step::cases() as $step) {
        $route = Route::timing($step);
        $timed = $route instanceof Route ? [...$timed, $step->value => $route] : $timed;
    }

    expect($timed)->toBe([
        'first pass' => Route::StreamFirstPass,
        'second pass' => Route::StreamSecondPass,
        'fresh runs' => Route::FreshRun,
        'static check' => Route::StaticCheck,
    ]);
});

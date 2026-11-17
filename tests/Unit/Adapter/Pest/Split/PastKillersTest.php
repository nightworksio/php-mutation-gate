<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\PastKillers;

it('forks a mutant at any test where it has no likely killer, otherwise at its killer alone', function (): void {
    $past = new PastKillers();

    expect($past->forks('m', '', 'T::any'))->toBeTrue()
        ->and($past->forks('m', 'T::killer', 'T::killer'))->toBeTrue()
        ->and($past->forks('m', 'T::killer', 'T::later'))->toBeFalse();
});

it('forks a mutant at later tests once the stream fell back on its killer, or a test held a descriptor at its site', function (): void {
    $fellBack = new PastKillers();
    $fellBack->fellBackOn('T::killer');
    $held = new PastKillers();
    $held->heldAt('m');

    expect($fellBack->forks('m', 'T::killer', 'T::later'))->toBeTrue()
        ->and($fellBack->forks('m', 'T::other', 'T::later'))->toBeFalse()
        ->and($held->forks('m', 'T::killer', 'T::later'))->toBeTrue()
        ->and($held->forks('n', 'T::killer', 'T::later'))->toBeFalse();
});

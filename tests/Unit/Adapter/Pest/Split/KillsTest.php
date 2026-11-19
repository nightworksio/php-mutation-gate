<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\Kills;

it('kills each mutant a child failed in a test the parent passed, at once or once the parent ends it, and none in a test it failed', function (): void {
    $kills = new Kills();
    $kills->ended('T::passed', passed: true);
    $kills->ended('T::failed', passed: false);

    $kills->failedIn([['m1', 'T::running'], ['m2', 'T::passed'], ['m3', 'T::failed'], ['m4', 'T::running']]);
    $before = [$kills->has('m1'), $kills->has('m2'), $kills->has('m3'), $kills->has('m4')];
    $kills->ended('T::running', passed: true);

    expect($before)->toBe([false, true, false, false])
        ->and([$kills->has('m1'), $kills->has('m4')])->toBe([true, true]);
});

<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Proof\Credentials;

it('is held where every variable it names is set, and not where one is unset or empty', function (): void {
    $credentials = Credentials::of('KEY', 'SECRET');

    expect($credentials->heldIn(Variables::of(['KEY' => 'k', 'SECRET' => 's'])))->toBeTrue()
        ->and($credentials->heldIn(Variables::of(['KEY' => 'k'])))->toBeFalse()
        ->and($credentials->heldIn(Variables::of(['KEY' => 'k', 'SECRET' => ''])))->toBeFalse()
        ->and($credentials->heldIn(Variables::of([])))->toBeFalse();
});

it('is always held where a store needs none', function (): void {
    expect(Credentials::none()->heldIn(Variables::of([])))->toBeTrue();
});

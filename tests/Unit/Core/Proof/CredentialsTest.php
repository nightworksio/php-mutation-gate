<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Proof\Credentials;

it('is held where every variable it names is set, and not where one is unset or empty', function (): void {
    $credentials = Credentials::needing('KEY', 'SECRET');

    expect($credentials->heldIn(Variables::of(['KEY' => 'k', 'SECRET' => 's'])))->toBeTrue()
        ->and($credentials->heldIn(Variables::of(['KEY' => 'k'])))->toBeFalse()
        ->and($credentials->heldIn(Variables::of(['KEY' => 'k', 'SECRET' => ''])))->toBeFalse()
        ->and($credentials->heldIn(Variables::of([])))->toBeFalse();
});

it('is always held where a store needs none', function (): void {
    expect(Credentials::none()->heldIn(Variables::of([])))->toBeTrue();
});

it('lists every variable the store reads, those it needs first, and needs only those', function (): void {
    $credentials = Credentials::needing('KEY', 'SECRET')->reading('TOKEN', 'ROLE');

    expect([...$credentials->variables()])->toBe(['KEY', 'SECRET', 'TOKEN', 'ROLE'])
        ->and($credentials->heldIn(Variables::of(['KEY' => 'k', 'SECRET' => 's'])))->toBeTrue()
        ->and($credentials->heldIn(Variables::of(['TOKEN' => 't', 'ROLE' => 'r', 'KEY' => 'k'])))->toBeFalse();
});

it('is held where every variable of any one set it names is set', function (): void {
    $credentials = Credentials::needing('FILE')->orNeeding('URL', 'REQUEST')->reading('ROLE');

    expect($credentials->heldIn(Variables::of(['FILE' => 'f'])))->toBeTrue()
        ->and($credentials->heldIn(Variables::of(['URL' => 'u', 'REQUEST' => 'r'])))->toBeTrue()
        ->and($credentials->heldIn(Variables::of(['URL' => 'u', 'ROLE' => 'r'])))->toBeFalse()
        ->and($credentials->heldIn(Variables::of(['ROLE' => 'r'])))->toBeFalse()
        ->and([...$credentials->variables()])->toBe(['FILE', 'URL', 'REQUEST', 'ROLE']);
});

<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Owner\Owner;
use NightWorksIO\MutationGate\Core\Owner\OwnerGroup;
use NightWorksIO\MutationGate\Tests\Support\Owners;

it('is named by its owners in the order the rule writes them', function (): void {
    $payments = Owner::parse('@acme/payments');
    $jo = Owner::parse('@jo');
    $group = $payments instanceof Owner && $jo instanceof Owner ? OwnerGroup::of($payments, $jo) : OwnerGroup::unowned();

    expect($group->name())->toBe('@acme/payments @jo')
        ->and([...$group])->toBe([$payments, $jo])
        ->and($group)->toHaveCount(2)
        ->and($group->isUnowned())->toBeFalse();
});

it('is unowned with no owner, and says so', function (): void {
    expect(OwnerGroup::unowned()->name())->toBe('unowned')
        ->and([...OwnerGroup::unowned()])->toBe([])
        ->and(OwnerGroup::unowned())->toHaveCount(0)
        ->and(OwnerGroup::unowned()->isUnowned())->toBeTrue()
        ->and(OwnerGroup::of()->isUnowned())->toBeTrue();
});

it('names an owner it lists, spelt in any case, and no other', function (): void {
    $group = Owners::codeOwners()->groupOf(Path::of('src/Billing/Pay.php'));

    expect([
        $group->names(Owners::owner('@jo')),
        $group->names(Owners::owner('@ACME/Payments')),
        $group->names(Owners::owner('@acme/web')),
        OwnerGroup::unowned()->names(Owners::owner('@jo')),
    ])
        ->toBe([true, true, false, false]);
});

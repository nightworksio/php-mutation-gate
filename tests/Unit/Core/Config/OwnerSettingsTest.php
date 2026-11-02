<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\OwnerSettings;
use NightWorksIO\MutationGate\Core\Config\PhpCalls;
use NightWorksIO\MutationGate\Core\Config\Table;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Owner\OwnerMention;

it('mentions owners the first time and holds no owner to a floor where no layer says otherwise', function (): void {
    expect(OwnerSettings::of()->mention())->toBe(OwnerMention::First)
        ->and(OwnerSettings::of()->floors())->toEqual(Table::none())
        ->and(OwnerSettings::of()->standard()->php())->toEqual(PhpCalls::inWith('Owner::mentionedFirst()'));
});

it('lays a later layer\'s settings over an earlier one\'s, each on its own', function (): void {
    $floors = Table::row('@acme/payments', 90);
    $earlier = OwnerSettings::of($floors, OwnerMention::Never);

    expect($earlier->over(OwnerSettings::of())->mention())->toBe(OwnerMention::Never)
        ->and($earlier->over(OwnerSettings::of(mention: OwnerMention::First))->mention())->toBe(OwnerMention::First)
        ->and($earlier->over(OwnerSettings::of(mention: OwnerMention::First))->floors())->toBe($floors)
        ->and(OwnerSettings::of()->over(OwnerSettings::of(new Absent(), OwnerMention::Never))->php())
        ->toEqual(PhpCalls::inWith('Owner::neverMentioned()'));
});

it('calls the builder for each owner\'s floor before whether owners are mentioned', function (OwnerMention $mention, string $call): void {
    expect(OwnerSettings::of(Table::row('@acme/payments', 90), $mention)->php())
        ->toEqual(PhpCalls::inWith("Owner::floor('@acme/payments', 90)", $call));
})->with([
    'first' => [OwnerMention::First, 'Owner::mentionedFirst()'],
    'never' => [OwnerMention::Never, 'Owner::neverMentioned()'],
]);

it('writes nothing for owners where it sets nothing', function (): void {
    expect(Json::object(OwnerSettings::of()->written())->isEmpty())->toBeTrue()
        ->and(OwnerSettings::of()->php())->toEqual(PhpCalls::none());
});

<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\PhpCalls;
use NightWorksIO\MutationGate\Core\Config\Pulling;
use NightWorksIO\MutationGate\Core\Config\ShardPull;
use NightWorksIO\MutationGate\Core\Format\Member;

it('claims under auto on a service the race test passed on, and steals elsewhere', function (): void {
    expect(ShardPull::Auto->whereRaced())->toBe(ShardPull::Claim)
        ->and(ShardPull::Auto->whereUnraced())->toBe(ShardPull::Steal)
        ->and(ShardPull::Steal->whereRaced())->toBe(ShardPull::Steal)
        ->and(ShardPull::Claim->whereUnraced())->toBe(ShardPull::Claim)
        ->and(ShardPull::Off->whereRaced())->toBe(ShardPull::Off);
});

it('pulls under every value but off', function (ShardPull $pull, bool $pulls): void {
    expect($pull->pulls())->toBe($pulls);
})->with([
    'auto' => [ShardPull::Auto, true],
    'claim' => [ShardPull::Claim, true],
    'steal' => [ShardPull::Steal, true],
    'off' => [ShardPull::Off, false],
]);

it('pulls automatically where no layer says, and takes a later layer\'s word', function (): void {
    $none = Pulling::of();
    $laid = $none->over(Pulling::of(ShardPull::Off))->over(Pulling::of(new Absent()));

    expect([$none->pull(), $none->php()->code()])->toBe([ShardPull::Auto, ''])
        ->and([$laid->pull(), $laid->php()->code()])
        ->toBe([ShardPull::Off, PhpCalls::inWith('Shards::owningTheirParts()')->code()])
        ->and($laid->written())->toEqual(Member::of('pull', 'off'));
});

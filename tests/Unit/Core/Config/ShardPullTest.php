<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Pulling;
use NightWorksIO\MutationGate\Core\Config\ShardPull;

it('claims under auto on a service the race test passed on, and steals elsewhere', function (): void {
    expect(ShardPull::Auto->on('https://github.com'))->toBe(ShardPull::Claim)
        ->and(ShardPull::Auto->on('https://ghe.example.com'))->toBe(ShardPull::Steal)
        ->and(ShardPull::Steal->on('https://github.com'))->toBe(ShardPull::Steal)
        ->and(ShardPull::Claim->on('https://ghe.example.com'))->toBe(ShardPull::Claim);
});

it('pulls under every value but off', function (ShardPull $pull, bool $pulls): void {
    expect($pull->pulls())->toBe($pulls);
})->with([
    'auto' => [ShardPull::Auto, true],
    'claim' => [ShardPull::Claim, true],
    'steal' => [ShardPull::Steal, true],
    'off' => [ShardPull::Off, false],
]);

it('pulls automatically and leaves the verdict to its job where no layer says, and takes a later layer\'s word', function (): void {
    $none = Pulling::of();
    $laid = $none->over(Pulling::of(ShardPull::Off))->over(Pulling::of(new Absent(), lastJudges: true));

    expect([$none->pull(), $none->lastJudges(), $none->php()])->toBe([ShardPull::Auto, false, []])
        ->and([$laid->pull(), $laid->lastJudges(), $laid->php()])
        ->toBe([ShardPull::Off, true, ['Shards::owningTheirParts()', 'Shards::lastJudging()']]);
});

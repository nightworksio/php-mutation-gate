<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\Setting;
use NightWorksIO\MutationGate\Config\ShardShares;
use NightWorksIO\MutationGate\Tests\Support\Configs;

it('writes each share\'s key, and writes the config back with the same call', function (Closure $call, string $path, float $value, string $method): void {
    [$at, $php] = Configs::oneCall($call, $path);

    expect($at)->toBe($value)->and($php)->toContain(sprintf('%s(%s)', $method, $value));
})->with([
    'ShardShares::fill' => [static fn(): Setting => ShardShares::fill(0.5), 'shards.fill', 0.5, 'ShardShares::fill'],
    'ShardShares::confirm' => [static fn(): Setting => ShardShares::confirm(0.25), 'shards.confirm', 0.25, 'ShardShares::confirm'],
]);

<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\Setting;
use NightWorksIO\MutationGate\Config\Shards;
use NightWorksIO\MutationGate\Tests\Support\Configs;

$calls = [
    'Shards::pullingByClaims' => [static fn(): Setting => Shards::pullingByClaims(), 'shards.pull', 'claim', 'Shards::pullingByClaims'],
    'Shards::pullingBySteals' => [static fn(): Setting => Shards::pullingBySteals(), 'shards.pull', 'steal', 'Shards::pullingBySteals'],
    'Shards::owningTheirParts' => [static fn(): Setting => Shards::owningTheirParts(), 'shards.pull', 'off', 'Shards::owningTheirParts'],
];

it('writes each call\'s key, and writes the config back with the same call', function (Closure $call, string $path, string|bool $value, string $method): void {
    [$at, $php] = Configs::oneCall($call, $path);

    expect($at)->toBe($value)->and($php)->toContain(sprintf('%s(', $method));
})->with($calls);

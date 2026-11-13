<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\Local;
use NightWorksIO\MutationGate\Config\Setting;
use NightWorksIO\MutationGate\Tests\Support\Configs;

$calls = [
    'Local::fullRounds' => [static fn(): Setting => Local::fullRounds(), 'local.quick', false, 'Local::fullRounds'],
    'Local::cancellingByRound' => [static fn(): Setting => Local::cancellingByRound(), 'local.cancelByUnit', false, 'Local::cancellingByRound'],
];

it('writes each call\'s key, and writes the config back with the same call', function (Closure $call, string $path, string|bool $value, string $method): void {
    [$at, $php] = Configs::oneCall($call, $path);

    expect($at)->toBe($value)->and($php)->toContain(sprintf('%s(', $method));
})->with($calls);

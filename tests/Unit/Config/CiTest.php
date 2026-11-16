<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\Ci;
use NightWorksIO\MutationGate\Config\Setting;
use NightWorksIO\MutationGate\Tests\Support\Configs;

$calls = [
    'Ci::trustingMergedDeltas' => [static fn(): Setting => Ci::trustingMergedDeltas(), 'ci.trustMergedPullRequests', 'delta', 'Ci::trustingMergedDeltas'],
    'Ci::refusingMergedOwnScopeProofs' => [static fn(): Setting => Ci::refusingMergedOwnScopeProofs(), 'ci.mergedOwnScopeProofs', 'refuse', 'Ci::refusingMergedOwnScopeProofs'],
    'Ci::trustingMergedOwnScopeProofs' => [static fn(): Setting => Ci::trustingMergedOwnScopeProofs(), 'ci.mergedOwnScopeProofs', 'trust', 'Ci::trustingMergedOwnScopeProofs'],
];

it('writes each call\'s key, and writes the config back with the same call', function (Closure $call, string $path, string|bool $value, string $method): void {
    [$at, $php] = Configs::oneCall($call, $path);

    expect($at)->toBe($value)->and($php)->toContain(sprintf('%s(', $method));
})->with($calls);

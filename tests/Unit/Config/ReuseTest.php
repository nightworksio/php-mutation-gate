<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\Reuse;
use NightWorksIO\MutationGate\Config\Setting;
use NightWorksIO\MutationGate\Tests\Support\Configs;

$calls = [
    'Reuse::identical' => [static fn(): Setting => Reuse::identical(), 'reuse.identical', 'on', 'Reuse::identical'],
    'Reuse::identicalOnPullRequests' => [static fn(): Setting => Reuse::identicalOnPullRequests(), 'reuse.identical', 'pulls', 'Reuse::identicalOnPullRequests'],
    'Reuse::neverIdentical' => [static fn(): Setting => Reuse::neverIdentical(), 'reuse.identical', 'off', 'Reuse::neverIdentical'],
    'Reuse::fromForks' => [static fn(): Setting => Reuse::fromForks(), 'reuse.forks', true, 'Reuse::fromForks'],
    'Reuse::notFromForks' => [static fn(): Setting => Reuse::notFromForks(), 'reuse.forks', false, 'Reuse::notFromForks'],
    'Reuse::reachingOwnScopeProofs' => [static fn(): Setting => Reuse::reachingOwnScopeProofs(), 'reuse.ownScopeProofs', 'reach', 'Reuse::reachingOwnScopeProofs'],
    'Reuse::refusingOwnScopeProofs' => [static fn(): Setting => Reuse::refusingOwnScopeProofs(), 'reuse.ownScopeProofs', 'refuse', 'Reuse::refusingOwnScopeProofs'],
    'Reuse::trustingOwnScopeProofs' => [static fn(): Setting => Reuse::trustingOwnScopeProofs(), 'reuse.ownScopeProofs', 'trust', 'Reuse::trustingOwnScopeProofs'],
    'Reuse::delta' => [static fn(): Setting => Reuse::delta(), 'reuse.delta', true, 'Reuse::delta'],
    'Reuse::noDelta' => [static fn(): Setting => Reuse::noDelta(), 'reuse.delta', false, 'Reuse::noDelta'],
    'Reuse::environment' => [static fn(): Setting => Reuse::environment('APP_ENV', 'TZ'), 'reuse.environment', ['APP_ENV', 'TZ'], 'Reuse::environment'],
    'Reuse::passes' => [static fn(): Setting => Reuse::passes(4), 'reuse.passes', 4, 'Reuse::passes'],
];

it('writes each call\'s key, and writes the config back with the same call', function (Closure $call, string $path, string|int|bool|array $value, string $method): void {
    [$at, $php] = Configs::oneCall($call, $path);

    expect($at)->toBe($value)->and($php)->toContain(sprintf('%s(', $method));
})->with($calls);

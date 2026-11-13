<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\Gate;
use NightWorksIO\MutationGate\Config\Outbreak;
use NightWorksIO\MutationGate\Config\Runner;
use NightWorksIO\MutationGate\Config\Setting;
use NightWorksIO\MutationGate\Tests\Support\Configs;

$calls = static fn(): array => [
    'on' => [Outbreak::on(), 'outbreak.enabled', true, 'on'],
    'off' => [Outbreak::off(), 'outbreak.enabled', false, 'off'],
    'binary' => [Outbreak::binary('bin/outbreak'), 'outbreak.binary', 'bin/outbreak', 'binary'],
    'timeout' => [Outbreak::timeout('90s'), 'outbreak.timeout', '90s', 'timeout'],
    'threads' => [Outbreak::threads(2), 'outbreak.threads', 2, 'threads'],
    'equivalenceOn' => [Outbreak::equivalenceOn(), 'outbreak.equivalence', true, 'equivalenceOn'],
    'forkStatesCounted' => [Outbreak::forkStatesCounted(), 'outbreak.forkStates', true, 'forkStatesCounted'],
    'forkStatesUncounted' => [Outbreak::forkStatesUncounted(), 'outbreak.forkStates', false, 'forkStatesUncounted'],
    'touchedKillsRechecked' => [Outbreak::touchedKillsRechecked(), 'outbreak.recheckTouchedKills', true, 'touchedKillsRechecked'],
    'touchedKillsTrusted' => [Outbreak::touchedKillsTrusted(), 'outbreak.recheckTouchedKills', false, 'touchedKillsTrusted'],
    'equivalenceOff' => [Outbreak::equivalenceOff(), 'outbreak.equivalence', false, 'equivalenceOff'],
    'graphInQuickRounds' => [Outbreak::graphInQuickRounds(), 'outbreak.graph', 'local', 'graphInQuickRounds'],
    'graphNarrowing' => [Outbreak::graphNarrowing(), 'outbreak.graph', 'narrow', 'graphNarrowing'],
    'graphWidening' => [Outbreak::graphWidening(), 'outbreak.graph', 'widen', 'graphWidening'],
    'graphOff' => [Outbreak::graphOff(), 'outbreak.graph', 'off', 'graphOff'],
    'linesRecorded' => [Outbreak::linesRecorded(), 'outbreak.lines', true, 'linesRecorded'],
    'linesByPcov' => [Outbreak::linesByPcov(), 'outbreak.lines', false, 'linesByPcov'],
    'stepsCounted' => [Outbreak::stepsCounted(), 'outbreak.steps', true, 'stepsCounted'],
    'stepsUncounted' => [Outbreak::stepsUncounted(), 'outbreak.steps', false, 'stepsUncounted'],
    'stepFloor' => [Outbreak::stepFloor(1000), 'outbreak.stepFloor', 1000, 'stepFloor'],
    'serving' => [Outbreak::serving(), 'outbreak.serve.enabled', true, 'serving'],
    'notServing' => [Outbreak::notServing(), 'outbreak.serve.enabled', false, 'notServing'],
    'snapshotEvery' => [Outbreak::snapshotEvery('300s'), 'outbreak.serve.snapshotEvery', '300s', 'snapshotEvery'],
    'syncEvery' => [Outbreak::syncEvery('2s'), 'outbreak.serve.syncEvery', '2s', 'syncEvery'],
    'debounce' => [Outbreak::debounce('1s'), 'outbreak.serve.debounce', '1s', 'debounce'],
    'cacheStoredAuto' => [Outbreak::cacheStoredAuto(), 'outbreak.cache.store', 'auto', 'cacheStoredAuto'],
    'cacheStoredNowhere' => [Outbreak::cacheStoredNowhere(), 'outbreak.cache.store', 'none', 'cacheStoredNowhere'],
    'cacheStoredInActions' => [Outbreak::cacheStoredInActions(), 'outbreak.cache.store', 'actions', 'cacheStoredInActions'],
    'cacheStoredInRegistry' => [Outbreak::cacheStoredInRegistry(), 'outbreak.cache.store', 'oci', 'cacheStoredInRegistry'],
    'cacheRegistry' => [Outbreak::cacheRegistry('ghcr.io/acme/proofs'), 'outbreak.cache.registry', 'ghcr.io/acme/proofs', 'cacheRegistry'],
    'cacheTrustingTheDefaultBranch' => [Outbreak::cacheTrustingTheDefaultBranch(), 'outbreak.cache.trust', 'default-branch', 'cacheTrustingTheDefaultBranch'],
    'cacheTrustingTheRepository' => [Outbreak::cacheTrustingTheRepository(), 'outbreak.cache.trust', 'repository', 'cacheTrustingTheRepository'],
];

it('writes every outbreak key, as the config file would', function (): void {
    expect(Configs::written(Gate::configure()->runner(Runner::pest())->with(
        Outbreak::off(),
        Outbreak::binary('bin/outbreak'),
        Outbreak::timeout('90s'),
        Outbreak::threads(2),
    )))
        ->toMatchArray(['outbreak' => ['enabled' => false, 'binary' => 'bin/outbreak', 'timeout' => '90s', 'threads' => 2]])
        ->and(Configs::built(Gate::configure()->runner(Runner::pest())->with(Outbreak::on()))->shards()->outbreak()->enabled())
        ->toBeTrue();
});

it('writes each call\'s key, and writes the config back with the same call', function (Setting $call, string $path, string|int|bool $value, string $method): void {
    [$at, $php] = Configs::oneCall($call, $path);

    expect($at)->toBe($value)->and($php)->toContain(sprintf('Outbreak::%s(', $method));
})->with($calls);

<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\Gate;
use NightWorksIO\MutationGate\Config\Outbreak;
use NightWorksIO\MutationGate\Config\Runner;
use NightWorksIO\MutationGate\Config\Setting;
use NightWorksIO\MutationGate\Tests\Support\Configs;

$calls = [
    'on' => [static fn(): Setting => Outbreak::on(), 'outbreak.enabled', true, 'on'],
    'off' => [static fn(): Setting => Outbreak::off(), 'outbreak.enabled', false, 'off'],
    'binary' => [static fn(): Setting => Outbreak::binary('bin/outbreak'), 'outbreak.binary', 'bin/outbreak', 'binary'],
    'timeout' => [static fn(): Setting => Outbreak::timeout('90s'), 'outbreak.timeout', '90s', 'timeout'],
    'threads' => [static fn(): Setting => Outbreak::threads(2), 'outbreak.threads', 2, 'threads'],
    'equivalenceOn' => [static fn(): Setting => Outbreak::equivalenceOn(), 'outbreak.equivalence', true, 'equivalenceOn'],
    'forkStatesCounted' => [static fn(): Setting => Outbreak::forkStatesCounted(), 'outbreak.forkStates', true, 'forkStatesCounted'],
    'forkStatesUncounted' => [static fn(): Setting => Outbreak::forkStatesUncounted(), 'outbreak.forkStates', false, 'forkStatesUncounted'],
    'touchedKillsRechecked' => [static fn(): Setting => Outbreak::touchedKillsRechecked(), 'outbreak.recheckTouchedKills', true, 'touchedKillsRechecked'],
    'touchedKillsTrusted' => [static fn(): Setting => Outbreak::touchedKillsTrusted(), 'outbreak.recheckTouchedKills', false, 'touchedKillsTrusted'],
    'equivalenceOff' => [static fn(): Setting => Outbreak::equivalenceOff(), 'outbreak.equivalence', false, 'equivalenceOff'],
    'graphInQuickRounds' => [static fn(): Setting => Outbreak::graphInQuickRounds(), 'outbreak.graph', 'local', 'graphInQuickRounds'],
    'graphNarrowing' => [static fn(): Setting => Outbreak::graphNarrowing(), 'outbreak.graph', 'narrow', 'graphNarrowing'],
    'graphWidening' => [static fn(): Setting => Outbreak::graphWidening(), 'outbreak.graph', 'widen', 'graphWidening'],
    'graphOff' => [static fn(): Setting => Outbreak::graphOff(), 'outbreak.graph', 'off', 'graphOff'],
    'linesRecorded' => [static fn(): Setting => Outbreak::linesRecorded(), 'outbreak.lines', true, 'linesRecorded'],
    'linesByPcov' => [static fn(): Setting => Outbreak::linesByPcov(), 'outbreak.lines', false, 'linesByPcov'],
    'stepsCounted' => [static fn(): Setting => Outbreak::stepsCounted(), 'outbreak.steps', true, 'stepsCounted'],
    'stepsUncounted' => [static fn(): Setting => Outbreak::stepsUncounted(), 'outbreak.steps', false, 'stepsUncounted'],
    'stepFloor' => [static fn(): Setting => Outbreak::stepFloor(1000), 'outbreak.stepFloor', 1000, 'stepFloor'],
    'serving' => [static fn(): Setting => Outbreak::serving(), 'outbreak.serve.enabled', true, 'serving'],
    'notServing' => [static fn(): Setting => Outbreak::notServing(), 'outbreak.serve.enabled', false, 'notServing'],
    'snapshotEvery' => [static fn(): Setting => Outbreak::snapshotEvery('300s'), 'outbreak.serve.snapshotEvery', '300s', 'snapshotEvery'],
    'syncEvery' => [static fn(): Setting => Outbreak::syncEvery('2s'), 'outbreak.serve.syncEvery', '2s', 'syncEvery'],
    'debounce' => [static fn(): Setting => Outbreak::debounce('1s'), 'outbreak.serve.debounce', '1s', 'debounce'],
    'cacheStoredAuto' => [static fn(): Setting => Outbreak::cacheStoredAuto(), 'outbreak.cache.store', 'auto', 'cacheStoredAuto'],
    'cacheStoredNowhere' => [static fn(): Setting => Outbreak::cacheStoredNowhere(), 'outbreak.cache.store', 'none', 'cacheStoredNowhere'],
    'cacheStoredInActions' => [static fn(): Setting => Outbreak::cacheStoredInActions(), 'outbreak.cache.store', 'actions', 'cacheStoredInActions'],
    'cacheStoredInRegistry' => [static fn(): Setting => Outbreak::cacheStoredInRegistry(), 'outbreak.cache.store', 'oci', 'cacheStoredInRegistry'],
    'cacheRegistry' => [static fn(): Setting => Outbreak::cacheRegistry('ghcr.io/acme/proofs'), 'outbreak.cache.registry', 'ghcr.io/acme/proofs', 'cacheRegistry'],
    'cacheTrustingTheDefaultBranch' => [static fn(): Setting => Outbreak::cacheTrustingTheDefaultBranch(), 'outbreak.cache.trust', 'default-branch', 'cacheTrustingTheDefaultBranch'],
    'cacheTrustingTheRepository' => [static fn(): Setting => Outbreak::cacheTrustingTheRepository(), 'outbreak.cache.trust', 'repository', 'cacheTrustingTheRepository'],
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

it('writes each call\'s key, and writes the config back with the same call', function (Closure $call, string $path, string|int|bool $value, string $method): void {
    [$at, $php] = Configs::oneCall($call, $path);

    expect($at)->toBe($value)->and($php)->toContain(sprintf('Outbreak::%s(', $method));
})->with($calls);

<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\Gate;
use NightWorksIO\MutationGate\Config\Outbreak;
use NightWorksIO\MutationGate\Config\OutbreakCache;
use NightWorksIO\MutationGate\Config\OutbreakGraph;
use NightWorksIO\MutationGate\Config\OutbreakServe;
use NightWorksIO\MutationGate\Config\Runner;
use NightWorksIO\MutationGate\Config\Setting;
use NightWorksIO\MutationGate\Tests\Support\Configs;

$calls = [
    'on' => [static fn(): Setting => Outbreak::on(), 'outbreak.enabled', true, 'Outbreak::on'],
    'off' => [static fn(): Setting => Outbreak::off(), 'outbreak.enabled', false, 'Outbreak::off'],
    'binary' => [static fn(): Setting => Outbreak::binary('bin/outbreak'), 'outbreak.binary', 'bin/outbreak', 'Outbreak::binary'],
    'timeout' => [static fn(): Setting => Outbreak::timeout('90s'), 'outbreak.timeout', '90s', 'Outbreak::timeout'],
    'threads' => [static fn(): Setting => Outbreak::threads(2), 'outbreak.threads', 2, 'Outbreak::threads'],
    'equivalenceOn' => [static fn(): Setting => Outbreak::equivalenceOn(), 'outbreak.equivalence', true, 'Outbreak::equivalenceOn'],
    'forkStatesCounted' => [static fn(): Setting => Outbreak::forkStatesCounted(), 'outbreak.forkStates', true, 'Outbreak::forkStatesCounted'],
    'forkStatesUncounted' => [static fn(): Setting => Outbreak::forkStatesUncounted(), 'outbreak.forkStates', false, 'Outbreak::forkStatesUncounted'],
    'touchedKillsRechecked' => [static fn(): Setting => Outbreak::touchedKillsRechecked(), 'outbreak.recheckTouchedKills', true, 'Outbreak::touchedKillsRechecked'],
    'touchedKillsTrusted' => [static fn(): Setting => Outbreak::touchedKillsTrusted(), 'outbreak.recheckTouchedKills', false, 'Outbreak::touchedKillsTrusted'],
    'equivalenceOff' => [static fn(): Setting => Outbreak::equivalenceOff(), 'outbreak.equivalence', false, 'Outbreak::equivalenceOff'],
    'graphInQuickRounds' => [static fn(): Setting => OutbreakGraph::inQuickRounds(), 'outbreak.graph', 'local', 'OutbreakGraph::inQuickRounds'],
    'graphNarrowing' => [static fn(): Setting => OutbreakGraph::narrowing(), 'outbreak.graph', 'narrow', 'OutbreakGraph::narrowing'],
    'graphWidening' => [static fn(): Setting => OutbreakGraph::widening(), 'outbreak.graph', 'widen', 'OutbreakGraph::widening'],
    'graphOff' => [static fn(): Setting => OutbreakGraph::off(), 'outbreak.graph', 'off', 'OutbreakGraph::off'],
    'linesRecorded' => [static fn(): Setting => Outbreak::linesRecorded(), 'outbreak.lines', true, 'Outbreak::linesRecorded'],
    'linesByPcov' => [static fn(): Setting => Outbreak::linesByPcov(), 'outbreak.lines', false, 'Outbreak::linesByPcov'],
    'stepsCounted' => [static fn(): Setting => Outbreak::stepsCounted(), 'outbreak.steps', true, 'Outbreak::stepsCounted'],
    'stepsUncounted' => [static fn(): Setting => Outbreak::stepsUncounted(), 'outbreak.steps', false, 'Outbreak::stepsUncounted'],
    'stepFloor' => [static fn(): Setting => Outbreak::stepFloor(1000), 'outbreak.stepFloor', 1000, 'Outbreak::stepFloor'],
    'serving' => [static fn(): Setting => OutbreakServe::serving(), 'outbreak.serve.enabled', true, 'OutbreakServe::serving'],
    'notServing' => [static fn(): Setting => OutbreakServe::notServing(), 'outbreak.serve.enabled', false, 'OutbreakServe::notServing'],
    'snapshotEvery' => [static fn(): Setting => OutbreakServe::snapshotEvery('300s'), 'outbreak.serve.snapshotEvery', '300s', 'OutbreakServe::snapshotEvery'],
    'syncEvery' => [static fn(): Setting => OutbreakServe::syncEvery('2s'), 'outbreak.serve.syncEvery', '2s', 'OutbreakServe::syncEvery'],
    'debounce' => [static fn(): Setting => OutbreakServe::debounce('1s'), 'outbreak.serve.debounce', '1s', 'OutbreakServe::debounce'],
    'cacheStoredAuto' => [static fn(): Setting => OutbreakCache::storedAuto(), 'outbreak.cache.store', 'auto', 'OutbreakCache::storedAuto'],
    'cacheStoredNowhere' => [static fn(): Setting => OutbreakCache::storedNowhere(), 'outbreak.cache.store', 'none', 'OutbreakCache::storedNowhere'],
    'cacheStoredInActions' => [static fn(): Setting => OutbreakCache::storedInActions(), 'outbreak.cache.store', 'actions', 'OutbreakCache::storedInActions'],
    'cacheStoredInRegistry' => [static fn(): Setting => OutbreakCache::storedInRegistry(), 'outbreak.cache.store', 'oci', 'OutbreakCache::storedInRegistry'],
    'cacheRegistry' => [static fn(): Setting => OutbreakCache::registry('ghcr.io/acme/proofs'), 'outbreak.cache.registry', 'ghcr.io/acme/proofs', 'OutbreakCache::registry'],
    'cacheTrustingTheDefaultBranch' => [static fn(): Setting => OutbreakCache::trustingTheDefaultBranch(), 'outbreak.cache.trust', 'default-branch', 'OutbreakCache::trustingTheDefaultBranch'],
    'cacheTrustingTheRepository' => [static fn(): Setting => OutbreakCache::trustingTheRepository(), 'outbreak.cache.trust', 'repository', 'OutbreakCache::trustingTheRepository'],
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

    expect($at)->toBe($value)->and($php)->toContain(sprintf('%s(', $method));
})->with($calls);

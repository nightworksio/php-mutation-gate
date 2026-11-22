<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\DeliveredLedger;
use NightWorksIO\MutationGate\Adapter\Filesystem\DeliveryDirectory;
use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Adapter\Outbreak\CacheDelivery;
use NightWorksIO\MutationGate\Cli\Flow\CachedPacks;
use NightWorksIO\MutationGate\Cli\Flow\Ledgers;
use NightWorksIO\MutationGate\Cli\Flow\Standing;
use NightWorksIO\MutationGate\Config\Outbreak as OutbreakSettings;
use NightWorksIO\MutationGate\Config\OutbreakCache;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Delivery\Stage;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Plan\Considered;
use NightWorksIO\MutationGate\Core\Proof\CacheClass;
use NightWorksIO\MutationGate\Core\Proof\CachePack;
use NightWorksIO\MutationGate\Core\Proof\CacheRequest;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\TakenProofs;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\CachingEngine;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A run by this id at the plan's base. */
function cachedRun(string $id): Run
{
    return Run::of($id, Instant::at(new DateTimeImmutable('2026-11-01T00:00:00Z')), Planned::of()->base());
}

/** A proof of src/Money.php under the key the planned plan gives it, by this run. */
function cachedProof(Run $run): Proof
{
    return Proof::of(Digest::sha256Of('money'), Path::of('src/Money.php'), Mutants::none(), $run);
}

it('pushes a verdict\'s entries into its class\'s pack in the Actions cache, and nothing with no store', function (): void {
    $engine = new CachingEngine();
    $adapters = Flows::adapters(Flows::project(), [], $engine);
    $entries = Path::of('.mutation-gate/cache/entries/trusted.pack');

    $pushed = new CachedPacks($adapters, Flows::settings(OutbreakSettings::on(), OutbreakCache::storedInActions()))
        ->pushed(CacheClass::Trusted, $entries, Planned::of()->base());
    $nowhere = new CachedPacks($adapters, Flows::settings(OutbreakSettings::on(), OutbreakCache::storedNowhere()))
        ->pushed(CacheClass::Trusted, $entries, Planned::of()->base());

    expect($pushed)->toBeInstanceOf(Written::class)
        ->and($nowhere)->toEqual(NotWritten::because('No store of proof packs is in use.'))
        ->and(array_map(static fn(CacheRequest $request): array => $request->packs(), $engine->pushes))->toEqual([['trusted' => $entries]]);
});

it('leaves the proofs this run wrote as its class\'s entries and pushes them under actions, and leaves none with no store', function (bool $stored): void {
    $project = Flows::project();
    $engine = new CachingEngine();
    $ours = cachedRun('ours');
    $ledger = Ledger::empty()->withProof(cachedProof($ours))
        ->withProof(Proof::of(Digest::sha256Of('held'), Path::of('src/Held.php'), Mutants::none(), cachedRun('earlier')));
    $settings = Flows::settings(OutbreakSettings::on(), $stored ? OutbreakCache::storedInActions() : OutbreakCache::storedNowhere());

    new CachedPacks(Flows::adapters($project, [], $engine), $settings)->left($ledger, Planned::of(), $ours, onTheDefaultBranch: true);
    $entries = sprintf('%s/.mutation-gate/cache/entries/trusted.pack', $project);

    expect(is_file($entries) ? file_get_contents($entries) : 'none')
        ->toBe($stored ? CachePack::bytes(CacheClass::Trusted, Planned::of()->base(), Proofs::of(cachedProof($ours))) : 'none')
        ->and($engine->pushes)->toHaveCount($stored ? 1 : 0);
})->with(['actions' => [true], 'no store' => [false]]);

it('pushes nothing where the entries cannot be written', function (): void {
    $project = Flows::project();
    $engine = new CachingEngine();
    $ours = cachedRun('ours');
    Scratch::write($project, '.mutation-gate/cache/entries/trusted.pack/kept', '');

    new CachedPacks(Flows::adapters($project, [], $engine), Flows::settings(OutbreakSettings::on(), OutbreakCache::storedInActions()))
        ->left(Ledger::empty()->withProof(cachedProof($ours)), Planned::of(), $ours, onTheDefaultBranch: true);

    expect($engine->pushes)->toBe([]);
});

it('hands deliver the entries and the registry\'s request under oci, a branch\'s run writing the branch pack', function (): void {
    $project = Flows::project();
    $ours = cachedRun('ours');
    $delivered = DeliveredLedger::over(new ProofStoreFake(), DeliveryDirectory::of(Directory::at($project), Stage::Verdict));
    $settings = Flows::settings(OutbreakSettings::on(), OutbreakCache::storedInRegistry(), OutbreakCache::registry('ghcr.io/owner/proofs'));

    new CachedPacks(Flows::adapters($project, [], $delivered, new CachingEngine()), $settings)
        ->left(Ledger::empty()->withProof(cachedProof($ours)), Planned::of(), $ours, onTheDefaultBranch: false);
    $request = sprintf('%s/.mutation-gate/delivery/verdict/%s', $project, CacheRequest::DELIVERED);

    expect(is_file($request) ? file_get_contents($request) : 'none')
        ->toBe(CacheDelivery::request('ghcr.io/owner/proofs', Planned::of()->base(), CacheClass::Branch)->text());
});

it('says how many units the plan proved by a pack\'s proof alone, and nothing where a ledger holds each', function (bool $ledgered): void {
    $project = Flows::project();
    $store = new ProofStoreFake();
    $plan = Planned::of()->considering(Considered::everything()->proving(Units::of(Unit::file(Path::of('src/Money.php')))));
    $packed = cachedProof(cachedRun('packed'));

    if ($ledgered) {
        $store->write(Scope::branch('main'), Ledger::empty()->withProof($packed));
    }

    foreach (CacheClass::cases() as $class) {
        Scratch::write($project, sprintf('.mutation-gate/cache/%s.pack', $class->value), CachePack::bytes($class, $plan->base(), Proofs::of($packed)));
    }

    $ledgers = Ledgers::read($store, Standing::planned($plan), Writing::Auto, TakenProofs::Every);
    $lines = new CachedPacks(Flows::adapters($project, [], $store), Flows::settings(OutbreakSettings::on(), OutbreakCache::storedInActions()))->lines($plan, $ledgers);

    expect($lines)->toBe($ledgered ? [] : ['The proof cache proved 1 units no ledger the run reads holds a proof of.']);
})->with(['by a pack alone' => [false], 'by a ledger too' => [true]]);

it('reads the trusted pack alone on the default branch and in a merge group, and the branch pack after it anywhere else', function (): void {
    $packs = new CachedPacks(Flows::adapters(Flows::project()), Flows::settings(OutbreakSettings::on(), OutbreakCache::storedInActions(), OutbreakCache::trustingTheDefaultBranch()));
    $on = static fn(RunOn $run): array => $packs->classes(Standing::planned(Planned::of()->on($run)));

    expect($on(RunOn::at(Scope::branch('main'), Scope::branch('main'))))->toBe([CacheClass::Trusted])
        ->and($on(RunOn::mergeGroup(Revision::ref('refs/heads/main'), Scope::branch('main'))))->toBe([CacheClass::Trusted])
        ->and($on(RunOn::at(Scope::branch('feature'), Scope::branch('main'))))->toBe([CacheClass::Trusted, CacheClass::Branch]);
});

it('removes each pack the run reads before the binary takes it, and asks for each into its place', function (): void {
    $project = Flows::project();
    $engine = new CachingEngine();
    Scratch::write($project, '.mutation-gate/cache/trusted.pack', 'stale');
    Scratch::write($project, '.mutation-gate/cache/branch.pack', 'stale');
    $standing = Standing::planned(Planned::of()->on(RunOn::at(Scope::branch('feature'), Scope::branch('main'))));

    $pulled = new CachedPacks(Flows::adapters($project, [], $engine), Flows::settings(OutbreakSettings::on(), OutbreakCache::storedInActions()))
        ->pulled($standing, Planned::of()->base());

    expect($pulled)->toBeInstanceOf(Written::class)
        ->and([is_file(sprintf('%s/.mutation-gate/cache/trusted.pack', $project)), is_file(sprintf('%s/.mutation-gate/cache/branch.pack', $project))])
        ->toBe([false, false])
        ->and(array_map(static fn(CacheRequest $request): array => $request->packs(), $engine->pulls))->toEqual([[
            'trusted' => Path::of('.mutation-gate/cache/trusted.pack'),
            'branch' => Path::of('.mutation-gate/cache/branch.pack'),
        ]]);
});

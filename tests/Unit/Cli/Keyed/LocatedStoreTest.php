<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Azure\ContainerLedger;
use NightWorksIO\MutationGate\Cli\Keyed\LocatedStore;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Config\Options;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Port\ProofStore;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\Cloud;
use NightWorksIO\MutationGate\Tests\Support\FixedTokens;

/**
 * The store located in this environment, with one store registered under this name, built as this builds it.
 *
 * @param array<string, string>                 $environment
 * @param Closure(Options): (ProofStore|Invalid) $build
 */
function locatedStoreOf(array $environment, string $name, Closure $build): LocatedStore
{
    return new LocatedStore(Variables::of($environment), new Extensions(Origin::of('acme/gate'))->withProofStore(Name::of($name), $build));
}

const LOCATED_AZURE = [
    'MUTATION_GATE_STORE' => 'azure',
    'MUTATION_GATE_STORE_ACCOUNT' => 'acme',
    'MUTATION_GATE_STORE_CONTAINER' => 'ledgers',
    'MUTATION_GATE_AZURE_TOKEN' => 'eyJ',
];

it('builds the store its environment locates, where the job holds its credentials', function (): void {
    $store = new ProofStoreFake();

    expect(locatedStoreOf(LOCATED_AZURE, 'azure', static fn(): ProofStore => $store)->forDefaultBranch(Scope::branch('main')))
        ->toBe($store);
});

it('keeps the default branch\'s ledger in Azure\'s public container, where one is named', function (): void {
    $cloud = new Cloud();
    $build = static fn(): ProofStore => ContainerLedger::of($cloud->exchange(), 'acme', 'ledgers', 'gate', FixedTokens::of('eyJ'))
        ->publishing('public');
    $store = locatedStoreOf(LOCATED_AZURE, 'azure', $build)->forDefaultBranch(Scope::branch('trunk'));

    $read = $store instanceof ProofStore ? $store->read(Scope::branch('trunk')) : $store;

    expect($read)->toEqual(Ledger::empty())
        ->and(array_column($cloud->requests, 'url'))
        ->toBe(['https://acme.blob.core.windows.net/public/gate/refs/heads/trunk/ledger.json.gz']);
});

it('builds no store where the job does not hold its credentials, and says so apart', function (): void {
    $built = new ArrayObject();
    $located = locatedStoreOf(
        ['MUTATION_GATE_STORE' => 'gcs', 'MUTATION_GATE_STORE_BUCKET' => 'proofs'],
        'gcs',
        static function () use ($built): ProofStore {
            $built->append('built');

            return new ProofStoreFake();
        },
    )->forDefaultBranch(Scope::branch('main'));

    expect($located)->toEqual(NotWritten::because('MUTATION_GATE_STORE names gcs, whose credentials this job does not hold.'))
        ->and([...$built])->toBe([]);
});

it('cannot judge a store its environment does not locate, or one whose options build none', function (): void {
    $refused = static fn(): Invalid => Invalid::because(Problem::at('endpoint', 'is refused'), Problem::at('bucket', 'too'));

    expect(locatedStoreOf([], 'azure', $refused)->forDefaultBranch(Scope::branch('main')))
        ->toEqual(CannotJudge::because('MUTATION_GATE_STORE names no built-in store that needs credentials: s3, gcs or azure.'))
        ->and(locatedStoreOf(LOCATED_AZURE, 'azure', $refused)->forDefaultBranch(Scope::branch('main')))
        ->toEqual(CannotJudge::because('proofs.store.with.endpoint: is refused; proofs.store.with.bucket: too'));
});

it('locates the store from this process\'s own environment', function (): void {
    expect(LocatedStore::online()->forDefaultBranch(Scope::branch('main')))
        ->toEqual(CannotJudge::because('MUTATION_GATE_STORE names no built-in store that needs credentials: s3, gcs or azure.'));
});

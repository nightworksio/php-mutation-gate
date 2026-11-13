<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_filter;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Filesystem\DeliveredLedger;
use NightWorksIO\MutationGate\Adapter\Outbreak\CacheDelivery;
use NightWorksIO\MutationGate\Core\Config\CacheStore;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Proof\CacheClass;
use NightWorksIO\MutationGate\Core\Proof\CachedProofs;
use NightWorksIO\MutationGate\Core\Proof\CachePack;
use NightWorksIO\MutationGate\Core\Proof\CacheRequest;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\LedgerLimits;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\ProofCache;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Written;

use function sprintf;

/**
 * The packs of `outbreak cache` a run reads (ADR-0131): those of the classes
 * of writer it trusts, at the base its keys are built on, as the plan left
 * them in `.mutation-gate/cache`. Every run trusts the default branch's own
 * runs; a pull request and a branch's push trust a branch's runs too, and the
 * default branch and a merge group only where `outbreak.cache.trust` is
 * `repository`. Where the store is `none`, a pack is missing or cannot be
 * read, the run proves from its ledgers alone.
 *
 * @internal
 */
final readonly class CachedPacks
{
    private const string NO_STORE = 'No store of proof packs is in use.';

    private const string PROVED = 'The proof cache proved %d units no ledger the run reads holds a proof of.';

    public function __construct(private Adapters $adapters, private Settings $settings)
    {
    }

    /** Whether the run has a store of packs at all, as `outbreak.cache.store` resolves for it. */
    public function store(): CacheStore
    {
        $cache = $this->settings->shards()->outbreak()->cache();

        return $this->settings->shards()->outbreak()->enabled()
            ? $cache->storeFor($this->adapters->environment->onGitHubActions())
            : CacheStore::None;
    }

    /**
     * The classes of writer a run of this standing reads, the first first.
     *
     * @return list<CacheClass>
     */
    public function classes(Standing $standing): array
    {
        $scope = $standing->runOn()->scope();
        $trusted = $scope instanceof Scope && $scope->equals($standing->defaultBranch())
            || ! $standing->runOn()->groupBase() instanceof NotGiven;

        return CacheClass::readBy($trusted, $this->settings->shards()->outbreak()->cache()->trust());
    }

    /**
     * The packs a run of this standing reads at this base taken by
     * Outbreak's binary into the gate's directory from where a job that runs
     * none of the project's code left them: the Actions cache's restore, or
     * `fetch`'s pull from the workflow's registry. The plan, which runs the
     * project's code, reaches no registry and holds no token. What was there
     * before is removed first, so a pack the binary did not take proves
     * nothing.
     */
    public function pulled(Standing $standing, Digest $base): Written|NotWritten
    {
        $store = $this->store();
        $cache = $this->adapters->maps;

        if ($store === CacheStore::None || ! $cache instanceof ProofCache) {
            return NotWritten::because(self::NO_STORE);
        }

        $request = $this->request(CacheStore::Actions, $base);

        foreach ($this->classes($standing) as $class) {
            $this->adapters->project->remove(Workspace::cachePack($class));
            $request = $request->for($class, Workspace::cachePack($class), Workspace::restoredPacks($class));
        }

        return $cache->pulled($request);
    }

    /**
     * The entries a verdict wrote at this base, of the class the run writes
     * as, merged into their pack in the store; or why they were not.
     */
    public function pushed(CacheClass $class, Path $entries, Digest $base): Written|NotWritten
    {
        $store = $this->store();
        $cache = $this->adapters->maps;

        return $store === CacheStore::None || ! $cache instanceof ProofCache
            ? NotWritten::because(self::NO_STORE)
            : $cache->pushed($this->request($store, $base)->for($class, $entries, Workspace::restoredPacks($class)));
    }

    /** The proofs the packs a run of this standing reads hold at this base. */
    public function read(Standing $standing, Digest $base): CachedProofs
    {
        $cached = CachedProofs::none();

        if ($this->store() === CacheStore::None) {
            return $cached;
        }

        $limits = LedgerLimits::kept($this->settings->proofs()->retained());

        foreach ($this->classes($standing) as $class) {
            $bytes = $this->adapters->project->read(Workspace::cachePack($class));
            $proofs = $bytes instanceof Contents ? CachePack::read($bytes->text(), $class, $base, $limits) : null;
            $cached = $proofs instanceof Proofs ? $cached->with($class, $proofs) : $cached;
        }

        return $cached;
    }

    /**
     * The proofs this run wrote into its ledger at the plan's base left as the
     * entries of its class's pack, for the store of `outbreak cache` to take
     * (ADR-0131): under `actions` merged into the pack the Actions cache saves
     * in the run's scope now, and under `oci` left for `deliver`, which holds
     * the registry's credential. A run with no store leaves none.
     */
    public function left(Ledger $ledger, Plan $plan, Run $run, bool $onTheDefaultBranch): void
    {
        $store = $this->store();

        if ($store === CacheStore::None) {
            return;
        }

        $class = CacheClass::writtenBy($onTheDefaultBranch);
        $ours = Proofs::of(...array_filter(
            [...$ledger->proofs()],
            static fn(Proof $proof): bool => $proof->run()->id() === $run->id(),
        ));
        $entries = Workspace::cacheEntries($class);
        $bytes = Contents::of(CachePack::bytes($class, $plan->base(), $ours));
        $written = $this->adapters->project->write($entries, $bytes);
        $registry = $this->settings->shards()->outbreak()->cache()->registry();
        $proofs = $this->adapters->proofs;

        if (! $written instanceof Written) {
            return;
        }

        if ($store === CacheStore::Actions) {
            $this->pushed($class, $entries, $plan->base());

            return;
        }

        if ($proofs instanceof DeliveredLedger && is_string($registry)) {
            $proofs->cache(CacheDelivery::request($registry, $plan->base(), $class), $bytes);
        }
    }
    /**
     * What the plan says of the cache: how many of the units it proved only
     * a pack's proof covers, none of the ledgers' (ADR-0131); nothing where
     * the cache proved none.
     *
     * @return list<string>
     */
    public function lines(Plan $plan, Ledgers $ledgers): array
    {
        $cached = $this->read(Standing::planned($plan), $plan->base());
        $taken = 0;

        foreach ($plan->considered()->proved() as $unit) {
            $key = $plan->keys()->keyOf($unit->path());
            $taken += $key instanceof Digest
                && ! $ledgers->defaultBranch()->proofs()->has($key)
                && ! $ledgers->own()->proofs()->has($key)
                && ($cached->trusted()->has($key) || $cached->branch()->has($key))
                    ? 1
                    : 0;
        }

        return $taken === 0 ? [] : [sprintf(self::PROVED, $taken)];
    }

    private function request(CacheStore $store, Digest $base): CacheRequest
    {
        return CacheRequest::to(
            $store,
            $this->settings->shards()->outbreak()->cache()->registry(),
            $base,
            LedgerLimits::kept($this->settings->proofs()->retained()),
        );
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

/**
 * Where `outbreak cache` keeps proofs by their keys, beside the ledgers, and
 * which of its packs the default branch reads (ADR-0131): `outbreak.cache`.
 * Each decides how the gate runs, and none is in a proof's key.
 */
final readonly class OutbreakCache
{
    /** The key the `outbreak` section holds these settings under. */
    public const string OPTION = 'cache';

    /** The key of `outbreak.cache.store`. */
    public const string STORE = 'store';

    /** The key of `outbreak.cache.registry`. */
    public const string REGISTRY = 'registry';

    /** The key of `outbreak.cache.trust`. */
    public const string TRUST = 'trust';

    private function __construct(
        private CacheStore|Absent $store,
        private string|Absent $registry,
        private CacheTrust|Absent $trust,
    ) {
    }

    public static function of(
        CacheStore|Absent $store = new Absent(),
        string|Absent $registry = new Absent(),
        CacheTrust|Absent $trust = new Absent(),
    ): self {
        return new self($store, $registry, $trust);
    }

    /** This, with what a later layer sets laid over it, key by key. */
    public function over(self $later): self
    {
        return new self(
            Absent::laid($this->store, $later->store),
            Absent::laid($this->registry, $later->registry),
            Absent::laid($this->trust, $later->trust),
        );
    }

    /** `outbreak.cache.store`: where the packs live; `auto` by default. */
    public function store(): CacheStore
    {
        return $this->store instanceof CacheStore ? $this->store : CacheStore::Auto;
    }

    /** `outbreak.cache.registry`: the OCI repository the packs live in under `oci`; none by default. */
    public function registry(): string|NotGiven
    {
        return $this->registry instanceof Absent ? NotGiven::value() : $this->registry;
    }

    /** `outbreak.cache.trust`: which packs the default branch and a merge group read; `default-branch` by default. */
    public function trust(): CacheTrust
    {
        return $this->trust instanceof CacheTrust ? $this->trust : CacheTrust::DefaultBranch;
    }

    /** These settings as the runner's options carry them, every one in force. */
    public function option(): Member
    {
        return Member::of(self::OPTION, Json::object(
            Member::of(self::STORE, $this->store()->value),
            Member::of(self::REGISTRY, $this->registry),
            Member::of(self::TRUST, $this->trust()->value),
        ));
    }

    /** These settings as a layer writes them, only those it sets. */
    public function written(): Member
    {
        return Member::unlessEmpty(self::OPTION, Json::object(
            Member::of(self::STORE, $this->store instanceof CacheStore ? $this->store->value : $this->store),
            Member::of(self::REGISTRY, $this->registry),
            Member::of(self::TRUST, $this->trust instanceof CacheTrust ? $this->trust->value : $this->trust),
        ));
    }

    /**
     * The builder's calls that set these as a layer sets them.
     *
     * @return list<string>
     */
    public function php(): array
    {
        return [
            ...$this->store instanceof CacheStore ? [$this->store->php()] : [],
            ...$this->registry instanceof Absent
                ? []
                : [sprintf('Outbreak::cacheRegistry(%s)', PhpCalls::literal($this->registry))],
            ...$this->trust instanceof CacheTrust ? [$this->trust->php()] : [],
        ];
    }
}

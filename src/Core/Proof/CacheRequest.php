<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\Config\CacheStore;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * What a run asks of the store of `outbreak cache` (ADR-0131): the store and
 * its registry, the base a pack holds proofs at, the limits a pack is held
 * to, and the packs each class of writer reads or writes, a pack restored by
 * the Actions cache read from where it was restored.
 *
 * @internal
 */
final readonly class CacheRequest
{
    /**
     * @param array<string, Path> $packs    by class: where its pack is pulled into, or the entries pushed are
     * @param array<string, Path> $restored by class: where the Actions cache restored its packs
     */
    private function __construct(
        private CacheStore $store,
        private string|NotGiven $registry,
        private Digest $base,
        private LedgerLimits $limits,
        private array $packs,
        private array $restored,
    ) {
    }

    /** A request to this store, under this registry, at this base, each pack held to these limits. */
    public static function to(CacheStore $store, string|NotGiven $registry, Digest $base, LedgerLimits $limits): self
    {
        return new self($store, $registry, $base, $limits, [], []);
    }

    /** This request, for one more class's pack, at this path, restored at that path under `actions`. */
    public function for(CacheClass $class, Path $pack, Path $restored): self
    {
        return new self(
            $this->store,
            $this->registry,
            $this->base,
            $this->limits,
            [...$this->packs, $class->value => $pack],
            [...$this->restored, $class->value => $restored],
        );
    }

    public function store(): CacheStore
    {
        return $this->store;
    }

    public function registry(): string|NotGiven
    {
        return $this->registry;
    }

    public function base(): Digest
    {
        return $this->base;
    }

    public function limits(): LedgerLimits
    {
        return $this->limits;
    }

    /** @return array<string, Path> each class's pack, by the class */
    public function packs(): array
    {
        return $this->packs;
    }

    /** @return array<string, Path> where the Actions cache restored each class's packs, by the class */
    public function restored(): array
    {
        return $this->restored;
    }
}

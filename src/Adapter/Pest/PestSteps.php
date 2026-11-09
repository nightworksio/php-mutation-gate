<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Core\Config\Duplicates;
use NightWorksIO\MutationGate\Core\Config\LongFilter;
use NightWorksIO\MutationGate\Core\Config\NarrowedKillTrust;
use NightWorksIO\MutationGate\Core\Config\Toggle;
use NightWorksIO\MutationGate\Core\Config\Toggles;

/**
 * Which steps a patched run takes: those `pest.trials`, `pest.valueReaders` and `pest.narrow` turn on or off, what
 * a narrowed kill needs before it counts, what a run selects where its filter cannot be passed, whether
 * mutants that are one program are judged once, and whether a shard's chunks keep their mutants' workers
 * (ADR-0023, ADR-0137, ADR-0138, ADR-0142).
 *
 * @internal
 */
final readonly class PestSteps
{
    private function __construct(
        private Toggles $toggles,
        private NarrowedKillTrust $narrowedKills,
        private LongFilter $longFilter,
        private Duplicates $duplicates,
        private bool $bootChunks = false,
    ) {
    }

    public static function standard(): self
    {
        return new self(Toggles::none(), NarrowedKillTrust::Vouch, LongFilter::Files, Duplicates::JudgeOnce);
    }

    public static function of(
        Toggles $toggles,
        NarrowedKillTrust $narrowedKills,
        LongFilter $longFilter,
        Duplicates $duplicates,
        bool $bootChunks = false,
    ): self {
        return new self($toggles, $narrowedKills, $longFilter, $duplicates, $bootChunks);
    }

    /** Whether a shard's chunks keep their mutants' warm workers for the next chunk, as `pest.bootChunks` says. */
    public function bootsChunks(): bool
    {
        return $this->bootChunks;
    }

    /** Whether mutants that are one program are judged once (ADR-0137). */
    public function duplicates(): Duplicates
    {
        return $this->duplicates;
    }

    public function on(Toggle $toggle): bool
    {
        return $this->toggles->on($toggle);
    }

    public function narrowedKills(): NarrowedKillTrust
    {
        return $this->narrowedKills;
    }

    public function longFilter(): LongFilter
    {
        return $this->longFilter;
    }
}

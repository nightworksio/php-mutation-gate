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
 * a narrowed kill needs before it counts, what a run selects where its filter cannot be passed, and whether
 * mutants that are one program are judged once (ADR-0137, ADR-0138, ADR-0142).
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
    ): self {
        return new self($toggles, $narrowedKills, $longFilter, $duplicates);
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

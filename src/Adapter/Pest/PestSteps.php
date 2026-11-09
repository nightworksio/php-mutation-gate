<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Core\Config\LongFilter;
use NightWorksIO\MutationGate\Core\Config\NarrowedKillTrust;
use NightWorksIO\MutationGate\Core\Config\Toggle;
use NightWorksIO\MutationGate\Core\Config\Toggles;

/**
 * Which steps a patched run takes: those `pest.trials`, `pest.valueReaders` and `pest.narrow` turn on or off, what
 * a narrowed kill needs before it counts, and what a run selects where its filter cannot be passed (ADR-0138,
 * ADR-0142).
 *
 * @internal
 */
final readonly class PestSteps
{
    private function __construct(
        private Toggles $toggles,
        private NarrowedKillTrust $narrowedKills,
        private LongFilter $longFilter,
    ) {
    }

    public static function standard(): self
    {
        return new self(Toggles::none(), NarrowedKillTrust::Vouch, LongFilter::Files);
    }

    public static function of(Toggles $toggles, NarrowedKillTrust $narrowedKills, LongFilter $longFilter): self
    {
        return new self($toggles, $narrowedKills, $longFilter);
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

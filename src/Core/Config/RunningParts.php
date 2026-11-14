<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/**
 * The parts of one layer of config that decide how a run goes: its shards,
 * its CI, a run on this machine, Pest's, the static check, and which steps
 * of a run are on or off.
 */
final readonly class RunningParts
{
    public function __construct(
        private Shards $shards,
        private Ci $ci,
        private Local $local,
        private Pest $pest,
        private StaticCheck $staticCheck,
        private Toggles $toggles,
    ) {
    }

    public function shards(): Shards
    {
        return $this->shards;
    }

    public function ci(): Ci
    {
        return $this->ci;
    }

    public function local(): Local
    {
        return $this->local;
    }

    public function pest(): Pest
    {
        return $this->pest;
    }

    public function staticCheck(): StaticCheck
    {
        return $this->staticCheck;
    }

    /** Which steps of a run are on or off (ADR-0112, ADR-0142). */
    public function toggles(): Toggles
    {
        return $this->toggles;
    }
}

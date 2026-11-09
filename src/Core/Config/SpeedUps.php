<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Local\LocalSwitch;

use function sprintf;

/**
 * Which speed-ups of the local loop a layer turns on or off, each by its
 * `local` key, and each on where no layer sets it (ADR-0120).
 */
final readonly class SpeedUps
{
    /** @param array<string, bool|Absent> $switched by the switch's key */
    private function __construct(private array $switched)
    {
    }

    public static function of(
        bool|Absent $watchStatus = new Absent(),
        bool|Absent $coverageAlone = new Absent(),
        bool|Absent $sizedPools = new Absent(),
        bool|Absent $listOnce = new Absent(),
        bool|Absent $streamBatches = new Absent(),
    ): self {
        return new self([
            LocalSwitch::WatchStatus->value => $watchStatus,
            LocalSwitch::CoverageAlone->value => $coverageAlone,
            LocalSwitch::SizedPools->value => $sizedPools,
            LocalSwitch::ListOnce->value => $listOnce,
            LocalSwitch::StreamBatches->value => $streamBatches,
        ]);
    }

    /** Every speed-up on, as when no layer sets one. */
    public static function standard(): self
    {
        return self::of(watchStatus: true, coverageAlone: true, sizedPools: true, listOnce: true, streamBatches: true);
    }

    /** These, with what a later layer sets laid over them, key by key. */
    public function over(self $later): self
    {
        $switched = [];

        foreach ($this->switched as $key => $value) {
            $switched[$key] = Absent::laid($value, $later->switched[$key]);
        }

        return new self($switched);
    }

    /** Whether this speed-up is on: it is unless a layer turns it off. */
    public function isOn(LocalSwitch $switch): bool
    {
        return $this->switched[$switch->value] !== false;
    }

    /** The `local` object a config writes, with the keys a layer sets. */
    public function written(Json $local): Json
    {
        $members = [];

        foreach ($this->switched as $key => $value) {
            $members[] = Member::of($key, $value);
        }

        return $local->with(...$members);
    }

    /** The PHP builder's calls for the keys a layer sets. */
    public function php(): PhpCalls
    {
        $calls = [];

        foreach ($this->switched as $key => $value) {
            $calls = $value instanceof Absent
                ? $calls
                : [...$calls, sprintf('LocalWork::%s(on: %s)', $key, PhpCalls::literal($value))];
        }

        return PhpCalls::inWith(...$calls);
    }
}

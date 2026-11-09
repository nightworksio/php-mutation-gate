<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Local\CpuProfile;
use NightWorksIO\MutationGate\Core\Local\MacosClass;
use NightWorksIO\MutationGate\Core\Local\OnBattery;
use NightWorksIO\MutationGate\Core\Local\Priority;
use NightWorksIO\MutationGate\Core\Local\WindowsClass;
use NightWorksIO\MutationGate\Core\Local\WorkerShare;

/**
 * How `watch` and `serve` use the machine, and the speed-ups they take
 * (ADR-0120): keys of `local`.
 */
final readonly class LocalWork implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** `local.cpu`: how much of the machine the local loop takes, `balanced` by default. */
    public static function cpu(CpuProfile $profile): self
    {
        return new self(Json::at('local.cpu', $profile->value));
    }

    /** `local.workers`: how many workers, or which share of the logical cores; the profile's by default. */
    public static function workers(int|WorkerShare $workers): self
    {
        return new self(Json::at('local.workers', $workers instanceof WorkerShare ? $workers->value : $workers));
    }

    /** `local.priority`: how the local loop weighs its work against the machine's; the profile's by default. */
    public static function priority(Priority $priority): self
    {
        return new self(Json::at('local.priority', $priority->value));
    }

    /** `local.macosClass`: the QoS clamp on macOS; the priority's by default. */
    public static function macosClass(MacosClass $class): self
    {
        return new self(Json::at('local.macosClass', $class->value));
    }

    /** `local.linuxNice`: the niceness on Linux, 0 to 19; the priority's by default. */
    public static function linuxNice(int $nice): self
    {
        return new self(Json::at('local.linuxNice', $nice));
    }

    /** `local.windowsClass`: the priority class on Windows; the priority's by default. */
    public static function windowsClass(WindowsClass $class): self
    {
        return new self(Json::at('local.windowsClass', $class->value));
    }

    /** `local.onBattery`: what the local loop does on battery or in Low Power Mode, `reduce` by default. */
    public static function onBattery(OnBattery $onBattery): self
    {
        return new self(Json::at('local.onBattery', $onBattery->value));
    }

    /** `local.childWait`: how long a warm worker or its client waits for a wake before it looks, `1s` by default. */
    public static function childWait(string $duration): self
    {
        return new self(Json::at('local.childWait', $duration));
    }

    /** `local.watchStatus`: whether `watch` looks through `git status`; on by default. */
    public static function watchStatus(bool $on): self
    {
        return new self(Json::at('local.watchStatus', $on));
    }

    /** `local.coverageAlone`: whether a coverage run of one test file runs in one process; on by default. */
    public static function coverageAlone(bool $on): self
    {
        return new self(Json::at('local.coverageAlone', $on));
    }

    /** `local.sizedPools`: whether a pool of warm workers boots no more workers than it has runs; on by default. */
    public static function sizedPools(bool $on): self
    {
        return new self(Json::at('local.sizedPools', $on));
    }

    /** `local.listOnce`: whether a round lists the tests once and a later round reuses the list; on by default. */
    public static function listOnce(bool $on): self
    {
        return new self(Json::at('local.listOnce', $on));
    }

    /** `local.streamBatches`: whether `watch` prints what each batch judged as it ends; on by default. */
    public static function streamBatches(bool $on): self
    {
        return new self(Json::at('local.streamBatches', $on));
    }

    public function written(): Json
    {
        return $this->json;
    }
}

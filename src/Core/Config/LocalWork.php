<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function is_int;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Local\CpuProfile;
use NightWorksIO\MutationGate\Core\Local\LocalSwitch;
use NightWorksIO\MutationGate\Core\Local\MacosClass;
use NightWorksIO\MutationGate\Core\Local\OnBattery;
use NightWorksIO\MutationGate\Core\Local\Priority;
use NightWorksIO\MutationGate\Core\Local\WindowsClass;
use NightWorksIO\MutationGate\Core\Local\WorkerShare;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * How `watch` and `serve` use the machine (ADR-0120), which the `Local` part
 * holds: how many workers and at what priority, what they do on battery, and
 * the speed-ups that change no result, each its own key and on by default
 * (see SpeedUps).
 * `run` and CI go by `runner.*` and none of these.
 */
final readonly class LocalWork
{
    /** How long a warm worker or its client waits for a wake before it looks anyway, by default, in seconds. */
    private const float CHILD_WAIT = 1.0;

    private function __construct(
        private CpuProfile|Absent $cpu,
        private WorkerShare|int|Absent $workers,
        private Priority|Absent $priority,
        private MacosClass|Absent $macosClass,
        private int|Absent $linuxNice,
        private WindowsClass|Absent $windowsClass,
        private OnBattery|Absent $onBattery,
        private Seconds|Absent $childWait,
        private SpeedUps $speedUps,
    ) {
    }

    public static function of(
        CpuProfile|Absent $cpu = new Absent(),
        WorkerShare|int|Absent $workers = new Absent(),
        Priority|Absent $priority = new Absent(),
        MacosClass|Absent $macosClass = new Absent(),
        int|Absent $linuxNice = new Absent(),
        WindowsClass|Absent $windowsClass = new Absent(),
        OnBattery|Absent $onBattery = new Absent(),
        Seconds|Absent $childWait = new Absent(),
        SpeedUps|Absent $speedUps = new Absent(),
    ): self {
        return new self(
            $cpu,
            $workers,
            $priority,
            $macosClass,
            $linuxNice,
            $windowsClass,
            $onBattery,
            $childWait,
            $speedUps instanceof SpeedUps ? $speedUps : SpeedUps::of(),
        );
    }

    /** The keys whose default is a value of its own: the others follow `local.cpu` and `local.priority`. */
    public static function standard(): self
    {
        $none = self::of();

        return self::of(
            $none->cpu(),
            onBattery: $none->onBattery(),
            childWait: $none->childWait(),
            speedUps: SpeedUps::standard(),
        );
    }

    public function over(self $later): self
    {
        return new self(
            Absent::laid($this->cpu, $later->cpu),
            Absent::laid($this->workers, $later->workers),
            Absent::laid($this->priority, $later->priority),
            Absent::laid($this->macosClass, $later->macosClass),
            Absent::laid($this->linuxNice, $later->linuxNice),
            Absent::laid($this->windowsClass, $later->windowsClass),
            Absent::laid($this->onBattery, $later->onBattery),
            Absent::laid($this->childWait, $later->childWait),
            $this->speedUps->over($later->speedUps),
        );
    }

    /** `local.cpu`: how much of the machine the local loop takes; balanced by default. */
    public function cpu(): CpuProfile
    {
        return $this->cpu instanceof CpuProfile ? $this->cpu : CpuProfile::Balanced;
    }

    /** `local.workers`, or the profile's share: how many workers of these cores the local loop runs. */
    public function workers(ProcessCount $cores): ProcessCount
    {
        $workers = $this->workers instanceof Absent ? $this->cpu()->workers() : $this->workers;

        return is_int($workers) ? ProcessCount::atLeastOne($workers) : $workers->of($cores);
    }

    /** `local.priority`, or the profile's. */
    public function priority(): Priority
    {
        return $this->priority instanceof Priority ? $this->priority : $this->cpu()->priority();
    }

    /** `local.macosClass`, or the priority's on macOS. */
    public function macosClass(): MacosClass
    {
        return $this->macosClass instanceof MacosClass ? $this->macosClass : $this->priority()->macos();
    }

    /** `local.linuxNice`, or the priority's on Linux. */
    public function linuxNice(): int
    {
        return is_int($this->linuxNice) ? $this->linuxNice : $this->priority()->nice();
    }

    /** `local.windowsClass`, or the priority's on Windows. */
    public function windowsClass(): WindowsClass
    {
        return $this->windowsClass instanceof WindowsClass ? $this->windowsClass : $this->priority()->windows();
    }

    /** `local.onBattery`: what the local loop does on battery or in Low Power Mode; reduce by default. */
    public function onBattery(): OnBattery
    {
        return $this->onBattery instanceof OnBattery ? $this->onBattery : OnBattery::Reduce;
    }

    /** `local.childWait`: how long a warm worker or its client waits for a wake before it looks anyway. */
    public function childWait(): Seconds
    {
        return $this->childWait instanceof Seconds ? $this->childWait : Seconds::of(self::CHILD_WAIT);
    }

    /** Whether a speed-up of the local loop is on: `local.<switch>`, each on by default. */
    public function isOn(LocalSwitch $switch): bool
    {
        return $this->speedUps->isOn($switch);
    }

    /** The `local` object a config writes, with the keys a layer sets. */
    public function written(Json $local): Json
    {
        $members = [
            Member::of('cpu', $this->word($this->cpu)),
            Member::of('workers', $this->workers instanceof WorkerShare ? $this->workers->value : $this->workers),
            Member::of('priority', $this->word($this->priority)),
            Member::of('macosClass', $this->word($this->macosClass)),
            Member::of('linuxNice', $this->linuxNice),
            Member::of('windowsClass', $this->word($this->windowsClass)),
            Member::of('onBattery', $this->word($this->onBattery)),
            Member::of('childWait', WholeSeconds::duration($this->childWait)),
        ];

        return $this->speedUps->written($local->with(...$members));
    }

    /** The PHP builder's calls for the keys a layer sets. */
    public function php(): PhpCalls
    {
        $calls = [
            ...$this->named('cpu', 'CpuProfile', $this->cpu),
            ...match (true) {
                is_int($this->workers) => [sprintf('LocalWork::workers(%d)', $this->workers)],
                $this->workers instanceof WorkerShare => [
                    sprintf('LocalWork::workers(WorkerShare::%s)', $this->workers->name),
                ],
                default => [],
            },
            ...$this->named('priority', 'Priority', $this->priority),
            ...$this->named('macosClass', 'MacosClass', $this->macosClass),
            ...is_int($this->linuxNice) ? [sprintf('LocalWork::linuxNice(%d)', $this->linuxNice)] : [],
            ...$this->named('windowsClass', 'WindowsClass', $this->windowsClass),
            ...$this->named('onBattery', 'OnBattery', $this->onBattery),
            ...$this->childWait instanceof Seconds
                ? [sprintf('LocalWork::childWait(%s)', PhpCalls::literal($this->childWait->written()))]
                : [],
        ];

        return PhpCalls::inWith(...$calls)->and($this->speedUps->php());
    }

    /** A case as a config writes it, or absent where the layer leaves it. */
    private function word(CpuProfile|Priority|MacosClass|WindowsClass|OnBattery|Absent $value): string|Absent
    {
        return $value instanceof Absent ? $value : $value->value;
    }

    /**
     * The builder's call that sets a key to a case, where the layer sets it.
     *
     * @return list<string>
     */
    private function named(
        string $key,
        string $enum,
        CpuProfile|Priority|MacosClass|WindowsClass|OnBattery|Absent $value,
    ): array {
        return $value instanceof Absent ? [] : [sprintf('LocalWork::%s(%s::%s)', $key, $enum, $value->name)];
    }
}

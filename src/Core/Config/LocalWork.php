<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_fill_keys;
use function array_key_exists;
use function is_int;
use function max;

use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Local\CpuProfile;
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
 * the speed-ups that change no result, each its own key and on by default.
 * `run` and CI go by `runner.*` and none of these.
 */
final readonly class LocalWork
{
    /** `local.watchStatus`: `watch` looks through `git status` and hashes only what differs. */
    public const string WATCH_STATUS = 'watchStatus';

    /** `local.coverageAlone`: a coverage run of one test file runs in one process. */
    public const string COVERAGE_ALONE = 'coverageAlone';

    /** `local.sizedPools`: a pool of warm workers boots no more workers than it has runs. */
    public const string SIZED_POOLS = 'sizedPools';

    /** `local.listOnce`: a round lists the tests once, and a later round reuses the list while no test file moved. */
    public const string LIST_ONCE = 'listOnce';

    /** `local.streamBatches`: `watch` prints what each batch judged as it ends. */
    public const string STREAM_BATCHES = 'streamBatches';

    /** The keys that switch a speed-up, in their order, each on by default. */
    public const array SWITCHES = [
        self::WATCH_STATUS,
        self::COVERAGE_ALONE,
        self::SIZED_POOLS,
        self::LIST_ONCE,
        self::STREAM_BATCHES,
    ];
    /** How long a warm worker or its client waits for a wake before it looks anyway, by default, in seconds. */
    private const float CHILD_WAIT = 1.0;

    /** @param array<string, bool|Absent> $switches by key */
    private function __construct(
        private CpuProfile|Absent $cpu,
        private WorkerShare|int|Absent $workers,
        private Priority|Absent $priority,
        private MacosClass|Absent $macosClass,
        private int|Absent $linuxNice,
        private WindowsClass|Absent $windowsClass,
        private OnBattery|Absent $onBattery,
        private Seconds|Absent $childWait,
        private array $switches,
    ) {
    }

    /** @param array<string, bool|Absent> $switches by key, those left out absent */
    public static function of(
        CpuProfile|Absent $cpu = new Absent(),
        WorkerShare|int|Absent $workers = new Absent(),
        Priority|Absent $priority = new Absent(),
        MacosClass|Absent $macosClass = new Absent(),
        int|Absent $linuxNice = new Absent(),
        WindowsClass|Absent $windowsClass = new Absent(),
        OnBattery|Absent $onBattery = new Absent(),
        Seconds|Absent $childWait = new Absent(),
        array $switches = [],
    ): self {
        $set = [];

        foreach (self::SWITCHES as $key) {
            $set[$key] = array_key_exists($key, $switches) ? $switches[$key] : new Absent();
        }

        return new self(
            $cpu,
            $workers,
            $priority,
            $macosClass,
            $linuxNice,
            $windowsClass,
            $onBattery,
            $childWait,
            $set,
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
            switches: array_fill_keys(self::SWITCHES, value: true),
        );
    }

    public function over(self $later): self
    {
        $switches = [];

        foreach ($this->switches as $key => $value) {
            $switches[$key] = Absent::laid($value, $later->switches[$key]);
        }

        return new self(
            Absent::laid($this->cpu, $later->cpu),
            Absent::laid($this->workers, $later->workers),
            Absent::laid($this->priority, $later->priority),
            Absent::laid($this->macosClass, $later->macosClass),
            Absent::laid($this->linuxNice, $later->linuxNice),
            Absent::laid($this->windowsClass, $later->windowsClass),
            Absent::laid($this->onBattery, $later->onBattery),
            Absent::laid($this->childWait, $later->childWait),
            $switches,
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

        return is_int($workers) ? ProcessCount::of(max(1, $workers)) : $workers->of($cores);
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

    /**
     * Whether a speed-up is on: `local.watchStatus`, `local.coverageAlone`,
     * `local.sizedPools`, `local.listOnce` or `local.streamBatches`; each is by
     * default.
     */
    public function isOn(string $switch): bool
    {
        return ! array_key_exists($switch, $this->switches) || $this->switches[$switch] !== false;
    }

    /** @return list<Member> the members a config writes under `local`, a member for each key */
    public function written(): array
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

        foreach ($this->switches as $key => $value) {
            $members[] = Member::of($key, $value);
        }

        return $members;
    }

    /**
     * The PHP builder's calls for the keys set.
     *
     * @return list<string>
     */
    public function php(): array
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

        foreach ($this->switches as $key => $value) {
            $calls = $value instanceof Absent
                ? $calls
                : [...$calls, sprintf('LocalWork::%s(on: %s)', $key, PhpCalls::literal($value))];
        }

        return $calls;
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

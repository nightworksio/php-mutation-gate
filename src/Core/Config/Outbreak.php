<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * Whether Outbreak's core, the `outbreak` binary, takes the bulk of the
 * coverage maps a plan hands its shards, and of the map a run's report is
 * converted into, off the gate's PHP, which binary, how long one run of it
 * may take and on how many threads it writes (ADR-0118, ADR-0119):
 * `outbreak`, which the `Shards` part holds. None changes a result, since the
 * binary's output is the gate's own, so none is in a proof's key or decides
 * how the gate runs.
 */
final readonly class Outbreak
{
    /** The key the Pest runner's options hold these settings under. */
    public const string OPTION = 'outbreak';

    /** The key of `outbreak.enabled`. */
    public const string ENABLED = 'enabled';

    /** The key of `outbreak.binary`. */
    public const string BINARY = 'binary';

    /** The key of `outbreak.timeout`. */
    public const string TIMEOUT = 'timeout';

    /** The key of `outbreak.threads`. */
    public const string THREADS = 'threads';

    /** The minutes one run of the binary may take, where the config does not say. */
    private const int MINUTES = 5;

    private function __construct(
        private bool|Absent $enabled,
        private string|Absent $binary,
        private Seconds|Absent $timeout,
        private int|Absent $threads,
    ) {
    }

    public static function of(
        bool|Absent $enabled = new Absent(),
        string|Absent $binary = new Absent(),
        Seconds|Absent $timeout = new Absent(),
        int|Absent $threads = new Absent(),
    ): self {
        return new self($enabled, $binary, $timeout, $threads);
    }

    /** This, with what a later layer sets laid over it, key by key. */
    public function over(self $later): self
    {
        return new self(
            Absent::laid($this->enabled, $later->enabled),
            Absent::laid($this->binary, $later->binary),
            Absent::laid($this->timeout, $later->timeout),
            Absent::laid($this->threads, $later->threads),
        );
    }

    /**
     * `outbreak.enabled`: whether the gate runs the binary where one fits the
     * machine and passes its checksum; true by default.
     */
    public function enabled(): bool
    {
        return $this->enabled instanceof Absent || $this->enabled;
    }

    /**
     * `outbreak.binary`: the binary to run, from the project's root where it
     * is relative, in place of the one the package ships; none by default.
     */
    public function binary(): string|NotGiven
    {
        return $this->binary instanceof Absent ? NotGiven::value() : $this->binary;
    }

    /**
     * `outbreak.timeout`: how long one run of the binary may take before it is
     * stopped and the gate does the work itself; five minutes by default.
     */
    public function timeout(): Seconds
    {
        return $this->timeout instanceof Seconds ? $this->timeout : Seconds::minutes(self::MINUTES);
    }

    /**
     * `outbreak.threads`: how many threads the binary writes a plan's maps on;
     * none by default, for as many as the machine has cores.
     */
    public function threads(): int|NotGiven
    {
        return $this->threads instanceof Absent ? NotGiven::value() : $this->threads;
    }

    /**
     * The runner a config chooses, with these settings beneath the options it
     * gives the Pest runner, which reads the plan's whole map in each shard
     * with the binary; any other runner as it is.
     */
    public function beneath(Choice $runner): Choice
    {
        $options = Json::object(Member::of(
            self::OPTION,
            Json::object(
                Member::of(self::ENABLED, $this->enabled()),
                Member::of(self::BINARY, $this->binary),
                Member::of(self::TIMEOUT, $this->timeout()->seconds()),
                Member::of(self::THREADS, $this->threads),
            ),
        ));

        return $runner->use()->value() === BuiltinRunner::Pest->value
            ? Choice::of($runner->use()->value(), $runner->options()->over($options))
            : $runner;
    }

    public function written(): Json
    {
        return Json::object(Member::unlessEmpty(
            self::OPTION,
            Json::object(
                Member::of(self::ENABLED, $this->enabled),
                Member::of(self::BINARY, $this->binary),
                Member::of(self::TIMEOUT, WholeSeconds::duration($this->timeout)),
                Member::of(self::THREADS, $this->threads),
            ),
        ));
    }

    public function php(): PhpCalls
    {
        return PhpCalls::inWith(...[
            ...match ($this->enabled) {
                true => ['Outbreak::on()'],
                false => ['Outbreak::off()'],
                default => [],
            },
            ...$this->binary instanceof Absent
                ? []
                : [sprintf('Outbreak::binary(%s)', PhpCalls::literal($this->binary))],
            ...$this->timeout instanceof Seconds
                ? [sprintf('Outbreak::timeout(%s)', PhpCalls::literal($this->timeout->written()))]
                : [],
            ...$this->threads instanceof Absent ? [] : [sprintf('Outbreak::threads(%d)', $this->threads)],
        ]);
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_keys;
use function array_map;
use function intval;

use NightWorksIO\MutationGate\Core\Cost\LineRate;
use NightWorksIO\MutationGate\Core\Cost\SecondsPerLine;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * How a plan is cut into shards and what cutting it costs (ADR-0006,
 * ADR-0013, ADR-0016): `shards` and `costs`.
 */
final readonly class Shards implements Part
{
    /** The seconds of work one shard is cut to, and the most shards a plan cuts. */
    private const int SECONDS = 600;

    private const int MOST = 20;


    private function __construct(
        private Seconds|Absent $seconds,
        private int|Absent $max,
        private Seconds|Absent $target,
        private Seconds|Absent $setup,
        private Table|Absent $secondsPerLine,
        private Price|Absent $perRunnerMinute,
    ) {
    }

    /** `shards.setup`: each shard's CI setup before the gate starts, which the gate cannot time (ADR-0013). */
    public function setup(): Seconds
    {
        return $this->setup instanceof Seconds ? $this->setup : Seconds::minutes(1);
    }

    public static function of(
        Seconds|Absent $seconds = new Absent(),
        int|Absent $max = new Absent(),
        Seconds|Absent $target = new Absent(),
        Seconds|Absent $setup = new Absent(),
        Table|Absent $secondsPerLine = new Absent(),
        Price|Absent $perRunnerMinute = new Absent(),
    ): self {
        return new self($seconds, $max, $target, $setup, $secondsPerLine, $perRunnerMinute);
    }

    public static function none(): self
    {
        return self::of();
    }

    public static function standard(): self
    {
        $none = self::none();

        return self::of(
            seconds: $none->seconds(),
            max: $none->max(),
            setup: $none->setup(),
            secondsPerLine: self::table(SecondsPerLine::standard()),
        );
    }

    /**
     * Where a later layer sets one of `target` and `seconds`, it replaces the other. A part that sets both keeps
     * both, for the definition to refuse.
     */
    public function over(Part $later): self
    {
        if (! $later instanceof self) {
            return $this;
        }

        $cutByTarget = ! $later->target instanceof Absent && $later->seconds instanceof Absent;
        $cutBySeconds = ! $later->seconds instanceof Absent && $later->target instanceof Absent;

        return new self(
            $cutByTarget ? new Absent() : Absent::laid($this->seconds, $later->seconds),
            Absent::laid($this->max, $later->max),
            $cutBySeconds ? new Absent() : Absent::laid($this->target, $later->target),
            Absent::laid($this->setup, $later->setup),
            match (true) {
                $later->secondsPerLine instanceof Absent => $this->secondsPerLine,
                $this->secondsPerLine instanceof Absent => $later->secondsPerLine,
                default => $this->secondsPerLine->merged($later->secondsPerLine),
            },
            Absent::laid($this->perRunnerMinute, $later->perRunnerMinute),
        );
    }

    /** How long one shard is cut to take. */
    public function seconds(): Seconds
    {
        return $this->seconds instanceof Seconds ? $this->seconds : Seconds::of(self::SECONDS);
    }

    /** The most shards a plan cuts. */
    public function max(): int
    {
        return $this->max instanceof Absent ? self::MOST : $this->max;
    }

    /** What a line costs before anything was measured, by path prefix. */
    public function secondsPerLine(): SecondsPerLine
    {
        return $this->secondsPerLine instanceof Table
            ? SecondsPerLine::of(...array_map(
                static fn(string $prefix, int|float $seconds): LineRate => LineRate::of($prefix, Seconds::of($seconds)),
                array_keys([...$this->secondsPerLine]),
                [...$this->secondsPerLine],
            ))
            : SecondsPerLine::standard();
    }

    /** The options the gate hands the cost model it builds in, `learned`: the seconds a line costs, by prefix. */
    public function costOptions(): Options
    {
        $perLine = Json::object();

        foreach ($this->secondsPerLine() as $prefix => $seconds) {
            $perLine = $perLine->with(Member::of($prefix, $seconds));
        }

        return Options::of(Json::object(Member::of(SecondsPerLine::KEY, $perLine)));
    }

    /** `shards.target`: the wall time the count is cut to fit, in place of `shards.seconds` (ADR-0013). */
    public function target(): Seconds|Absent
    {
        return $this->target;
    }

    /** `costs.perRunnerMinute`: what a runner-minute costs, where the team gives a rate (ADR-0016). */
    public function perRunnerMinute(): Price|Absent
    {
        return $this->perRunnerMinute;
    }

    public function written(PathOrigin $origin): Json
    {
        return Json::object(
            Member::unlessEmpty(
                'shards',
                Json::object(
                    Member::of(
                        'seconds',
                        $this->seconds instanceof Seconds ? intval($this->seconds->seconds()) : $this->seconds,
                    ),
                    Member::of('max', $this->max),
                    Member::of('target', $this->target instanceof Seconds ? $this->target->written() : $this->target),
                    Member::of('setup', $this->setup instanceof Seconds ? $this->setup->written() : $this->setup),
                ),
            ),
            Member::unlessEmpty(
                'costs',
                Json::object(
                    Member::of(
                        SecondsPerLine::KEY,
                        $this->secondsPerLine instanceof Table
                            ? $this->perLineFrom($origin)->written()
                            : $this->secondsPerLine,
                    ),
                    Member::of(
                        'perRunnerMinute',
                        $this->perRunnerMinute instanceof Price
                            ? $this->perRunnerMinute->written()
                            : $this->perRunnerMinute,
                    ),
                ),
            ),
        );
    }

    public function php(PathOrigin $origin): PhpCalls
    {
        $settings = [
            ...$this->seconds instanceof Seconds
                ? [sprintf('Shards::seconds(%d)', intval($this->seconds->seconds()))]
                : [],
            ...$this->max instanceof Absent ? [] : [sprintf('Shards::max(%d)', $this->max)],
            ...$this->target instanceof Seconds
                ? [sprintf('Shards::target(%s)', PhpCalls::literal($this->target->written()))]
                : [],
            ...$this->setup instanceof Seconds
                ? [sprintf('Shards::setup(%s)', PhpCalls::literal($this->setup->written()))]
                : [],
        ];

        return PhpCalls::inWith(...$settings, ...$this->costs($origin));
    }

    /** @return list<string> */
    private function costs(PathOrigin $origin): array
    {
        $calls = [];

        foreach ($this->secondsPerLine instanceof Table ? $this->perLineFrom($origin) : [] as $prefix => $seconds) {
            $calls[] = sprintf(
                'Shards::secondsPerLine(%s, %s)',
                PhpCalls::literal($prefix),
                PhpCalls::literal($seconds),
            );
        }

        return $this->perRunnerMinute instanceof Price ? [...$calls, sprintf(
            'Shards::perRunnerMinute(%s, %s)',
            PhpCalls::literal($this->perRunnerMinute->amount()),
            PhpCalls::literal($this->perRunnerMinute->currency()),
        )] : $calls;
    }

    /** Seconds per line by path prefix, as a table of them. */
    private static function table(SecondsPerLine $rates): Table
    {
        $table = Table::none();

        foreach ($rates as $prefix => $seconds) {
            $table = $table->merged(Table::row($prefix, $seconds));
        }

        return $table;
    }

    /** `costs.secondsPerLine` as a layer at this origin writes it: each prefix but `""`, every path, named from it. */
    private function perLineFrom(PathOrigin $origin): Table
    {
        $from = Table::none();

        foreach ($this->secondsPerLine instanceof Table ? $this->secondsPerLine : [] as $prefix => $seconds) {
            $key = $prefix;
            $from = $from->merged(
                Table::row($key === LineRate::EVERYWHERE ? $key : $origin->written(Path::of($key)), $seconds),
            );
        }

        return $from;
    }
}

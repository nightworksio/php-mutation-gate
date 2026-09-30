<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_keys;
use function array_map;
use function intval;

use NightWorksIO\MutationGate\Core\Config\Definition\Duration;
use NightWorksIO\MutationGate\Core\Config\Definition\Field;
use NightWorksIO\MutationGate\Core\Config\Definition\Integer;
use NightWorksIO\MutationGate\Core\Config\Definition\Number;
use NightWorksIO\MutationGate\Core\Config\Definition\NumberMap;
use NightWorksIO\MutationGate\Core\Config\Definition\Reading;
use NightWorksIO\MutationGate\Core\Config\Definition\Section;
use NightWorksIO\MutationGate\Core\Cost\LineRate;
use NightWorksIO\MutationGate\Core\Cost\SecondsPerLine;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;
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

    /** Each shard's CI setup before the gate starts, which the gate cannot time. */
    private const int SETUP = 60;

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
        return $this->setup instanceof Seconds ? $this->setup : Seconds::of(self::SETUP);
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
            secondsPerLine: Table::of(SecondsPerLine::standard()->written()),
        );
    }

    /** @return list<Field<Layer>> */
    public static function fields(): array
    {
        $judges = Effect::JudgesOrReportsOnly;
        $seconds = Field::optional('seconds', Integer::atLeast(1), $judges);
        $max = Field::optional('max', Integer::atLeast(1), $judges);
        $target = Field::optional('target', Duration::written(), $judges);
        $setup = Field::optional('setup', Duration::written(), $judges);
        $perLine = Field::optional('secondsPerLine', NumberMap::of(Number::atLeast(0)), $judges);
        $perMinute = Field::optional('perRunnerMinute', Price::shape(), $judges);

        return [
            Field::section(
                'shards',
                Section::of(
                    static function (Node $shards) use ($seconds, $max, $target, $setup): Layer|Invalid {
                        $cut = $seconds->read($shards);
                        $most = $max->read($shards);
                        $fit = $target->read($shards);
                        $before = $setup->read($shards);

                        return Reading::built(
                            static fn(): Layer => Layer::of(self::of(
                                seconds: self::secondsOf($cut->value()),
                                max: $most->value(),
                                target: $fit->value(),
                                setup: $before->value(),
                            )),
                            $cut,
                            $most,
                            $fit,
                            $before,
                        );
                    },
                    $seconds,
                    $max,
                    $target,
                    $setup,
                )->atMostOne(['seconds', 'target']),
            ),
            Field::section(
                'costs',
                Section::of(
                    static function (Node $costs) use ($perLine, $perMinute): Layer|Invalid {
                        $lines = $perLine->read($costs);
                        $minutes = $perMinute->read($costs);

                        return Reading::built(
                            static fn(): Layer => Layer::of(self::of(
                                secondsPerLine: $lines->value(),
                                perRunnerMinute: $minutes->value(),
                            )),
                            $lines,
                            $minutes,
                        );
                    },
                    $perLine,
                    $perMinute,
                ),
            ),
        ];
    }

    /** Where a later layer sets `target` or `seconds`, the one it sets replaces the other. */
    public function over(Part $later): self
    {
        if (! $later instanceof self) {
            return $this;
        }

        $cutByTarget = ! $later->target instanceof Absent;
        $cutBySeconds = ! $later->seconds instanceof Absent;

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

    public function written(Origin $origin): Json
    {
        $shards = Json::object();
        $shards = $this->seconds instanceof Seconds
            ? $shards->with('seconds', intval($this->seconds->seconds()))
            : $shards;
        $shards = $this->max instanceof Absent ? $shards : $shards->with('max', $this->max);
        $shards = $this->target instanceof Seconds ? $shards->with('target', $this->target->written()) : $shards;
        $shards = $this->setup instanceof Seconds ? $shards->with('setup', $this->setup->written()) : $shards;
        $costs = Json::object();
        $costs = $this->secondsPerLine instanceof Table
            ? $costs->with('secondsPerLine', $this->secondsPerLine->written())
            : $costs;
        $costs = $this->perRunnerMinute instanceof Price
            ? $costs->with('perRunnerMinute', $this->perRunnerMinute->written())
            : $costs;
        $written = $shards->isEmpty() ? Json::object() : Json::object()->with('shards', $shards);

        return $costs->isEmpty() ? $written : $written->with('costs', $costs);
    }

    public function php(Origin $origin): PhpCalls
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

        return PhpCalls::inWith(...$settings, ...$this->costs());
    }

    /** @return list<string> */
    private function costs(): array
    {
        $calls = [];

        foreach ($this->secondsPerLine instanceof Table ? $this->secondsPerLine : [] as $prefix => $seconds) {
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

    private static function secondsOf(int|Absent $seconds): Seconds|Absent
    {
        return $seconds instanceof Absent ? $seconds : Seconds::of($seconds);
    }
}

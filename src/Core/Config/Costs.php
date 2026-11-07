<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Cost\FirstRunShape;
use NightWorksIO\MutationGate\Core\Cost\LineRate;
use NightWorksIO\MutationGate\Core\Cost\SavingsWindow;
use NightWorksIO\MutationGate\Core\Cost\SecondsPerLine;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * What the gate's work costs and how it says so (ADR-0006, ADR-0016): `costs`,
 * which the `Shards` part holds: the cost model that plans the work, what a line
 * costs before anything was measured, the price of a runner's minute, how far
 * back what the gate saved is counted, and what a first estimate takes a shard
 * to be made of (ADR-0114).
 */
final readonly class Costs
{
    private const string MODEL = 'model';

    private function __construct(
        private Table|Absent $secondsPerLine,
        private Price|Absent $perRunnerMinute,
        private Seconds|Absent $savingsWindow,
        private Choice|Absent $model,
        private int|Absent $testsBeforeAKill,
        private int|float|Absent $surviving,
        private int|float|Absent $shardOverhead,
    ) {
    }

    public static function of(
        Table|Absent $secondsPerLine = new Absent(),
        Price|Absent $perRunnerMinute = new Absent(),
        Seconds|Absent $savingsWindow = new Absent(),
        Choice|Absent $model = new Absent(),
        int|Absent $testsBeforeAKill = new Absent(),
        int|float|Absent $surviving = new Absent(),
        int|float|Absent $shardOverhead = new Absent(),
    ): self {
        return new self(
            $secondsPerLine,
            $perRunnerMinute,
            $savingsWindow,
            $model,
            $testsBeforeAKill,
            $surviving,
            $shardOverhead,
        );
    }

    /** Every key at the value it takes where every layer leaves it out. */
    public static function standard(): self
    {
        return self::of(
            self::table(SecondsPerLine::standard()),
            savingsWindow: SavingsWindow::standard()->length(),
            testsBeforeAKill: FirstRunShape::TESTS_BEFORE_A_KILL,
            surviving: FirstRunShape::SURVIVING,
            shardOverhead: FirstRunShape::SHARD_OVERHEAD,
        );
    }

    /** This, with what a later layer sets laid over it: its rates beside these, its other keys in place of these. */
    public function over(self $later): self
    {
        return new self(
            match (true) {
                $later->secondsPerLine instanceof Absent => $this->secondsPerLine,
                $this->secondsPerLine instanceof Absent => $later->secondsPerLine,
                default => $this->secondsPerLine->merged($later->secondsPerLine),
            },
            Absent::laid($this->perRunnerMinute, $later->perRunnerMinute),
            Absent::laid($this->savingsWindow, $later->savingsWindow),
            Absent::laid($this->model, $later->model),
            Absent::laid($this->testsBeforeAKill, $later->testsBeforeAKill),
            Absent::laid($this->surviving, $later->surviving),
            Absent::laid($this->shardOverhead, $later->shardOverhead),
        );
    }

    /** What a line costs before anything was measured, by path prefix. */
    public function secondsPerLine(): SecondsPerLine
    {
        if (! $this->secondsPerLine instanceof Table) {
            return SecondsPerLine::standard();
        }

        $rates = [];

        foreach ($this->secondsPerLine as $prefix => $seconds) {
            $rates[] = LineRate::of($prefix, Seconds::of($seconds));
        }

        return SecondsPerLine::of(...$rates);
    }

    /** `costs.perRunnerMinute`: what a runner-minute costs, where the team gives a rate (ADR-0016). */
    public function perRunnerMinute(): Price|Absent
    {
        return $this->perRunnerMinute;
    }

    /** `costs.savingsWindow`: how far back the savings the summary and the badge show are counted (ADR-0016). */
    public function savingsWindow(): SavingsWindow
    {
        return $this->savingsWindow instanceof Seconds
            ? SavingsWindow::of($this->savingsWindow)
            : SavingsWindow::standard();
    }

    /**
     * `costs.testsBeforeAKill`, `costs.surviving` and `costs.shardOverhead`:
     * what a first estimate takes a shard to be made of.
     */
    public function firstRunShape(): FirstRunShape
    {
        return FirstRunShape::of($this->testsBeforeAKill, $this->surviving, $this->shardOverhead);
    }

    /**
     * `costs.model`, `learned` where no layer chooses one, with what a line costs, by prefix, laid beneath its
     * options, which the built-in model reads.
     */
    public function model(): Choice
    {
        $perLine = Json::object();

        foreach ($this->secondsPerLine() as $prefix => $seconds) {
            $perLine = $perLine->with(Member::of($prefix, $seconds));
        }

        $chosen = $this->model instanceof Choice
            ? $this->model
            : Choice::of(BuiltinCostModel::Learned->value, Options::none());

        return $chosen->over(Json::object(Member::of(SecondsPerLine::KEY, $perLine)));
    }

    /** The `costs` member a config at this origin writes; nothing where no key is set. */
    public function written(PathOrigin $origin): Member
    {
        return Member::unlessEmpty(
            'costs',
            Json::object(
                Member::of(
                    SecondsPerLine::KEY,
                    $this->secondsPerLine instanceof Table
                        ? $this->perLineFrom($this->secondsPerLine, $origin)->written()
                        : $this->secondsPerLine,
                ),
                Member::of(
                    'perRunnerMinute',
                    $this->perRunnerMinute instanceof Price
                        ? $this->perRunnerMinute->written()
                        : $this->perRunnerMinute,
                ),
                Member::of(self::MODEL, $this->model instanceof Choice ? $this->model->written() : $this->model),
                Member::of(
                    SavingsWindow::KEY,
                    $this->savingsWindow instanceof Seconds ? $this->savingsWindow->written() : $this->savingsWindow,
                ),
                Member::of('testsBeforeAKill', $this->testsBeforeAKill),
                Member::of('surviving', $this->surviving),
                Member::of('shardOverhead', $this->shardOverhead),
            ),
        );
    }

    /** The PHP builder's calls for the keys set. */
    public function php(PathOrigin $origin): PhpCalls
    {
        $calls = [];
        $rates = $this->secondsPerLine instanceof Table ? $this->perLineFrom($this->secondsPerLine, $origin) : [];

        foreach ($rates as $prefix => $seconds) {
            $calls[] = sprintf(
                'Shards::secondsPerLine(%s, %s)',
                PhpCalls::literal($prefix),
                PhpCalls::literal($seconds),
            );
        }

        return PhpCalls::inWith(
            ...$calls,
            ...$this->perRunnerMinute instanceof Price ? [sprintf(
                'Shards::perRunnerMinute(%s, %s)',
                PhpCalls::literal($this->perRunnerMinute->amount()),
                PhpCalls::literal($this->perRunnerMinute->currency()),
            )] : [],
            ...$this->savingsWindow instanceof Seconds
                ? [sprintf('Shards::savingsWindow(%s)', PhpCalls::literal($this->savingsWindow->written()))]
                : [],
            ...$this->model instanceof Choice
                ? [PhpCalls::chosen($this->model, AdapterBuilder::CostModel, BuiltinCostModel::Learned->value)]
                : [],
            ...$this->firstRunCalls(),
        );
    }

    /** @return list<string> the builder's calls for the keys of a first estimate a layer sets */
    private function firstRunCalls(): array
    {
        $calls = [];

        foreach (
            [
                'testsBeforeAKill' => $this->testsBeforeAKill,
                'surviving' => $this->surviving,
                'shardOverhead' => $this->shardOverhead,
            ] as $method => $value
        ) {
            $calls = $value instanceof Absent
                ? $calls
                : [...$calls, sprintf('Shards::%s(%s)', $method, PhpCalls::literal($value))];
        }

        return $calls;
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
    private function perLineFrom(Table $rates, PathOrigin $origin): Table
    {
        $from = Table::none();

        foreach ($rates as $prefix => $seconds) {
            $key = $prefix;
            $from = $from->merged(
                Table::row($key === LineRate::EVERYWHERE ? $key : $origin->written(Path::of($key)), $seconds),
            );
        }

        return $from;
    }
}

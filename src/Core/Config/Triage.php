<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function intval;

use NightWorksIO\MutationGate\Core\Config\Definition\Duration;
use NightWorksIO\MutationGate\Core\Config\Definition\Enumerated;
use NightWorksIO\MutationGate\Core\Config\Definition\Field;
use NightWorksIO\MutationGate\Core\Config\Definition\Flag;
use NightWorksIO\MutationGate\Core\Config\Definition\Integer;
use NightWorksIO\MutationGate\Core\Config\Definition\Into;
use NightWorksIO\MutationGate\Core\Config\Definition\Reading;
use NightWorksIO\MutationGate\Core\Config\Definition\Section;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

use function sprintf;

/**
 * How a run spends its time and what it does with a mutant it cannot settle
 * at once (ADR-0008, ADR-0013): `budget`, `timeouts`, `flaky` and `tests`.
 */
final readonly class Triage implements Part
{
    /** The seconds a mutant may run, and how many timed-out mutants are run again. */
    private const int TIMEOUT = 10;

    private const int RETRIES = 20;

    private function __construct(
        private Seconds|Absent $budget,
        private TimeoutMode|Absent $mode,
        private Seconds|Absent $limit,
        private int|Absent $retries,
        private bool|Absent $confirmSurvivors,
        private TestOrder|Absent $order,
    ) {
    }

    public static function of(
        Seconds|Absent $budget = new Absent(),
        TimeoutMode|Absent $mode = new Absent(),
        Seconds|Absent $limit = new Absent(),
        int|Absent $retries = new Absent(),
        bool|Absent $confirmSurvivors = new Absent(),
        TestOrder|Absent $order = new Absent(),
    ): self {
        return new self($budget, $mode, $limit, $retries, $confirmSurvivors, $order);
    }

    public static function none(): self
    {
        return self::of();
    }

    public static function standard(): self
    {
        $none = self::none();

        return self::of(
            mode: $none->timeouts(),
            limit: $none->limit(),
            retries: $none->retries(),
            confirmSurvivors: $none->confirmSurvivors(),
            order: $none->order(),
        );
    }

    /** @return list<Field<Layer>> */
    public static function fields(): array
    {
        $judges = Effect::JudgesOrReportsOnly;
        $results = Effect::AffectsResults;
        $mode = Field::optional('mode', Enumerated::of(TimeoutMode::cases()), $judges);
        $seconds = Field::optional('seconds', Integer::atLeast(1), $results);
        $retries = Field::optional('retries', Integer::atLeast(0), $results);

        return [
            Field::optional(
                'budget',
                Into::of(
                    Duration::written(),
                    static fn(Seconds $budget): Layer => Layer::of(self::of(budget: $budget)),
                ),
                $judges,
            ),
            Field::section(
                'timeouts',
                Section::of(
                    static function (Node $timeouts) use ($mode, $seconds, $retries): Layer|Invalid {
                        $timedOut = $mode->read($timeouts);
                        $limit = $seconds->read($timeouts);
                        $again = $retries->read($timeouts);
                        $cap = $limit->value();

                        return Reading::built(
                            static fn(): Layer => Layer::of(self::of(
                                mode: $timedOut->value(),
                                limit: $cap instanceof Absent ? $cap : Seconds::of($cap),
                                retries: $again->value(),
                            )),
                            $timedOut,
                            $limit,
                            $again,
                        );
                    },
                    $mode,
                    $seconds,
                    $retries,
                ),
            ),
            Field::section(
                'flaky',
                Section::single(
                    Field::optional('confirmSurvivors', Flag::boolean(), $results),
                    static fn(bool|Absent $confirm): Layer => Layer::of(self::of(confirmSurvivors: $confirm)),
                ),
            ),
            Field::section(
                'tests',
                Section::single(
                    Field::optional('order', Enumerated::of(TestOrder::cases()), $results),
                    static fn(TestOrder|Absent $order): Layer => Layer::of(self::of(order: $order)),
                ),
            ),
        ];
    }

    public function over(Part $later): self
    {
        return $later instanceof self
            ? new self(
                Absent::laid($this->budget, $later->budget),
                Absent::laid($this->mode, $later->mode),
                Absent::laid($this->limit, $later->limit),
                Absent::laid($this->retries, $later->retries),
                Absent::laid($this->confirmSurvivors, $later->confirmSurvivors),
                Absent::laid($this->order, $later->order),
            )
            : $this;
    }

    /** How long a run may take, riskiest code first (ADR-0008). */
    public function budget(): Seconds|Unlimited
    {
        return $this->budget instanceof Seconds ? $this->budget : Unlimited::time();
    }

    /** `timeouts.mode` */
    public function timeouts(): TimeoutMode
    {
        return $this->mode instanceof TimeoutMode ? $this->mode : TimeoutMode::Confirm;
    }

    /** `timeouts.seconds`: the cap on one mutant's run, and the covering tests' time past which it is skipped. */
    public function limit(): Seconds
    {
        return $this->limit instanceof Seconds ? $this->limit : Seconds::of(self::TIMEOUT);
    }

    /** `timeouts.retries`: the most timed-out mutants retried per shard. */
    public function retries(): int
    {
        return $this->retries instanceof Absent ? self::RETRIES : $this->retries;
    }

    /** `flaky.confirmSurvivors`: whether each survivor is run once more before it counts. */
    public function confirmSurvivors(): bool
    {
        return $this->confirmSurvivors instanceof Absent || $this->confirmSurvivors;
    }

    /** `tests.order`: the order each mutant's covering tests run in (ADR-0013). */
    public function order(): TestOrder
    {
        return $this->order instanceof TestOrder ? $this->order : TestOrder::KillersFirst;
    }

    public function written(Origin $origin): Json
    {
        $written = $this->budget instanceof Seconds
            ? Json::object()->with('budget', $this->budget->written())
            : Json::object();
        $timeouts = $this->mode instanceof TimeoutMode ? Json::object()->with('mode', $this->mode->value) : Json::object();
        $timeouts = $this->limit instanceof Seconds
            ? $timeouts->with('seconds', intval($this->limit->seconds()))
            : $timeouts;
        $timeouts = $this->retries instanceof Absent ? $timeouts : $timeouts->with('retries', $this->retries);
        $written = $timeouts->isEmpty() ? $written : $written->with('timeouts', $timeouts);
        $written = $this->confirmSurvivors instanceof Absent
            ? $written
            : $written->with('flaky', Json::object()->with('confirmSurvivors', $this->confirmSurvivors));

        return $this->order instanceof TestOrder
            ? $written->with('tests', Json::object()->with('order', $this->order->value))
            : $written;
    }

    public function php(Origin $origin): PhpCalls
    {
        return PhpCalls::inWith(...[
            ...$this->budget instanceof Seconds
                ? [sprintf('Budget::of(%s)', PhpCalls::literal($this->budget->written()))]
                : [],
            ...$this->mode instanceof TimeoutMode ? [match ($this->mode) {
                TimeoutMode::Confirm => 'Timeouts::confirmed()',
                TimeoutMode::Unjudged => 'Timeouts::unjudged()',
            }] : [],
            ...$this->limit instanceof Seconds
                ? [sprintf('Timeouts::seconds(%d)', intval($this->limit->seconds()))]
                : [],
            ...$this->retries instanceof Absent ? [] : [sprintf('Timeouts::retries(%d)', $this->retries)],
            ...$this->confirmSurvivors instanceof Absent ? [] : [
                $this->confirmSurvivors ? 'Flaky::confirmingSurvivors()' : 'Flaky::notConfirmingSurvivors()',
            ],
            ...$this->order instanceof TestOrder ? [match ($this->order) {
                TestOrder::KillersFirst => 'Tests::killersFirst()',
                TestOrder::Runner => 'Tests::inRunnerOrder()',
            }] : [],
        ]);
    }
}

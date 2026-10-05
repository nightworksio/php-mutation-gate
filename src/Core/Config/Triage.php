<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function intval;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Time\Budgets;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

use function sprintf;

/**
 * How a run spends its time and what it does with a mutant it cannot settle
 * at once (ADR-0008, ADR-0013, ADR-0020): `budget`, `timeouts`, `flaky`,
 * `tests` and `survivorsFirst`.
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
        private SurvivorsFirst|Absent $survivorsFirst,
    ) {
    }

    public static function of(
        Seconds|Absent $budget = new Absent(),
        TimeoutMode|Absent $mode = new Absent(),
        Seconds|Absent $limit = new Absent(),
        int|Absent $retries = new Absent(),
        bool|Absent $confirmSurvivors = new Absent(),
        TestOrder|Absent $order = new Absent(),
        SurvivorsFirst|Absent $survivorsFirst = new Absent(),
    ): self {
        return new self($budget, $mode, $limit, $retries, $confirmSurvivors, $order, $survivorsFirst);
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
            survivorsFirst: $none->survivorsFirst(),
        );
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
                Absent::laid($this->survivorsFirst, $later->survivorsFirst),
            )
            : $this;
    }

    /** How long a run may take, riskiest code first (ADR-0008). */
    public function budget(): Seconds|Unlimited
    {
        return $this->budget instanceof Seconds ? $this->budget : Budgets::standard()->run();
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

    /** `survivorsFirst.max`: how many of the last run's survivors a pull request's run re-checks first. */
    public function survivorsFirst(): SurvivorsFirst
    {
        return $this->survivorsFirst instanceof SurvivorsFirst ? $this->survivorsFirst : SurvivorsFirst::standard();
    }

    public function written(PathOrigin $origin): Json
    {
        return Json::object(
            Member::of('budget', $this->budget instanceof Seconds ? $this->budget->written() : $this->budget),
            Member::unlessEmpty(
                'timeouts',
                Json::object(
                    Member::of('mode', $this->mode instanceof TimeoutMode ? $this->mode->value : $this->mode),
                    Member::of(
                        'seconds',
                        $this->limit instanceof Seconds ? intval($this->limit->seconds()) : $this->limit,
                    ),
                    Member::of('retries', $this->retries),
                ),
            ),
            Member::unlessEmpty('flaky', Json::object(Member::of('confirmSurvivors', $this->confirmSurvivors))),
            Member::unlessEmpty(
                'tests',
                Json::object(
                    Member::of('order', $this->order instanceof TestOrder ? $this->order->value : $this->order),
                ),
            ),
            Member::unlessEmpty(
                'survivorsFirst',
                Json::object(Member::of(
                    'max',
                    $this->survivorsFirst instanceof SurvivorsFirst
                        ? $this->survivorsFirst->most()
                        : $this->survivorsFirst,
                )),
            ),
        );
    }

    public function php(PathOrigin $origin): PhpCalls
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
            ...$this->orderCalls(),
        ]);
    }

    /** @return list<string> the builder's calls for the order of each mutant's tests, and of the survivors' re-check */
    private function orderCalls(): array
    {
        return [
            ...$this->order instanceof TestOrder ? [match ($this->order) {
                TestOrder::KillersFirst => 'Tests::killersFirst()',
                TestOrder::Runner => 'Tests::inRunnerOrder()',
            }] : [],
            ...$this->survivorsFirst instanceof SurvivorsFirst
                ? [sprintf('Survivors::firstAtMost(%d)', $this->survivorsFirst->most())]
                : [],
        ];
    }
}

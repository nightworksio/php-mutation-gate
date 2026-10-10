<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_map;
use function intval;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\TighterSilence;
use NightWorksIO\MutationGate\Core\Test\SuiteName;
use NightWorksIO\MutationGate\Core\Test\Suites;
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
    /** The least and the most seconds a mutant may run. */
    private const int TIMEOUT = 10;

    private const int MOST = 300;

    private const string UNDER_THE_FLOOR = 'expected at least timeouts.seconds, %d, got %d';

    /** @param Listed<string>|Absent $suites */
    private function __construct(
        private Seconds|Absent $budget,
        private TimeoutMode|Absent $mode,
        private Seconds|Absent $limit,
        private Seconds|Absent $most,
        private TighterSilence|Absent $tighter,
        private bool|Absent $confirmSurvivors,
        private TestOrder|Absent $order,
        private SurvivorsFirst|Absent $survivorsFirst,
        private Listed|Absent $suites,
    ) {
    }

    /** @param Listed<string>|Absent $suites */
    public static function of(
        Seconds|Absent $budget = new Absent(),
        TimeoutMode|Absent $mode = new Absent(),
        Seconds|Absent $limit = new Absent(),
        Seconds|Absent $most = new Absent(),
        bool|Absent $confirmSurvivors = new Absent(),
        TestOrder|Absent $order = new Absent(),
        SurvivorsFirst|Absent $survivorsFirst = new Absent(),
        TighterSilence|Absent $tighter = new Absent(),
        Listed|Absent $suites = new Absent(),
    ): self {
        return new self($budget, $mode, $limit, $most, $tighter, $confirmSurvivors, $order, $survivorsFirst, $suites);
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
            most: $none->most(),
            confirmSurvivors: $none->confirmSurvivors(),
            order: $none->order(),
            survivorsFirst: $none->survivorsFirst(),
            tighter: $none->tighter(),
        );
    }

    public function over(Part $later): self
    {
        return $later instanceof self
            ? new self(
                Absent::laid($this->budget, $later->budget),
                Absent::laid($this->mode, $later->mode),
                Absent::laid($this->limit, $later->limit),
                Absent::laid($this->most, $later->most),
                Absent::laid($this->tighter, $later->tighter),
                Absent::laid($this->confirmSurvivors, $later->confirmSurvivors),
                Absent::laid($this->order, $later->order),
                Absent::laid($this->survivorsFirst, $later->survivorsFirst),
                Absent::laid($this->suites, $later->suites),
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

    /** `timeouts.seconds`: the least one mutant's run is allowed, and what one whose tests are not timed is. */
    public function limit(): Seconds
    {
        return $this->limit instanceof Seconds ? $this->limit : Seconds::of(self::TIMEOUT);
    }

    /** `timeouts.most`: the most one mutant's run is allowed, and Infection's skip for tests that take as long. */
    public function most(): Seconds
    {
        return $this->most instanceof Seconds ? $this->most : Seconds::of(self::MOST);
    }

    /**
     * `timeouts.tighter`: the mutators, by their short names, whose silence
     * limit has a lower floor, and that floor.
     */
    public function tighter(): TighterSilence
    {
        return $this->tighter instanceof TighterSilence ? $this->tighter : TighterSilence::standard();
    }

    /**
     * What each mutant's limit is kept between, `timeouts.seconds` and
     * `timeouts.most`, with the mutators whose silence limit has a lower
     * floor, `timeouts.tighter`.
     */
    public function bounds(): LimitBounds
    {
        return LimitBounds::between($this->limit(), $this->most())->tighterFor($this->tighter());
    }

    /** Why the timeouts cannot be laid, a most under the floor; or nothing. */
    public function refusal(): Invalid|Absent
    {
        $floor = intval($this->limit()->seconds());
        $most = intval($this->most()->seconds());

        return $most < $floor
            ? Invalid::because(Problem::at('timeouts.most', sprintf(self::UNDER_THE_FLOOR, $floor, $most)))
            : Absent::setting();
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

    /** `tests.suites`: the suites whose tests judge, every suite where no layer lists any (ADR-0002). */
    public function suites(): Suites
    {
        $names = $this->suites instanceof Listed ? [...$this->suites] : [];

        return $names === [] ? Suites::all() : Suites::named(...array_map(SuiteName::of(...), $names));
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
                    Member::of('most', $this->most instanceof Seconds ? intval($this->most->seconds()) : $this->most),
                    Member::of('tighter', $this->tighterWritten()),
                ),
            ),
            Member::unlessEmpty('flaky', Json::object(Member::of('confirmSurvivors', $this->confirmSurvivors))),
            Member::unlessEmpty(
                'tests',
                Json::object(
                    Member::of('order', $this->order instanceof TestOrder ? $this->order->value : $this->order),
                    Member::of(
                        'suites',
                        $this->suites instanceof Listed ? Json::items(...$this->suites) : $this->suites,
                    ),
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
            ...$this->most instanceof Seconds ? [sprintf('Timeouts::most(%d)', intval($this->most->seconds()))] : [],
            ...$this->tighter instanceof TighterSilence ? [sprintf(
                'Timeouts::tighter(%s)',
                PhpCalls::literals(intval($this->tighter->floor()->seconds()), ...$this->tighter),
            )] : [],
            ...$this->confirmSurvivors instanceof Absent ? [] : [
                $this->confirmSurvivors ? 'Flaky::confirmingSurvivors()' : 'Flaky::notConfirmingSurvivors()',
            ],
            ...$this->orderCalls(),
        ]);
    }

    /** `timeouts.tighter` as a config writes it; nothing where no layer set it. */
    private function tighterWritten(): Json|Absent
    {
        return $this->tighter instanceof TighterSilence
            ? Json::object(
                Member::of('mutators', Json::items(...$this->tighter)),
                Member::of('floor', intval($this->tighter->floor()->seconds())),
            )
            : $this->tighter;
    }

    /** @return list<string> the builder's calls for the order of each mutant's tests, and of the survivors' re-check */
    private function orderCalls(): array
    {
        return [
            ...$this->order instanceof TestOrder ? [match ($this->order) {
                TestOrder::KillersFirst => 'Tests::killersFirst()',
                TestOrder::Runner => 'Tests::inRunnerOrder()',
            }] : [],
            ...$this->suites instanceof Listed
                ? [sprintf('Tests::suites(%s)', PhpCalls::literals(...$this->suites))]
                : [],
            ...$this->survivorsFirst instanceof SurvivorsFirst
                ? [sprintf('Survivors::firstAtMost(%d)', $this->survivorsFirst->most())]
                : [],
        ];
    }
}

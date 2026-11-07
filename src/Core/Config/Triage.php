<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function intval;
use function max;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\Multiple;
use NightWorksIO\MutationGate\Core\Runner\MutantLimit;
use NightWorksIO\MutationGate\Core\Runner\PerTestLimit;
use NightWorksIO\MutationGate\Core\Runner\TighterSilence;
use NightWorksIO\MutationGate\Core\Test\JudgingSuites;
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

    /** The most a kill's unmutated control may run, in seconds, where no layer sets it. */
    private const int CONTROL_MOST = 900;

    private const string UNDER_THE_FLOOR = 'expected at least timeouts.seconds, %d, got %d';

    private const string UNDER_THE_MOST = 'expected at least timeouts.most, %d, got %d';

    private function __construct(
        private Seconds|Absent $budget,
        private TimeoutMode|Absent $mode,
        private Seconds|Learned|Absent $limit,
        private Seconds|Absent $most,
        private int|float|Learned|Absent $multiple,
        private TighterSilence|Absent $tighter,
        private bool|Absent $confirmSurvivors,
        private TestOrder|Absent $order,
        private SurvivorsFirst $survivorsFirst,
        private SuiteLists $lists,
        private PerTestSetting $perTest,
        private Seconds|Absent $controlMost,
        private Seconds|Absent $startUp,
        private LearnedBounds $learned,
        private HeavySetting $heavy,
    ) {
    }

    public static function of(
        Seconds|Absent $budget = new Absent(),
        TimeoutMode|Absent $mode = new Absent(),
        Seconds|Learned|Absent $limit = new Absent(),
        Seconds|Absent $most = new Absent(),
        bool|Absent $confirmSurvivors = new Absent(),
        TestOrder|Absent $order = new Absent(),
        SurvivorsFirst|Absent $survivorsFirst = new Absent(),
        TighterSilence|Absent $tighter = new Absent(),
        SuiteLists|Absent $lists = new Absent(),
        int|float|Learned|Absent $multiple = new Absent(),
        int|false|Absent $perTest = new Absent(),
        Seconds|Absent $testFloor = new Absent(),
        Seconds|Absent $controlMost = new Absent(),
        Seconds|Absent $startUp = new Absent(),
        LearnedBounds|Absent $learned = new Absent(),
        bool|Absent $heavyFill = new Absent(),
        int|Absent $heavyParts = new Absent(),
    ): self {
        return new self(
            $budget,
            $mode,
            $limit,
            $most,
            $multiple,
            $tighter,
            $confirmSurvivors,
            $order,
            $survivorsFirst instanceof SurvivorsFirst ? $survivorsFirst : SurvivorsFirst::standard(),
            $lists instanceof SuiteLists ? $lists : SuiteLists::of(),
            PerTestSetting::of($perTest, $testFloor),
            $controlMost,
            $startUp,
            LearnedBounds::orNone($learned),
            HeavySetting::of($heavyFill, $heavyParts),
        );
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
            survivorsFirst: $none->survivorsFirst()->resolved(),
            tighter: TighterSilence::standard(),
            multiple: MutantLimit::FACTOR,
            perTest: PerTestLimit::MULTIPLE,
            testFloor: Seconds::of(PerTestLimit::FLOOR),
            startUp: Seconds::of(MutantLimit::START_UP),
            learned: LearnedBounds::standard(),
            heavyFill: $none->heavy()->fills(),
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
                Absent::laid($this->multiple, $later->multiple),
                Absent::laid($this->tighter, $later->tighter),
                Absent::laid($this->confirmSurvivors, $later->confirmSurvivors),
                Absent::laid($this->order, $later->order),
                $this->survivorsFirst->over($later->survivorsFirst),
                $this->lists->over($later->lists),
                $this->perTest->over($later->perTest),
                Absent::laid($this->controlMost, $later->controlMost),
                Absent::laid($this->startUp, $later->startUp),
                $this->learned->over($later->learned),
                $this->heavy->over($later->heavy),
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

    /**
     * `timeouts.seconds`: the least one mutant's run is allowed, and what one
     * whose tests are not timed is; where it is learned, what it is before a
     * start-up is measured.
     */
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
     * `timeouts.controlMost`: the most a kill's unmutated control is allowed
     * (ADR-0014), which runs the tests its mutant's run selected, and the
     * selection a run that left its filter out loaded can take far longer
     * than the tests that failed in it; where no layer sets it, 900 s, or
     * `timeouts.most` where that is longer.
     */
    public function controlMost(): Seconds
    {
        return $this->controlMost instanceof Seconds
            ? $this->controlMost
            : Seconds::of(max(self::CONTROL_MOST, $this->most()->seconds()));
    }

    /** `timeouts.learned`: how a learned timeout multiple is read from a ledger's kills. */
    public function learned(): LearnedBounds
    {
        return $this->learned;
    }

    /**
     * What each mutant's limit is kept between, `timeouts.seconds` and
     * `timeouts.most`, with the mutators whose silence limit has a lower
     * floor, `timeouts.tighter`, and how long each test of a run may run on
     * its own, `timeouts.perTest` and `timeouts.testFloor` (ADR-0090).
     */
    public function bounds(): LimitBounds
    {
        $startUp = $this->startUp instanceof Seconds ? $this->startUp->seconds() : MutantLimit::START_UP;
        $bounds = LimitBounds::between($this->limit(), $this->most(), $startUp)
            ->tighterFor($this->tighter instanceof TighterSilence ? $this->tighter : TighterSilence::standard())
            ->multipliedBy(match (true) {
                $this->multiple instanceof Absent => Multiple::standard(),
                $this->multiple instanceof Learned => Learned::Learned,
                default => Multiple::of($this->multiple),
            })
            ->watchingEachTest($this->perTest->limit());

        return $this->limit instanceof Learned ? $bounds->learningFloor() : $bounds;
    }

    /** `timeouts.heavyFill` and `timeouts.heavyParts`: how heavy mutants' parts run (ADR-0114). */
    public function heavy(): HeavySetting
    {
        return $this->heavy;
    }

    /** Why the timeouts cannot be laid, a most under the floor or a control's most under the most; or nothing. */
    public function refusal(): Invalid|Absent
    {
        $floor = intval($this->limit()->seconds());
        $most = intval($this->most()->seconds());
        $controls = intval($this->controlMost()->seconds());

        return match (true) {
            $most < $floor => Invalid::because(
                Problem::at('timeouts.most', sprintf(self::UNDER_THE_FLOOR, $floor, $most)),
            ),
            $controls < $most => Invalid::because(
                Problem::at('timeouts.controlMost', sprintf(self::UNDER_THE_MOST, $most, $controls)),
            ),
            default => $this->learned->refusal(),
        };
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

    /**
     * `tests.suites` and `tests.holding`: the suites whose tests judge every
     * unit, every suite where no layer lists any, and those whose tests judge
     * only the units they hold (ADR-0002).
     */
    public function suites(): JudgingSuites
    {
        return $this->lists->suites();
    }

    /** `survivorsFirst`: how a pull request's run re-checks the last run's survivors first. */
    public function survivorsFirst(): SurvivorsFirst
    {
        return $this->survivorsFirst;
    }

    public function written(PathOrigin $origin): Json
    {
        return Json::object(
            Member::of('budget', WholeSeconds::duration($this->budget)),
            Member::unlessEmpty(
                'timeouts',
                $this->heavy->written(Json::object(
                    Member::of('mode', $this->mode instanceof TimeoutMode ? $this->mode->value : $this->mode),
                    Member::of('seconds', LimitCalls::secondsWritten($this->limit)),
                    Member::of('most', WholeSeconds::written($this->most)),
                    Member::of('multiple', LimitCalls::multipleWritten($this->multiple)),
                    Member::of('startUp', WholeSeconds::duration($this->startUp)),
                    Member::of('tighter', LimitCalls::tighterWritten($this->tighter)),
                    Member::of('controlMost', WholeSeconds::written($this->controlMost)),
                    $this->learned->written(),
                    ...$this->perTest->written(),
                )),
            ),
            Member::unlessEmpty('flaky', Json::object(Member::of('confirmSurvivors', $this->confirmSurvivors))),
            Member::unlessEmpty(
                'tests',
                Json::object(
                    Member::of('order', $this->order instanceof TestOrder ? $this->order->value : $this->order),
                    $this->lists->judgingWritten(),
                    $this->lists->holdingWritten(),
                ),
            ),
            Member::unlessEmpty('survivorsFirst', $this->survivorsFirst->written()),
        );
    }

    public function php(PathOrigin $origin): PhpCalls
    {
        return PhpCalls::inWith(...[
            ...$this->budget instanceof Seconds
                ? [sprintf('Budget::of(%s)', PhpCalls::literal($this->budget->written()))]
                : [],
            ...$this->mode instanceof TimeoutMode ? [$this->mode->php()] : [],
            ...LimitCalls::seconds($this->limit),
            ...LimitCalls::multiple($this->multiple),
            ...$this->startUp instanceof Seconds
                ? [sprintf('Timeouts::startUp(%s)', PhpCalls::literal($this->startUp->written()))]
                : [],
            ...$this->most instanceof Seconds ? [sprintf('Timeouts::most(%d)', intval($this->most->seconds()))] : [],
            ...LimitCalls::tighter($this->tighter),
            ...$this->perTest->calls(),
            ...LimitCalls::controlMost($this->controlMost),
            ...$this->confirmSurvivors instanceof Absent ? [] : [
                $this->confirmSurvivors ? 'Flaky::confirmingSurvivors()' : 'Flaky::notConfirmingSurvivors()',
            ],
            ...$this->order instanceof TestOrder ? [$this->order->php()] : [],
        ])
            ->and($this->heavy->calls())
            ->and($this->survivorsFirst->calls())
            ->and($this->lists->calls())
            ->and($this->learned->php());
    }
}

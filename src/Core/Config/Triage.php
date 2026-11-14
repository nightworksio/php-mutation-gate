<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Runner\MutantLimit;
use NightWorksIO\MutationGate\Core\Runner\PerTestLimit;
use NightWorksIO\MutationGate\Core\Runner\TighterSilence;
use NightWorksIO\MutationGate\Core\Test\JudgingSuites;
use NightWorksIO\MutationGate\Core\Time\Budgets;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

/**
 * How a run spends its time and what it does with a mutant it cannot settle
 * at once (ADR-0008, ADR-0013, ADR-0020): `budget`, `timeouts`, `flaky`,
 * `tests` and `survivorsFirst`.
 */
final readonly class Triage implements Part
{
    private function __construct(
        private Seconds|Absent $budget,
        private TimeoutMode|Absent $mode,
        private TimeoutLimits $limits,
        private FlakySetting $flaky,
        private TestOrder|Absent $order,
        private SurvivorsFirst $survivorsFirst,
        private SuiteLists $lists,
        private LearnedBounds $learned,
        private HeavySetting $heavy,
        private int|float|Absent $headroom,
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
        int|float|Absent $headroom = new Absent(),
        int|float|Absent $startUpMultiple = new Absent(),
        KillControl|Absent $killControls = new Absent(),
    ): self {
        return new self(
            $budget,
            $mode,
            TimeoutLimits::of(
                $limit,
                $most,
                $multiple,
                $tighter,
                $perTest,
                $testFloor,
                $startUp,
                $startUpMultiple,
                $controlMost,
            ),
            FlakySetting::of($confirmSurvivors, $killControls),
            $order,
            $survivorsFirst instanceof SurvivorsFirst ? $survivorsFirst : SurvivorsFirst::standard(),
            $lists instanceof SuiteLists ? $lists : SuiteLists::of(),
            LearnedBounds::orNone($learned),
            HeavySetting::of($heavyFill, $heavyParts),
            $headroom,
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
            limit: $none->limits()->limit(),
            most: $none->limits()->most(),
            confirmSurvivors: $none->flaky()->confirmSurvivors(),
            order: $none->order(),
            survivorsFirst: $none->survivorsFirst()->resolved(),
            tighter: TighterSilence::standard(),
            multiple: MutantLimit::FACTOR,
            perTest: PerTestLimit::MULTIPLE,
            testFloor: Seconds::of(PerTestLimit::FLOOR),
            startUp: Seconds::of(MutantLimit::START_UP),
            learned: LearnedBounds::standard(),
            heavyFill: $none->heavy()->fills(),
            headroom: TimeoutHeadroom::STANDARD,
            startUpMultiple: MutantLimit::FACTOR,
            killControls: $none->flaky()->killControls(),
        );
    }

    public function over(Part $later): self
    {
        return $later instanceof self
            ? new self(
                Absent::laid($this->budget, $later->budget),
                Absent::laid($this->mode, $later->mode),
                $this->limits->over($later->limits),
                $this->flaky->over($later->flaky),
                Absent::laid($this->order, $later->order),
                $this->survivorsFirst->over($later->survivorsFirst),
                $this->lists->over($later->lists),
                $this->learned->over($later->learned),
                $this->heavy->over($later->heavy),
                Absent::laid($this->headroom, $later->headroom),
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

    /** `timeouts.headroom` */
    public function headroom(): TimeoutHeadroom
    {
        return $this->headroom instanceof Absent ? TimeoutHeadroom::standard() : TimeoutHeadroom::of($this->headroom);
    }

    /** What each mutant's limit is kept between, and what a run and each of its tests are allowed (ADR-0008). */
    public function limits(): TimeoutLimits
    {
        return $this->limits;
    }

    /** `timeouts.learned`: how a learned timeout multiple is read from a ledger's kills. */
    public function learned(): LearnedBounds
    {
        return $this->learned;
    }

    /** `timeouts.heavyFill` and `timeouts.heavyParts`: how heavy mutants' parts run (ADR-0114). */
    public function heavy(): HeavySetting
    {
        return $this->heavy;
    }

    /** Why the timeouts cannot be laid, as their limits or the learned multiple's bounds say; or nothing. */
    public function refusal(): Invalid|Absent
    {
        $refused = $this->limits->refusal();

        return $refused instanceof Invalid ? $refused : $this->learned->refusal();
    }

    /** `flaky`: whether each survivor is run once more before it counts, and what a kill's control runs. */
    public function flaky(): FlakySetting
    {
        return $this->flaky;
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
                    Member::of('headroom', $this->headroom),
                )->merged($this->limits->written())->with($this->learned->written())),
            ),
            Member::unlessEmpty('flaky', $this->flaky->written()),
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
        return PhpCalls::duration('Budget::of', $this->budget)
            ->and(PhpCalls::inWith(...[
                ...$this->mode instanceof TimeoutMode ? [$this->mode->php()] : [],
                ...LimitCalls::headroom($this->headroom),
            ]))
            ->and($this->limits->php())
            ->and(PhpCalls::inWith(...$this->order instanceof TestOrder ? [$this->order->php()] : []))
            ->and($this->flaky->calls())
            ->and($this->heavy->calls())
            ->and($this->survivorsFirst->calls())
            ->and($this->lists->calls())
            ->and($this->learned->php());
    }
}

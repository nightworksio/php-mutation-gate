<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function max;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Runner\Headroom;
use NightWorksIO\MutationGate\Core\Serve\ServeStart;
use NightWorksIO\MutationGate\Core\Time\Budgets;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Watch\Poll;

use function sprintf;

/**
 * How long the gate runs while a person works and before a push (ADR-0010),
 * and how its daemon serves those runs and is started (see LocalServe,
 * ADR-0078): `local`.
 */
final readonly class Local implements Part
{
    /** The fewest runs that can disagree. */
    private const int TRIAGE_FEWEST = 2;

    private function __construct(
        private Seconds|Absent $watchBudget,
        private Seconds|Absent $prePushBudget,
        private LocalServe $serve,
        private bool|Absent $inferSubsumed,
        private Seconds|Absent $watchPoll,
        private int|Absent $triageFewest,
        private int|Absent $memoryHeadroom,
        private LocalWork $work,
        private bool|Absent $quick,
        private bool|Absent $cancelByUnit,
        private LocalRounds $rounds,
    ) {
    }

    public static function of(
        Seconds|Absent $watchBudget = new Absent(),
        Seconds|Absent $prePushBudget = new Absent(),
        Seconds|Absent $serveIdle = new Absent(),
        ServeStart|Absent $serveStart = new Absent(),
        Seconds|Absent $serveStartWait = new Absent(),
        bool|Absent $inferSubsumed = new Absent(),
        Seconds|Absent $watchPoll = new Absent(),
        int|Absent $triageFewest = new Absent(),
        int|Absent $memoryHeadroom = new Absent(),
        Seconds|Absent $serveRequest = new Absent(),
        LocalWork|Absent $work = new Absent(),
        bool|Absent $quick = new Absent(),
        bool|Absent $cancelByUnit = new Absent(),
        LocalRounds|Absent $rounds = new Absent(),
    ): self {
        return new self(
            $watchBudget,
            $prePushBudget,
            LocalServe::of($serveIdle, $serveStart, $serveStartWait, $serveRequest),
            $inferSubsumed,
            $watchPoll,
            $triageFewest,
            $memoryHeadroom,
            $work instanceof LocalWork ? $work : LocalWork::of(),
            $quick,
            $cancelByUnit,
            $rounds instanceof LocalRounds ? $rounds : LocalRounds::of(),
        );
    }

    public static function none(): self
    {
        return self::of();
    }

    public static function standard(): self
    {
        $none = self::none();
        $serve = $none->serve();

        return self::of(
            $none->watchBudget(),
            $none->prePushBudget(),
            $serve->idle(),
            $serve->start(),
            $serve->startWait(),
            $none->inferSubsumed(),
            $none->watchPoll(),
            $none->triageFewest(),
            $none->memoryHeadroom(),
            $serve->request(),
            LocalWork::standard(),
            $none->quick(),
            $none->cancelsByUnit(),
        );
    }

    public function over(Part $later): self
    {
        return $later instanceof self
            ? new self(
                Absent::laid($this->watchBudget, $later->watchBudget),
                Absent::laid($this->prePushBudget, $later->prePushBudget),
                $this->serve->over($later->serve),
                Absent::laid($this->inferSubsumed, $later->inferSubsumed),
                Absent::laid($this->watchPoll, $later->watchPoll),
                Absent::laid($this->triageFewest, $later->triageFewest),
                Absent::laid($this->memoryHeadroom, $later->memoryHeadroom),
                $this->work->over($later->work),
                Absent::laid($this->quick, $later->quick),
                Absent::laid($this->cancelByUnit, $later->cancelByUnit),
                $this->rounds->over($later->rounds),
            )
            : $this;
    }

    public function watchBudget(): Seconds
    {
        return $this->watchBudget instanceof Seconds ? $this->watchBudget : Budgets::standard()->watch();
    }

    public function prePushBudget(): Seconds
    {
        return $this->prePushBudget instanceof Seconds ? $this->prePushBudget : Budgets::standard()->prePush();
    }

    /** `local.memoryHeadroom`: how many times the suite's peak `doctor` wants a memory cap to hold. */
    public function memoryHeadroom(): int
    {
        return $this->memoryHeadroom instanceof Absent ? Headroom::TIMES : $this->memoryHeadroom;
    }

    /** How the local daemon serves the local runs and is started (ADR-0078). */
    public function serve(): LocalServe
    {
        return $this->serve;
    }

    /**
     * `local.inferSubsumed`: whether a run outside CI runs each mutant another
     * subsumes after the others, and shows one its budget left unjudged as
     * inferred killed where its subsumer was killed (ADR-0079); it does by
     * default.
     */
    public function inferSubsumed(): bool
    {
        return $this->inferSubsumed instanceof Absent || $this->inferSubsumed;
    }

    /**
     * `local.quick`: whether each round of `watch` is quick, advisory and
     * keyed apart from every other run's proofs (ADR-0146); it is by default.
     */
    public function quick(): bool
    {
        return $this->quick instanceof Absent || $this->quick;
    }

    /**
     * `local.cancelByUnit`: whether a save during a round of `watch` drops
     * only the work of the units it moved, halting a batch that runs one, and
     * the round goes on (ADR-0146); it does by default. Off, a save stops the
     * round before its next batch.
     */
    public function cancelsByUnit(): bool
    {
        return $this->cancelByUnit instanceof Absent || $this->cancelByUnit;
    }

    /** How many rounds `watch` runs, and the map its first round measures against (ADR-0146). */
    public function rounds(): LocalRounds
    {
        return $this->rounds;
    }

    /** How `watch` and `serve` use the machine, and the speed-ups they take (ADR-0120). */
    public function work(): LocalWork
    {
        return $this->work;
    }

    /** `local.watchPoll`: how long `watch` waits between two looks at the files it polls. */
    public function watchPoll(): Seconds
    {
        return $this->watchPoll instanceof Seconds ? $this->watchPoll : Poll::interval();
    }

    /**
     * `triage.fewest`: the fewest runs `triage --repeat` takes.
     *
     * @return int<2, max>
     */
    public function triageFewest(): int
    {
        return $this->triageFewest instanceof Absent
            ? self::TRIAGE_FEWEST
            : max(self::TRIAGE_FEWEST, $this->triageFewest);
    }

    public function written(PathOrigin $origin): Json
    {
        return Json::object(
            Member::unlessEmpty('triage', Json::object(Member::of('fewest', $this->triageFewest))),
            Member::unlessEmpty(
                'local',
                $this->work->written(Json::object(
                    Member::of('watchBudget', WholeSeconds::duration($this->watchBudget)),
                    Member::of('prePushBudget', WholeSeconds::duration($this->prePushBudget)),
                )->merged($this->serve->written())->with(
                    Member::of('inferSubsumed', $this->inferSubsumed),
                    Member::of('watchPoll', WholeSeconds::duration($this->watchPoll)),
                    Member::of('memoryHeadroom', $this->memoryHeadroom),
                    Member::of('quick', $this->quick),
                    Member::of('cancelByUnit', $this->cancelByUnit),
                )->merged($this->rounds->written())),
            ),
        );
    }

    public function php(PathOrigin $origin): PhpCalls
    {
        return PhpCalls::duration('Local::watchBudget', $this->watchBudget)
            ->and(PhpCalls::duration('Local::prePushBudget', $this->prePushBudget))
            ->and($this->serve->php())
            ->and(PhpCalls::flag($this->inferSubsumed, 'Local::inferringSubsumed()', 'Local::runningSubsumed()'))
            ->and(PhpCalls::duration('Local::watchPoll', $this->watchPoll))
            ->and(PhpCalls::flag($this->quick, 'Local::quickRounds()', 'Local::fullRounds()'))
            ->and(PhpCalls::flag($this->cancelByUnit, 'Local::cancellingByUnit()', 'Local::cancellingByRound()'))
            ->and($this->rounds->php())
            ->and(PhpCalls::inWith(
                ...$this->memoryHeadroom instanceof Absent
                    ? []
                    : [sprintf('Local::memoryHeadroom(%d)', $this->memoryHeadroom)],
            ))
            ->and(PhpCalls::inWith(
                ...$this->triageFewest instanceof Absent
                    ? []
                    : [sprintf('Local::triageFewest(%d)', $this->triageFewest)],
            ))
            ->and($this->work->php());
    }

}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function max;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Serve\ServeStart;
use NightWorksIO\MutationGate\Core\Time\Budgets;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Watch\Poll;

use function sprintf;

/**
 * How long the gate runs while a person works and before a push (ADR-0010),
 * and how its daemon serves those runs and is started (ADR-0078): `local`.
 */
final readonly class Local implements Part
{
    /** How long a daemon waits for a request before it stops, by default. */
    private const int SERVE_IDLE_MINUTES = 30;

    /** How long `watch` waits for a daemon it starts to listen, by default, in seconds. */
    private const float SERVE_START_WAIT = 10.0;

    /** The fewest runs that can disagree. */
    private const int TRIAGE_FEWEST = 2;

    private function __construct(
        private Seconds|Absent $watchBudget,
        private Seconds|Absent $prePushBudget,
        private Seconds|Absent $serveIdle,
        private ServeStart|Absent $serveStart,
        private Seconds|Absent $serveStartWait = new Absent(),
        private bool|Absent $inferSubsumed = new Absent(),
        private Seconds|Absent $watchPoll = new Absent(),
        private int|Absent $triageFewest = new Absent(),
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
    ): self {
        return new self(
            $watchBudget,
            $prePushBudget,
            $serveIdle,
            $serveStart,
            $serveStartWait,
            $inferSubsumed,
            $watchPoll,
            $triageFewest,
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
            $none->watchBudget(),
            $none->prePushBudget(),
            $none->serveIdle(),
            $none->serveStart(),
            $none->serveStartWait(),
            $none->inferSubsumed(),
            $none->watchPoll(),
            $none->triageFewest(),
        );
    }

    public function over(Part $later): self
    {
        return $later instanceof self
            ? new self(
                Absent::laid($this->watchBudget, $later->watchBudget),
                Absent::laid($this->prePushBudget, $later->prePushBudget),
                Absent::laid($this->serveIdle, $later->serveIdle),
                Absent::laid($this->serveStart, $later->serveStart),
                Absent::laid($this->serveStartWait, $later->serveStartWait),
                Absent::laid($this->inferSubsumed, $later->inferSubsumed),
                Absent::laid($this->watchPoll, $later->watchPoll),
                Absent::laid($this->triageFewest, $later->triageFewest),
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

    /** `local.serveIdle`: how long a daemon waits for a request before it stops. */
    public function serveIdle(): Seconds
    {
        return $this->serveIdle instanceof Seconds ? $this->serveIdle : Seconds::minutes(self::SERVE_IDLE_MINUTES);
    }

    /** `local.serveStartWait`: how long `watch` waits for a daemon it starts to listen. */
    public function serveStartWait(): Seconds
    {
        return $this->serveStartWait instanceof Seconds ? $this->serveStartWait : Seconds::of(self::SERVE_START_WAIT);
    }

    /** `local.serveStart`: whether `watch` starts a daemon where none runs; it does by default. */
    public function serveStart(): ServeStart
    {
        return $this->serveStart instanceof ServeStart ? $this->serveStart : ServeStart::Watch;
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
                Json::object(
                    Member::of('watchBudget', WholeSeconds::duration($this->watchBudget)),
                    Member::of('prePushBudget', WholeSeconds::duration($this->prePushBudget)),
                    Member::of('serveIdle', WholeSeconds::duration($this->serveIdle)),
                    Member::of(
                        'serveStart',
                        $this->serveStart instanceof ServeStart ? $this->serveStart->value : $this->serveStart,
                    ),
                    Member::of('serveStartWait', WholeSeconds::duration($this->serveStartWait)),
                    Member::of('inferSubsumed', $this->inferSubsumed),
                    Member::of('watchPoll', WholeSeconds::duration($this->watchPoll)),
                ),
            ),
        );
    }

    public function php(PathOrigin $origin): PhpCalls
    {
        return PhpCalls::inWith(...[
            ...$this->watchBudget instanceof Seconds
                ? [sprintf('Local::watchBudget(%s)', PhpCalls::literal($this->watchBudget->written()))]
                : [],
            ...$this->prePushBudget instanceof Seconds
                ? [sprintf('Local::prePushBudget(%s)', PhpCalls::literal($this->prePushBudget->written()))]
                : [],
            ...$this->serveIdle instanceof Seconds
                ? [sprintf('Local::serveIdle(%s)', PhpCalls::literal($this->serveIdle->written()))]
                : [],
            ...$this->serveStart instanceof ServeStart
                ? [sprintf('Local::serveStarting(ServeStart::%s)', $this->serveStart->name)]
                : [],
            ...$this->serveStartWait instanceof Seconds
                ? [sprintf('Local::serveStartWait(%s)', PhpCalls::literal($this->serveStartWait->written()))]
                : [],
            ...match ($this->inferSubsumed) {
                true => ['Local::inferringSubsumed()'],
                false => ['Local::runningSubsumed()'],
                default => [],
            },
            ...$this->watchPoll instanceof Seconds
                ? [sprintf('Local::watchPoll(%s)', PhpCalls::literal($this->watchPoll->written()))]
                : [],
            ...$this->triageFewest instanceof Absent
                ? []
                : [sprintf('Local::triageFewest(%d)', $this->triageFewest)],
        ]);
    }
}

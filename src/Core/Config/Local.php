<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Serve\ServeStart;
use NightWorksIO\MutationGate\Core\Time\Budgets;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * How long the gate runs while a person works and before a push (ADR-0010),
 * and how its daemon serves those runs (ADR-0078): `local`.
 */
final readonly class Local implements Part
{
    /** How long a daemon waits for a request before it stops, by default. */
    private const int SERVE_IDLE_MINUTES = 30;

    private function __construct(
        private Seconds|Absent $watchBudget,
        private Seconds|Absent $prePushBudget,
        private Seconds|Absent $serveIdle,
        private ServeStart|Absent $serveStart,
    ) {
    }

    public static function of(
        Seconds|Absent $watchBudget = new Absent(),
        Seconds|Absent $prePushBudget = new Absent(),
        Seconds|Absent $serveIdle = new Absent(),
        ServeStart|Absent $serveStart = new Absent(),
    ): self {
        return new self($watchBudget, $prePushBudget, $serveIdle, $serveStart);
    }

    public static function none(): self
    {
        return self::of();
    }

    public static function standard(): self
    {
        $none = self::none();

        return self::of($none->watchBudget(), $none->prePushBudget(), $none->serveIdle(), $none->serveStart());
    }

    public function over(Part $later): self
    {
        return $later instanceof self
            ? new self(
                Absent::laid($this->watchBudget, $later->watchBudget),
                Absent::laid($this->prePushBudget, $later->prePushBudget),
                Absent::laid($this->serveIdle, $later->serveIdle),
                Absent::laid($this->serveStart, $later->serveStart),
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

    /** `local.serveStart`: whether `watch` starts a daemon where none runs; it does by default. */
    public function serveStart(): ServeStart
    {
        return $this->serveStart instanceof ServeStart ? $this->serveStart : ServeStart::Watch;
    }

    public function written(PathOrigin $origin): Json
    {
        return Json::object(Member::unlessEmpty(
            'local',
            Json::object(
                Member::of(
                    'watchBudget',
                    $this->watchBudget instanceof Seconds ? $this->watchBudget->written() : $this->watchBudget,
                ),
                Member::of(
                    'prePushBudget',
                    $this->prePushBudget instanceof Seconds ? $this->prePushBudget->written() : $this->prePushBudget,
                ),
                Member::of(
                    'serveIdle',
                    $this->serveIdle instanceof Seconds ? $this->serveIdle->written() : $this->serveIdle,
                ),
                Member::of(
                    'serveStart',
                    $this->serveStart instanceof ServeStart ? $this->serveStart->value : $this->serveStart,
                ),
            ),
        ));
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
        ]);
    }
}

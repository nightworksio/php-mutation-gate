<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Time;

/**
 * How long each kind of process may take when its config sets no budget
 * (ADR-0008, decision 1; ADR-0010): a run has none, `watch` a minute, and
 * `pre-push` five.
 */
final readonly class Budgets
{
    /** A pre-push run's budget, in seconds. */
    private const int PRE_PUSH = 300;

    private function __construct(private Seconds|Unlimited $run, private Seconds $watch, private Seconds $prePush)
    {
    }

    /** The one set of budgets the gate falls back on. */
    public static function standard(): self
    {
        return new self(Unlimited::time(), Seconds::minutes(1), Seconds::of(self::PRE_PUSH));
    }

    /** A run's, which has none: it judges everything it takes. */
    public function run(): Seconds|Unlimited
    {
        return $this->run;
    }

    public function watch(): Seconds
    {
        return $this->watch;
    }

    public function prePush(): Seconds
    {
        return $this->prePush;
    }
}

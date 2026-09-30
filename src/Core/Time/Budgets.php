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
    /** A watch cycle's budget, in minutes. */
    private const int WATCH_MINUTES = 1;

    /** A pre-push run's budget, in minutes. */
    private const int PRE_PUSH_MINUTES = 5;

    private function __construct(private Seconds|Unlimited $run, private Seconds $watch, private Seconds $prePush)
    {
    }

    /** The one set of budgets the gate falls back on. */
    public static function standard(): self
    {
        return new self(
            Unlimited::time(),
            Seconds::minutes(self::WATCH_MINUTES),
            Seconds::minutes(self::PRE_PUSH_MINUTES),
        );
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

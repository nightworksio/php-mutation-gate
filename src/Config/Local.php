<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Serve\ServeStart;

/**
 * The budgets of the local modes (ADR-0010), how their daemon serves them
 * (ADR-0078), and whether they infer subsumed kills (ADR-0079): `local`.
 */
final readonly class Local implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** `local.watchBudget` */
    public static function watchBudget(string $duration): self
    {
        return new self(Json::at('local.watchBudget', $duration));
    }

    /** `local.prePushBudget` */
    public static function prePushBudget(string $duration): self
    {
        return new self(Json::at('local.prePushBudget', $duration));
    }

    /** `local.serveStartWait`: how long `watch` waits for a daemon it starts to listen. */
    public static function serveStartWait(string $duration): self
    {
        return new self(Json::at('local.serveStartWait', $duration));
    }

    /** `local.serveIdle`: how long a daemon waits for a request before it stops. */
    public static function serveIdle(string $duration): self
    {
        return new self(Json::at('local.serveIdle', $duration));
    }

    /** `local.serveStart`: whether `watch` starts a daemon where none runs. */
    public static function serveStarting(ServeStart $start): self
    {
        return new self(Json::at('local.serveStart', $start->value));
    }

    /** `local.inferSubsumed`, as by default: a run outside CI runs subsumed mutants last and infers their kills. */
    public static function inferringSubsumed(): self
    {
        return new self(Json::at('local.inferSubsumed', value: true));
    }

    /** `local.inferSubsumed` off: a run outside CI runs every mutant in its turn and infers nothing. */
    public static function runningSubsumed(): self
    {
        return new self(Json::at('local.inferSubsumed', value: false));
    }

    /** `local.watchPoll`: how long `watch` waits between two looks at the files it polls, such as `1s`. */
    public static function watchPoll(string $duration): self
    {
        return new self(Json::at('local.watchPoll', $duration));
    }

    /** `local.memoryHeadroom`: how many times the suite's peak `doctor` wants a memory cap to hold, 2 by default. */
    public static function memoryHeadroom(int $times): self
    {
        return new self(Json::at('local.memoryHeadroom', $times));
    }

    /** `local.serveRequest`: how long a client may take to send a daemon its request, 5 s by default. */
    public static function serveRequest(string $duration): self
    {
        return new self(Json::at('local.serveRequest', $duration));
    }

    /** `local.quick`, as by default: each round of `watch` is quick, advisory and keyed apart (ADR-0146). */
    public static function quickRounds(): self
    {
        return new self(Json::at('local.quick', value: true));
    }

    /** `local.quick` off: each round of `watch` measures, checks and judges as `run` does. */
    public static function fullRounds(): self
    {
        return new self(Json::at('local.quick', value: false));
    }

    /** `local.cancelByUnit`, as by default: a save drops only the work of the units it moved (ADR-0146). */
    public static function cancellingByUnit(): self
    {
        return new self(Json::at('local.cancelByUnit', value: true));
    }

    /** `local.cancelByUnit` off: a save stops the round of `watch` before its next batch. */
    public static function cancellingByRound(): self
    {
        return new self(Json::at('local.cancelByUnit', value: false));
    }

    /** `triage.fewest`: the fewest runs `triage --repeat` takes; 2. */
    public static function triageFewest(int $runs): self
    {
        return new self(Json::at('triage.fewest', $runs));
    }

    public function written(): Json
    {
        return $this->json;
    }
}

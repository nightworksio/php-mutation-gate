<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Serve\ServeStart;

/** The budgets of the local modes (ADR-0010), and how their daemon serves them (ADR-0078): `local`. */
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

    public function written(): Json
    {
        return $this->json;
    }
}

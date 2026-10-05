<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/** Which of the last run's survivors a pull request's run re-checks first (ADR-0020): `survivorsFirst.max`. */
final readonly class Survivors implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** At most this many, those on changed lines first; `0` re-checks none. */
    public static function firstAtMost(int $survivors): self
    {
        return new self(Json::at('survivorsFirst.max', $survivors));
    }

    public function written(): Json
    {
        return $this->json;
    }
}

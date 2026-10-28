<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

/** `owners.floors`: the floor of an owner CODEOWNERS names, over every file its rules name it for (ADR-0022). */
final readonly class Owner implements Setting
{
    private function __construct(private Json $json)
    {
    }

    public static function floor(string $owner, int|float $floor): self
    {
        return new self(Json::at('owners.floors', Json::object(Member::of($owner, $floor))));
    }

    public function written(): Json
    {
        return $this->json;
    }
}

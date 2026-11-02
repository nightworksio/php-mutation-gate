<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Owner\OwnerMention;

/**
 * `owners`: the floor of an owner CODEOWNERS names, over every file its rules name it for, and whether the comment
 * mentions owners (ADR-0022).
 */
final readonly class Owner implements Setting
{
    private function __construct(private Json $json)
    {
    }

    public static function floor(string $owner, int|float $floor): self
    {
        return new self(Json::at('owners.floors', Json::object(Member::of($owner, $floor))));
    }

    /** `owners.mention`: the comment mentions an owner in the update that first gives it a survivor. */
    public static function mentionedFirst(): self
    {
        return new self(Json::at('owners.mention', OwnerMention::First->value));
    }

    /** `owners.mention`: the comment names owners and mentions none. */
    public static function neverMentioned(): self
    {
        return new self(Json::at('owners.mention', OwnerMention::Never->value));
    }

    public function written(): Json
    {
        return $this->json;
    }
}

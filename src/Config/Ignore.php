<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\NotGiven;

/** An `ignores.entries` entry (ADR-0008): an equivalent mutant, with a reason and an optional end. */
final readonly class Ignore
{
    private function __construct(private Json $json)
    {
    }

    /** One mutant, by the id every report prints; `until` is the day the ignore ends, `YYYY-MM-DD`. */
    public static function mutant(string $id, string $because, string|NotGiven $until = new NotGiven()): self
    {
        return self::entry($until, Member::of('mutant', $id), Member::of('reason', $because));
    }

    /** One mutator, by its full name or its family, in the paths a glob matches. */
    public static function mutator(
        string $mutator,
        string $in,
        string $because,
        string|NotGiven $until = new NotGiven(),
    ): self {
        return self::entry(
            $until,
            Member::of('path', $in),
            Member::of('mutator', $mutator),
            Member::of('reason', $because),
        );
    }

    public function written(): Json
    {
        return $this->json;
    }

    private static function entry(string|NotGiven $until, Member ...$entry): self
    {
        $json = Json::object(...$entry);

        return new self($until instanceof NotGiven ? $json : $json->with(Member::of('expires', $until)));
    }
}

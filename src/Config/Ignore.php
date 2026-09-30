<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

/** An `ignores.entries` entry (ADR-0008): an equivalent mutant, with a reason and an optional end. */
final readonly class Ignore
{
    private function __construct(private Json $json)
    {
    }

    /** One mutant, by the id every report prints; `until` is the day the ignore ends, `YYYY-MM-DD`. */
    public static function mutant(string $id, string $because, string $until = ''): self
    {
        return self::entry($until, Member::of('mutant', $id), Member::of('reason', $because));
    }

    /** One mutator, by its full name or its family, in the paths a glob matches. */
    public static function mutator(string $mutator, string $in, string $because, string $until = ''): self
    {
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

    private static function entry(string $until, Member ...$entry): self
    {
        return new self(
            Json::object(...$entry)->with(Member::of('expires', $until === '' ? Absent::setting() : $until)),
        );
    }
}

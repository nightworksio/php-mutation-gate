<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/** An `ignores.entries` entry (ADR-0008): an equivalent mutant, with a reason and an optional end. */
final readonly class Ignore
{
    private function __construct(private Json $json)
    {
    }

    /** One mutant, by the id every report prints; `until` is the day the ignore ends, `YYYY-MM-DD`. */
    public static function mutant(string $id, string $because, string $until = ''): self
    {
        return self::entry(['mutant' => $id, 'reason' => $because], $until);
    }

    /** One mutator, by its full name or its family, in the paths a glob matches. */
    public static function mutator(string $mutator, string $in, string $because, string $until = ''): self
    {
        return self::entry(['path' => $in, 'mutator' => $mutator, 'reason' => $because], $until);
    }

    public function written(): Json
    {
        return $this->json;
    }

    /** @param array<string, string> $entry */
    private static function entry(array $entry, string $until): self
    {
        return new self(Json::decoded($until === '' ? $entry : [...$entry, 'expires' => $until]));
    }
}

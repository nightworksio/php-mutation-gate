<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Config\Duplicates;
use NightWorksIO\MutationGate\Core\Format\Json;

/** Whether mutants that are one program are judged once (ADR-0137): `mutants`. */
final readonly class Mutants implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** `mutants.duplicates`, `judge-once`: the first of mutants that are one program is judged, as by default. */
    public static function judgingDuplicatesOnce(): self
    {
        return new self(Json::at('mutants.duplicates', Duplicates::JudgeOnce->value));
    }

    /** `mutants.duplicates`, `judge-each`: every mutant is judged by its own run. */
    public static function judgingEach(): self
    {
        return new self(Json::at('mutants.duplicates', Duplicates::JudgeEach->value));
    }

    public function written(): Json
    {
        return $this->json;
    }
}

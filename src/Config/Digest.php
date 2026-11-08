<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/** How much the digest says of one mutant (ADR-0009): `digest`. */
final readonly class Digest implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** `digest.tests`: how many of a mutant's covering tests `digest --detail=<id>` names; 5. */
    public static function tests(int $tests): self
    {
        return new self(Json::at('digest.tests', $tests));
    }

    public function written(): Json
    {
        return $this->json;
    }
}

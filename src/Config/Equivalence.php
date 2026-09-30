<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

/** Whether a mutant the optimizer compiles as its original is proven equivalent (ADR-0013): `equivalence.static`. */
final readonly class Equivalence implements Setting
{
    private function __construct(private bool $proven)
    {
    }

    public static function provenStatically(): self
    {
        return new self(proven: true);
    }

    /** Each such mutant is run like any other. */
    public static function notProvenStatically(): self
    {
        return new self(proven: false);
    }

    public function written(): Json
    {
        return Json::object(Member::of('equivalence', Json::object(Member::of('static', $this->proven))));
    }
}

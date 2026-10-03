<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/**
 * The registered mutators a run makes mutants with besides its runner's own
 * (ADR-0021): `mutators.sets` and `mutators.except`.
 */
final readonly class Mutators implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** `mutators.sets`: the mutator sets turned on, each by the name an extension registers it under. */
    public static function sets(string ...$names): self
    {
        return new self(Json::at('mutators.sets', Json::items(...$names)));
    }

    /** `mutators.except`: single mutators of those sets turned off, each by its name, `<set>/<Name>`. */
    public static function except(string ...$names): self
    {
        return new self(Json::at('mutators.except', Json::items(...$names)));
    }

    public function written(): Json
    {
        return $this->json;
    }
}

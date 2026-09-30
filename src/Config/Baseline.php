<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/** `baseline`: the committed floors, and what an improvement does (ADR-0003). */
final readonly class Baseline implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** `baseline.path` */
    public static function at(string $path): self
    {
        return new self(Json::decoded(['baseline' => ['path' => $path]]));
    }

    /** An improvement fails a pull request until the raised floor is committed. */
    public static function requiringImprovement(): self
    {
        return new self(Json::decoded(['baseline' => ['improvement' => 'require']]));
    }

    /** An improvement passes, and the summary shows how to raise the floor. */
    public static function reportingImprovement(): self
    {
        return new self(Json::decoded(['baseline' => ['improvement' => 'report']]));
    }

    public function written(): Json
    {
        return $this->json;
    }
}

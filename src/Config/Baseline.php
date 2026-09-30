<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Config\Definition\Json;

/** `baseline`: the committed floors, and what an improvement does (ADR-0003). */
final readonly class Baseline implements Setting
{
    private function __construct(private string $json)
    {
    }

    /** `baseline.path` */
    public static function at(string $path): self
    {
        return new self(Json::encode(['baseline' => ['path' => $path]]));
    }

    /** An improvement fails a pull request until the raised floor is committed. */
    public static function requiringImprovement(): self
    {
        return new self(Json::encode(['baseline' => ['improvement' => 'require']]));
    }

    /** An improvement passes, and the summary shows how to raise the floor. */
    public static function reportingImprovement(): self
    {
        return new self(Json::encode(['baseline' => ['improvement' => 'report']]));
    }

    public function written(): string
    {
        return $this->json;
    }
}

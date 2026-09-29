<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Config\Definition\Json;

/** `uncovered`: how uncovered mutants count in a score (ADR-0003). */
final readonly class Uncovered implements Setting
{
    private function __construct(private string $json)
    {
    }

    /** As not killed. */
    public static function counted(): self
    {
        return new self(Json::encode(['uncovered' => 'count']));
    }

    /** Not at all: left out of the score, and still listed. */
    public static function excluded(): self
    {
        return new self(Json::encode(['uncovered' => 'exclude']));
    }

    public function written(): string
    {
        return $this->json;
    }
}

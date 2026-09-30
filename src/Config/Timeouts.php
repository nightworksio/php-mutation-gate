<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Config\Definition\Json;

/** How timeouts are judged (ADR-0008): `timeouts`. */
final readonly class Timeouts implements Setting
{
    private function __construct(private string $json)
    {
    }

    /** A timeout is a kill when the covering tests normally finish in under half the limit. */
    public static function confirmed(): self
    {
        return new self(Json::encode(['timeouts' => ['mode' => 'confirm']]));
    }

    /** A timeout is always too slow to judge. */
    public static function unjudged(): self
    {
        return new self(Json::encode(['timeouts' => ['mode' => 'unjudged']]));
    }

    /** `timeouts.seconds` */
    public static function seconds(int $seconds): self
    {
        return new self(Json::encode(['timeouts' => ['seconds' => $seconds]]));
    }

    /** `timeouts.retries` */
    public static function retries(int $mutants): self
    {
        return new self(Json::encode(['timeouts' => ['retries' => $mutants]]));
    }

    public function written(): string
    {
        return $this->json;
    }
}

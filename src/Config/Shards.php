<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Config\Definition\Json;

/** How the work is cut (ADR-0006): `shards` and `costs`. */
final readonly class Shards implements Setting
{
    private function __construct(private string $json)
    {
    }

    /** `shards.seconds` */
    public static function seconds(int $seconds): self
    {
        return new self(Json::encode(['shards' => ['seconds' => $seconds]]));
    }

    /** `shards.max` */
    public static function max(int $shards): self
    {
        return new self(Json::encode(['shards' => ['max' => $shards]]));
    }

    /** `costs.secondsPerLine`: what a line under a path prefix costs before anything was measured. */
    public static function secondsPerLine(string $prefix, int|float $seconds): self
    {
        return new self(Json::encode(['costs' => ['secondsPerLine' => [$prefix => $seconds]]]));
    }

    public function written(): string
    {
        return $this->json;
    }
}

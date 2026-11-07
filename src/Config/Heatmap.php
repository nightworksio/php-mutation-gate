<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/** How much of the heatmap each report shows (ADR-0100): `heatmap`. */
final readonly class Heatmap implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** `heatmap.files`: how many of the lowest-scoring files the dashboard's summary lists; 10. */
    public static function files(int $files): self
    {
        return new self(Json::at('heatmap.files', $files));
    }


    /** `heatmap.methods`: the heatmap scores each function of a file too, as by default. */
    public static function withMethods(): self
    {
        return new self(Json::at('heatmap.methods', value: true));
    }

    /** `heatmap.methods`: the heatmap scores files only. */
    public static function withoutMethods(): self
    {
        return new self(Json::at('heatmap.methods', value: false));
    }

    public function written(): Json
    {
        return $this->json;
    }
}

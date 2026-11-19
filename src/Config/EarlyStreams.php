<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/**
 * When a full split-stream run starts without a fresh coverage map, its
 * streams recording what their tests execute (ADR-0135): `splitStream.early`.
 */
final readonly class EarlyStreams implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** `splitStream.early` `no-match`, as by default: a full run plans without a fresh map where no proof matches. */
    public static function whereNothingMatches(): self
    {
        return new self(Json::at('splitStream.early', 'no-match'));
    }

    /** `splitStream.early` `always`: every full split-stream run plans without a fresh map, taking no proof. */
    public static function always(): self
    {
        return new self(Json::at('splitStream.early', 'always'));
    }

    /** `splitStream.early` `unproven-only`: only a run under `unproven` plans without a fresh map. */
    public static function unprovenOnly(): self
    {
        return new self(Json::at('splitStream.early', 'unproven-only'));
    }

    /** `splitStream.early` `never`: every plan waits for a fresh map. */
    public static function never(): self
    {
        return new self(Json::at('splitStream.early', 'never'));
    }

    public function written(): Json
    {
        return $this->json;
    }
}

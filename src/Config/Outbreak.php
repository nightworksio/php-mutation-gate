<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/**
 * Whether Outbreak's core takes the coverage maps' bulk off the gate's PHP,
 * its binary, how long a run of it may take and its threads (ADR-0118):
 * `outbreak`.
 */
final readonly class Outbreak implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** `outbreak.enabled`, as by default: the gate runs the binary where one fits and passes its checksum. */
    public static function on(): self
    {
        return new self(Json::at('outbreak.enabled', value: true));
    }

    /** `outbreak.enabled` off: the gate does every part of the work in PHP. */
    public static function off(): self
    {
        return new self(Json::at('outbreak.enabled', value: false));
    }

    /** `outbreak.binary`: the binary to run, from the project's root where relative, in place of the shipped one. */
    public static function binary(string $path): self
    {
        return new self(Json::at('outbreak.binary', $path));
    }

    /** `outbreak.timeout`: how long one run of the binary may take, such as `90s`; 5 minutes by default. */
    public static function timeout(string $duration): self
    {
        return new self(Json::at('outbreak.timeout', $duration));
    }

    /** `outbreak.threads`: how many threads the binary writes a plan's maps on; every core by default. */
    public static function threads(int $threads): self
    {
        return new self(Json::at('outbreak.threads', $threads));
    }

    public function written(): Json
    {
        return $this->json;
    }
}

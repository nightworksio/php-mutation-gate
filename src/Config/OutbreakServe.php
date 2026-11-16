<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/**
 * Whether `outbreak serve` holds the project's state, and how often it
 * snapshots, syncs and settles a change (ADR-0133): `outbreak.serve`.
 */
final readonly class OutbreakServe implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /**
     * `outbreak.serve.enabled`, as by default: `outbreak serve` holds the project's state where the binary is in use.
     */
    public static function serving(): self
    {
        return new self(Json::at('outbreak.serve.enabled', value: true));
    }

    /** `outbreak.serve.enabled` off: `watch` and the hooks talk to the PHP daemon alone, which reads every file. */
    public static function notServing(): self
    {
        return new self(Json::at('outbreak.serve.enabled', value: false));
    }

    /** `outbreak.serve.snapshotEvery`: how often, at most, the daemon writes its snapshot, such as `10m`. */
    public static function snapshotEvery(string $duration): self
    {
        return new self(Json::at('outbreak.serve.snapshotEvery', $duration));
    }

    /** `outbreak.serve.syncEvery`: how often, at most, the daemon syncs its journal to disk, such as `1s`. */
    public static function syncEvery(string $duration): self
    {
        return new self(Json::at('outbreak.serve.syncEvery', $duration));
    }

    /**
     * `outbreak.serve.debounce`: how long the changes the daemon watches settle before a round starts, such as `50ms`.
     */
    public static function debounce(string $duration): self
    {
        return new self(Json::at('outbreak.serve.debounce', $duration));
    }

    public function written(): Json
    {
        return $this->json;
    }
}

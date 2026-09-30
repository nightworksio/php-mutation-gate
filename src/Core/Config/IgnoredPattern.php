<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Time\Day;

/**
 * The mutants of one mutator, by its full name or its family, in the paths a
 * glob matches, which the config ignores with a reason (ADR-0008).
 */
final readonly class IgnoredPattern implements Ignored
{
    private function __construct(
        private string $path,
        private string $mutator,
        private string $reason,
        private Day|Absent $expires,
    ) {
    }

    public static function of(string $path, string $mutator, string $reason, Day|Absent $expires): self
    {
        return new self($path, $mutator, $reason, $expires);
    }

    /** The glob. */
    public function path(): string
    {
        return $this->path;
    }

    public function mutator(): string
    {
        return $this->mutator;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function expires(): Day|Absent
    {
        return $this->expires;
    }
}

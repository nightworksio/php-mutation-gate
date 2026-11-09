<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use Closure;
use NightWorksIO\MutationGate\Core\NotWritten;

/**
 * The outline of the map of a run's report that an engine is reading while
 * the gate goes on, taken once the gate needs it (ADR-0119).
 *
 * @internal
 */
final readonly class PendingOutline
{
    /** @param Closure(): (MapOutline|NotWritten) $taking what the engine read, waited for */
    private function __construct(private Closure $taking)
    {
    }

    /** @param Closure(): (MapOutline|NotWritten) $taking what the engine read, waited for */
    public static function taking(Closure $taking): self
    {
        return new self($taking);
    }

    /** An outline already read, or why there is none. */
    public static function read(MapOutline|NotWritten $outline): self
    {
        return new self(static fn(): MapOutline|NotWritten => $outline);
    }

    /** The outline, once the engine has read it; or why it read none. */
    public function outline(): MapOutline|NotWritten
    {
        return ($this->taking)();
    }
}

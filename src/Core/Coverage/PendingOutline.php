<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use Closure;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;

/**
 * The outline of the map of a run's report that an engine is reading while
 * the gate goes on, taken once the gate needs it (ADR-0119).
 *
 * @internal
 */
final readonly class PendingOutline
{
    /**
     * @param Closure(): (MapOutline|NotWritten) $taking what the engine read, waited for
     * @param NotWritten|NotGiven                $none   why there is none, where that is known at once
     */
    private function __construct(private Closure $taking, private NotWritten|NotGiven $none)
    {
    }

    /** @param Closure(): (MapOutline|NotWritten) $taking what the engine read, waited for */
    public static function taking(Closure $taking): self
    {
        return new self($taking, NotGiven::value());
    }

    /** An outline already read, or why there is none. */
    public static function read(MapOutline|NotWritten $outline): self
    {
        return new self(
            static fn(): MapOutline|NotWritten => $outline,
            $outline instanceof NotWritten ? $outline : NotGiven::value(),
        );
    }

    /** The outline, once the engine has read it; or why it read none. */
    public function outline(): MapOutline|NotWritten
    {
        return ($this->taking)();
    }

    /** Why the engine reads no outline, where that is known before it is taken; nothing where it may read one. */
    public function noneAtOnce(): NotWritten|NotGiven
    {
        return $this->none;
    }
}

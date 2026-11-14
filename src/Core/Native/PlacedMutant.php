<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Native;

/**
 * A mutant's files as the gate's wrapper serves it (ADR-0023): the file the
 * override serves, the mutated file it serves in its place, and the guard the
 * wrapper writes.
 *
 * @internal
 */
interface PlacedMutant
{
    /** The guard file the wrapper and the recorder write. */
    public function guard(): string;

    /** What the guard file says of the run. */
    public function guarded(): Guard;

    /** The file the override serves the mutant in place of. */
    public function original(): string;

    /** The mutated file the override serves. */
    public function mutated(): string;
}

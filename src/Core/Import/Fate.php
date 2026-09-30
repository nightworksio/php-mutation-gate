<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Import;

/** What becomes of a key of another tool's config when the gate takes over from it (ADR-0016, decision 1). */
enum Fate
{
    /** The gate's config holds it now, so it can go from the other file. */
    case Imported;

    /** The other tool still reads it, so it stays in its file. */
    case Stays;

    /** The gate has no use for it, so it can go from the other file. */
    case Dropped;

    /** Whether the key can be deleted from the other tool's file once the gate's config is written. */
    public function deletes(): bool
    {
        return $this !== self::Stays;
    }
}

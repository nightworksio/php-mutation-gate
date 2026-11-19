<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Control;

/** What asked for an unmutated control, as a shard's measured steps name it (ADR-0167). */
enum ControlKind: string
{
    /** A timed-out mutant's (see TimeoutControls). */
    case Timeout = 'timeout';

    /** A file's kills at once (see KillControls). */
    case Kills = 'kills';

    /** One kill on its own, where its file's control did not pass (see KillControls). */
    case Kill = 'kill';

    /** A kill no test is named for (see UnnamedKillControls). */
    case UnnamedKill = 'unnamed kill';

    /** A mutant out of memory (see MemoryControls). */
    case Memory = 'memory';

    /** A part of a heavy mutant's run, or its control (see HeavyMutants). */
    case HeavyPart = 'heavy part';
}

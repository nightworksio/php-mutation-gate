<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

/** The arguments the commands take, by the name each is read by. */
enum Argument: string
{
    /** A mutant, by its id or the start of one, or a cluster, by its id. */
    case Id = 'id';

    /** The Infection config `import` imports. */
    case File = 'file';

    /** The unit `triage` runs. */
    case Path = 'path';

    /** What `hook` does: install or uninstall. */
    case Action = 'action';
}

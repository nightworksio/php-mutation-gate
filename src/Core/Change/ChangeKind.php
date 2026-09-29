<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Change;

/** What a change did to a path. */
enum ChangeKind: string
{
    case Added = 'added';
    case Modified = 'modified';
    case Deleted = 'deleted';
    case Renamed = 'renamed';
}

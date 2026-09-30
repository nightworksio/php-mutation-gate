<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

/** Which results the problems output shows: all of them, or those on changed lines, as `--only=changed` asks. */
enum ProblemsShown: string
{
    case All = 'all';
    case Changed = 'changed';
}

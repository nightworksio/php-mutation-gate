<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli;

/** What a run ends with, for CI to read. */
enum ExitCode: int
{
    case Passed = 0;
    case Failed = 1;
    case CannotJudge = 2;
}

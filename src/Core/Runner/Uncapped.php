<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/** No cap: a process may use as much memory as the machine has. */
enum Uncapped
{
    case Memory;
}

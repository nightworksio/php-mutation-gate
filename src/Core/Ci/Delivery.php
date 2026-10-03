<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

/** How a plan reaches its CI: printed to the command's output, written to a file of its own, or appended to one. */
enum Delivery
{
    case Printed;

    case Written;

    case Appended;
}

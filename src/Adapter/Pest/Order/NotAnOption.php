<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Order;

/** An argument that is none of the options the rewrite drops, which it keeps. */
enum NotAnOption
{
    case Argument;
}

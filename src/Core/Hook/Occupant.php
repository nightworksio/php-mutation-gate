<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hook;

/** Who wrote the file where a hook of the gate's goes: nobody, the gate, or someone else. */
enum Occupant
{
    case Nobody;
    case TheGate;
    case Someone;
}

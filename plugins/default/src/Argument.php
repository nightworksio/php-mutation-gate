<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateDefault;

/** Which argument of a call an unwrapping mutator puts in the call's place, by its position. */
enum Argument: int
{
    case First = 0;
    case Second = 1;
    case Third = 2;
}

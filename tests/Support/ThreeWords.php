<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

/** Three words a setting takes, one case each. */
enum ThreeWords: string
{
    case One = 'one';
    case Two = 'two';
    case Three = 'three';
}

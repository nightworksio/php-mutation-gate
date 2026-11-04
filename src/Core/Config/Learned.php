<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** A limit the gate learns from what its earlier runs measured, in place of a fixed one. */
enum Learned: string
{
    case Learned = 'learned';
}

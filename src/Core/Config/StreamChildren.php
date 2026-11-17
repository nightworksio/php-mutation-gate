<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/**
 * `splitStream.children` as a share of the machine (ADR-0173).
 */
enum StreamChildren: string
{
    /** The logical cores shared among the streams still running, at least one a stream. */
    case Cores = 'cores';

    /** How a config and the runner's option write `splitStream.children`: a count, or this share's name. */
    public static function text(int|self $children): int|string
    {
        return $children instanceof self ? $children->value : $children;
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

/** A report that names no root for its relative paths, as one CI uploads does. */
final readonly class Unrooted
{
    public static function report(): self
    {
        return new self();
    }
}

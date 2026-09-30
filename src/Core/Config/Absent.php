<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** A setting the config leaves out, where leaving it out has a meaning of its own. */
final readonly class Absent
{
    public static function setting(): self
    {
        return new self();
    }
}

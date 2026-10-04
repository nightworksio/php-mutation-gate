<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

/** The survivors proven equivalent, and a warning where opcache could check none of them. */
final readonly class Equivalents
{
    public function __construct(public MutantIds $proven, public Warnings $warnings)
    {
    }

    public static function none(): self
    {
        return new self(MutantIds::none(), Warnings::none());
    }
}

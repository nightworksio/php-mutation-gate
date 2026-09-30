<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use NightWorksIO\MutationGate\Core\File\DiskPath;

/** The project's Infection config, and the directory PHPUnit has run a run's judging tests under coverage into. */
final readonly class Prepared
{
    public function __construct(public OwnConfig $config, public DiskPath $coverage)
    {
    }
}

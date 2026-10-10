<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use LogicException;
use NightWorksIO\MutationGate\Adapter\Process\Running;
use NightWorksIO\MutationGate\Core\Runner\ProcessCommand;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlot;

/** A command started in the one place of a runner, at time 0, where a test needs it running. */
final readonly class Started
{
    public static function command(ProcessCommand $command): Running
    {
        $running = Running::start($command, 0, WorkerSlot::alone(), 0.0);

        if (! $running instanceof Running) {
            throw new LogicException('The command did not start.');
        }

        return $running;
    }
}

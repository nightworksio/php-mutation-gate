<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use NightWorksIO\MutationGate\Core\Runner\ProcessEnds;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

/** What runs PHPUnit for the adapter: a process in the project's root, or a fake in a test. */
interface Shell
{
    public function run(Command $command): Ran;

    /**
     * Commands run side by side, one in each place at a time, none started
     * once the time to start them in has run out; the ends of those started,
     * in the order the commands were given.
     */
    public function sideBySide(
        WorkerSlots $slots,
        Seconds|Unlimited $startingWithin,
        Command ...$commands,
    ): ProcessEnds;

    /** The same shell, running each command in another directory. */
    public function in(string $directory): self;
}

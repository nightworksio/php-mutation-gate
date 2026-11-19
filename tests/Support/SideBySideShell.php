<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\Shell;
use NightWorksIO\MutationGate\Core\Runner\ProcessEnds;
use NightWorksIO\MutationGate\Core\Runner\ProcessWatch;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Unwatched;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

/** A shell that runs commands as another does, and starts none on its own: it only runs them side by side. */
final readonly class SideBySideShell implements Shell
{
    public function __construct(private Shell $shell)
    {
    }

    public function run(Command $command, ProcessWatch $watch = new Unwatched()): Ran
    {
        return $this->shell->run($command, $watch);
    }

    public function sideBySide(WorkerSlots $slots, Seconds|Unlimited $startingWithin, Command ...$commands): ProcessEnds
    {
        return $this->shell->sideBySide($slots, $startingWithin, ...$commands);
    }

    public function in(string $directory): Shell
    {
        return new self($this->shell->in($directory));
    }
}

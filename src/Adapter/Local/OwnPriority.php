<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Local;

use function function_exists;
use function getmypid;
use function is_executable;
use function is_int;

use NightWorksIO\MutationGate\Core\Local\MacosClass;
use NightWorksIO\MutationGate\Core\Local\Priority;
use NightWorksIO\MutationGate\Core\Local\System;
use NightWorksIO\MutationGate\Core\Local\WindowsClass;

use function pcntl_getpriority;
use function pcntl_setpriority;
use function proc_nice;
use function strval;

use Symfony\Component\Process\Process;

/**
 * This process's priority, which every process it starts afterwards
 * inherits (ADR-0120). It is only ever lowered where a process cannot raise
 * its own again: a niceness on Linux and macOS goes up and stays there. On
 * macOS the background and maintenance classes are `taskpolicy`'s darwin
 * background, which is set and taken back as the class asks; a running
 * process cannot be put under the utility clamp, so it takes the utility
 * niceness. On Windows the priority class is set as asked.
 *
 * @internal
 */
final class OwnPriority
{
    private bool $background = false;

    private function __construct(private readonly System $system, private readonly int $process)
    {
    }

    public static function here(): self
    {
        return new self(System::here(), (int) getmypid());
    }

    /** The priority of this process on this system. */
    public static function on(System $system, int $process): self
    {
        return new self($system, $process);
    }

    /** This process at the class its platform takes of these. */
    public function lowered(MacosClass $macos, int $nice, WindowsClass $windows): void
    {
        if ($this->system === System::Windows) {
            proc_nice($windows->nice());

            return;
        }

        if ($this->system === System::Darwin) {
            $this->darwin($macos);

            return;
        }

        $this->niced($nice);
    }

    private function darwin(MacosClass $class): void
    {
        $background = $class === MacosClass::Background || $class === MacosClass::Maintenance;

        if ($background !== $this->background && is_executable(Launch::TASKPOLICY)) {
            new Process([Launch::TASKPOLICY, $background ? '-b' : '-B', '-p', strval($this->process)])->run();
            $this->background = $background;
        }

        if ($class === MacosClass::Utility) {
            $this->niced(Priority::Utility->nice());
        }
    }

    /** This process at this niceness, where it is above the one it has. */
    private function niced(int $nice): void
    {
        $now = function_exists('pcntl_getpriority') ? pcntl_getpriority() : false;

        if (is_int($now) && $nice > $now) {
            pcntl_setpriority($nice);
        }
    }
}

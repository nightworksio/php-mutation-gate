<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Local;

use function is_executable;

use NightWorksIO\MutationGate\Core\Config\LocalWork;
use NightWorksIO\MutationGate\Core\Local\System;

use function strval;

/**
 * What goes before the command line of a process the gate starts to serve
 * the local loop, the daemon of `serve` (ADR-0120): on macOS `taskpolicy`
 * with the QoS clamp, on Linux `nice` with the niceness, each where the
 * class asks for one and the program is there. Every process the daemon
 * starts inherits it.
 *
 * @internal
 */
final readonly class Launch
{
    /** The program that runs another under a QoS clamp on macOS, or changes a running one's. */
    public const string TASKPOLICY = '/usr/sbin/taskpolicy';

    private const string NICE = '/usr/bin/nice';

    private function __construct(private System $system, private string $taskpolicy, private string $nice)
    {
    }

    public static function here(): self
    {
        return new self(System::here(), self::TASKPOLICY, self::NICE);
    }

    /** One on this system, clamping with this `taskpolicy` and niceing with this `nice`, each where it runs. */
    public static function on(System $system, string $taskpolicy = self::TASKPOLICY, string $nice = self::NICE): self
    {
        return new self($system, $taskpolicy, $nice);
    }

    /** @return list<string> */
    public function prefix(LocalWork $work): array
    {
        $macos = $work->macosClass();
        $nice = $work->linuxNice();

        return match (true) {
            $this->system === System::Darwin && $macos->clamps() && is_executable($this->taskpolicy)
                => [$this->taskpolicy, '-c', $macos->value],
            $this->system === System::Linux && $nice > 0 && is_executable($this->nice)
                => [$this->nice, '-n', strval($nice)],
            default => [],
        };
    }
}

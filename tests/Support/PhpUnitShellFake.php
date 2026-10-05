<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_slice;
use function array_values;

use Closure;

use function count;

use NightWorksIO\MutationGate\Adapter\PhpUnit\Command;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Shell;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ProcessEnds;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlot;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

/**
 * A shell that runs no PHPUnit: it keeps every command it is given and
 * answers each through a function of the command and how many came before
 * it. That function can write what the real PHPUnit would have, such as the
 * extension's results.
 */
final class PhpUnitShellFake implements Shell
{
    /** @var list<Command> */
    private array $commands = [];

    /** @var list<string> */
    private array $directories = [];

    /** @var list<WorkerSlot> */
    private array $places = [];

    /** @var list<int> how many commands each run side by side was given */
    private array $batches = [];

    /** How many commands of each run side by side start, as a deadline would cut it; every one where not given. */
    private int|NotGiven $starting;

    /** @param Closure(Command, int): Ran $answer */
    public function __construct(private readonly Closure $answer)
    {
        $this->starting = NotGiven::value();
    }

    /** This shell, starting no more than this many of the commands of each run side by side. */
    public function startingAtMost(int $count): self
    {
        $this->starting = $count;

        return $this;
    }

    /** A shell that answers every command with the same end. */
    public static function answering(Ran $ran): self
    {
        return new self(static fn(): Ran => $ran);
    }

    public function run(Command $command): Ran
    {
        $before = count($this->commands);
        $this->commands[] = $command;

        return ($this->answer)($command, $before);
    }

    /**
     * Each command answered in turn, as if each in the next place, none where
     * no time is given to start them in.
     */
    public function sideBySide(WorkerSlots $slots, Seconds|Unlimited $startingWithin, Command ...$commands): ProcessEnds
    {
        $this->batches[] = count($commands);
        $places = [...$slots];
        $ends = [];
        $starting = $startingWithin instanceof Unlimited || $startingWithin->seconds() > 0.0 ? $commands : [];
        $starting = $this->starting instanceof NotGiven ? $starting : array_slice($starting, 0, $this->starting);

        foreach (array_values($starting) as $at => $command) {
            $this->places[] = $places[$at % count($places)];
            $ends[] = $this->run($command);
        }

        return ProcessEnds::of(...$ends);
    }

    /** @return list<int> how many commands each run side by side was given, in order */
    public function batches(): array
    {
        return $this->batches;
    }

    /** @return list<WorkerSlot> the place each command run side by side ran in, in order */
    public function places(): array
    {
        return $this->places;
    }

    /** This shell, which keeps the directory it was moved to. */
    public function in(string $directory): self
    {
        $this->directories[] = $directory;

        return $this;
    }

    /** @return list<string> every directory the shell was moved to, in order */
    public function directories(): array
    {
        return $this->directories;
    }

    /** @return list<Command> every command run, in order */
    public function commands(): array
    {
        return $this->commands;
    }
}

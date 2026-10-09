<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use Closure;

use function count;

use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\Shell;
use NightWorksIO\MutationGate\Core\Runner\ProcessEnds;
use NightWorksIO\MutationGate\Core\Runner\ProcessWatch;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Unwatched;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

/**
 * A shell that runs nothing: it keeps every command it is given and answers
 * each through a function of the command and how many came before it. That
 * function can write what the real program would have, such as Pest's
 * results file.
 */
final class ShellFake implements Shell
{
    /** @var list<Command> */
    private array $commands = [];

    /** @var list<string> */
    private array $directories = [];

    /** @var list<int> */
    private array $batches = [];

    /** @param Closure(Command, int): Ran $answer */
    public function __construct(private readonly Closure $answer)
    {
    }

    /** A shell that answers every command with the same end. */
    public static function answering(Ran $ran): self
    {
        return new self(static fn(): Ran => $ran);
    }

    public function run(Command $command, ProcessWatch $watch = new Unwatched()): Ran
    {
        $before = count($this->commands);
        $this->commands[] = $command;
        $watch->look();

        return ($this->answer)($command, $before);
    }

    /** Each command answered in turn, as if each in the next place, none where no time is given to start them in. */
    public function sideBySide(
        WorkerSlots $slots,
        Seconds|Unlimited $startingWithin,
        Command ...$commands,
    ): ProcessEnds {
        $this->batches[] = count($commands);
        $ends = [];
        $starting = $startingWithin instanceof Unlimited || $startingWithin->seconds() > 0.0 ? $commands : [];

        foreach ($starting as $command) {
            $ends[] = $this->run($command);
        }

        return ProcessEnds::of(...$ends);
    }

    /** @return list<int> how many commands each run side by side was given, in order */
    public function batches(): array
    {
        return $this->batches;
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

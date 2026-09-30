<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use Closure;

use function count;

use NightWorksIO\MutationGate\Adapter\Infection\Command;
use NightWorksIO\MutationGate\Adapter\Infection\Ran;
use NightWorksIO\MutationGate\Adapter\Infection\Shell;

/**
 * A shell that runs neither PHPUnit nor Infection: it keeps every command it
 * is given and answers each through a function of the command and how many
 * came before it. That function can write what the real program would have,
 * such as Infection's logs.
 */
final class InfectionShellFake implements Shell
{
    /** @var list<Command> */
    private array $commands = [];

    /** @var list<string> */
    private array $directories = [];

    /** @param Closure(Command, int): Ran $answer */
    public function __construct(private readonly Closure $answer)
    {
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

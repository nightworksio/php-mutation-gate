<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_search;
use function array_slice;

use Closure;

use function dirname;
use function file_get_contents;
use function file_put_contents;
use function in_array;
use function is_int;

use NightWorksIO\MutationGate\Adapter\Native\Warm\Claims;
use NightWorksIO\MutationGate\Adapter\Native\Warm\End;
use NightWorksIO\MutationGate\Adapter\Native\Warm\Job;
use NightWorksIO\MutationGate\Adapter\Native\Warm\Printed;
use NightWorksIO\MutationGate\Adapter\Native\Warm\Refusal;
use NightWorksIO\MutationGate\Adapter\Native\Warm\WarmRun;
use NightWorksIO\MutationGate\Adapter\Native\Warm\Workplace;
use NightWorksIO\MutationGate\Core\Native\Command;
use NightWorksIO\MutationGate\Core\Native\Shell;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ProcessEnds;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

use function sprintf;

/**
 * A shell whose warm workers fork nothing: each worker it is given claims the
 * job's runs in turn, as the real ones do, and answers each through a
 * function of the run, writing what it printed and how it ended where the
 * gate reads them; or refuses, or fails, as it is told. Every other command
 * is answered by a function of the command, as a fresh run.
 */
final class PhpUnitWorkersFake implements Shell
{
    /** @var list<Command> every command it was given, workers included */
    private array $commands = [];

    /** @var list<WarmRun> every run its workers' children ran */
    private array $children = [];

    /**
     * @param Closure(WarmRun): Ran $child how each run in a worker ends
     * @param Closure(Command): Ran $fresh how each fresh run ends
     */
    public function __construct(
        private readonly Closure $child,
        private readonly Closure $fresh,
        private readonly Refusal|Ran|NotGiven $boot = new NotGiven(),
        private readonly int $claims = PHP_INT_MAX,
    ) {
    }

    /** This shell, its workers refusing, or failing as this says, before they claim any run. */
    public function booting(Refusal|Ran $boot): self
    {
        return new self($this->child, $this->fresh, $boot, $this->claims);
    }

    /** This shell, each of its workers claiming at most this many runs, then ending, as at the run's end. */
    public function claimingAtMost(int $claims): self
    {
        return new self($this->child, $this->fresh, $this->boot, $claims);
    }

    public function run(Command $command): Ran
    {
        $this->commands[] = $command;

        return ($this->fresh)($command);
    }

    public function sideBySide(WorkerSlots $slots, Seconds|Unlimited $startingWithin, Command ...$commands): ProcessEnds
    {
        $ends = [];

        foreach ($commands as $command) {
            $this->commands[] = $command;
            $at = array_search($this->worker(), $command->arguments(), strict: true);
            $ends[] = is_int($at) ? $this->worked($command, $at) : ($this->fresh)($command);
        }

        return ProcessEnds::of(...$ends);
    }

    /** @return list<Command> */
    public function commands(): array
    {
        return $this->commands;
    }

    /** @return list<Command> every command it ran fresh, workers left out */
    public function fresh(): array
    {
        return $this->started(worker: false);
    }

    /** @return list<Command> every worker it started */
    public function workers(): array
    {
        return $this->started(worker: true);
    }

    /** @return list<WarmRun> */
    public function children(): array
    {
        return $this->children;
    }

    public function in(string $directory): self
    {
        return $this;
    }

    /** @return list<Command> each command it was given that started a worker, or each that did not */
    private function started(bool $worker): array
    {
        $started = [];

        foreach ($this->commands as $command) {
            $isWorker = in_array($this->worker(), $command->arguments(), strict: true);
            $started = $isWorker === $worker ? [...$started, $command] : $started;
        }

        return $started;
    }

    /** A worker's run, as the real one would leave its workplace. */
    private function worked(Command $command, int $script): Ran
    {
        [, $directory, $told] = array_slice($command->arguments(), $script + 1);
        $workplace = Workplace::at($directory);
        $place = (int) $told;

        if ($this->boot instanceof Refusal) {
            $this->boot->writtenTo($workplace->refused($place));

            return Ran::exited(0, '');
        }

        if ($this->boot instanceof Ran) {
            file_put_contents($workplace->out($place), $this->boot->output());

            return $this->boot;
        }

        $job = Job::read((string) file_get_contents($workplace->job()));
        $claims = Claims::of($workplace, $job);

        for ($left = $this->claims, $next = $claims->next(); is_int($next); $next = --$left > 0 ? $claims->next() : NotGiven::value()) {
            $from = Printed::from($workplace, $place);
            $this->children[] = $job->run($next);
            $ran = ($this->child)($job->run($next));
            file_put_contents($workplace->out($place), $ran->output(), FILE_APPEND);
            $code = $ran->exitCode();
            End::exited(is_int($code) ? $code : 1, 0.5, $from->untilNow($workplace))->writtenTo($workplace->end($next));
        }

        return Ran::exited(0, '');
    }

    /** The warm worker's script PHPUnit's workers run, in this package's own directory. */
    private function worker(): string
    {
        return sprintf('%s/bin/mutation-gate-worker', dirname(__DIR__, 2));
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Native\Warm;

use function file_get_contents;
use function hrtime;
use function is_file;
use function is_int;
use function is_string;

use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Native\WarmBoot;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

/**
 * A warm worker (ADR-0023). Once its script has set PHP up and required the
 * autoloader, as the framework's own script does, it boots as the framework
 * boots before it runs: the settings and variables its config sets for PHP,
 * then its bootstrap. Where the boot passes the guard, it claims the job's
 * runs in turn and forks a child for each, which runs one mutant in a copy of
 * the booted process the worker itself never runs a mutant in, and writes how
 * each ended. Where its script finds PHP cannot fork, or the boot fails the
 * guard, it claims nothing and says why; where the boot itself fails, the
 * worker fails as the framework would. Either way the gate runs those mutants
 * in fresh processes.
 */
final readonly class Worker
{
    private const string UNREAD = 'The worker could not read its job: %s';

    private function __construct(private Workplace $workplace, private int $place)
    {
    }

    /** The worker in this place of the workplace in this directory. */
    public static function at(string $directory, int $place): self
    {
        return new self(Workplace::at($directory), $place);
    }

    /** Boots, then runs each run it claims: the worker's exit code, 0 also where it says why it forked none. */
    public function run(WarmBoot $warmBoot, Forking $forking): int
    {
        $job = $this->job();

        if (! $job instanceof Job) {
            return $this->refused($job);
        }

        $refusal = $this->booted($job, $warmBoot);

        return $refusal instanceof Refusal ? $this->refused($refusal) : $this->claimed($job, $forking);
    }

    /** The worker forking nothing, for this reason, which it writes where the gate reads it. */
    public function refused(Refusal $refusal): int
    {
        $refusal->writtenTo($this->workplace->refused($this->place));

        return 0;
    }

    /** Each run the worker claims, run in turn until none is left or the job's end has come. */
    private function claimed(Job $job, Forking $forking): int
    {
        $claims = Claims::of($this->workplace, $job);

        for ($at = $claims->next(); is_int($at); $at = $claims->next()) {
            $this->ranAt($job, $at, $forking);
        }

        return 0;
    }

    private function job(): Job|Refusal
    {
        $text = is_file($this->workplace->job()) ? file_get_contents($this->workplace->job()) : NotGiven::value();

        try {
            return is_string($text)
                ? Job::read($text)
                : Refusal::guarded(sprintf(self::UNREAD, $this->workplace->job()));
        } catch (NotInShape $unread) {
            return Refusal::guarded(sprintf(self::UNREAD, $unread->getMessage()));
        }
    }

    /** Why the worker may not fork once booted; or nothing, where it may. */
    private function booted(Job $job, WarmBoot $warmBoot): Refusal|NotGiven
    {
        return BootCheck::of($job->mutated(), $this->boot($job, $warmBoot)->done())->refusal();
    }

    /** PHP set up as the framework and its config set it up before a test runs, its bootstrap included. */
    private function boot(Job $job, WarmBoot $warmBoot): Boot
    {
        $boot = Boot::begun($job->autoloader(), $warmBoot->events());
        $bootstrap = $warmBoot->configured($job->config());

        if ($bootstrap instanceof NotGiven) {
            return $boot;
        }

        include_once $bootstrap;

        return $boot->through($bootstrap);
    }

    /** The run at a position, in a child forked for it, and how it ended written beside the job. */
    private function ranAt(Job $job, int $at, Forking $forking): void
    {
        $run = $job->run($at);
        $printed = Printed::from($this->workplace, $this->place);
        $started = hrtime(as_number: true);
        $child = $forking->forked($run);

        if (is_int($child)) {
            Waiting::for($child, $run->limit(), $started, $run->silence())
                ->ended($printed, $this->workplace)
                ->writtenTo($this->workplace->end($at));
        }
    }
}

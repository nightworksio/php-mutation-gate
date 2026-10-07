<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit\Warm;

use function dirname;
use function file_get_contents;
use function hrtime;
use function is_file;
use function is_int;
use function is_string;

use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

/**
 * A warm worker (ADR-0023, decisions 12 and 13). Once its script has set PHP
 * up and required the autoloader, as PHPUnit's own script does, it boots as
 * PHPUnit boots before it runs: the settings and variables PHPUnit's config
 * sets for PHP, then its bootstrap. Where the boot passes the guard, it claims
 * the job's runs in turn and forks a child for each, which runs one mutant in
 * a copy of the booted process the worker itself never runs a mutant in, and
 * writes how each ended. Where its script finds PHP cannot fork, or the boot
 * fails the guard, it claims nothing and says why; where the boot itself
 * fails, the worker fails as PHPUnit would. Either way the gate runs those
 * mutants in fresh processes.
 */
final readonly class Worker
{
    /** The worker's script, in this package's own directory. */
    private const string SCRIPT = '%s/bin/mutation-gate-worker';

    /** How many directories up from this file the package's own directory is. */
    private const int PACKAGE = 4;

    private const string UNREAD = 'The worker could not read its job: %s';

    private function __construct(private Workplace $workplace, private int $place)
    {
    }

    /** The worker in this place of the workplace in this directory. */
    public static function at(string $directory, int $place): self
    {
        return new self(Workplace::at($directory), $place);
    }

    /** The script a worker's PHP runs. */
    public static function script(): string
    {
        return sprintf(self::SCRIPT, dirname(__DIR__, self::PACKAGE));
    }

    /** Boots, then runs each run it claims: the worker's exit code, 0 also where it says why it forked none. */
    public function run(Forking $forking): int
    {
        $job = $this->job();

        if (! $job instanceof Job) {
            return $this->refused($job);
        }

        $refusal = $this->booted($job);

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
        $text = is_file($this->workplace->job()) ? file_get_contents($this->workplace->job()) : false;

        try {
            return is_string($text)
                ? Job::read($text)
                : Refusal::guarded(sprintf(self::UNREAD, $this->workplace->job()));
        } catch (NotInShape $unread) {
            return Refusal::guarded(sprintf(self::UNREAD, $unread->getMessage()));
        }
    }

    /** Why the worker may not fork once booted; or nothing, where it may. */
    private function booted(Job $job): Refusal|NotGiven
    {
        return BootCheck::of($job->mutated(), $this->boot($job)->done())->refusal();
    }

    /** PHP set up as PHPUnit and its config set it up before a test runs, its bootstrap included. */
    private function boot(Job $job): Boot
    {
        $boot = Boot::begun($job->autoloader());
        $config = $job->config();
        $loaded = $config instanceof NotGiven ? NotGiven::value() : Configuring::installed()->loader()->load($config);

        if ($loaded instanceof NotGiven) {
            return $boot;
        }

        Configuring::installed()->handler()->handle($loaded->php());

        if (! $loaded->phpunit()->hasBootstrap()) {
            return $boot;
        }

        include_once $loaded->phpunit()->bootstrap();

        return $boot->through($loaded->phpunit()->bootstrap());
    }

    /** The run at a position, in a child forked for it, and how it ended written beside the job. */
    private function ranAt(Job $job, int $at, Forking $forking): void
    {
        $run = $job->run($at);
        $printed = Printed::from($this->workplace, $this->place);
        $started = hrtime(as_number: true);
        $child = $forking->forked($run);

        if ($child > 0) {
            Waiting::for($child, $run->limit(), $started, $run->silence())
                ->ended($printed, $this->workplace)
                ->writtenTo($this->workplace->end($at));
        }
    }
}

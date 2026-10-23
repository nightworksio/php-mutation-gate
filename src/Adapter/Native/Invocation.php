<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Native;

use NightWorksIO\MutationGate\Adapter\Native\Warm\Workplace;
use NightWorksIO\MutationGate\Core\Native\Command;
use NightWorksIO\MutationGate\Core\Native\Framework;
use NightWorksIO\MutationGate\Core\Native\Variable;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Opcache;
use NightWorksIO\MutationGate\Core\Runner\PcovReach;
use NightWorksIO\MutationGate\Core\Runner\Platform;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * A framework, as the native runner starts it.
 *
 * - For one mutant (ADR-0023): opcache off, so no cached original runs in
 *   the mutated file's place and no mutated file is cached for a later run;
 *   the override prepended; the files the override and the framework's
 *   recorder read and write told; and the framework's own arguments, which
 *   select the covering tests and stop at the first that fails.
 * - For a run of no test, timing a mutant's start-up: as for a mutant, its
 *   mutant the file unchanged, narrowed to no test.
 * - For a warm worker: its script, started as a mutant's own run starts.
 * - For a coverage map: pcov collecting from every tree of the project, and
 *   the framework's run of the tests the run asks for.
 * - For the PHP a mutant runs on: described, with opcache off as a mutant's
 *   run has it.
 */
final readonly class Invocation
{
    /** How PHP's command line turns a switch off, for opcache in every run of a mutant. */
    private const string OFF = '%s=0';

    public function __construct(private Project $project, private string $override, private Framework $framework)
    {
    }

    /** A mutant's own run, judged by the tests the request asks for, each allowed its limit. */
    public function of(MutantFiles $files, MutationRequest $request, Seconds $limit): Command
    {
        return $this->told($files, ...$this->framework->mutant($files->selection(), $request))
            ->withholding($request->withheld())
            ->within($limit);
    }

    /**
     * A warm worker (ADR-0023), its PHP started as a mutant's own run starts
     * it, running the framework's worker script on the project's autoloader
     * in a place of a workplace, and writing its output, and its children's,
     * to files.
     */
    public function worker(string $script, Workplace $workplace, int $place, Withheld $withheld): Command
    {
        return $this->overridden(
            $script,
            $this->project->autoloader(),
            $workplace->directory(),
            sprintf('%d', $place),
        )
            ->withholding($withheld)
            ->writingTo($workplace->out($place), $workplace->err($place));
    }

    /** A run of no test, started as a mutant's own run is, its mutant the file unchanged. */
    public function startingUp(MutantFiles $files, Withheld $withheld): Command
    {
        return $this->told($files, ...$this->framework->startUp($files->selection()))->withholding($withheld);
    }

    /** The PHP a mutant's run starts, as it describes itself, with opcache off as the run has it. */
    public function describing(Withheld $withheld): Command
    {
        return Command::php('-d', sprintf(self::OFF, Opcache::CLI), ...Platform::describing())->withholding($withheld);
    }

    /**
     * The tests a coverage run asks for, under coverage, with their map
     * written to a file and pcov collecting from every tree of the project.
     */
    public function coverage(CoverageRun $request, string $map): Command
    {
        return Command::php(
            ...PcovReach::under($this->project->root(), $this->project->vendor())->options(),
            ...$this->framework->coverage($request, $map),
        )->withholding($request->withheld());
    }

    /** A mutant's own run, overridden, told the files the override and the recorder read and write. */
    private function told(MutantFiles $files, string ...$arguments): Command
    {
        return $this->overridden(...$arguments)
            ->telling(Variable::Results, $files->results())
            ->telling(Variable::Guard, $files->guard())
            ->telling(Variable::Mutant, $files->original())
            ->telling(Variable::Mutated, $files->mutated());
    }

    /** PHP with opcache off and the override prepended, as every run of a mutant starts it, running these. */
    private function overridden(string ...$arguments): Command
    {
        return Command::php(
            '-d',
            sprintf(self::OFF, Opcache::CLI),
            '-d',
            sprintf('auto_prepend_file=%s', $this->override),
            ...$arguments,
        );
    }
}

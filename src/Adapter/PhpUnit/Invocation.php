<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\Opcache;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitOption;
use NightWorksIO\MutationGate\Core\Runner\Platform;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * PHPUnit, as the gate starts it.
 *
 * - For one mutant (ADR-0023 decision 9): opcache off, so no cached original
 *   runs in the mutated file's place and no mutated file is cached for a
 *   later run; the override prepended; the extension recording each test;
 *   only the covering tests selected; and stopped at the first test that
 *   fails or errors, and not at one that is only risky or warns. It collects
 *   no coverage, writes none of the project's logs and leaves PHPUnit's
 *   test run history as it was, whatever the project's config asks, with
 *   the option the installed PHPUnit names that by, so a project that fails
 *   on PHPUnit's own deprecations runs.
 * - For a run of no test, timing a mutant's start-up: as for a mutant, its
 *   mutant the file unchanged, narrowed to no test, and passing with none
 *   run.
 * - For a coverage map: the tests the run asks for, with their map written as
 *   `--coverage-php` writes it, and neither the project's logs nor the test
 *   run history written.
 * - For the suite's groups: listed, without colour, and no test run.
 * - For the PHP a mutant runs on: described, with opcache off as a mutant's
 *   run has it.
 */
final readonly class Invocation
{
    /** How PHP's command line turns a switch off, for opcache in every run of a mutant. */
    private const string OFF = '%s=0';

    /** The option that leaves the test run history as it was, as the installed PHPUnit names it. */
    private PhpUnitOption $history;

    public function __construct(private Project $project, private string $override)
    {
        $this->history = Installed::historyIn($project->installed());
    }

    public function of(
        MutantFiles $files,
        WholeSuite|Group|Filter $judgedBy,
        Seconds $limit,
        Withheld $withheld,
    ): Command {
        return $this->mutant($files, ...$this->judgedBy($judgedBy))->withholding($withheld)->within($limit);
    }

    /** A run of no test, started as a mutant's own run is, its mutant the file unchanged. */
    public function startingUp(MutantFiles $files, Withheld $withheld): Command
    {
        $none = [...$this->judgedBy(Filter::nothing()), PhpUnitOption::DoNotFailOnEmptyTestSuite->value];

        return $this->mutant($files, ...$none)->withholding($withheld);
    }

    /** The suite's groups, listed and no test run. */
    public function listingGroups(Withheld $withheld): Command
    {
        return Command::php(
            $this->project->phpunit(),
            PhpUnitOption::ListGroups->value,
            PhpUnitOption::NoColors->value,
        )->withholding($withheld);
    }

    /** The PHP a mutant's run starts, as it describes itself, with opcache off as the run has it. */
    public function describing(Withheld $withheld): Command
    {
        return Command::php('-d', sprintf(self::OFF, Opcache::CLI), ...Platform::describing())->withholding($withheld);
    }

    /** The tests a coverage run asks for, under coverage, with their map written to a file. */
    public function coverage(CoverageRun $request, string $map): Command
    {
        return Command::php(
            $this->project->phpunit(),
            sprintf('%s=%s', PhpUnitOption::CoveragePhp->value, $map),
            PhpUnitOption::NoLogging->value,
            $this->history->value,
            PhpUnitOption::NoProgress->value,
            ...$this->judgedBy($request->tests()),
        )
            ->withholding($request->withheld());
    }

    /** A mutant's own run: opcache off, the override, the extension and its selection, with these options after. */
    private function mutant(MutantFiles $files, string ...$options): Command
    {
        return Command::php(
            '-d',
            sprintf(self::OFF, Opcache::CLI),
            '-d',
            sprintf('auto_prepend_file=%s', $this->override),
            $this->project->phpunit(),
            PhpUnitOption::Extension->value,
            Extension::class,
            $files->selection(),
            PhpUnitOption::StopOnError->value,
            PhpUnitOption::StopOnFailure->value,
            PhpUnitOption::NoCoverage->value,
            PhpUnitOption::NoLogging->value,
            $this->history->value,
            PhpUnitOption::NoProgress->value,
            ...$options,
        )
            ->telling(Variable::Results, $files->results())
            ->telling(Variable::Guard, $files->guard())
            ->telling(Variable::Mutant, $files->original())
            ->telling(Variable::Mutated, $files->mutated());
    }

    /** @return list<string> the options that keep a run to the tests that judge the unit */
    private function judgedBy(WholeSuite|Group|Filter $judgedBy): array
    {
        return match (true) {
            $judgedBy instanceof Group => [PhpUnitOption::Group->value, $judgedBy->name()],
            $judgedBy instanceof Filter => [PhpUnitOption::Filter->value, $judgedBy->pattern()],
            default => [],
        };
    }
}

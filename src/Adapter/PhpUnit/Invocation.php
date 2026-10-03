<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\Opcache;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitOption;
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
 *   result cache as it was, whatever the project's config asks.
 * - For a coverage map: the tests the run asks for, with their map written as
 *   `--coverage-php` writes it, and neither the project's logs nor the result
 *   cache written.
 */
final readonly class Invocation
{
    public function __construct(private Project $project, private string $override)
    {
    }

    public function of(
        MutantFiles $files,
        WholeSuite|Group|Filter $judgedBy,
        Seconds $limit,
        Withheld $withheld,
    ): Command {
        return Command::php(
            '-d',
            sprintf('%s=0', Opcache::CLI),
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
            PhpUnitOption::DoNotCacheResult->value,
            PhpUnitOption::NoProgress->value,
            ...$this->judgedBy($judgedBy),
        )
            ->telling(Variable::Results, $files->results())
            ->telling(Variable::Guard, $files->guard())
            ->telling(Variable::Mutant, $files->original())
            ->telling(Variable::Mutated, $files->mutated())
            ->withholding($withheld)
            ->within($limit);
    }

    /** The tests a coverage run asks for, under coverage, with their map written to a file. */
    public function coverage(CoverageRun $request, string $map): Command
    {
        return Command::php(
            $this->project->phpunit(),
            sprintf('%s=%s', PhpUnitOption::CoveragePhp->value, $map),
            PhpUnitOption::NoLogging->value,
            PhpUnitOption::DoNotCacheResult->value,
            PhpUnitOption::NoProgress->value,
            ...$this->judgedBy($request->tests()),
        )
            ->withholding($request->withheld());
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

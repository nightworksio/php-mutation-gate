<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Codeception;

use function array_map;
use function file_get_contents;
use function is_file;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Native\Command;
use NightWorksIO\MutationGate\Core\Native\Framework;
use NightWorksIO\MutationGate\Core\Native\ListedTests;
use NightWorksIO\MutationGate\Core\Native\Selection;
use NightWorksIO\MutationGate\Core\Native\Shell;
use NightWorksIO\MutationGate\Core\Native\TestLayout;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\CodeceptionConfig;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Program;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestListing;

/**
 * Codeception, as the native runner runs it (ADR-0027): installed in the
 * project's vendor directory, and started on its own script with the gate's
 * extension enabled and told what to do (see CommandLine). Its tests are
 * selected by their ids, from a file the extension reads, and listed by a
 * run of none. It has no warm workers, its config sets no PHP setting, it
 * runs every suite in one run, and the gate writes no stub of its tests.
 */
final readonly class CodeceptionFramework implements Framework
{
    /** The file a mutant's run lists the tests it selects in. */
    private const string SELECTED = 'selected.txt';

    /** How a mutant's run names that file to the extension: as it is. */
    private const string AS_IT_IS = '%s';

    private CommandLine $commandLine;

    public function __construct(private Installation $installation, private TestLayout $tests)
    {
        $this->commandLine = new CommandLine($installation->codecept(), $installation->vendor());
    }

    public function program(): Program
    {
        return Program::Codeception;
    }

    public function runner(): BuiltinRunner
    {
        return BuiltinRunner::Codeception;
    }

    /** Codeception and php-code-coverage, at a version the runner supports. */
    public function versions(): Versions|CannotJudge
    {
        return Installed::versionsIn($this->installation->installed());
    }

    public function isInstalled(): bool
    {
        return $this->installation->hasCodeception();
    }

    public function in(Path $package, TestLayout $tests): self
    {
        return new self($this->installation->in($package), $tests);
    }

    /** Codeception's config in the project's root, under either name. */
    public function definitions(): Paths
    {
        return CodeceptionConfig::candidatesIn(Path::root());
    }

    /** Codeception's config sets no PHP setting the gate reads. */
    public function errorDisplay(): NotGiven
    {
        return NotGiven::value();
    }

    /** No warm worker boots Codeception, so none reads its config. */
    public function config(): NotGiven
    {
        return NotGiven::value();
    }

    public function script(): string
    {
        return $this->installation->codecept();
    }

    public function workerScript(): NotGiven
    {
        return NotGiven::value();
    }

    /** Exactly these tests, by their ids, which the extension reads from their file. */
    public function selection(TestIds $tests): Selection
    {
        return Selection::exactly(
            self::SELECTED,
            array_map(static fn(TestId $test): string => $test->value(), [...$tests]),
            self::AS_IT_IS,
        );
    }

    public function mutant(string $selection, MutationRequest $request): array
    {
        return $this->commandLine->mutant($selection, $request);
    }

    public function startUp(string $selection): array
    {
        return $this->commandLine->startUp($selection);
    }

    public function coverage(CoverageRun $request, string $map): array
    {
        return $this->commandLine->coverage($request, $map);
    }

    /** Every test and its groups, as the extension lists them into a file; Codeception runs every suite. */
    public function listing(Shell $shell, Withheld $withheld, Suites $suites, string $file): TestListing|CannotJudge
    {
        $ran = $shell->run(Command::php(...$this->commandLine->listing($file))->withholding($withheld));

        return ListedTests::read($ran, is_file($file) ? file_get_contents($file) : false, $file, Program::Codeception);
    }

    /** Codeception says nothing of its process ending mid-test beyond what PHP says. */
    public function endedHidden(string $output, bool $configuredToHide): bool
    {
        return false;
    }

    public function tests(): TestLayout
    {
        return $this->tests;
    }

    /** Codeception's tests, among them Cests, which are in no style a stub is written in. */
    public function testStyle(): NotGiven
    {
        return NotGiven::value();
    }
}

<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpSpec;

use function array_map;
use function file_get_contents;
use function is_file;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Native\Command;
use NightWorksIO\MutationGate\Core\Native\Framework;
use NightWorksIO\MutationGate\Core\Native\Selection;
use NightWorksIO\MutationGate\Core\Native\Shell;
use NightWorksIO\MutationGate\Core\Native\TestLayout;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\PhpSpecConfig;
use NightWorksIO\MutationGate\Core\Runner\Program;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestListing;
use NightWorksIO\MutationGate\Core\ThisPackage;

/**
 * PhpSpec, as the native runner runs it (ADR-0027): installed in the
 * project's vendor directory, and started through the gate's launcher (see
 * Launch), which loads the gate's extension into PhpSpec before it reads the
 * project's config. Its examples are selected by their ids, which no line
 * break is ever in, and listed by a run of none. It has no warm workers,
 * its config sets no PHP setting, and it runs every suite in one run.
 */
final readonly class PhpSpecFramework implements Framework
{
    /** The gate's launcher, in this package's own directory. */
    private const string LAUNCHER = 'mutation-gate-phpspec';

    /** The file a mutant's run lists the examples it selects in. */
    private const string EXAMPLES = 'examples.txt';

    private CommandLine $commandLine;

    public function __construct(private Installation $installation, private TestLayout $tests)
    {
        $this->commandLine = new CommandLine(ThisPackage::script(self::LAUNCHER), $installation->autoloader());
    }

    public function program(): Program
    {
        return Program::PhpSpec;
    }

    public function runner(): BuiltinRunner
    {
        return BuiltinRunner::PhpSpec;
    }

    /** PhpSpec and php-code-coverage, at a version the runner supports. */
    public function versions(): Versions|CannotJudge
    {
        return Installed::versionsIn($this->installation->installed());
    }

    public function isInstalled(): bool
    {
        return $this->installation->hasPhpSpec();
    }

    public function in(Path $package, TestLayout $tests): self
    {
        return new self($this->installation->in($package), $tests);
    }

    /** The PhpSpec config in the project's root, whichever name it has. */
    public function definitions(): Paths
    {
        return PhpSpecConfig::candidatesIn(Path::root());
    }

    /** PhpSpec's config sets no PHP setting. */
    public function errorDisplay(): NotGiven
    {
        return NotGiven::value();
    }

    /** No warm worker boots PhpSpec, so none reads its config. */
    public function config(): NotGiven
    {
        return NotGiven::value();
    }

    public function script(): string
    {
        return ThisPackage::script(self::LAUNCHER);
    }

    public function workerScript(): NotGiven
    {
        return NotGiven::value();
    }

    /** Exactly these examples, by their ids, which the launcher reads from their file. */
    public function selection(TestIds $tests): Selection
    {
        return Selection::exactly(
            self::EXAMPLES,
            array_map(static fn(TestId $test): string => $test->value(), [...$tests]),
            LaunchOption::Examples->with('%s'),
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

    /** Every example and its holding groups, as the launcher lists them into a file; PhpSpec runs every suite. */
    public function listing(Shell $shell, Withheld $withheld, Suites $suites, string $file): TestListing|CannotJudge
    {
        $ran = $shell->run(Command::php(...$this->commandLine->listing($file))->withholding($withheld));

        return ListedExamples::in($ran, is_file($file) ? file_get_contents($file) : false, $file);
    }

    /** PhpSpec says nothing of its process ending mid-example beyond what PHP says. */
    public function endedHidden(string $output, bool $configuredToHide): bool
    {
        return false;
    }

    public function tests(): TestLayout
    {
        return $this->tests;
    }

    /** PhpSpec's specs, which are in no style a stub is written in. */
    public function testStyle(): NotGiven
    {
        return NotGiven::value();
    }
}

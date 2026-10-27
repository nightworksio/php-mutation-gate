<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Testo;

use function array_map;
use function file_get_contents;
use function is_file;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Native\Command;
use NightWorksIO\MutationGate\Core\Native\CoverageReport;
use NightWorksIO\MutationGate\Core\Native\Framework;
use NightWorksIO\MutationGate\Core\Native\ListedTests;
use NightWorksIO\MutationGate\Core\Native\Selection;
use NightWorksIO\MutationGate\Core\Native\Shell;
use NightWorksIO\MutationGate\Core\Native\TestLayout;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Program;
use NightWorksIO\MutationGate\Core\Runner\TestoConfig;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestListing;
use NightWorksIO\MutationGate\Core\ThisPackage;

/**
 * Testo, as the native runner runs it (ADR-0027): installed in the project's
 * vendor directory, and started through the gate's launcher (see Launch),
 * which runs Testo over the project's own `testo.php` with the gate's plugin
 * added. Its tests are selected by their addresses, which no line break is
 * ever in, listed by a run of none, and measured with Testo's own XML
 * coverage, which the plugin times. It has no warm workers, its config sets
 * no PHP setting, and no stub is written in its style.
 */
final readonly class TestoFramework implements Framework
{
    /** The gate's launcher, in this package's own directory. */
    private const string LAUNCHER = 'mutation-gate-testo';

    /** The file a mutant's run lists the tests it selects in. */
    private const string TESTS = 'tests.txt';

    private CommandLine $commandLine;

    public function __construct(private Installation $installation, private TestLayout $tests)
    {
        $this->commandLine = new CommandLine(ThisPackage::script(self::LAUNCHER), $installation->autoloader());
    }

    public function program(): Program
    {
        return Program::Testo;
    }

    public function runner(): BuiltinRunner
    {
        return BuiltinRunner::Testo;
    }

    /** Testo and its coverage plugin, Testo of the series the runner drives. */
    public function versions(): Versions|CannotJudge
    {
        return Installed::versionsIn($this->installation->installed());
    }

    public function isInstalled(): bool
    {
        return $this->installation->hasTesto();
    }

    public function in(Path $package, TestLayout $tests): self
    {
        return new self($this->installation->in($package), $tests);
    }

    /** Testo's config in the project's root. */
    public function definitions(): Paths
    {
        return TestoConfig::candidatesIn(Path::root());
    }

    /** Testo's config sets no PHP setting. */
    public function errorDisplay(): NotGiven
    {
        return NotGiven::value();
    }

    /** No warm worker boots Testo, so none reads its config. */
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

    /** Exactly these tests, by their addresses, which the launcher reads from their file. */
    public function selection(TestIds $tests): Selection
    {
        return Selection::exactly(
            self::TESTS,
            array_map(static fn(TestId $test): string => $test->value(), [...$tests]),
            LaunchOption::Tests->with('%s'),
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

    /** Testo's XML coverage, which times no test, beside the times the gate's plugin wrote. */
    public function report(): CoverageReport
    {
        return CoverageReport::Xml;
    }

    public function coverage(CoverageRun $request, string $map): array
    {
        return $this->commandLine->coverage($request, $map);
    }

    /** These suites' tests and their holding groups, as the launcher lists them into a file. */
    public function listing(Shell $shell, Withheld $withheld, Suites $suites, string $file): TestListing|CannotJudge
    {
        $ran = $shell->run(Command::php(...$this->commandLine->listing($suites, $file))->withholding($withheld));

        return ListedTests::read($ran, is_file($file) ? file_get_contents($file) : false, $file, Program::Testo);
    }

    /** Testo says nothing of its process ending mid-test beyond what PHP says. */
    public function endedHidden(string $output, bool $configuredToHide): bool
    {
        return false;
    }

    public function tests(): TestLayout
    {
        return $this->tests;
    }

    /** No stub is written in Testo's style. */
    public function testStyle(): NotGiven
    {
        return NotGiven::value();
    }
}

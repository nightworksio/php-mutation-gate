<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function array_map;
use function file_get_contents;
use function is_file;

use NightWorksIO\MutationGate\Core\Assertion\AssertionStyle;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Workspace;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Native\Command;
use NightWorksIO\MutationGate\Core\Native\CoverageReport;
use NightWorksIO\MutationGate\Core\Native\Framework;
use NightWorksIO\MutationGate\Core\Native\Selection as SelectionFile;
use NightWorksIO\MutationGate\Core\Native\Shell;
use NightWorksIO\MutationGate\Core\Native\TestLayout;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\ErrorDisplay;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitConfig;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitTestList;
use NightWorksIO\MutationGate\Core\Runner\PrematureEnd;
use NightWorksIO\MutationGate\Core\Runner\Program;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestListing;
use NightWorksIO\MutationGate\Core\ThisPackage;

/**
 * PHPUnit, as the native runner runs it (ADR-0023): installed in the
 * project's vendor directory, started on its command line (see CommandLine)
 * with the extension recording each test, and booted by its warm workers
 * (see PhpUnitBoot).
 */
final readonly class PhpUnitFramework implements Framework
{
    /** The warm worker's script, in this package's own directory. */
    private const string WORKER = 'mutation-gate-worker';

    private const string UNPLACED
        = 'A test that covers it has a line break in its name, and no test file found holds every test that covers it.';

    private CommandLine $commandLine;

    public function __construct(private Installation $installation, private TestLayout $tests)
    {
        $history = Installed::historyIn($installation->installed());
        $this->commandLine = new CommandLine(
            $installation->phpunit(),
            $history,
            $installation->absolute(Workspace::phpUnitCache()),
        );
    }

    public function program(): Program
    {
        return Program::PhpUnit;
    }

    public function runner(): BuiltinRunner
    {
        return BuiltinRunner::PhpUnit;
    }

    /** PHPUnit and php-code-coverage, at a version the runner supports. */
    public function versions(): Versions|CannotJudge
    {
        return Installed::versionsIn($this->installation->installed());
    }

    public function isInstalled(): bool
    {
        return $this->installation->hasPhpUnit();
    }

    public function in(Path $package, TestLayout $tests): self
    {
        return new self($this->installation->in($package), $tests);
    }

    /** The PHPUnit config in the project's root, whichever name it has. */
    public function definitions(): Paths
    {
        return PhpUnitConfig::candidatesIn(Path::root());
    }

    public function errorDisplay(): ErrorDisplay|NotGiven
    {
        return $this->installation->errorDisplay();
    }

    public function config(): string|NotGiven
    {
        return $this->installation->config();
    }

    public function script(): string
    {
        return $this->installation->phpunit();
    }

    public function workerScript(): string
    {
        return ThisPackage::script(self::WORKER);
    }

    /**
     * By the tests' ids where PHPUnit can read each back; by the files that
     * declare their classes where it cannot, which must hold every one.
     */
    public function selection(TestIds $tests): SelectionFile|Reason
    {
        $by = Selection::of($tests);

        if ($by === Selection::Ids) {
            return $by->listing(array_map(static fn(TestId $test): string => $test->value(), [...$tests]));
        }

        $classes = $this->tests->declaring($tests);

        return $classes->placeEach($tests)
            ? $by->listing(array_map($this->installation->absolute(...), [...$classes->files()]))
            : Reason::that(self::UNPLACED);
    }

    public function mutant(string $selection, MutationRequest $request): array
    {
        return $this->commandLine->mutant($selection, $request);
    }

    public function startUp(string $selection): array
    {
        return $this->commandLine->startUp($selection);
    }

    /** php-code-coverage's PHP report, which times each test. */
    public function report(): CoverageReport
    {
        return CoverageReport::Php;
    }

    public function coverage(CoverageRun $request, string $map): array
    {
        return $this->commandLine->coverage($request, $map);
    }

    /** These suites' tests and their groups, as PHPUnit lists them into a file; or why it lists none. */
    public function listing(Shell $shell, Withheld $withheld, Suites $suites, string $file): TestListing|CannotJudge
    {
        $ran = $shell->run(Command::php(...$this->commandLine->listing($suites, $file))->withholding($withheld));
        $xml = is_file($file) ? file_get_contents($file) : false;

        return PhpUnitTestList::listedIn($ran, $xml, $file, Program::PhpUnit);
    }

    /** As PHPUnit prints a premature end of its process (ADR-0004). */
    public function endedHidden(string $output, bool $configuredToHide): bool
    {
        return PrematureEnd::hidingIn($output, $configuredToHide);
    }

    public function tests(): TestLayout
    {
        return $this->tests;
    }

    /** PHPUnit's test classes. */
    public function testStyle(): AssertionStyle
    {
        return AssertionStyle::PhpUnit;
    }
}

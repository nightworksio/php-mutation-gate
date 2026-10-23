<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Native\Framework;
use NightWorksIO\MutationGate\Core\Native\Selection;
use NightWorksIO\MutationGate\Core\Native\Shell;
use NightWorksIO\MutationGate\Core\Native\TestLayout;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\ErrorDisplay;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Program;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestListing;

/** A framework as another is in every way but one: it has no warm workers. */
final readonly class WithoutWorkers implements Framework
{
    public function __construct(private Framework $framework)
    {
    }

    public function program(): Program
    {
        return $this->framework->program();
    }

    public function runner(): BuiltinRunner
    {
        return $this->framework->runner();
    }

    public function versions(): Versions|CannotJudge
    {
        return $this->framework->versions();
    }

    public function isInstalled(): bool
    {
        return $this->framework->isInstalled();
    }

    public function in(Path $package, Paths $tests): self
    {
        return new self($this->framework->in($package, $tests));
    }

    public function definitions(): Paths
    {
        return $this->framework->definitions();
    }

    public function errorDisplay(): ErrorDisplay|NotGiven
    {
        return $this->framework->errorDisplay();
    }

    public function config(): string|NotGiven
    {
        return $this->framework->config();
    }

    public function script(): string
    {
        return $this->framework->script();
    }

    public function workerScript(): NotGiven
    {
        return NotGiven::value();
    }

    public function selection(TestIds $tests): Selection|Reason
    {
        return $this->framework->selection($tests);
    }

    public function mutant(string $selection, MutationRequest $request): array
    {
        return $this->framework->mutant($selection, $request);
    }

    public function startUp(string $selection): array
    {
        return $this->framework->startUp($selection);
    }

    public function coverage(CoverageRun $request, string $map): array
    {
        return $this->framework->coverage($request, $map);
    }

    public function listing(Shell $shell, Withheld $withheld, Suites $suites, string $file): TestListing|CannotJudge
    {
        return $this->framework->listing($shell, $withheld, $suites, $file);
    }

    public function endedHidden(string $output, bool $configuredToHide): bool
    {
        return $this->framework->endedHidden($output, $configuredToHide);
    }

    public function tests(): TestLayout
    {
        return $this->framework->tests();
    }
}

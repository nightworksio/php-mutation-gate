<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\CoverageFailure;
use NightWorksIO\MutationGate\Core\Runner\Program;

it('says whose coverage run failed, and what it printed', function (): void {
    expect(CoverageFailure::said(Program::PhpUnit, "Tests: 1 failed\n"))
        ->toEqual(CannotJudge::because("PHPUnit's coverage run failed. PHPUnit said:\nTests: 1 failed\n"));
});

it('says that the project has none of the configs a program reads, where its coverage run failed without one', function (): void {
    $looked = Paths::of(Path::of('phpunit.xml'), Path::of('phpunit.dist.xml'), Path::of('phpunit.xml.dist'));

    expect(CoverageFailure::unconfigured(Program::PhpUnit, $looked, "Usage:\n"))->toEqual(CannotJudge::because(
        "PHPUnit's coverage run failed, and the project has none of its configs: no `phpunit.xml`, "
        . "`phpunit.dist.xml` or `phpunit.xml.dist` in its root. PHPUnit said:\nUsage:\n",
    ));
});

<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Runner\CoverageFailure;
use NightWorksIO\MutationGate\Core\Runner\Program;

it('says whose coverage run failed, and what it printed', function (): void {
    expect(CoverageFailure::said(Program::PhpUnit, "Tests: 1 failed\n"))
        ->toEqual(CannotJudge::because("PHPUnit's coverage run failed. PHPUnit said:\nTests: 1 failed\n"));
});

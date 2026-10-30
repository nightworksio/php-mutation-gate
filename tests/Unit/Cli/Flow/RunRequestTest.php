<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Project\TestSuite;
use NightWorksIO\MutationGate\Cli\Flow\RunRequest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\DeclaredSuites;
use NightWorksIO\MutationGate\Core\Test\SuiteDirectory;
use NightWorksIO\MutationGate\Core\Test\TestCases;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Tests\Support\Flows;

it('tells the runner which files the suite runs as files of test cases', function (): void {
    $specs = SuiteDirectory::of(Path::of('tests'), 'Spec.php');
    $suite = TestSuite::of([$specs], SuiteDirectory::conventional(), Paths::none(), Paths::of(Path::of('tests/Fixture')), DeclaredSuites::none());
    $request = RunRequest::of(Flows::adapters(Flows::project(), [], $suite), Flows::settings(), Paths::of(Path::of('src/Money.php')), WholeSuite::tests());

    expect($request->narrowing()->testCases())->toEqual(TestCases::of(Paths::none(), Paths::of(Path::of('tests/Fixture')), $specs));
});

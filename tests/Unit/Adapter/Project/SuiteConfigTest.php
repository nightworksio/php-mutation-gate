<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Project\DirectoryGlob;
use NightWorksIO\MutationGate\Adapter\Project\PhpSpecSuite;
use NightWorksIO\MutationGate\Adapter\Project\SuiteConfig;
use NightWorksIO\MutationGate\Adapter\Project\TestSuite;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Runner\PhpSpecConfig;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitConfig;
use NightWorksIO\MutationGate\Core\Test\SuiteDirectory;

it('reads PhpSpec\'s config for the PhpSpec runner, and PHPUnit\'s for every other', function (): void {
    expect(array_map(SuiteConfig::ofRunner(...), ['phpspec', 'phpunit', 'pest', 'infection', 'acme']))
        ->toBe([SuiteConfig::PhpSpec, SuiteConfig::PhpUnit, SuiteConfig::PhpUnit, SuiteConfig::PhpUnit, SuiteConfig::PhpUnit]);
});

it('looks for each framework\'s config where it does, reads it as it does, and knows its convention', function (): void {
    $glob = DirectoryGlob::from(Root::here());
    $phpunit = SuiteConfig::PhpUnit->declaredIn(Contents::of('<phpunit><testsuites><testsuite name="u"><directory>t</directory></testsuite></testsuites></phpunit>'), Path::of('phpunit.xml'), $glob);
    $phpspec = SuiteConfig::PhpSpec->declaredIn(Contents::of("suites:\n  a:\n    spec_path: s"), Path::of('phpspec.yml'), $glob);

    expect(SuiteConfig::PhpUnit->candidatesIn(Path::root()))->toEqual(PhpUnitConfig::candidatesIn(Path::root()))
        ->and(SuiteConfig::PhpSpec->candidatesIn(Path::root()))->toEqual(PhpSpecConfig::candidatesIn(Path::root()))
        ->and($phpunit instanceof TestSuite ? $phpunit->directories() : $phpunit)->toEqual([SuiteDirectory::of(Path::of('t'), '')])
        ->and($phpspec instanceof TestSuite ? $phpspec->directories() : $phpspec)->toEqual([SuiteDirectory::of(Path::of('s/spec'), 'Spec.php')])
        ->and(SuiteConfig::PhpUnit->conventional())->toEqual(TestSuite::conventional(SuiteDirectory::conventional()))
        ->and(SuiteConfig::PhpSpec->conventional())->toEqual(PhpSpecSuite::conventional());
});

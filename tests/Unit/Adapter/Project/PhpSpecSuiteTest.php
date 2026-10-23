<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Project\PhpSpecSuite;
use NightWorksIO\MutationGate\Adapter\Project\TestSuite;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\DeclaredSuites;
use NightWorksIO\MutationGate\Core\Test\SuiteDirectory;

$suiteOf = static fn(string $config): TestSuite|CannotJudge => PhpSpecSuite::declaredIn(Contents::of($config), Path::of('phpspec.yml'));

it('keeps each suite\'s specs under its spec path, prefix and namespace less its PSR-4 prefix, each read without its outer separators, as PhpSpec\'s locator does, in files ending in Spec.php', function () use ($suiteOf): void {
    $suite = $suiteOf(<<<'YAML'
        suites:
          billing:
            namespace: Acme\Billing
            spec_path: packages/billing
          tax:
            namespace: Acme\Tax\Rates
            psr4_prefix: Acme\Tax
            spec_path: '%paths.config%/modules'
            spec_prefix: specs
          plain:
            namespace: Plain
          flat:
            namespace: Flat
            psr4_prefix: Flat
            spec_prefix: ''
          elsewhere:
            namespace: Elsewhere
            psr4_prefix: Other
          wrapped:
            namespace: '\Wrapped\Money\'
            psr4_prefix: '\Wrapped\'
        YAML);

    expect($suite instanceof TestSuite ? $suite->directories() : $suite)->toEqual([
        SuiteDirectory::of(Path::of('packages/billing/spec/Acme/Billing'), 'Spec.php'),
        SuiteDirectory::of(Path::of('modules/specs/Rates'), 'Spec.php'),
        SuiteDirectory::of(Path::of('spec/Plain'), 'Spec.php'),
        SuiteDirectory::of(Path::of('.'), 'Spec.php'),
        SuiteDirectory::of(Path::of('spec/Elsewhere'), 'Spec.php'),
        SuiteDirectory::of(Path::of('spec/Money'), 'Spec.php'),
    ])
        ->and($suite instanceof TestSuite ? $suite->suites() : $suite)->toEqual(DeclaredSuites::none())
        ->and($suite instanceof TestSuite && $suite->holdsTestCase(Path::of('spec/Plain/MoneySpec.php')))->toBeTrue()
        ->and($suite instanceof TestSuite && $suite->holdsTestCase(Path::of('spec/Plain/Money.php')))->toBeFalse();
});

it('keeps the specs in spec where the config declares no suite, or a suite in no shape it reads', function (string $config) use ($suiteOf): void {
    $suite = $suiteOf($config);

    expect($suite instanceof TestSuite ? $suite->paths() : $suite)->toEqual(Paths::of(Path::of('spec')));
})->with([
    'no suites' => ['formatter.name: pretty'],
    'nothing' => [''],
    'suites that are not a map' => ['suites: everything'],
    'a suite whose settings are not text' => ["suites:\n  a:\n    spec_path: [x]\n    spec_prefix: 3"],
]);

it('keeps the specs in spec where there is no config', function (): void {
    expect(PhpSpecSuite::conventional()->directories())->toEqual([SuiteDirectory::of(Path::of('spec'), 'Spec.php')]);
});

it('cannot read a config that is not YAML', function () use ($suiteOf): void {
    $suite = $suiteOf("suites: [unclosed\n");

    expect($suite instanceof CannotJudge ? $suite->why() : $suite)->toStartWith('phpspec.yml is not YAML, so the specs it declares cannot be read: ');
});

<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\OwnConfig;
use NightWorksIO\MutationGate\Adapter\Infection\Project;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A config read from its text, which a test expects the gate to accept. */
function ownConfig(string $text): OwnConfig
{
    $config = OwnConfig::read('infection.json5', $text);

    return $config instanceof OwnConfig ? $config : throw new RuntimeException($config->why());
}

/**
 * The config the gate generates from a project's, decoded.
 *
 * @return array<mixed>
 */
function ownGenerated(OwnConfig $config, Mutators $mutators): array
{
    $project = Project::at(Root::of('/project'), Paths::none(), Path::of('.gate'));
    $decoded = json_decode($config->generated($project, ['/project/src'], Seconds::of(12.5), $mutators), associative: true);

    return is_array($decoded) ? $decoded : [];
}

it('reads the first config file Infection would, and a config that sets nothing without one', function (): void {
    $root = Scratch::directory();
    $project = Project::at(Root::of($root), Paths::none(), Path::of('.gate'));
    $none = OwnConfig::in($project);
    Scratch::write($root, 'infection.json.dist', '{"initialTestsPhpOptions": "-d dist=1"}');
    Scratch::write($root, 'infection.json', "{\n  // JSON5\n  initialTestsPhpOptions: '-d json=1',\n}");
    $found = OwnConfig::in($project);

    expect($none instanceof OwnConfig ? [$none->name(), $none->phpOptions(), $none->mutators()->patterns()] : [])
        ->toBe(['infection.json5', [], []])
        ->and($found instanceof OwnConfig ? [$found->name(), $found->phpOptions()] : [])
        ->toBe(['infection.json', ['-d', 'json=1']]);
});

it('cannot judge a config that is not JSON5, or does not hold an object', function (): void {
    expect(OwnConfig::read('infection.json5', '{"source":'))->toEqual(CannotJudge::because(
        'infection.json5 cannot be read, so the gate cannot say what Infection would mutate: '
        . 'Unexpected EOF at line 1 column 11 of the JSON5 data',
    ))->and(OwnConfig::read('infection.json5', '"text"'))->toEqual(CannotJudge::because(
        'infection.json5 does not hold an object, so the gate cannot say what Infection would mutate.',
    ));
});

it('refuses a test framework other than PHPUnit, and PHPUnit that is Pest', function (): void {
    expect(OwnConfig::read('infection.json5', '{"testFramework": "phpspec"}'))->toEqual(CannotJudge::because(
        'infection.json5 sets testFramework to phpspec. The gate runs Infection with PHPUnit alone, so it cannot judge that suite.',
    ))->and(OwnConfig::read('infection.json', '{"phpUnit": {"customPath": "vendor/bin/Pest"}}'))->toEqual(CannotJudge::because(
        'infection.json points phpUnit.customPath at vendor/bin/Pest. Infection cannot run Pest tests: use the Pest runner.',
    ))->and(OwnConfig::read('infection.json5', '{"testFramework": "phpunit", "phpUnit": {"customPath": "tools/phpunit"}}'))
        ->toBeInstanceOf(OwnConfig::class);
});

it('runs the project\'s PHPUnit from its config directory, or its own from the root', function (): void {
    $project = Project::at(Root::of('/project'), Paths::none(), Path::of('.gate'));
    $custom = ownConfig('{"phpUnit": {"customPath": "tools/phpunit.phar", "configDir": "config"}}');
    $plain = ownConfig('{}');

    expect($custom->phpunit($project))->toBe('/project/tools/phpunit.phar')
        ->and($custom->configDirectory($project))->toBe('/project/config')
        ->and($plain->phpunit($project))->toBe('/project/vendor/bin/phpunit')
        ->and($plain->configDirectory($project))->toBe('/project');
});

it('splits the project\'s PHPUnit arguments as Infection does, the deprecated spelling where the other is not set', function (): void {
    expect(ownConfig('{"testFrameworkExtraArgs": "--testsuite=unit --filter=\"a b\""}')->extraArguments())
        ->toBe(['--testsuite=unit', '--filter=a b'])
        ->and(ownConfig('{"testFrameworkOptions": "--stop-on-failure"}')->extraArguments())->toBe(['--stop-on-failure'])
        ->and(ownConfig('{"testFrameworkExtraArgs": "--a", "testFrameworkOptions": "--b"}')->extraArguments())->toBe(['--a'])
        ->and(ownConfig('{}')->extraArguments())->toBe([]);
});

it('keeps the project\'s mutators, bootstrap, PHPUnit and static analysis, and writes over or leaves out the rest', function (): void {
    $config = ownConfig((string) json_encode([
        '$schema' => 'vendor/infection/infection/resources/schema.json',
        'source' => ['directories' => ['app'], 'excludes' => ['Legacy']],
        'logs' => ['html' => 'infection.html', 'github' => true],
        'timeout' => 3,
        'tmpDir' => 'var',
        'threads' => 'max',
        'minMsi' => 90,
        'minCoveredMsi' => 95,
        'maxTimeouts' => 2,
        'timeoutsAsEscaped' => true,
        'ignoreMsiWithNoMutations' => true,
        'testFrameworkExtraArgs' => '--testsuite=unit',
        'mutators' => ['@default' => true, 'Plus' => ['ignore' => ['A::b']]],
        'bootstrap' => 'tests/bootstrap.php',
        'initialTestsPhpOptions' => '-d memory_limit=1G',
        'testFramework' => 'phpunit',
        'staticAnalysisTool' => 'phpstan',
        'staticAnalysisToolOptions' => '--memory-limit=1G',
        'phpUnit' => ['configDir' => 'config', 'customPath' => 'tools/phpunit'],
        'phpStan' => ['configDir' => 'build'],
        'mago' => ['customPath' => '/usr/bin/mago'],
    ]));

    expect(ownGenerated($config, Mutators::all()))->toBe([
        'mutators' => ['@default' => true, 'Plus' => ['ignore' => ['A::b']]],
        'bootstrap' => 'tests/bootstrap.php',
        'initialTestsPhpOptions' => '-d memory_limit=1G',
        'testFramework' => 'phpunit',
        'staticAnalysisTool' => 'phpstan',
        'staticAnalysisToolOptions' => '--memory-limit=1G',
        'phpUnit' => ['configDir' => '/project/config', 'customPath' => '/project/tools/phpunit'],
        'phpStan' => ['configDir' => '/project/build'],
        'mago' => ['customPath' => '/usr/bin/mago'],
        'source' => ['directories' => ['/project/src']],
        'timeout' => 12.5,
        'tmpDir' => '/project/.gate/infection/tmp',
        'logs' => [
            'json' => '/project/.gate/infection/logs/infection.json',
            'text' => '/project/.gate/infection/logs/infection.log',
        ],
    ]);
});

it('sets the PHPUnit config directory to the root where the project names none', function (): void {
    expect(ownGenerated(ownConfig('{}'), Mutators::all()))->toBe([
        'phpUnit' => ['configDir' => '/project'],
        'source' => ['directories' => ['/project/src']],
        'timeout' => 12.5,
        'tmpDir' => '/project/.gate/infection/tmp',
        'logs' => [
            'json' => '/project/.gate/infection/logs/infection.json',
            'text' => '/project/.gate/infection/logs/infection.log',
        ],
    ])->and(ownGenerated(ownConfig('{"phpStan": {}}'), Mutators::all())['phpStan'])->toBe([])
        ->and(ownGenerated(ownConfig('{"mago": {"configDir": "", "customPath": "bin/mago"}}'), Mutators::all())['mago'])
        ->toBe(['configDir' => '', 'customPath' => '/project/bin/mago']);
});

it('narrows the mutators to those named, with the project\'s settings for each and its global ignores', function (): void {
    $config = ownConfig((string) json_encode([
        'mutators' => [
            '@default' => true,
            'global-ignore' => ['A::*'],
            'global-ignoreSourceCodeByRegex' => ['Log::.*'],
            'Plus' => ['settings' => ['a' => 1]],
            'Minus' => false,
            'TrueValue' => true,
        ],
    ]));

    expect(ownGenerated($config, Mutators::named('Plus', 'TrueValue', 'Decrement'))['mutators'])->toBe([
        'global-ignore' => ['A::*'],
        'global-ignoreSourceCodeByRegex' => ['Log::.*'],
        'Plus' => ['settings' => ['a' => 1]],
        'TrueValue' => true,
        'Decrement' => true,
    ])->and(ownGenerated(ownConfig('{}'), Mutators::named('Plus'))['mutators'])->toBe(['Plus' => true]);
});

it('names the package of the static analysis tool the project has Infection kill mutants with', function (): void {
    expect(ownConfig('{"staticAnalysisTool": "phpstan"}')->staticAnalysis())->toBe(['phpstan/phpstan'])
        ->and(ownConfig('{"staticAnalysisTool": "mago"}')->staticAnalysis())->toBe(['carthage-software/mago'])
        ->and(ownConfig('{"staticAnalysisTool": "psalm"}')->staticAnalysis())->toBe([])
        ->and(ownConfig('{}')->staticAnalysis())->toBe([]);
});

it('drops a setting whose key reads as a number, as any other it does not keep', function (): void {
    $generated = ownGenerated(ownConfig('{"12": true, "source": {"directories": ["src"]}}'), Mutators::all());

    expect($generated)->toHaveKey('source')
        ->and(array_key_exists(12, $generated))->toBeFalse();
});

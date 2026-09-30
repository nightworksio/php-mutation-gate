<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\OwnConfig;
use NightWorksIO\MutationGate\Adapter\Infection\Project;
use NightWorksIO\MutationGate\Adapter\Infection\StartUpConfig;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A project whose PHPUnit config, in `config/`, bootstraps, logs, reports coverage and lists a suite and a default one. */
function startingProject(): Project
{
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'infection.json5', '{"phpUnit": {"configDir": "config"}}');
    Scratch::write($root, 'config/phpunit.xml', <<<'XML'
        <?xml version="1.0"?>
        <phpunit bootstrap="../vendor/autoload.php" colors="true" defaultTestSuite="unit">
            <testsuites>
                <testsuite name="unit">
                    <directory>../tests/Unit</directory>
                    <file>./../tests/OneTest.php</file>
                    <exclude>../tests/Unit/Slow</exclude>
                </testsuite>
            </testsuites>
            <source>
                <include>
                    <directory>/absolute/src</directory>
                    <directory>../src</directory>
                </include>
            </source>
            <coverage>
                <report>
                    <html outputDirectory="build"/>
                </report>
            </coverage>
            <logging>
                <junit outputFile="build/junit.xml"/>
            </logging>
            <php>
                <ini name="memory_limit" value="512M"/>
            </php>
        </phpunit>
        XML);

    return Project::at(Root::of($root), Paths::of(Path::of('tests')), Path::of('.gate'));
}

function ownConfigOf(Project $project): OwnConfig
{
    $config = OwnConfig::in($project);

    return $config instanceof OwnConfig ? $config : throw new RuntimeException($config->why());
}

it('shapes the project\'s config as Infection shapes a mutant\'s, with one suite that holds no test file', function (): void {
    $project = startingProject();
    $file = StartUpConfig::written($project, ownConfigOf($project));
    $written = new DOMDocument();
    $written->load(is_string($file) ? $file : '');
    $xpath = new DOMXPath($written);
    $root = $project->root();

    expect($file)->toBe(sprintf('%s/.gate/infection/start-up/phpunit.xml', $root))
        ->and($xpath->evaluate('string(/phpunit/@bootstrap)'))->toBe(sprintf('%s/config/../vendor/autoload.php', $root))
        ->and($xpath->evaluate('string(/phpunit/@colors)'))->toBe('false')
        ->and($xpath->evaluate('count(/phpunit/@defaultTestSuite)'))->toEqual(0)
        ->and($xpath->evaluate('count(/phpunit/testsuites/testsuite)'))->toEqual(1)
        ->and($xpath->evaluate('string(/phpunit/testsuites/testsuite/@name)'))->toBe(StartUpConfig::SUITE)
        ->and($xpath->evaluate('count(/phpunit/testsuites/testsuite/*)'))->toEqual(0)
        ->and($xpath->evaluate('count(/phpunit/logging|/phpunit/coverage/report)'))->toEqual(0)
        ->and($xpath->evaluate('string(/phpunit/source/include/directory[1])'))->toBe('/absolute/src')
        ->and($xpath->evaluate('string(/phpunit/source/include/directory[2])'))->toBe(sprintf('%s/config/../src', $root))
        ->and($xpath->evaluate('string(/phpunit/php/ini/@value)'))->toBe('512M');
});

it('writes each path of a suite on the root absolute before it goes, as Infection\'s path replacer writes it', function (): void {
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'infection.json5', '{}');
    Scratch::write($root, 'phpunit.xml', '<phpunit bootstrap="./boot.php"><testsuite name="all"><file>./a/./b.php</file></testsuite></phpunit>');
    $project = Project::at(Root::of($root), Paths::of(Path::of('tests')), Path::of('.gate'));
    $file = StartUpConfig::written($project, ownConfigOf($project));
    $xpath = new DOMXPath((static function (string $file): DOMDocument {
        $document = new DOMDocument();
        $document->load($file);

        return $document;
    })(is_string($file) ? $file : ''));

    expect($xpath->evaluate('string(/phpunit/@bootstrap)'))->toBe(sprintf('%s/boot.php', $root))
        ->and($xpath->evaluate('count(/phpunit/testsuite)'))->toEqual(0)
        ->and($xpath->evaluate('count(/phpunit/testsuites/testsuite)'))->toEqual(1);
});

it('cannot shape a config that is not there, or not XML', function (): void {
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'infection.json5', '{}');
    $project = Project::at(Root::of($root), Paths::of(Path::of('tests')), Path::of('.gate'));
    $missing = StartUpConfig::written($project, ownConfigOf($project));
    Scratch::write($root, 'phpunit.xml.dist', 'not <xml');

    expect($missing)->toEqual(CannotJudge::because(sprintf('The run of no test needs PHPUnit\'s config, and there is none in %s.', $root)))
        ->and(StartUpConfig::written($project, ownConfigOf($project)))
        ->toEqual(CannotJudge::because(sprintf('PHPUnit\'s config %s/phpunit.xml.dist is not XML the gate can read.', $root)));
});

it('cannot write the config where an earlier one cannot be removed', function (): void {
    $project = startingProject();
    $directory = sprintf('%s/.gate/infection/start-up', $project->root());
    Scratch::write($project->root(), '.gate/infection/start-up/phpunit.xml', 'earlier');
    chmod($directory, 0o555);
    $stale = StartUpConfig::written($project, ownConfigOf($project));
    chmod($directory, 0o755);

    expect($stale)->toEqual(CannotJudge::because(sprintf(
        'The gate cannot remove %s/phpunit.xml, so it cannot tell what this run wrote from what an earlier one did.',
        $directory,
    )));
});

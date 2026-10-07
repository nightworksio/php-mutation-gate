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
    Scratch::write($root, 'src/Money.php', '<?php // money');
    Scratch::write($root, 'config/phpunit.xml', <<<'XML'
        <?xml version="1.0"?>
        <phpunit bootstrap="../vendor/autoload.php" colors="true" defaultTestSuite="unit" printerClass="Printer">
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
                    <directory>C:\lib</directory>
                    <directory>phar://tools.phar/src</directory>
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
    $file = StartUpConfig::written($project, ownConfigOf($project), Path::of('src/Money.php'));
    $written = new DOMDocument();
    $written->load(is_string($file) ? $file : '');
    $xpath = new DOMXPath($written);
    $root = $project->root();

    $bootstrap = sprintf('%s/.gate/infection/start-up/interceptor.autoload.php', $root);
    $copy = sprintf('%s/.gate/infection/start-up/Money.php', $root);

    expect($file)->toBe(sprintf('%s/.gate/infection/start-up/phpunit.xml', $root))
        ->and($xpath->evaluate('string(/phpunit/@bootstrap)'))->toBe($bootstrap)
        ->and(file_get_contents($bootstrap))->toBe(sprintf(<<<'PHP'
            <?php
            if (function_exists('proc_nice')) {
                proc_nice(1);
            }

            require_once '%1$s/vendor/infection/include-interceptor/src/IncludeInterceptor.php';
            use Infection\StreamWrapper\IncludeInterceptor;
            IncludeInterceptor::intercept('%1$s/src/Money.php', '%2$s');
            IncludeInterceptor::enable();
            require_once '%1$s/config/../vendor/autoload.php';
            PHP, $root, $copy))
        ->and(file_get_contents($copy))->toBe('<?php // money')
        ->and($xpath->evaluate('string(/phpunit/@colors)'))->toBe('false')
        ->and($xpath->evaluate('count(/phpunit/@printerClass)'))->toEqual(0)
        ->and($xpath->evaluate('count(/phpunit/@defaultTestSuite)'))->toEqual(0)
        ->and($xpath->evaluate('count(/phpunit/testsuites/testsuite)'))->toEqual(1)
        ->and($xpath->evaluate('string(/phpunit/testsuites/testsuite/@name)'))->toBe(StartUpConfig::SUITE)
        ->and($xpath->evaluate('count(/phpunit/testsuites/testsuite/*)'))->toEqual(0)
        ->and($xpath->evaluate('count(/phpunit/logging|/phpunit/coverage/report)'))->toEqual(0)
        ->and($xpath->evaluate('string(/phpunit/source/include/directory[1])'))->toBe('/absolute/src')
        ->and($xpath->evaluate('string(/phpunit/source/include/directory[2])'))->toBe(sprintf('%s/config/../src', $root))
        ->and($xpath->evaluate('string(/phpunit/source/include/directory[3])'))->toBe('C:\lib')
        ->and($xpath->evaluate('string(/phpunit/source/include/directory[4])'))->toBe('phar://tools.phar/src')
        ->and($xpath->evaluate('string(/phpunit/php/ini/@value)'))->toBe('512M');
});

it('writes each path of a suite on the root absolute before it goes, and loads a bootstrap the config names from its directory', function (): void {
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'infection.json5', '{}');
    Scratch::write($root, 'src/Money.php', '<?php');
    Scratch::write($root, 'phpunit.xml', '<phpunit bootstrap="./boot.php"><testsuite name="all"><file>./a/./b.php</file></testsuite></phpunit>');
    $project = Project::at(Root::of($root), Paths::of(Path::of('tests')), Path::of('.gate'));
    $file = StartUpConfig::written($project, ownConfigOf($project), Path::of('src/Money.php'));
    $xpath = new DOMXPath((static function (string $file): DOMDocument {
        $document = new DOMDocument();
        $document->load($file);

        return $document;
    })(is_string($file) ? $file : ''));

    expect(file_get_contents(sprintf('%s/.gate/infection/start-up/interceptor.autoload.php', $root)))
        ->toEndWith(sprintf("require_once '%s/boot.php';", $root))
        ->and($xpath->evaluate('count(/phpunit/testsuite)'))->toEqual(0)
        ->and($xpath->evaluate('count(/phpunit/testsuites/testsuite)'))->toEqual(1);
});

it('loads the project\'s autoloader where the config names no bootstrap', function (): void {
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'infection.json5', '{}');
    Scratch::write($root, 'src/Money.php', '<?php');
    Scratch::write($root, 'phpunit.xml', '<phpunit/>');
    $project = Project::at(Root::of($root), Paths::of(Path::of('tests')), Path::of('.gate'));
    StartUpConfig::written($project, ownConfigOf($project), Path::of('src/Money.php'));

    expect(file_get_contents(sprintf('%s/.gate/infection/start-up/interceptor.autoload.php', $root)))
        ->toEndWith(sprintf("require_once '%s/vendor/autoload.php';", $root));
});

it('cannot serve a copy of a file that is not there', function (): void {
    $project = startingProject();

    expect(StartUpConfig::written($project, ownConfigOf($project), Path::of('src/Gone.php')))
        ->toEqual(CannotJudge::because('A run on Infection\'s config for a mutant needs an unchanged copy of src/Gone.php, which was not made.'));
});

it('cannot shape a config that is not there, or not XML', function (): void {
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'infection.json5', '{}');
    $project = Project::at(Root::of($root), Paths::of(Path::of('tests')), Path::of('.gate'));
    $missing = StartUpConfig::written($project, ownConfigOf($project), Path::of('src/Money.php'));
    Scratch::write($root, 'phpunit.xml.dist', 'not <xml');

    expect($missing)->toEqual(CannotJudge::because(sprintf('A run on Infection\'s config for a mutant needs PHPUnit\'s config, and there is none in %s.', $root)))
        ->and(StartUpConfig::written($project, ownConfigOf($project), Path::of('src/Money.php')))
        ->toEqual(CannotJudge::because(sprintf('PHPUnit\'s config %s/phpunit.xml.dist is not XML the gate can read.', $root)));
});

it('cannot write the config where an earlier one cannot be removed', function (): void {
    $project = startingProject();
    $directory = sprintf('%s/.gate/infection/start-up', $project->root());
    Scratch::write($project->root(), '.gate/infection/start-up/phpunit.xml', 'earlier');
    chmod($directory, 0o555);
    $stale = StartUpConfig::written($project, ownConfigOf($project), Path::of('src/Money.php'));
    chmod($directory, 0o755);

    expect($stale)->toEqual(CannotJudge::because(sprintf(
        'The gate cannot remove %s/phpunit.xml, so it cannot tell what this run wrote from what an earlier one did.',
        $directory,
    )));
});

it('writes a control\'s config in a place of its own, its one suite holding the control\'s test files by their absolute paths', function (): void {
    $project = startingProject();
    $file = StartUpConfig::holding(
        $project,
        ownConfigOf($project),
        Path::of('src/Money.php'),
        Paths::of(Path::of('tests/MoneyTest.php'), Path::of('tests/R&DTest.php')),
        'controls/3',
    );
    $written = new DOMDocument();
    $written->load(is_string($file) ? $file : '');
    $xpath = new DOMXPath($written);
    $root = $project->root();

    expect($file)->toBe(sprintf('%s/.gate/infection/controls/3/phpunit.xml', $root))
        ->and($xpath->evaluate('string(/phpunit/@bootstrap)'))->toBe(sprintf('%s/.gate/infection/controls/3/interceptor.autoload.php', $root))
        ->and(file_get_contents(sprintf('%s/.gate/infection/controls/3/Money.php', $root)))->toBe('<?php // money')
        ->and($xpath->evaluate('string(/phpunit/testsuites/testsuite/@name)'))->toBe(StartUpConfig::SUITE)
        ->and($xpath->evaluate('string(/phpunit/testsuites/testsuite/file[1])'))->toBe(sprintf('%s/tests/MoneyTest.php', $root))
        ->and($xpath->evaluate('string(/phpunit/testsuites/testsuite/file[2])'))->toBe(sprintf('%s/tests/R&DTest.php', $root))
        ->and($xpath->evaluate('count(/phpunit/testsuites/testsuite/file)'))->toEqual(2);
});

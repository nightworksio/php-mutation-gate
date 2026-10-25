<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\CoverageLayout;
use NightWorksIO\MutationGate\Adapter\Infection\CoverageXml;
use NightWorksIO\MutationGate\Adapter\Infection\JUnit;
use NightWorksIO\MutationGate\Adapter\Infection\Project;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\ExecutedMethod;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A project whose tests declare Tests\MoneyTest and Tests\HeldTest, and whose source holds src/Money.php and Held.php. */
function layoutProject(): Project
{
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'tests/MoneyTest.php', "<?php\nnamespace Tests;\nfinal class MoneyTest {}");
    Scratch::write($root, 'tests/Unit/HeldTest.php', "<?php\nnamespace Tests;\nfinal class HeldTest {}");
    Scratch::write($root, 'src/Money.php', '<?php');
    Scratch::write($root, 'Held.php', '<?php');

    return Project::at(Root::of($root), Paths::of(Path::of('tests')), Path::of('.gate'));
}

/** The text of the first node a query finds in an XML file, with PHPUnit's coverage namespace as `p`. */
function layoutRead(string $file, string $query): string
{
    $document = new DOMDocument();
    $document->load($file);
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('p', 'https://schema.phpunit.de/coverage/1.0');
    $nodes = $xpath->query($query);

    return $nodes instanceof DOMNodeList ? (string) $nodes->item(0)?->nodeValue : '';
}

function layoutMap(): CoverageMap
{
    return CoverageMap::empty()
        ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('Tests\MoneyTest::adds'))
        ->covered(Path::of('src/Money.php'), Line::of(16), TestId::of('Tests\MoneyTest::adds#0'))
        ->covered(Path::of('src/Money.php'), Line::of(16), TestId::of('Tests\MoneyTest::adds#small amounts'))
        ->covered(Path::of('Held.php'), Line::of(4), TestId::of('Tests\HeldTest::doubles'))
        ->timed(TestId::of('Tests\MoneyTest::adds'), Seconds::of(0.25))
        ->timed(TestId::of('Tests\MoneyTest::adds#0'), Seconds::of(0.125))
        ->timed(TestId::of('Tests\MoneyTest::adds#small amounts'), Seconds::of(0.0625))
        ->timed(TestId::of('Tests\HeldTest::doubles'), Seconds::of(1.5))
        ->executing(Path::of('src/Money.php'), ExecutedMethod::of('add', 9, 12), ExecutedMethod::of('large', 14, 17));
}

it('writes a map into the layout Infection reads, which reads back as the map it was', function (): void {
    $project = layoutProject();
    $directory = DiskPath::of(sprintf('%s/.gate/infection/coverage', $project->root()));

    expect(CoverageLayout::write($project, layoutMap(), $directory))->toEqual($directory)
        ->and(CoverageXml::read($project, $directory))->toEqual(layoutMap());
});

it('writes each test class\'s suite with its file and the time its tests took, as Infection looks them up', function (): void {
    $project = layoutProject();
    $directory = sprintf('%s/coverage', $project->root());
    CoverageLayout::write($project, layoutMap(), DiskPath::of($directory));
    $log = sprintf('%s/junit.xml', $directory);
    $suite = static fn(string $class, string $attribute): string => layoutRead($log, sprintf('//testsuite[@name="%s"][1]/@%s', $class, $attribute));
    $read = JUnit::at(DiskPath::of($log));

    expect($suite('Tests\MoneyTest', 'file'))->toBe(sprintf('%s/tests/MoneyTest.php', $project->root()))
        ->and($suite('Tests\HeldTest', 'file'))->toBe(sprintf('%s/tests/Unit/HeldTest.php', $project->root()))
        ->and($read instanceof JUnit ? $read->classSeconds('Tests\MoneyTest') : 0.0)->toBe(0.4375)
        ->and($read instanceof JUnit ? $read->classSeconds('Tests\HeldTest') : 0.0)->toBe(1.5)
        ->and(layoutRead($log, '//testcase[@class="Tests\MoneyTest"][2]/@name'))->toBe('adds with data set #0')
        ->and(layoutRead($log, '//testcase[@class="Tests\MoneyTest"][3]/@name'))->toBe('adds with data set "small amounts"');
});

it('logs no test for a covering id that names no test method, and asks no file to declare it', function (): void {
    $project = layoutProject();
    $directory = DiskPath::of(sprintf('%s/coverage', $project->root()));
    $map = layoutMap()->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('money.phpt'));

    expect(CoverageLayout::write($project, $map, $directory))->toEqual($directory)
        ->and(substr_count((string) file_get_contents(sprintf('%s/junit.xml', $directory->value())), '<testcase '))->toBe(4);
});

it('writes each report as Infection reads it: its path, a share of lines run, its methods and its lines', function (): void {
    $project = layoutProject();
    $directory = sprintf('%s/coverage', $project->root());
    CoverageLayout::write($project, layoutMap(), DiskPath::of($directory));
    $read = layoutRead(...);
    $money = sprintf('%s/coverage-xml/src/Money.php.xml', $directory);
    $index = sprintf('%s/coverage-xml/index.xml', $directory);

    expect($read($index, '/p:phpunit/p:project/@source'))->toBe($project->root())
        ->and($read($index, '/p:phpunit/p:project/p:directory[1]/p:totals/p:lines/@executed'))->toBe('3')
        ->and($read($index, '//p:file[2]/@href'))->toBe('Held.php.xml')
        ->and($read($money, '/p:phpunit/p:file/@path'))->toBe('/src')
        ->and($read($money, '/p:phpunit/p:file/p:totals/p:lines/@percent'))->toBe('100')
        ->and($read($money, '/p:phpunit/p:file/p:class/p:method[2]/@name'))->toBe('large')
        ->and($read($money, '/p:phpunit/p:file/p:class/p:method[2]/@start'))->toBe('14')
        ->and($read($money, '/p:phpunit/p:file/p:class/p:method[2]/@end'))->toBe('17')
        ->and($read($money, '/p:phpunit/p:file/p:class/p:method[2]/@coverage'))->toBe('100')
        ->and($read(sprintf('%s/coverage-xml/Held.php.xml', $directory), '/p:phpunit/p:file/@path'))->toBe('/');
});

it('writes the index, a file\'s report and the JUnit log to the byte, an untimed test at no seconds', function (): void {
    $project = layoutProject();
    $directory = sprintf('%s/coverage', $project->root());
    $map = layoutMap()->covered(Path::of('Held.php'), Line::of(5), TestId::of('Tests\HeldTest::halves'));
    CoverageLayout::write($project, $map, DiskPath::of($directory));
    $written = static fn(string $file): string => str_replace(
        $project->root(),
        'ROOT',
        (string) file_get_contents(sprintf('%s/%s', $directory, $file)),
    );
    $money = 'class="Tests\MoneyTest" file="ROOT/tests/MoneyTest.php"';
    $held = 'class="Tests\HeldTest" file="ROOT/tests/Unit/HeldTest.php"';
    $coverage = static fn(string $body): string => sprintf(
        "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<phpunit xmlns=\"https://schema.phpunit.de/coverage/1.0\">%s</phpunit>\n",
        $body,
    );

    expect([$written('coverage-xml/index.xml'), $written('coverage-xml/src/Money.php.xml'), $written('junit.xml')])->toBe([
        $coverage(implode('', [
            '<project source="ROOT"><directory name="/"><totals><lines executed="4"/></totals>',
            '<file name="Money.php" href="src/Money.php.xml"/><file name="Held.php" href="Held.php.xml"/>',
            '</directory></project>',
        ])),
        $coverage(implode('', [
            '<file name="Money.php" path="/src"><totals><lines executed="2" percent="100"/></totals>',
            '<class><method name="add" start="9" end="12" coverage="100"/>',
            '<method name="large" start="14" end="17" coverage="100"/></class>',
            '<coverage><line nr="11"><covered by="Tests\MoneyTest::adds"/></line>',
            '<line nr="16"><covered by="Tests\MoneyTest::adds#0"/>',
            '<covered by="Tests\MoneyTest::adds#small amounts"/></line></coverage></file>',
        ])),
        sprintf("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n%s\n", implode('', [
            '<testsuites><testsuite name="Tests\MoneyTest" file="ROOT/tests/MoneyTest.php" time="0.437500">',
            sprintf('<testcase name="adds" %s time="0.250000"/>', $money),
            sprintf('<testcase name="adds with data set #0" %s time="0.125000"/>', $money),
            sprintf('<testcase name="adds with data set &quot;small amounts&quot;" %s time="0.062500"/>', $money),
            '</testsuite><testsuite name="Tests\HeldTest" file="ROOT/tests/Unit/HeldTest.php" time="1.500000">',
            sprintf('<testcase name="doubles" %s time="1.500000"/>', $held),
            sprintf('<testcase name="halves" %s time="0.000000"/>', $held),
            '</testsuite></testsuites>',
        ])),
    ]);
});

it('writes an index that says a line ran, where the map holds none of this run\'s files', function (): void {
    $project = layoutProject();
    $directory = sprintf('%s/coverage', $project->root());
    CoverageLayout::write($project, CoverageMap::empty(), DiskPath::of($directory));
    $index = new DOMDocument();
    $index->load(sprintf('%s/coverage-xml/index.xml', $directory));

    expect($index->getElementsByTagName('lines')->item(0)?->getAttribute('executed'))->toBe('1');
});

it('cannot write a map whose test class no test file declares, or over what an earlier run left and cannot be removed', function (): void {
    $project = layoutProject();
    $directory = sprintf('%s/coverage', $project->root());
    $gone = layoutMap()->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('Tests\GoneTest::adds'));
    CoverageLayout::write($project, layoutMap(), DiskPath::of($directory));
    chmod($directory, 0o555);
    $locked = CoverageLayout::write($project, layoutMap(), DiskPath::of($directory));
    chmod($directory, 0o755);

    expect(CoverageLayout::write($project, $gone, DiskPath::of($directory)))->toEqual(CannotJudge::because(
        'The coverage map names the test class Tests\GoneTest, and no test file declares it, so Infection cannot run its tests.',
    ))->and($locked)->toEqual(CannotJudge::because(sprintf(
        'The gate cannot remove %s/junit.xml, so it cannot tell what this run wrote from what an earlier one did.',
        $directory,
    )));
});

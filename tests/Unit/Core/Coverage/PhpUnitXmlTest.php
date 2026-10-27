<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\Coverage\ExecutedMethod;
use NightWorksIO\MutationGate\Core\Coverage\PhpUnitXml;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;

/**
 * What each file of a coverage directory holds, by its path, for the reader to read.
 *
 * @param array<array-key, mixed> $files
 */
function xmlFiles(array $files): Closure
{
    return static function (DiskPath $file) use ($files): string|false {
        $text = array_key_exists($file->value(), $files) ? $files[$file->value()] : false;

        return is_string($text) ? $text : false;
    };
}

/** The index of one file's report, under a project whose source is this directory. */
function xmlIndex(string $source, string $href): string
{
    return sprintf(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <phpunit xmlns="https://schema.phpunit.de/coverage/1.0">
          <project source="%s">
            <directory name="%s"><file name="Money.php" href="%s"/></directory>
          </project>
        </phpunit>
        XML, $source, $source, $href);
}

/** A file's report, its directory under the source spelt so, with one line two tests ran and one method. */
function xmlReport(string $path): string
{
    return sprintf(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <phpunit xmlns="https://schema.phpunit.de/coverage/1.0">
          <file name="Money.php" path="%s">
            <class name="Money" start="5" executable="2" executed="1" crap="1">
              <method name="add" signature="add()" start="7" end="10" crap="1" executable="2" executed="1" coverage="50"/>
              <method name="never" signature="never()" start="12" end="14" crap="1" executable="1" executed="0" coverage="0"/>
            </class>
            <coverage>
              <line nr="9"><covered by="Tests\MoneyTest::adds"/><covered by="Tests\MoneyTest::rounds"/></line>
              <line nr="13"/>
            </coverage>
          </file>
        </phpunit>
        XML, $path);
}

it('reads the lines each test ran and the methods that ran, its files spelt from the root, as PHPUnit and Testo write them', function (string $path): void {
    $files = xmlFiles([
        '/c/index.xml' => xmlIndex('/p', 'src/Money.php.xml'),
        '/c/src/Money.php.xml' => xmlReport($path),
    ]);
    $missed = CoveredLine::of(Path::of('src/Money.php'), 13);

    expect(PhpUnitXml::read(Root::of('/p'), DiskPath::of('/c'), $files, $missed))->toEqual(CoverageMap::of(
        $missed,
        CoveredLine::of(Path::of('src/Money.php'), 9, 'Tests\MoneyTest::adds', 'Tests\MoneyTest::rounds'),
    )->executing(Path::of('src/Money.php'), ExecutedMethod::of('add', 7, 10)));
})->with(['PHPUnit\'s spelling' => '/src', 'Testo\'s spelling' => 'src']);

it('reads a file in the source directory itself, whose directory under it is spelt as none', function (): void {
    $files = xmlFiles(['/c/index.xml' => xmlIndex('/p', 'Money.php.xml'), '/c/Money.php.xml' => xmlReport('')]);
    $map = PhpUnitXml::read(Root::of('/p'), DiskPath::of('/c'), $files);

    expect($map instanceof CoverageMap ? $map->linesCovered(Path::of('Money.php'))->count() : $map)->toBe(1);
});

it('cannot judge where the index or a report is not there, or is not XML', function (array $files, string $missing): void {
    expect(PhpUnitXml::read(Root::of('/p'), DiskPath::of('/c'), xmlFiles($files)))->toEqual(CannotJudge::because(sprintf(
        '%s is not there or is not PHPUnit XML coverage, so the gate cannot say which tests run which line.',
        $missing,
    )));
})->with([
    'no index' => [[], '/c/index.xml'],
    'an index that is not XML' => [['/c/index.xml' => '<not xml'], '/c/index.xml'],
]);

it('cannot judge where a report the index names is not there', function (): void {
    $files = xmlFiles(['/c/index.xml' => xmlIndex('/p', 'src/Money.php.xml')]);

    expect(PhpUnitXml::read(Root::of('/p'), DiskPath::of('/c'), $files))->toEqual(CannotJudge::because(
        '/c/src/Money.php.xml is not there or is not PHPUnit XML coverage, so the gate cannot say which tests run which line.',
    ));
});

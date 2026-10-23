<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Codeception\Covering;
use NightWorksIO\MutationGate\Adapter\Codeception\Test;
use NightWorksIO\MutationGate\Adapter\Native\CoverageFile;
use NightWorksIO\MutationGate\Adapter\Native\Project;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Native\OwnFiles;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Fakes\CoverageDriverFake;
use NightWorksIO\MutationGate\Tests\Support\CodeceptionTests;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Filter;

afterEach(function (): void {
    Scratch::sweep();
});

it('maps the lines of the project\'s own files each test ran, with its time, as the native runner reads the map, and nothing of the vendor directory', function (): void {
    $root = (string) realpath(Scratch::directory());
    $tag = hash('xxh3', $root);
    $first = sprintf('src/First%s.php', $tag);
    $second = sprintf('src/Second%s.php', $tag);
    $vendored = sprintf('vendor/v/Vendored%s.php', $tag);

    foreach ([$first, $second, $vendored] as $file) {
        Scratch::write($root, $file, "<?php\n\$ran = 1;\n");
    }

    $map = sprintf('%s/coverage.php', $root);
    $driver = new CoverageDriverFake([
        [sprintf('%s/%s', $root, $first) => [2 => 1], sprintf('%s/%s', $root, $vendored) => [2 => 1]],
        [sprintf('%s/%s', $root, $second) => [2 => 1]],
    ]);
    $covering = new Covering($map, new CodeCoverage($driver, new Filter()), OwnFiles::under($root, sprintf('%s/vendor', $root)));

    require sprintf('%s/%s', $root, $first);
    require sprintf('%s/%s', $root, $vendored);
    $covering->started(Test::of(CodeceptionTests::held('testIsHeldByItsCase')));
    $covering->ended(0.25);
    require sprintf('%s/%s', $root, $second);
    $covering->started(Test::of(CodeceptionTests::held('testIsHeldByItsOwnPathToo')));
    $covering->ended(0.5);
    $unwritten = is_file($map);
    $covering->written();
    $read = CoverageFile::read(Project::at($root, Paths::of(Path::of('tests')), Path::of('vendor'), Path::of('.mutation-gate'), BuiltinRunner::Codeception), $map);
    $case = TestId::of(CodeceptionTests::id('testIsHeldByItsCase'));
    $method = TestId::of(CodeceptionTests::id('testIsHeldByItsOwnPathToo'));

    expect($unwritten)->toBeFalse()
        ->and($read instanceof CoverageMap ? $read->files() : $read)->toEqual(Paths::of(Path::of($first), Path::of($second)))
        ->and($read instanceof CoverageMap ? $read->testsCoveringFile(Path::of($first)) : $read)->toEqual(TestIds::of($case))
        ->and($read instanceof CoverageMap ? $read->testsCoveringFile(Path::of($second)) : $read)->toEqual(TestIds::of($method))
        ->and($read instanceof CoverageMap ? [$read->durationOf($case), $read->durationOf($method)] : $read)->toEqual([Seconds::of(0.25), Seconds::of(0.5)]);
});

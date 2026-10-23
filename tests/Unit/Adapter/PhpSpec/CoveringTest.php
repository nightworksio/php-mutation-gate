<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Native\CoverageFile;
use NightWorksIO\MutationGate\Adapter\Native\Project;
use NightWorksIO\MutationGate\Adapter\PhpSpec\Covering;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Native\OwnFiles;
use NightWorksIO\MutationGate\Core\Native\Selected;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Fakes\CoverageDriverFake;
use NightWorksIO\MutationGate\Tests\Support\PhpSpecExamples;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Filter;

afterEach(function (): void {
    Scratch::sweep();
});

it('listens for each example\'s start and end, and for the suite\'s end', function (): void {
    expect(Covering::getSubscribedEvents())->toBe(['beforeExample' => 'started', 'afterExample' => 'ended', 'afterSuite' => 'written']);
});

it('maps the lines of the project\'s own files each selected example ran, with its time, as the native runner reads the map, and nothing of the vendor directory or of an example the run does not select', function (): void {
    $root = (string) realpath(Scratch::directory());
    $tag = hash('xxh3', $root);
    $first = sprintf('src/First%s.php', $tag);
    $second = sprintf('src/Second%s.php', $tag);
    $vendored = sprintf('vendor/v/Vendored%s.php', $tag);

    foreach ([$first, $second, $vendored] as $file) {
        Scratch::write($root, $file, "<?php\n\$ran = 1;\n");
    }

    $map = sprintf('%s/coverage.php', $root);
    $own = OwnFiles::under($root, sprintf('%s/vendor', $root));
    $driver = new CoverageDriverFake([
        [sprintf('%s/%s', $root, $first) => [2 => 1], sprintf('%s/%s', $root, $vendored) => [2 => 1]],
        [sprintf('%s/%s', $root, $second) => [2 => 1]],
    ]);
    $everything = Selected::narrowedTo(NotGiven::value(), NotGiven::value(), NotGiven::value(), NotGiven::value());
    $nothing = Selected::narrowedTo(TestIds::none(), NotGiven::value(), NotGiven::value(), NotGiven::value());
    $covering = new Covering($map, new CodeCoverage($driver, new Filter()), $everything, $own);
    $skipping = new Covering($map, new CodeCoverage($driver, new Filter()), $nothing, $own);
    $skipping->started(PhpSpecExamples::event('it_is_held_by_its_spec'));
    $skipping->ended(PhpSpecExamples::event('it_is_held_by_its_spec'));

    require sprintf('%s/%s', $root, $first);
    require sprintf('%s/%s', $root, $vendored);
    $covering->started(PhpSpecExamples::event('it_is_held_by_its_spec'));
    $covering->ended(PhpSpecExamples::event('it_is_held_by_its_spec', time: 0.25));
    require sprintf('%s/%s', $root, $second);
    $covering->started(PhpSpecExamples::event('it_is_held_by_its_own_path_too'));
    $covering->ended(PhpSpecExamples::event('it_is_held_by_its_own_path_too', time: 0.5));
    $unwritten = is_file($map);
    $covering->written();
    $read = CoverageFile::read(Project::at($root, Paths::of(Path::of('spec')), Path::of('vendor'), Path::of('.mutation-gate'), BuiltinRunner::PhpSpec), $map);
    $spec = TestId::of(PhpSpecExamples::id('it_is_held_by_its_spec'));
    $method = TestId::of(PhpSpecExamples::id('it_is_held_by_its_own_path_too'));

    expect($unwritten)->toBeFalse()
        ->and($read instanceof CoverageMap ? $read->files() : $read)->toEqual(Paths::of(Path::of($first), Path::of($second)))
        ->and($read instanceof CoverageMap ? $read->testsCoveringFile(Path::of($first)) : $read)->toEqual(TestIds::of($spec))
        ->and($read instanceof CoverageMap ? $read->testsCoveringFile(Path::of($second)) : $read)->toEqual(TestIds::of($method))
        ->and($read instanceof CoverageMap ? [$read->durationOf($spec), $read->durationOf($method)] : $read)->toEqual([Seconds::of(0.25), Seconds::of(0.5)]);
});

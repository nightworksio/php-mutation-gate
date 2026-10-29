<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\TestFiles;
use NightWorksIO\MutationGate\Adapter\Pest\Unexecutable\TrialParts;
use NightWorksIO\MutationGate\Adapter\Pest\Unexecutable\TrialRun;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * The trials of a mutant of src/Money.php by these spec files, in a project that holds them, each test of the map
 * timed so, by its spec's name and its own.
 *
 * @param  array<string, array<string, float>> $specs
 * @return non-empty-list<TrialRun>
 */
function trialPartsOf(array $specs, string ...$files): array
{
    $root = (string) realpath(Scratch::directory());
    $map = CoverageMap::empty();

    foreach ($files as $file) {
        Scratch::write($root, sprintf('tests/%s.php', $file), '<?php');
    }

    foreach ($specs as $spec => $tests) {
        foreach ($tests as $test => $seconds) {
            $map = $map->timed(TestId::of(sprintf('P\Tests\%s::__pest_evaluable_it_%s', $spec, $test)), Seconds::of($seconds));
        }
    }

    $project = Project::at($root, Paths::of(Path::of('tests')), Path::of('.mutation-gate'), Path::of('vendor'));
    $bounds = LimitBounds::between(Seconds::of(1.0), Seconds::of(6.0))->startingIn(Seconds::of(0.1));

    return TrialParts::under(new TestFiles($project), $map, $bounds)
        ->of(trialPartsFiles(...$files), Path::of('src/Money.php'), '/results/mutant.php');
}

/** These spec files under tests/, in this order. */
function trialPartsFiles(string ...$files): Paths
{
    return Paths::of(...array_map(static fn(string $file): Path => Path::of(sprintf('tests/%s.php', $file)), $files));
}

/** A trial of the mutant by these spec files, allowed this long. */
function trialPart(float $limit, string ...$files): TrialRun
{
    return TrialRun::of(trialPartsFiles(...$files), Path::of('src/Money.php'), '/results/mutant.php', Seconds::of($limit));
}

it('tries the files whose tests fit in one run as one trial, allowed the standard limit of their tests', function (): void {
    expect(trialPartsOf(['ASpec' => ['a' => 0.5], 'BSpec' => ['b' => 1.0]], 'ASpec', 'BSpec'))
        ->toEqual([trialPart(4.8, 'ASpec', 'BSpec')]);
});

it('tries files whose tests do not fit in one run in parts that each fit, the fastest first, and files the map times no test of last', function (): void {
    $specs = ['ASpec' => ['a' => 1.5], 'BSpec' => ['b' => 1.0], 'CSpec' => ['c' => 0.5]];

    expect(trialPartsOf($specs, 'ASpec', 'BSpec', 'CSpec', 'HelpedSpec'))
        ->toEqual([trialPart(4.8, 'CSpec', 'BSpec'), trialPart(4.8, 'ASpec', 'HelpedSpec')]);
});

it('tries a file whose tests fit in no one part whole, once, in the first part that holds any of them', function (): void {
    $specs = ['BigSpec' => ['x' => 1.5, 'y' => 1.4], 'SmallSpec' => ['s' => 0.2]];

    expect(trialPartsOf($specs, 'BigSpec', 'SmallSpec'))->toEqual([trialPart(6.0, 'SmallSpec', 'BigSpec')]);
});

it('tries one file as one trial, however long its tests take', function (): void {
    expect(trialPartsOf(['BigSpec' => ['x' => 1.5, 'y' => 1.4]], 'BigSpec'))->toEqual([trialPart(6.0, 'BigSpec')]);
});

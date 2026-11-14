<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\StreamedSuites;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\StreamedSuite;
use NightWorksIO\MutationGate\Core\Coverage\TestEnded;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\FlowCommands;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
use Symfony\Component\Console\Input\ArrayInput;

afterEach(function (): void {
    Scratch::sweep();
});

/** What a flow runs with in this project. */
function streamedComposed(string $project): Composed
{
    $composed = FlowCommands::composition($project, ScriptedRunner::fixture(), new ProofStoreFake(), Flows::ci())->compose(new ArrayInput([]));

    return $composed instanceof Composed ? $composed : throw new RuntimeException('The flow did not compose.');
}

$plan = static fn(int ...$numbers): Plan => Plan::of(Revision::ref('HEAD'), Digest::sha256Of('base'), Keys::none(), Shards::of(...array_map(
    static fn(int $number): Shard => Shard::of(ShardId::of($number), Package::at(Path::root()), Units::of(Unit::file(Path::of('src/A.php'))), Seconds::of(1.0), 'src'),
    $numbers,
)));
$ended = static fn(float $seconds): TestEnded => new TestEnded(passed: true, troubled: false, own: false, seconds: $seconds);

it('leaves the suite every shard\'s streams ran, merged, beside the map they recorded, where the job that runs the suite takes them', function () use ($plan, $ended): void {
    $project = FlowCommands::project('"tests": {"suites": ["unit"]}');
    Scratch::write($project, 'phpunit.xml', '<?xml version="1.0"?><phpunit><testsuites><testsuite name="unit"><directory>tests/Unit</directory></testsuite><testsuite name="process"/></testsuites></phpunit>');
    Scratch::write($project, 'results/1.suite.json', StreamedSuite::of(complete: true, tests: ['T::a' => $ended(0.5)], reported: ['src/A.php' => [3]])->encoded());
    Scratch::write($project, 'results/2.suite.json', StreamedSuite::of(complete: true, tests: ['T::b' => $ended(0.25)], reported: ['src/B.php' => [4]])->encoded());
    chdir($project);

    $left = StreamedSuites::left(streamedComposed($project), $plan(1, 2), Path::of('results'), CoverageMap::of());
    $suite = StreamedSuite::decoded((string) file_get_contents(sprintf('%s/%s/suite.json', $project, Workspace::suite()->value())));

    expect($left)->toBeInstanceOf(Written::class)
        ->and(is_file(sprintf('%s/%s', $project, CoverageMapFile::in(Workspace::suite())->value())))->toBeTrue()
        ->and($suite instanceof StreamedSuite ? [$suite->secondsByTest(), $suite->reported(), $suite->suites()] : $suite)
        ->toBe([['T::a' => 0.5, 'T::b' => 0.25], ['src/A.php' => [3], 'src/B.php' => [4]], ['unit']]);
});

it('leaves nothing where a shard left no suite, or one that cannot be read', function (string $second) use ($plan, $ended): void {
    $project = FlowCommands::project();
    Scratch::write($project, 'results/1.suite.json', StreamedSuite::of(complete: true, tests: ['T::a' => $ended(0.5)], reported: [])->encoded());

    if ($second !== '') {
        Scratch::write($project, 'results/2.suite.json', $second);
    }

    chdir($project);

    expect(StreamedSuites::left(streamedComposed($project), $plan(1, 2), Path::of('results'), CoverageMap::of()))->toEqual(NotGiven::value())
        ->and(is_dir(sprintf('%s/%s', $project, Workspace::suite()->value())))->toBeFalse();
})->with([
    'none left' => [''],
    'unreadable' => ['not json'],
]);

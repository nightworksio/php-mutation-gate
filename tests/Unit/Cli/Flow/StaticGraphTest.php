<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Flow\StaticGraph;
use NightWorksIO\MutationGate\Cli\Flow\Suite;
use NightWorksIO\MutationGate\Config\OutbreakGraph;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Reach\GraphAnswer;
use NightWorksIO\MutationGate\Tests\Support\AnsweringGraph;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\ReadSuite;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** The suite of a project with no test file. */
function graphedSuite(string $project): Suite
{
    $suite = ReadSuite::of(Flows::trees(), Fingerprints::none(), Directory::at($project));

    return $suite instanceof Suite ? $suite : throw new RuntimeException($suite->why());
}

it('asks the graph which test files reach the files a save changed, for a quick round', function (): void {
    $project = Flows::project();
    $answer = GraphAnswer::of(['src/Money.php' => Paths::of(Path::of('tests/MoneyTest.php'))], [], [], Paths::none(), []);

    $found = new StaticGraph(Flows::adapters($project, [], new AnsweringGraph($answer)), Flows::settings())
        ->forQuickRound(graphedSuite($project), Paths::of(Path::of('src/Money.php')));

    expect($found)->toBe($answer);
});

it('takes nothing for a quick round where the graph answers nothing, is off, or is asked about no file', function (
    bool $answers,
    bool $off,
    bool $files,
): void {
    $project = Flows::project();
    $graph = new AnsweringGraph($answers ? GraphAnswer::of([], [], [], Paths::none(), []) : NotWritten::because('no graph'));
    $settings = $off ? Flows::settings(OutbreakGraph::off()) : Flows::settings();

    $found = new StaticGraph(Flows::adapters($project, [], $graph), $settings)
        ->forQuickRound(graphedSuite($project), $files ? Paths::of(Path::of('src/Money.php')) : Paths::none());

    expect($found)->toBeInstanceOf(NotGiven::class);
})->with([
    'answers nothing' => [false, false, true],
    'off' => [true, true, true],
    'no file' => [true, false, false],
]);

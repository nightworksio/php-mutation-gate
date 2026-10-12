<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Running;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Tests\Support\Dooms;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\RunningCases;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

afterEach(function (): void {
    Scratch::sweep();
});

$makeUnjudged = static fn(): Closure => RunningCases::unjudged(...);
$makeResultIn = static fn(): Closure => RunningCases::resultIn(...);

$makeOnPullRequest = static fn(): Closure => RunningCases::onPullRequest(...);
$makeFloored = static fn(): Closure => RunningCases::floored(...);
$makeAsked = static fn(): Closure => RunningCases::asked(...);
$makeDoomOf = static fn(): Closure => RunningCases::doomOf(...);

it('runs a pull request\'s shard on past a survivor proven equivalent, to the chunk whose survivor is not', function () use (
    $makeResultIn,
    $makeUnjudged,
    $makeOnPullRequest,
    $makeFloored,
    $makeAsked,
    $makeDoomOf,
): void {
    $resultIn = $makeResultIn();
    $unjudged = $makeUnjudged();
    $onPullRequest = $makeOnPullRequest();
    $floored = $makeFloored();
    $asked = $makeAsked();
    $doomOf = $makeDoomOf();

    $project = Flows::project();
    $runner = ScriptedRunner::fixture()->checking(Checkable::inPlace(Contents::of("<?php\n\nfinal  class Money\n{\n}\n")));

    new Running(Flows::adapters($project, [], $runner, $floored(Floor::whole())), Flows::settings(), Flows::setup())
        ->run(Planned::handedIn($project, $onPullRequest(Planned::oneShard())), ShardId::of(1), Workspace::results());
    $result = $resultIn($project, 1);

    expect($asked($runner))->toBe([['src/Money.php'], ['src/Held.php']])
        ->and($unjudged($result))->toBe([])
        ->and($doomOf($result))->toBe(['src/Held.php', Dooms::survivor('src/Held.php')->id()->value(), 'src', 10_000, 'tree']);
});

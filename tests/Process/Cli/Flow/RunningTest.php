<?php

declare(strict_types=1);
use NightWorksIO\MutationGate\Cli\Flow\Running;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Config\Equivalence;
use NightWorksIO\MutationGate\Config\Flaky;
use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

afterEach(function (): void {
    Scratch::sweep();
});

it('runs no survivor proven equivalent again, unless equivalence.static is false', function (Equivalence|Flaky $setting, array $retried): void {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture()->checking(Checkable::inPlace(Contents::of("<?php\n\nfinal  class Money\n{\n}\n")));

    new Running(Flows::adapters($project, [], $runner), Flows::settings($setting), Flows::setup())
        ->run(Planned::handedIn($project, Planned::oneShard()), ShardId::of(1), Workspace::results());
    $asked = array_map(
        static fn(array $retry): array => array_map(static fn(Mutant $mutant): string => $mutant->nativeId(), [...$retry[0]]),
        $runner->retries(),
    );

    expect($asked)->toBe($retried);
})->with([
    'proven equivalent' => [fn(): Equivalence => Equivalence::provenStatically(), [['Plus-11']]],
    'not proven' => [fn(): Equivalence => Equivalence::notProvenStatically(), [['Plus-11'], ['GreaterThan-16']]],
]);

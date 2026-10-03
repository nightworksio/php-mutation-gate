<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Setup;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\FlowCommands;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
use NightWorksIO\MutationGate\Tests\Support\StoppedClock;
use Symfony\Component\Console\Input\ArrayInput;

afterEach(function (): void {
    Scratch::sweep();
});

/** What a flow runs with in a project whose config holds these settings besides the fake runner. */
function composedWith(string $config): Composed
{
    $composition = FlowCommands::composition(
        FlowCommands::project($config),
        ScriptedRunner::fixture(),
        new ProofStoreFake(),
        Flows::ci(),
    );
    $composed = $composition->compose(new ArrayInput([]));

    return $composed instanceof Composed ? $composed : throw new RuntimeException('The flow did not compose.');
}

it('runs with a budget of its own, and every other setting and adapter as it was', function (): void {
    $before = composedWith('"budget": "1h", "shards": {"max": 3}');

    $after = $before->budgeted(Seconds::minutes(5));

    expect($after instanceof Composed ? $after->settings->triage()->budget() : $after)->toEqual(Seconds::minutes(5))
        ->and($after instanceof Composed ? $after->settings->shards()->max() : $after)->toBe(3)
        ->and($after instanceof Composed ? $after->settings->canonical() : $after)->toBe($before->settings->canonical())
        ->and($after instanceof Composed ? [$after->adapters, $after->setup, $after->reporting] : $after)
        ->toBe([$before->adapters, $before->setup, $before->reporting])
        ->and($before->settings->triage()->budget())->toEqual(Seconds::minutes(60));
});

it('is invalid where the settings no longer hold on the day its clock reads', function (): void {
    $before = composedWith('"ignores": {"maxDays": 30, "entries": [{"mutant": "3f9a1c2b7d04", "reason": "R", "expires": "2026-10-30"}]}');
    $earlier = new Composed(
        $before->settings,
        $before->adapters,
        new Setup($before->setup->configFile, $before->setup->gate, $before->setup->installed, new StoppedClock('2026-09-01T12:00:00+00:00')),
        $before->reporting,
    );

    expect($earlier->budgeted(Seconds::minutes(5)))->toBeInstanceOf(Invalid::class);
});

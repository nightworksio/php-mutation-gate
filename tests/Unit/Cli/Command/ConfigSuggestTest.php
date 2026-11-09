<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Command\ConfigSuggest;
use NightWorksIO\MutationGate\Cli\Config\Formats;
use NightWorksIO\MutationGate\Cli\Config\NoConfigFile;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\SuggestedConfig;
use NightWorksIO\MutationGate\Cli\Flow\Unsayable;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Config\Triage;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Mutator\MutatorSet;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\RepositoryFake;
use NightWorksIO\MutationGate\Tests\Support\FlowCommands;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
use NightWorksIO\MutationGate\Tests\Support\Tallies;
use NightWorksIO\MutationGateDefault\Arithmetic\BitwiseAndToBitwiseOr;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;

afterEach(function (): void {
    Scratch::sweep();
});

/** The flows' registry over a store holding this default branch's ledger, with a `default` set holding `BitwiseAndToBitwiseOr`. */
$registered = static function (Ledger $ledger): Extensions {
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), $ledger);

    return FlowCommands::registry(Flows::trees(), ScriptedRunner::fixture(), $store, Flows::ci(), RepositoryFake::onMain(Revision::ref(Flows::HEAD)), Flows::checkout())
        ->withMutators(MutatorSet::defaultName(), MutatorSet::of(BitwiseAndToBitwiseOr::class));
};

/** `config:suggest` in a project whose default branch's ledger is this one. */
$suggesting = static function (Ledger $ledger, string $project) use ($registered): Command {
    $registry = $registered($ledger);

    return ConfigSuggest::command(
        FlowCommands::registered($registry, $project, Variables::of([])),
        FlowCommands::effective($registry, $project),
        new Formats(class_exists(...)),
        $project,
    );
};

$idle = static fn(string $mutator): Ledger => Ledger::empty()->withLearned(Tallies::holding(
    Tallies::of('fake', $mutator, 600, 600, 0, 0, 50.0),
    Tallies::of('fake', 'Busy', 100, 50, 50, 0, 50.0),
));

it('prints each suggestion with its evidence, then the config file with them laid over it as a diff, writes nothing, and exits 0', function () use ($suggesting, $idle): void {
    $project = FlowCommands::project();
    $before = (string) file_get_contents(sprintf('%s/mutation-gate.json', $project));
    $printed = FlowCommands::run($suggesting($idle('default/BitwiseAndToBitwiseOr'), $project));

    expect($printed->code)->toBe(0)
        ->and($printed->errors)->toBe('', sprintf('errors: %s', json_encode($printed->errors)))
        ->and($printed->output)->toBe(<<<'SAID'
            - `default/BitwiseAndToBitwiseOr` under fake: 600 judged, 0 survived, 50% of all the time (50s). It finds nothing: turn it off.

            --- a/mutation-gate.json
            +++ b/mutation-gate.json
            @@ -1,4 +1,9 @@
             {
                 "$schema": "vendor/nightworksio/mutation-gate/resources/mutation-gate.schema.json",
            -    "runner": "fake"
            +    "runner": "fake",
            +    "mutators": {
            +        "except": [
            +            "default/BitwiseAndToBitwiseOr"
            +        ]
            +    }
             }

            SAID, sprintf('output: %s', json_encode($printed->output)))
        ->and((string) file_get_contents(sprintf('%s/mutation-gate.json', $project)))->toBe($before);
});

it('says suggestions are off and proposes nothing where suggest.enabled is false', function () use ($suggesting, $idle): void {
    $printed = FlowCommands::run($suggesting($idle('default/BitwiseAndToBitwiseOr'), FlowCommands::project('"suggest": {"enabled": false}')));

    expect($printed->code)->toBe(0)
        ->and($printed->output)->toBe(ConfigSuggest::OFF)
        ->and($printed->errors)->toBe('');
});

it('names a change the config cannot say with why, and leaves it out of the diff', function () use ($suggesting, $idle): void {
    $project = FlowCommands::project();
    $printed = FlowCommands::run($suggesting($idle('default/Unregistered'), $project));

    expect($printed->code)->toBe(0)
        ->and($printed->output)->toContain('  The config cannot say this: mutators.except names "default/Unregistered"')
        ->and($printed->output)->toEndWith("holds.\n")
        ->and($printed->output)->not->toContain('--- a/');
});

it('lists every suggestion, a change the config cannot say among them, and diffs only those it can', function () use ($suggesting): void {
    $ledger = Ledger::empty()->withLearned(Tallies::holding(
        Tallies::of('fake', 'default/BitwiseAndToBitwiseOr', 600, 600, 0, 0, 50.0),
        Tallies::of('fake', 'default/Unregistered', 600, 600, 0, 0, 50.0),
        Tallies::of('fake', 'Busy', 100, 50, 50, 0, 50.0),
    ));
    $project = FlowCommands::project();
    $printed = FlowCommands::run($suggesting($ledger, $project));

    expect($printed->output)->toStartWith('- `default/BitwiseAndToBitwiseOr` under fake: 600 judged')
        ->and($printed->output)->toContain("\n- `default/Unregistered` under fake: 600 judged")
        ->and($printed->output)->toContain('  The config cannot say this: mutators.except names "default/Unregistered"')
        ->and($printed->output)->toContain('+            "default/BitwiseAndToBitwiseOr"');
});

it('adds mutation-gate.json where the project has no config file', function () use ($registered, $idle): void {
    $project = FlowCommands::project();
    $composed = FlowCommands::registered($registered(Ledger::empty()), $project, Variables::of([]))->compose(new ArrayInput([]));
    $said = $composed instanceof Composed
        ? new SuggestedConfig($composed, new Formats(class_exists(...)), $project)->said($idle('default/BitwiseAndToBitwiseOr'), Layer::of(), NoConfigFile::there())
        : '';

    expect($said)->toContain(implode("\n", ['--- a/mutation-gate.json', '+++ b/mutation-gate.json', '@@ -1,0 +1,8 @@', '+{']));
});

it('says there is nothing to suggest where the ledger gives evidence for nothing', function () use ($suggesting): void {
    $project = FlowCommands::project();

    expect(FlowCommands::run($suggesting(Ledger::empty(), $project))->output)->toBe(sprintf("%s\n", SuggestedConfig::NONE));
});

it('says why the ledger cannot be read, and exits 2', function () use ($suggesting): void {
    $printed = FlowCommands::run($suggesting(Ledger::empty(), FlowCommands::project()), '--ledger=gone.json.gz');

    expect($printed->code)->toBe(2)
        ->and($printed->errors)->toContain('No ledger is at gone.json.gz.');
});

it('names a change whose settings a run would refuse, as it would refuse them', function () use ($registered): void {
    $project = FlowCommands::project();
    $composed = FlowCommands::registered($registered(Ledger::empty()), $project, Variables::of([]))->compose(new ArrayInput([]));
    $unbounded = Layer::of(Triage::of(limit: Seconds::of(600), most: Seconds::of(60)));

    expect($composed instanceof Composed ? Unsayable::why($composed, $unbounded) : $composed)->toBeString()
        ->and($composed instanceof Composed ? Unsayable::why($composed, Layer::of()) : $composed)->toBeInstanceOf(NotGiven::class);
});

it('says the change cannot be shown where the config file is in a format the gate does not write', function () use ($registered, $idle): void {
    $project = FlowCommands::project();
    $composed = FlowCommands::registered($registered(Ledger::empty()), $project, Variables::of([]))->compose(new ArrayInput([]));
    $said = $composed instanceof Composed
        ? new SuggestedConfig($composed, new Formats(class_exists(...)), $project)->said($idle('default/BitwiseAndToBitwiseOr'), Layer::of(), Path::of(sprintf('%s/mutation-gate.toml', $project)))
        : '';

    expect($said)->toEndWith("The change cannot be shown: mutation-gate.toml is in a format the gate does not write.\n");
});

it('says why the config cannot be read, and exits 2', function () use ($suggesting, $idle): void {
    $printed = FlowCommands::run($suggesting($idle('default/BitwiseAndToBitwiseOr'), FlowCommands::project('"budget": "soon"')));

    expect($printed->code)->toBe(2)
        ->and($printed->errors)->toContain('budget');
});

<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Command\MutatorsCommand;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\LedgerFile;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Unreadable;
use NightWorksIO\MutationGate\Core\Proof\UnreadReason;
use NightWorksIO\MutationGate\Core\Report\MutatorReport;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\RepositoryFake;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\FlowCommands;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Schema;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
use NightWorksIO\MutationGate\Tests\Support\Tallies;
use Symfony\Component\Console\Command\Command;

afterEach(function (): void {
    Scratch::sweep();
});

$tallied = static fn(): Ledger => Ledger::empty()->withLearned(Tallies::holding(
    Tallies::of('fake', 'Plus', 10, 8, 2, 0, 6.0),
    Tallies::of('fake', 'Minus', 4, 4, 0, 0, 2.0),
));

$composed = static function (Ledger $ledger, string $project): Composition {
    $store = new ProofStoreFake();
    $store->write(Scope::branch('main'), $ledger);

    return FlowCommands::composition($project, ScriptedRunner::fixture(), $store, Flows::ci());
};

it('prints what each mutator costs and finds from the default branch\'s ledger, running nothing, and exits 0', function () use ($tallied, $composed): void {
    $printed = FlowCommands::run(MutatorsCommand::command($composed($tallied(), FlowCommands::project())));

    expect($printed->code)->toBe(0)
        ->and($printed->errors)->toBe('')
        ->and($printed->output)->toBe(MutatorReport::text($tallied()->learned()->tallies()));
});

it('reads the default branch\'s ledger where --ledger names nothing', function () use ($tallied, $composed): void {
    $command = static fn(): Command => MutatorsCommand::command($composed($tallied(), FlowCommands::project()));

    expect(FlowCommands::run($command(), '--ledger=')->output)->toBe(FlowCommands::run($command())->output)
        ->and(FlowCommands::run($command(), '--ledger=')->code)->toBe(0);
});

it('prints its JSON as the committed schema describes it', function () use ($tallied, $composed): void {
    $printed = FlowCommands::run(MutatorsCommand::command($composed($tallied(), FlowCommands::project())), '--format=json');

    expect($printed->code)->toBe(0)
        ->and(Decoded::at($printed->output, 'mutators', 0, 'mutator'))->toBe('Plus')
        ->and(Schema::errors($printed->output, Schema::at('resources/mutators.schema.json')))->toBe([]);
});

it('reads the ledger --ledger names in place of the default branch\'s', function () use ($tallied, $composed): void {
    $project = FlowCommands::project();
    Scratch::write($project, 'kept/ledger.json.gz', LedgerFile::encode($tallied()));
    $printed = FlowCommands::run(MutatorsCommand::command($composed(Ledger::empty(), $project)), '--ledger=kept/ledger.json.gz');

    expect($printed->code)->toBe(0)
        ->and($printed->output)->toBe(MutatorReport::text($tallied()->learned()->tallies()));
});

it('reads a ledger --ledger names by its path from the file system\'s root', function () use ($tallied, $composed): void {
    $kept = Scratch::directory();
    Scratch::write($kept, 'ledger.json.gz', LedgerFile::encode($tallied()));
    $printed = FlowCommands::run(MutatorsCommand::command($composed(Ledger::empty(), FlowCommands::project())), sprintf('--ledger=%s/ledger.json.gz', $kept));

    expect($printed->code)->toBe(0)
        ->and($printed->output)->toBe(MutatorReport::text($tallied()->learned()->tallies()));
});

it('says where the ledger --ledger names is missing or unreadable, or the form is unknown, and exits 2', function (string $options, string $said) use ($composed): void {
    $project = FlowCommands::project();
    Scratch::write($project, 'broken.json.gz', 'not a ledger');
    $printed = FlowCommands::run(MutatorsCommand::command($composed(Ledger::empty(), $project)), $options);

    expect($printed->code)->toBe(2)
        ->and($printed->errors)->toContain($said);
})->with([
    'missing' => ['--ledger=gone.json.gz', 'No ledger is at gone.json.gz.'],
    'unreadable' => ['--ledger=broken.json.gz', 'The ledger at broken.json.gz cannot be read:'],
    'an unknown form' => ['--format=xml', '--format is xml; mutators writes text or json.'],
]);

it('says the ledger tallies nothing yet where it tallies nothing', function () use ($composed): void {
    expect(FlowCommands::run(MutatorsCommand::command($composed(Ledger::empty(), FlowCommands::project())))->output)
        ->toBe(sprintf("%s\n", MutatorReport::NONE));
});

it('says why the config, the checkout or the default branch\'s ledger cannot be read, and exits 2', function (string $config, ProofStoreFake $store, RepositoryFake $repository, string $said): void {
    $project = FlowCommands::project($config);
    $registry = FlowCommands::registry(Flows::trees(), ScriptedRunner::fixture(), $store, Flows::ci(), $repository, Flows::checkout());
    $printed = FlowCommands::run(MutatorsCommand::command(FlowCommands::registered($registry, $project, Variables::of([]))));

    expect($printed->code)->toBe(2)
        ->and($printed->errors)->toContain($said);
})->with([
    'a config it refuses' => fn(): array => ['"budget": "soon"', new ProofStoreFake(), RepositoryFake::onMain(Revision::ref(Flows::HEAD)), 'budget'],
    'no commit checked out' => fn(): array => ['', new ProofStoreFake(), new RepositoryFake(CannotTell::because('There is no commit.'), Scope::branch('main'), Scope::branch('main')), 'There is no commit.'],
    'an unreadable ledger' => fn(): array => ['', ProofStoreFake::unreadable(Unreadable::because(UnreadReason::Malformed, 'ledger.json.gz', 'not a ledger')), RepositoryFake::onMain(Revision::ref(Flows::HEAD)), 'not a ledger'],
]);

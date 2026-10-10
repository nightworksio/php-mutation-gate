<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Command\WatchCommand;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Report\Problems;
use NightWorksIO\MutationGate\Core\Runner\CoverageRan;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Test\TestPaths;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\ChangingCheckout;
use NightWorksIO\MutationGate\Tests\Support\CoverageAsked;
use NightWorksIO\MutationGate\Tests\Support\FlowCommands;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

afterEach(function (): void {
    Scratch::sweep();
});

const WATCHING = "Watching the trees and the tests for changes. Ctrl-C stops.\n";

const ARRIVED = "A change arrived, so the round stops and the gate judges again.\n";

/**
 * A wait that changes the checkout on its first call, and says to go on for
 * this many waits.
 *
 * @return Closure(): bool
 */
function waitsChanging(ChangingCheckout $checkout, int $waits): Closure
{
    $waited = 0;

    return static function () use ($checkout, $waits, &$waited): bool {
        ++$waited;
        $checkout->change();

        return $waited <= $waits;
    };
}

/** `watch` as the command line holds it, beside the global `--budget`, waiting as this says. */
function watchBesideBudget(Composition $composition, Closure $waited): Command
{
    $command = WatchCommand::command($composition, $waited);
    $application = new Application();
    $application->getDefinition()->addOption(new InputOption('budget', mode: InputOption::VALUE_REQUIRED));
    $application->addCommand($command);

    return $command;
}

/** The flows' checkout, becoming one where this file holds something else. */
$changing = static fn(string $path = 'src/Money.php'): ChangingCheckout => ChangingCheckout::becoming(
    ChangingCheckout::with($path, "<?php\n\n// changed\n"),
);

/** Each request for a coverage map, read, run for the whole suite or run for some test files, in order. */
$kinds = static fn(CoverageAsked $runner): array => array_values(array_filter(array_map(
    static fn(CoverageRun|CoverageRead|CoverageRan $asked): string => match (true) {
        $asked instanceof CoverageRead => CoverageRead::class,
        $asked instanceof CoverageRan => CoverageRan::class,
        $asked->tests() instanceof WholeSuite => CoverageRun::class,
        $asked->tests() instanceof TestPaths => sprintf(
            '%s %s',
            TestPaths::class,
            implode(' ', array_map(static fn(Path $file): string => $file->value(), [...$asked->tests()->files()])),
        ),
        default => '',
    },
    $runner->asked(),
), static fn(string $kind): bool => $kind !== ''));

it('runs a round from what is on disk, and stops when the wait says so', function () use ($changing, $kinds): void {
    $runner = new CoverageAsked(ScriptedRunner::fixture(), Flows::map());
    $composition = FlowCommands::watching(FlowCommands::project(), $runner, new ProofStoreFake(), $changing());

    $ran = FlowCommands::run(WatchCommand::command($composition, static fn(): bool => false));

    expect([$ran->code, $ran->errors])->toBe([0, ''])
        ->and($ran->output)->toStartWith(sprintf("%sWrote memory:refs/heads/main.\nWrote memory:refs/heads/main/coverage.json.gz.\nmutation-gate: passed\n", WATCHING))
        ->and($ran->output)->toContain("Trees\n  src scores 40.00%, below its floor of 50.00%.\n")
        ->and(substr_count($ran->output, 'mutation-gate: '))->toBe(1)
        ->and($kinds($runner))->toBe([CoverageRun::class]);
});

it('judges a round on the new code changed since HEAD, and holds no tree to its floor', function (): void {
    $changed = new ChangeSourceFake(
        Revision::head(),
        Changes::of(Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(16), Line::of(21)))),
        [Revision::workingTree()->name() => Flows::FILES, Revision::HEAD => Flows::FILES, Flows::MAIN => Flows::FILES],
    );
    $composition = FlowCommands::watching(FlowCommands::project(), ScriptedRunner::fixture(), new ProofStoreFake(), $changed);

    $ran = FlowCommands::run(WatchCommand::command($composition, static fn(): bool => false));

    expect($ran->code)->toBe(0)
        ->and($ran->output)->toContain("mutation-gate: failed\n")
        ->and($ran->output)->toContain("New code\n")
        ->and($ran->output)->toContain("src scores 40.00%, below its floor of 50.00%.\n");
});

it('runs a round after each change, measuring again only the test files whose entries the change moved', function (
    string $path,
    array $coverage,
) use ($kinds): void {
    $files = [...Flows::FILES, 'tests/HeldTest.php' => "<?php\n\nit('doubles', fn () => expect(2)->toBe(2));\n"];
    $project = FlowCommands::project();
    Scratch::write($project, 'tests/HeldTest.php', $files['tests/HeldTest.php']);
    $runner = new CoverageAsked(ScriptedRunner::fixture(), Flows::map());
    $checkout = ChangingCheckout::holding($files, ChangingCheckout::with($path, "<?php\n\n// changed\n", $files));
    $composition = FlowCommands::watching($project, $runner, new ProofStoreFake(), $checkout);

    $waits = waitsChanging($checkout, 1);
    $ran = FlowCommands::run(WatchCommand::command($composition, static function () use ($waits, $project, $path): bool {
        Scratch::write($project, $path, "<?php\n\n// changed\n");

        return $waits();
    }));

    expect($ran->code)->toBe(0)
        ->and(substr_count($ran->output, 'mutation-gate: '))->toBe(2)
        ->and($ran->output)->not->toContain(ARRIVED)
        ->and($kinds($runner))->toBe([CoverageRun::class, ...$coverage]);
})->with([
    'a source a test executed' => [
        'src/Money.php',
        [sprintf('%s tests/MoneyTest.php', TestPaths::class), CoverageRead::class],
    ],
    'a test' => [
        'tests/MoneyTest.php',
        [sprintf('%s tests/MoneyTest.php', TestPaths::class), CoverageRead::class],
    ],
    'support no test uses' => ['tests/Support/Clock.php', [CoverageRead::class]],
    'a file that defines the runner' => ['tests/Pest.php', [CoverageRun::class]],
]);

it('builds the map again after a round that could not plan, and says why each round could not', function () use (
    $changing,
    $kinds,
): void {
    $runner = new CoverageAsked(ScriptedRunner::fixture(), CannotJudge::because('1 test failed.'));
    $checkout = $changing();
    $composition = FlowCommands::watching(FlowCommands::project(), $runner, new ProofStoreFake(), $checkout);

    $ran = FlowCommands::run(WatchCommand::command($composition, waitsChanging($checkout, 1)));

    expect($ran->code)->toBe(0)
        ->and(substr_count($ran->errors, '1 test failed.'))->toBe(2)
        ->and($kinds($runner))->toBe([CoverageRun::class, CoverageRun::class]);
});

it('waits on through a change outside the trees and the tests', function () use ($changing, $kinds): void {
    $runner = new CoverageAsked(ScriptedRunner::fixture(), Flows::map());
    $checkout = $changing('README.md');
    $composition = FlowCommands::watching(FlowCommands::project(), $runner, new ProofStoreFake(), $checkout);

    $ran = FlowCommands::run(WatchCommand::command($composition, waitsChanging($checkout, 3)));

    expect($ran->code)->toBe(0)
        ->and(substr_count($ran->output, 'mutation-gate: '))->toBe(1)
        ->and($kinds($runner))->toBe([CoverageRun::class]);
});

it('stops a round a change arrives in, prints none of it, and judges again at once', function (): void {
    $checkout = ChangingCheckout::becomingAt(3, ChangingCheckout::with('src/Money.php', "<?php\n\n// changed\n"));
    $composition = FlowCommands::watching(FlowCommands::project(), ScriptedRunner::fixture(), new ProofStoreFake(), $checkout);

    $ran = FlowCommands::run(WatchCommand::command($composition, static fn(): bool => false));

    expect($ran->code)->toBe(0)
        ->and($ran->output)->toStartWith(sprintf("%s%sWrote memory:refs/heads/main.\nWrote memory:refs/heads/main/coverage.json.gz.\nmutation-gate: passed\n", WATCHING, ARRIVED))
        ->and(substr_count($ran->output, 'mutation-gate: '))->toBe(1)
        ->and($ran->output)->toContain('2 run, 0 proved');
});

it('runs each round under local.watchBudget, over the config\'s budget, unless --budget sets one', function (
    string $config,
    string $options,
    bool $unjudged,
) use ($changing): void {
    $composition = FlowCommands::watching(
        FlowCommands::project($config),
        ScriptedRunner::fixture(),
        new ProofStoreFake(),
        $changing(),
    );

    $ran = FlowCommands::run(watchBesideBudget($composition, static fn(): bool => false), $options);

    expect($ran->code)->toBe(0)
        ->and(str_contains($ran->output, 'is unjudged'))->toBe($unjudged);
})->with([
    'the config\'s budget, which watch does not take' => ['"budget": "1s"', '', false],
    'too little for a round' => ['"local": {"watchBudget": "1s"}', '', true],
    'too little, as --budget sets it' => ['', '--budget=1s', true],
    'enough, as --budget sets it' => ['"local": {"watchBudget": "1s"}', '--budget=5m', false],
]);

it('prints each round as problems for an editor, framed for a background matcher, and says nothing else', function () use (
    $changing,
): void {
    $checkout = $changing();
    $composition = FlowCommands::watching(FlowCommands::project(), ScriptedRunner::fixture(), new ProofStoreFake(), $checkout);

    $ran = FlowCommands::run(WatchCommand::command($composition, waitsChanging($checkout, 1)), '--output=problems');

    expect($ran->code)->toBe(0)
        ->and($ran->output)->toStartWith(sprintf("%s\n", Problems::JUDGING))
        ->and(substr_count($ran->output, Problems::JUDGING))->toBe(2)
        ->and($ran->output)->not->toContain('Watching');
});

it('cannot watch files it cannot list, then or later, an output it cannot print, or a config it cannot read', function (
    string $config,
    string $options,
    int $unlistedFrom,
    string $why,
): void {
    $checkout = ChangingCheckout::becomingAt($unlistedFrom, CannotTell::because('git is gone.'));
    $composition = FlowCommands::watching(FlowCommands::project($config), ScriptedRunner::fixture(), new ProofStoreFake(), $checkout);

    $ran = FlowCommands::run(WatchCommand::command($composition, waitsChanging($checkout, 1)), $options);

    expect($ran->code)->toBe(2)
        ->and($ran->errors)->toContain($why);
})->with([
    'at the start' => ['', '', 1, 'git is gone.'],
    'after a round' => ['', '', PHP_INT_MAX, 'git is gone.'],
    'an output' => ['', '--output=nowhere', PHP_INT_MAX, '--output is console or problems, not "nowhere".'],
    'a config' => ['"floors": 5', '', PHP_INT_MAX, 'floors'],
]);

it('takes the options that print for an editor', function () use ($changing): void {
    $definition = WatchCommand::command(
        FlowCommands::watching(FlowCommands::project(), ScriptedRunner::fixture(), new ProofStoreFake(), $changing()),
        static fn(): bool => false,
    )->getDefinition();

    expect($definition->hasOption('output'))->toBeTrue()
        ->and($definition->hasOption('only'))->toBeTrue()
        ->and($definition->hasOption('changed-since'))->toBeFalse();
});

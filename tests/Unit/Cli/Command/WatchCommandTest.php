<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Command\CoverageCommand;
use NightWorksIO\MutationGate\Cli\Command\WatchCommand;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\MeasuredAt;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Report\Problems;
use NightWorksIO\MutationGate\Core\Runner\CoverageRan;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Test\TestPaths;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Fakes\ChangeSourceFake;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Fakes\RepositoryFake;
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

/**
 * A project of the flows whose config holds these members, each round
 * judging by the config as it stands, ending with its whole report and
 * stopped whole by a change, unless the members set `local.quick`,
 * `local.streamBatches` or `local.cancelByUnit` themselves.
 */
function watchedProject(string $config = ''): string
{
    $decoded = json_decode(sprintf('{%s}', $config), associative: true);
    $members = is_array($decoded) ? $decoded : [];
    $local = array_key_exists('local', $members) && is_array($members['local']) ? $members['local'] : [];
    $members['local'] = ['quick' => false, 'streamBatches' => false, 'cancelByUnit' => false, ...$local];

    return FlowCommands::project(mb_substr((string) json_encode($members), 1, -1));
}

/**
 * The flows' files with a test of `src/Held.php`, which the fixture's map
 * holds, written into this project too.
 *
 * @return array<string, string>
 */
function withHeldTest(string $project): array
{
    $files = [...Flows::FILES, 'tests/HeldTest.php' => "<?php\n\nit('doubles', fn () => expect(2)->toBe(2));\n"];
    Scratch::write($project, 'tests/HeldTest.php', $files['tests/HeldTest.php']);

    return $files;
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
    $composition = FlowCommands::watching(watchedProject('"local": {"streamBatches": false}'), $runner, new ProofStoreFake(), $changing());

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
    $composition = FlowCommands::watching(watchedProject(), ScriptedRunner::fixture(), new ProofStoreFake(), $changed);

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
    $project = watchedProject();
    $files = withHeldTest($project);
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
        fn(): array => [sprintf('%s tests/MoneyTest.php', TestPaths::class), CoverageRead::class],
    ],
    'a test' => [
        'tests/MoneyTest.php',
        fn(): array => [sprintf('%s tests/MoneyTest.php', TestPaths::class), CoverageRead::class],
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
    $composition = FlowCommands::watching(watchedProject(), $runner, new ProofStoreFake(), $checkout);

    $ran = FlowCommands::run(WatchCommand::command($composition, waitsChanging($checkout, 1)));

    expect($ran->code)->toBe(0)
        ->and(substr_count($ran->errors, '1 test failed.'))->toBe(2)
        ->and($kinds($runner))->toBe([CoverageRun::class, CoverageRun::class]);
});

/**
 * `watch` in a project whose working tree is dirty, so its proofs keep no
 * coverage map, for this many rounds, each after the one before edits
 * src/Money.php.
 */
$dirtyRounds = static function (string $project, CoverageAsked $runner, int $rounds): void {
    $files = withHeldTest($project);
    $checkout = ChangingCheckout::holding($files, ChangingCheckout::with('src/Money.php', "<?php\n\n// changed\n", $files));
    $composition = FlowCommands::reading(
        Flows::trees(),
        $project,
        $runner,
        new ProofStoreFake(),
        Flows::ci(),
        Variables::of([]),
        RepositoryFake::onMain(Revision::ref(Flows::HEAD))->changed(),
        $checkout,
    );
    $waits = waitsChanging($checkout, $rounds - 1);
    $edits = 0;

    FlowCommands::run(WatchCommand::command($composition, static function () use ($waits, $project, &$edits): bool {
        ++$edits;
        Scratch::write($project, 'src/Money.php', sprintf("<?php\n\n// edit %d\n", $edits));

        return $waits();
    }));
};

it('measures a round after the first against the map the last round left, which the proofs do not keep', function () use (
    $dirtyRounds,
    $kinds,
): void {
    $runner = new CoverageAsked(ScriptedRunner::fixture(), Flows::map());

    $dirtyRounds(watchedProject(), $runner, 2);

    expect($kinds($runner))->toBe([CoverageRun::class, sprintf('%s tests/MoneyTest.php', TestPaths::class), CoverageRead::class]);
});

it('measures its first round, and each after a round that could not plan, without a map an earlier watch left', function () use (
    $dirtyRounds,
    $kinds,
): void {
    $project = watchedProject();
    $dirtyRounds($project, new CoverageAsked(ScriptedRunner::fixture(), Flows::map()), 1);
    $failing = new CoverageAsked(ScriptedRunner::fixture(), CannotJudge::because('1 test failed.'));

    $dirtyRounds($project, $failing, 2);

    expect($kinds($failing))->toBe([CoverageRun::class, CoverageRun::class]);
});

it('waits on through a change outside the trees and the tests', function () use ($changing, $kinds): void {
    $runner = new CoverageAsked(ScriptedRunner::fixture(), Flows::map());
    $checkout = $changing('README.md');
    $composition = FlowCommands::watching(watchedProject(), $runner, new ProofStoreFake(), $checkout);

    $ran = FlowCommands::run(WatchCommand::command($composition, waitsChanging($checkout, 3)));

    expect($ran->code)->toBe(0)
        ->and(substr_count($ran->output, 'mutation-gate: '))->toBe(1)
        ->and($kinds($runner))->toBe([CoverageRun::class]);
});

it('stops a round a change arrives in, prints none of it, and judges again at once', function (): void {
    $checkout = ChangingCheckout::becomingAt(3, ChangingCheckout::with('src/Money.php', "<?php\n\n// changed\n"));
    $composition = FlowCommands::watching(watchedProject('"local": {"watchPoll": "0s", "streamBatches": false}'), ScriptedRunner::fixture(), new ProofStoreFake(), $checkout);

    $ran = FlowCommands::run(WatchCommand::command($composition, static fn(): bool => false));

    expect($ran->code)->toBe(0)
        ->and($ran->output)->toStartWith(sprintf("%s%sWrote memory:refs/heads/main.\nWrote memory:refs/heads/main/coverage.json.gz.\nmutation-gate: passed\n", WATCHING, ARRIVED))
        ->and(substr_count($ran->output, 'mutation-gate: '))->toBe(1)
        ->and($ran->output)->toContain('2 run, 0 proved');
});

it('looks for a change while a round runs only once a wait has passed, and judges what one finds after it', function (): void {
    $checkout = ChangingCheckout::becomingAt(3, ChangingCheckout::with('src/Money.php', "<?php\n\n// changed\n"));
    $composition = FlowCommands::watching(watchedProject(), ScriptedRunner::fixture(), new ProofStoreFake(), $checkout);

    $ran = FlowCommands::run(WatchCommand::command($composition, static fn(): bool => false));

    expect($ran->code)->toBe(0)
        ->and($ran->output)->not->toContain(ARRIVED)
        ->and(substr_count($ran->output, 'mutation-gate: '))->toBe(2);
});

it('prints what each batch judged as it ends, before the round\'s verdict --report asks for, unless local.streamBatches is off', function (string $config, string $printed) use ($changing): void {
    $composition = FlowCommands::watching(watchedProject($config), ScriptedRunner::fixture(), new ProofStoreFake(), $changing());

    $ran = FlowCommands::run(WatchCommand::command($composition, static fn(): bool => false), '--whole-report');

    expect($ran->code)->toBe(0)
        ->and($ran->output)->toStartWith(sprintf("%s%sWrote memory:refs/heads/main.\n", WATCHING, $printed));
})->with([
    'streamed' => [
        '"local": {"streamBatches": true}',
        "src/Held.php: judged 1 mutant: 1 survived.\n  survived: src/Held.php:11 Plus\nsrc/Money.php: judged 4 mutants: 1 killed, 1 survived, 1 timed-out, 1 uncovered.\n  survived: src/Money.php:16 GreaterThan\n",
    ],
    'off' => ['"local": {"streamBatches": false}', ''],
]);

it('hashes every file each look where local.watchStatus is off', function (): void {
    $checkout = ChangingCheckout::becoming(ChangingCheckout::with('src/Money.php', "<?php\n\n// changed\n"));
    $composition = FlowCommands::watching(watchedProject('"local": {"watchStatus": false}'), ScriptedRunner::fixture(), new ProofStoreFake(), $checkout);

    $ran = FlowCommands::run(WatchCommand::command($composition, waitsChanging($checkout, 1)));

    expect($ran->code)->toBe(0)
        ->and(substr_count($ran->output, 'mutation-gate: '))->toBe(2);
});

it('runs each round under local.watchBudget, over the config\'s budget, unless --budget sets one', function (
    string $config,
    string $options,
    bool $unjudged,
) use ($changing): void {
    $composition = FlowCommands::watching(
        watchedProject($config),
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
    $composition = FlowCommands::watching(watchedProject(), ScriptedRunner::fixture(), new ProofStoreFake(), $checkout);

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
    $composition = FlowCommands::watching(watchedProject($config), ScriptedRunner::fixture(), new ProofStoreFake(), $checkout);

    $ran = FlowCommands::run(WatchCommand::command($composition, waitsChanging($checkout, 1)), $options);

    expect($ran->code)->toBe(2)
        ->and($ran->errors)->toContain($why);
})->with([
    'at the start' => ['', '', 1, 'git is gone.'],
    'after a round' => ['', '', PHP_INT_MAX, 'git is gone.'],
    'an output' => ['', '--output=nowhere', PHP_INT_MAX, '--output is console, problems or events, not "nowhere".'],
    'a config' => ['"floors": 5', '', PHP_INT_MAX, 'floors'],
]);

it('takes the options that print for an editor', function () use ($changing): void {
    $definition = WatchCommand::command(
        FlowCommands::watching(watchedProject(), ScriptedRunner::fixture(), new ProofStoreFake(), $changing()),
        static fn(): bool => false,
    )->getDefinition();

    expect($definition->hasOption('output'))->toBeTrue()
        ->and($definition->hasOption('only'))->toBeTrue()
        ->and($definition->hasOption('changed-since'))->toBeFalse();
});

it('waits local.watchPoll between two looks', function () use ($changing): void {
    $runner = new CoverageAsked(ScriptedRunner::fixture(), Flows::map());
    $composition = FlowCommands::watching(watchedProject('"local": {"watchPoll": "7s"}'), $runner, new ProofStoreFake(), $changing());
    $waited = [];

    FlowCommands::run(WatchCommand::command($composition, static function (Seconds $interval) use (&$waited): bool {
        $waited[] = $interval;

        return false;
    }));

    expect($waited)->toEqual([Seconds::of(7.0)]);
});

/** A map `coverage --into` wrote of the fixture's suite at the flows' head, in a working tree as clean or dirty as this says. */
function carriedMap(string $project, bool $dirty): void
{
    Scratch::write(
        $project,
        '.mutation-gate/carried/map.json.gz',
        CoverageMapFile::encode(Flows::map(), MeasuredAt::of(Revision::ref(Flows::HEAD), $dirty)),
    );
}

it('ends after the rounds --rounds counts though the wait goes on, passed where the last round judged', function () use (
    $changing,
    $kinds,
): void {
    $runner = new CoverageAsked(ScriptedRunner::fixture(), Flows::map());
    $composition = FlowCommands::watching(watchedProject(), $runner, new ProofStoreFake(), $changing());

    $ran = FlowCommands::run(WatchCommand::command($composition, static fn(): bool => true), '--rounds=1');

    expect([$ran->code, $ran->errors])->toBe([0, ''])
        ->and(substr_count($ran->output, 'mutation-gate: '))->toBe(1)
        ->and($kinds($runner))->toBe([CoverageRun::class]);
});

it('fails where the last round --rounds counts could not judge, saying why', function () use ($changing): void {
    $failing = new CoverageAsked(ScriptedRunner::fixture(), CannotJudge::because('1 test failed.'));
    $composition = FlowCommands::watching(watchedProject(), $failing, new ProofStoreFake(), $changing());

    $ran = FlowCommands::run(WatchCommand::command($composition, static fn(): bool => true), '--rounds=1');

    expect($ran->code)->not->toBe(0)
        ->and(sprintf('%s%s', $ran->output, $ran->errors))->toContain('1 test failed.');
});

it('starts its first round from the map coverage --into carried, measuring again only what changed from its commit', function () use (
    $kinds,
): void {
    $project = watchedProject('"local": {"quick": true}');
    $files = withHeldTest($project);
    $runner = new CoverageAsked(ScriptedRunner::fixture(), Flows::map());
    $changes = new ChangeSourceFake(
        Revision::head(),
        Changes::of(),
        [Revision::workingTree()->name() => $files, Revision::HEAD => $files, Flows::MAIN => $files],
    )->changedFrom(
        Revision::ref(Flows::HEAD),
        Changes::of(Change::modified(Path::of('tests/MoneyTest.php'), Lines::of(Line::of(3)))),
    );
    $composition = FlowCommands::watching($project, $runner, new ProofStoreFake(), $changes);
    $carried = FlowCommands::run(CoverageCommand::command($composition), '--into=.mutation-gate/carried');

    $ran = FlowCommands::run(
        WatchCommand::command($composition, static fn(): bool => false),
        '--rounds=1 --from-map=.mutation-gate/carried',
    );

    expect([$carried->code, $ran->code, $ran->errors])->toBe([0, 0, ''])
        ->and($ran->output)->not->toContain('cannot be carried')
        ->and($kinds($runner))->toBe([CoverageRun::class, sprintf('%s tests/MoneyTest.php', TestPaths::class), CoverageRead::class]);
});

it('measures every test in its first round where the carried map was measured in a dirty tree, or is not there, and says why', function (
    bool $written,
    string $said,
) use ($changing, $kinds): void {
    $project = watchedProject('"local": {"quick": true, "fromMap": ".mutation-gate/carried"}');

    if ($written) {
        carriedMap($project, dirty: true);
    }

    $runner = new CoverageAsked(ScriptedRunner::fixture(), Flows::map());
    $composition = FlowCommands::watching($project, $runner, new ProofStoreFake(), $changing());

    $ran = FlowCommands::run(WatchCommand::command($composition, static fn(): bool => false), '--rounds=1');

    expect($ran->code)->toBe(0)
        ->and($ran->output)->toContain('The map at .mutation-gate/carried cannot be carried into the first round: ')
        ->and($ran->output)->toContain($said)
        ->and($kinds($runner))->toBe([CoverageRun::class]);
})->with([
    'measured in a dirty tree' => [true, 'was measured in a dirty working tree'],
    'not there' => [false, 'map.json.gz'],
]);
/**
 * Each event line of a watch's output, by its site, status, killer and whether its round was quick.
 *
 * @return list<string>
 */
function watchEvents(string $output): array
{
    $events = [];

    foreach (array_filter(explode("\n", $output), static fn(string $line): bool => $line !== '') as $line) {
        $event = Node::decode($line);
        $by = $event->field('by');
        $events[] = sprintf(
            '%s %s by %s%s',
            $event->field('site')->text(),
            $event->field('status')->text(),
            $by->kind() === Kind::Text ? $by->text() : 'none',
            $event->field('quick')->boolean() ? ' (quick)' : '',
        );
    }

    return $events;
}

it('says each mutant a batch judged as one line of JSON with --output=events, and prints no verdict', function () use ($changing): void {
    $composition = FlowCommands::watching(watchedProject(), ScriptedRunner::fixture(), new ProofStoreFake(), $changing());

    $ran = FlowCommands::run(WatchCommand::command($composition, static fn(): bool => false), '--output=events');

    expect([$ran->code, $ran->errors])->toBe([0, ''])
        ->and(watchEvents($ran->output))->toBe([
            'src/Held.php:11 survived by none',
            'src/Money.php:11 killed by MoneyTest::adds',
            'src/Money.php:16 survived by none',
            'src/Money.php:21 survived by none',
            'src/Money.php:27 timeout by none',
        ]);
});

it('takes each round after one that planned as quick under local.quick, the saved file first, each event saying so', function (): void {
    $project = watchedProject('"local": {"quick": true}');
    $files = withHeldTest($project);
    $checkout = ChangingCheckout::holding($files, ChangingCheckout::with('src/Money.php', "<?php\n\n// changed\n", $files));
    $composition = FlowCommands::watching($project, ScriptedRunner::fixture(), new ProofStoreFake(), $checkout);
    $waits = waitsChanging($checkout, 1);

    $ran = FlowCommands::run(WatchCommand::command($composition, static function () use ($waits, $project): bool {
        Scratch::write($project, 'src/Money.php', "<?php\n\n// changed\n");

        return $waits();
    }), '--output=events');

    expect($ran->code)->toBe(0)
        ->and(array_slice(watchEvents($ran->output), 5))->toBe([
            'src/Money.php:11 killed by MoneyTest::adds (quick)',
            'src/Money.php:16 survived by none (quick)',
            'src/Money.php:21 survived by none (quick)',
            'src/Money.php:27 timeout by none (quick)',
            'src/Held.php:11 survived by none (quick)',
        ]);
});

it('leaves the units a change moved to the next round where local.cancelByUnit is on, and says so', function (): void {
    $checkout = ChangingCheckout::becomingAt(3, ChangingCheckout::with('src/Money.php', "<?php\n\n// changed\n"));
    $composition = FlowCommands::watching(watchedProject('"local": {"watchPoll": "0s", "cancelByUnit": true}'), ScriptedRunner::fixture(), new ProofStoreFake(), $checkout);

    $ran = FlowCommands::run(WatchCommand::command($composition, static fn(): bool => false));

    expect($ran->code)->toBe(0)
        ->and($ran->output)->toStartWith(sprintf("%sA change arrived: the units it moved wait for the next round, which judges again.\nWrote memory:refs/heads/main.\n", WATCHING))
        ->and(substr_count($ran->output, 'mutation-gate: '))->toBe(1);
});

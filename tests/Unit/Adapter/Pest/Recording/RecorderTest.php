<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Bridged;
use NightWorksIO\MutationGate\Adapter\Pest\PestStatus;
use NightWorksIO\MutationGate\Adapter\Pest\PlannedMutant;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Off;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\OnFinishMutationSuite;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\OnStartMutationGeneration;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\OnStartMutationSuite;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\OnTested;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\OnTimeout;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\OnUncovered;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\OnUntested;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordEvent;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Twins;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Tests\Support\Mutations;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Pest\Mutate\Event\Events\Test\Outcome\TestedSubscriber;
use Pest\Mutate\Event\Events\Test\Outcome\TimeoutSubscriber;
use Pest\Mutate\Event\Events\Test\Outcome\UncoveredSubscriber;
use Pest\Mutate\Event\Events\Test\Outcome\UntestedSubscriber;
use Pest\Mutate\Event\Events\TestSuite\FinishMutationSuiteSubscriber;
use Pest\Mutate\Event\Events\TestSuite\StartMutationGenerationSubscriber;
use Pest\Mutate\Event\Events\TestSuite\StartMutationSuiteSubscriber;
use Pest\Mutate\Event\Facade;
use Pest\Mutate\Mutation;
use Pest\Mutate\Mutators\String\UnwrapWordwrap;
use Pest\Mutate\Support\MutationTestResult;
use Pest\Mutate\Support\MutatorMap;
use Symfony\Component\Finder\SplFileInfo;

afterEach(function (): void {
    Scratch::sweep();
});

it('records nowhere unless the adapter names a results file', function (): void {
    $events = new Facade();

    expect(Recorder::listening(
        results: false,
        mutant: false,
        events: $events,
        coverage: '/c',
        telemetry: 'telemetry',
    ))->toBe(Off::Recording)
        ->and(Recorder::listening(
            results: '',
            mutant: false,
            events: $events,
            coverage: '/c',
            telemetry: 'telemetry',
        ))->toBe(Off::Recording)
        ->and($events->subscribers())->toBe([]);
});

it('records nothing in a mutant\'s own process', function (): void {
    $events = new Facade();

    expect(Recorder::listening('/r/results.jsonl', '/p/Money.php', $events, '/c', 'telemetry'))->toBe(Off::Recording)
        ->and(Recorder::listening('/r/results.jsonl', '', $events, '/c', 'telemetry'))->toBe(Off::Recording)
        ->and($events->subscribers())->toBe([]);
});

it('subscribes to every event it records, one subscriber for each', function (): void {
    $events = new Facade();
    $recorder = Recorder::listening(
        results: '/r/results.jsonl',
        mutant: false,
        events: $events,
        coverage: '/c',
        telemetry: 'telemetry',
    );
    $subscribers = $events->subscribers();

    expect($recorder)->toEqual(new Recorder('/r/results.jsonl', '/c', 'telemetry'))
        ->and(array_keys($subscribers))->toBe([
            StartMutationGenerationSubscriber::class,
            StartMutationSuiteSubscriber::class,
            TestedSubscriber::class,
            UntestedSubscriber::class,
            TimeoutSubscriber::class,
            UncoveredSubscriber::class,
            FinishMutationSuiteSubscriber::class,
        ])
        ->and(array_map(static fn(array $each): array => array_map(get_class(...), $each), $subscribers))->toBe([
            StartMutationGenerationSubscriber::class => [OnStartMutationGeneration::class],
            StartMutationSuiteSubscriber::class => [OnStartMutationSuite::class],
            TestedSubscriber::class => [OnTested::class],
            UntestedSubscriber::class => [OnUntested::class],
            TimeoutSubscriber::class => [OnTimeout::class],
            UncoveredSubscriber::class => [OnUncovered::class],
            FinishMutationSuiteSubscriber::class => [OnFinishMutationSuite::class],
        ]);
});

it('records where the environment asks, outside a mutant\'s own process', function (): void {
    $mutant = getenv('PEST_MUTATION_TESTING');
    $subscribed = Facade::instance()->subscribers();

    try {
        putenv('PEST_MUTATION_TESTING');
        putenv('MUTATION_GATE_RESULTS=/r/results.jsonl');
        $asked = Recorder::fromEnvironment();
        putenv('MUTATION_GATE_RESULTS');
        $unasked = Recorder::fromEnvironment();
    } finally {
        putenv('MUTATION_GATE_RESULTS');
        putenv(is_string($mutant) ? sprintf('PEST_MUTATION_TESTING=%s', $mutant) : 'PEST_MUTATION_TESTING');
        // Pest's facade is global: leave it with the subscribers it had.
        (function () use ($subscribed): void {
            $this->subscribers = $subscribed;
        })->call(Facade::instance());
    }

    expect($asked)->toBeInstanceOf(Recorder::class)
        ->and($unasked)->toBe(Off::Recording);
});

it('keeps the opening run\'s coverage map beside the results before Pest deletes it', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'pest/coverage.php', '<?php return [];');
    Mutations::recorder(sprintf('%s/results.jsonl', $root), sprintf('%s/pest/coverage.php', $root))->keepCoverage();

    expect(file_get_contents(sprintf('%s/results.jsonl.coverage.php', $root)))->toBe('<?php return [];')
        ->and(Recorder::coverageBeside('/r/results.jsonl'))->toBe('/r/results.jsonl.coverage.php');
});

it('keeps a mutant\'s mutated copy by its id beside the results, making the directory it needs', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'tmp/abc', '<?php return 1;');
    $test = Mutations::test('/p/src/Money.php', 'id-7', MutationTestResult::Uncovered, sprintf('%s/tmp/abc', $root));
    $recorder = Mutations::recorder(sprintf('%s/pest/results.jsonl', $root), '/c');

    $recorder->keepMutant($test);
    $recorder->keepMutant($test);

    expect(file_get_contents(sprintf('%s/pest/mutants/id-7.php', $root)))->toBe('<?php return 1;')
        ->and(Recorder::mutantBeside('/r/pest/results.jsonl', 'id-7'))->toBe('/r/pest/mutants/id-7.php');
});

it('records every planned mutant with its file, lines, mutator class, diff and mutated copy, then how many', function (): void {
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'src/Money.php', '<?php');
    $results = sprintf('%s/results.jsonl', $root);
    $suite = Mutations::suite(sprintf('%s/src/Money.php', $root), MutationTestResult::None, MutationTestResult::None);
    Mutations::recorder($results, '/c')->planned($suite);
    new Recorder(sprintf('%s/unknown.jsonl', $root), '/c', 'not a repository')->planned(Mutations::suite('/p/None.php'));

    $mutant = static fn(string $id, int $line): PlannedMutant => PlannedMutant::of(
        $id,
        DiskPath::of(sprintf('%s/src/Money.php', $root)),
        Line::of($line),
        Line::of($line + 1),
        Mutations::PLUS,
        "  <fg=red>-        return \$a + \$b;</>\n  <fg=green>+        return \$a - \$b;</>\n",
        DiskPath::of('/nowhere/mutated'),
    );

    expect(Mutations::recorded($results))->toBe([
        RecordLine::planned($mutant('id-1', 11)),
        RecordLine::planned($mutant('id-2', 12)),
        RecordLine::made(2, Seconds::of(1.5)),
    ])->and(Mutations::recorded(sprintf('%s/unknown.jsonl', $root)))->toBe([
        RecordLine::made(0, Unmeasured::duration()),
    ]);
});

it('records each mutant kept out as a twin after those Pest planned, before how many Pest made', function (): void {
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'src/Money.php', '<?php');
    $results = sprintf('%s/twinned.jsonl', $root);
    $file = sprintf('%s/src/Money.php', $root);
    Twins::isTwin(Mutations::mutation($file, 'id-1', 11), $results);
    Twins::isTwin(Mutations::mutation($file, 'twin', 11), $results);
    Mutations::recorder($results, '/c')->planned(Mutations::suite($file, MutationTestResult::None));

    expect(Mutations::recorded($results))->toBe([
        RecordLine::planned(Recorder::madeOf(Mutations::mutation($file, 'id-1', 11))),
        RecordLine::planned(Recorder::madeOf(Mutations::mutation($file, 'twin', 11)), RecordEvent::Twin),
        RecordLine::made(1, Seconds::of(1.5)),
    ]);
});

it('records each outcome as it arrives, one line of JSON with its slashes as they are', function (): void {
    $root = Scratch::directory();
    $results = sprintf('%s/results.jsonl', $root);
    $recorder = Mutations::recorder($results, '/c');
    $recorder->outcome(Mutations::test('/p/src/Money.php', 'a/b', MutationTestResult::Tested));
    $recorder->outcome(Mutations::test('/p/src/Money.php', "c\xff", MutationTestResult::Untested));

    expect(file_get_contents($results))->toBe(
        "{\"event\":\"outcome\",\"id\":\"a/b\",\"status\":\"tested\"}\n"
        . "{\"event\":\"outcome\",\"id\":\"c\\ufffd\",\"status\":\"untested\"}\n",
    );
});

it('records every mutant\'s final status and how long it ran, then the end', function (): void {
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'src/Money.php', '<?php');
    $results = sprintf('%s/results.jsonl', $root);
    $suite = Mutations::suite(sprintf('%s/src/Money.php', $root), MutationTestResult::Tested, MutationTestResult::None);

    foreach ($suite->repository->all() as $collection) {
        Mutations::timed($collection->tests()[0], 0.25);
    }

    Mutations::recorder($results, '/c')->finished($suite);

    expect(Mutations::recorded($results))->toBe([
        RecordLine::finished('id-1', PestStatus::Tested, 0.25),
        RecordLine::finished('id-2', PestStatus::None, 0.0),
        RecordLine::end(),
    ]);
});

it('names a planned mutant\'s bridged mutator by its own name, and any other by its class', function (): void {
    $bridged = new Mutation(
        new SplFileInfo('/p/src/Money.php', '', ''),
        'id-1',
        UnwrapWordwrap::class,
        11,
        12,
        '',
        '/nowhere/mutated',
    );

    try {
        Bridged::register(UnwrapWordwrap::class, 'acme/UnwrapWordwrap', []);

        expect(Recorder::madeOf($bridged)->mutator())->toBe('acme/UnwrapWordwrap')
            ->and(Recorder::madeOf(Mutations::test('/p/src/Money.php', 'id-2', MutationTestResult::None)->mutation)->mutator())
            ->toBe(Mutations::PLUS);
    } finally {
        MutatorMap::$map = null;
    }
});

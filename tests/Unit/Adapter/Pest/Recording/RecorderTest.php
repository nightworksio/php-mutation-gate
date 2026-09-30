<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Recording\Off;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\OnFinishMutationSuite;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\OnStartMutationGeneration;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\OnStartMutationSuite;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\OnTested;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\OnTimeout;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\OnUncovered;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\OnUntested;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
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
use Pest\Mutate\Support\MutationTestResult;

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

    try {
        putenv('PEST_MUTATION_TESTING');
        putenv('MUTATION_GATE_RESULTS=/r/results.jsonl');
        $asked = Recorder::fromEnvironment();
        putenv('MUTATION_GATE_RESULTS');
        $unasked = Recorder::fromEnvironment();
    } finally {
        putenv('MUTATION_GATE_RESULTS');
        putenv(is_string($mutant) ? sprintf('PEST_MUTATION_TESTING=%s', $mutant) : 'PEST_MUTATION_TESTING');
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

it('records every planned mutant with its file, lines, mutator class and diff', function (): void {
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'src/Money.php', '<?php');
    $results = sprintf('%s/results.jsonl', $root);
    $suite = Mutations::suite(sprintf('%s/src/Money.php', $root), MutationTestResult::None, MutationTestResult::None);
    Mutations::recorder($results, '/c')->planned($suite);

    expect(Mutations::recorded($results))->toBe([
        [
            'event' => 'planned',
            'id' => 'id-1',
            'file' => sprintf('%s/src/Money.php', $root),
            'start' => 11,
            'end' => 12,
            'mutator' => Mutations::PLUS,
            'diff' => "  <fg=red>-        return \$a + \$b;</>\n  <fg=green>+        return \$a - \$b;</>\n",
        ],
        [
            'event' => 'planned',
            'id' => 'id-2',
            'file' => sprintf('%s/src/Money.php', $root),
            'start' => 12,
            'end' => 13,
            'mutator' => Mutations::PLUS,
            'diff' => "  <fg=red>-        return \$a + \$b;</>\n  <fg=green>+        return \$a - \$b;</>\n",
        ],
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

it('records every mutant\'s final status and duration, then the opening run\'s seconds', function (): void {
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'src/Money.php', '<?php');
    $results = sprintf('%s/results.jsonl', $root);
    $suite = Mutations::suite(sprintf('%s/src/Money.php', $root), MutationTestResult::Tested, MutationTestResult::None);
    Mutations::recorder($results, '/c')->finished($suite);
    new Recorder(sprintf('%s/unknown.jsonl', $root), '/c', 'not a repository')->finished($suite);

    expect(Mutations::recorded($results))->toBe([
        ['event' => 'finished', 'id' => 'id-1', 'status' => 'tested', 'duration' => 0.0],
        ['event' => 'finished', 'id' => 'id-2', 'status' => 'none', 'duration' => 0.0],
        ['event' => 'end', 'opening' => 1.5],
    ])->and(Mutations::recorded(sprintf('%s/unknown.jsonl', $root)))->toBe([
        ['event' => 'finished', 'id' => 'id-1', 'status' => 'tested', 'duration' => 0.0],
        ['event' => 'finished', 'id' => 'id-2', 'status' => 'none', 'duration' => 0.0],
        ['event' => 'end', 'opening' => false],
    ]);
});

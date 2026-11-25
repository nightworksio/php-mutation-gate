<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\CoveringFiles;
use NightWorksIO\MutationGate\Adapter\Pest\Split\Streaming;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamPace;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamPlanFile;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamSlowdown;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamVerdicts;
use NightWorksIO\MutationGate\Adapter\Process\LiveProcesses;
use NightWorksIO\MutationGate\Core\Config\SlowdownCarry;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\Tree;

afterEach(function (): void {
    CoveringFiles::forget();
    Scratch::sweep();
});

it('cuts the test files into parts of about the same time, longest first, each beside its tests', function (): void {
    $units = [
        'tests/A.php' => ['files' => ['tests/A.php'], 'tests' => ['A::one', 'A::two']],
        'tests/B.php' => ['files' => ['tests/B.php'], 'tests' => ['B::one']],
        'tests/C.php' => ['files' => ['tests/C.php', 'tests/Helper.php'], 'tests' => ['C::one']],
    ];
    $times = ['A::one' => 2.0, 'A::two' => 2.0, 'B::one' => 3.0, 'C::one' => 1.0];

    expect(Streaming::cut($units, $times, 2))->toBe([
        ['files' => ['tests/A.php'], 'tests' => ['A::one', 'A::two']],
        ['files' => ['tests/C.php', 'tests/Helper.php', 'tests/B.php'], 'tests' => ['C::one', 'B::one']],
    ]);
});

it('gives its parts in their own order, whichever file the map lists first, and one empty part where there is no test file', function (): void {
    $units = [
        'tests/Short.php' => ['files' => ['tests/Short.php'], 'tests' => ['Short::one']],
        'tests/Long.php' => ['files' => ['tests/Long.php'], 'tests' => ['Long::one']],
    ];
    $times = ['Short::one' => 1.0, 'Long::one' => 9.0];

    expect(Streaming::cut($units, $times, 2))->toBe([
        ['files' => ['tests/Long.php'], 'tests' => ['Long::one']],
        ['files' => ['tests/Short.php'], 'tests' => ['Short::one']],
    ])
        ->and(Streaming::cut($units, $times, 0))->toBe(Streaming::cut($units, $times, 1))
        ->and(Streaming::cut([], [], 3))->toBe([['files' => [], 'tests' => []]]);
});

it('runs a part\'s files fastest first, one the map timed no test of after every one it did, then by path', function (): void {
    $units = [
        'tests/Contract/SlowTest.php' => ['files' => ['tests/Contract/SlowTest.php'], 'tests' => ['Slow::one', 'Slow::two']],
        'tests/Unit/FastTest.php' => ['files' => ['tests/Unit/FastTest.php'], 'tests' => ['Fast::one']],
        'tests/Unit/NewTest.php' => ['files' => ['tests/Unit/NewTest.php'], 'tests' => ['New::one']],
        'tests/Unit/AlsoNewTest.php' => ['files' => ['tests/Unit/AlsoNewTest.php'], 'tests' => ['AlsoNew::one']],
        'tests/Unit/EvenTest.php' => ['files' => ['tests/Unit/EvenTest.php'], 'tests' => ['Even::one']],
    ];
    $times = ['Slow::one' => 30.0, 'Slow::two' => 30.0, 'Fast::one' => 0.1, 'Even::one' => 0.1];

    expect(Streaming::cut($units, $times, 1))->toBe([[
        'files' => [
            'tests/Unit/EvenTest.php',
            'tests/Unit/FastTest.php',
            'tests/Contract/SlowTest.php',
            'tests/Unit/AlsoNewTest.php',
            'tests/Unit/NewTest.php',
        ],
        'tests' => ['Even::one', 'Fast::one', 'Slow::one', 'Slow::two', 'AlsoNew::one', 'New::one'],
    ]]);
});

it('makes no more parts than there are test files', function (): void {
    $units = ['tests/A.php' => ['files' => ['tests/A.php'], 'tests' => ['A::one']]];

    expect(Streaming::cut($units, [], 8))->toBe([['files' => ['tests/A.php'], 'tests' => ['A::one']]]);
});

it('keeps a file two parts need in each, once', function (): void {
    $units = [
        'tests/A.php' => ['files' => ['tests/A.php', 'tests/Shared.php'], 'tests' => ['A::one']],
        'tests/B.php' => ['files' => ['tests/B.php', 'tests/Shared.php'], 'tests' => ['B::one']],
    ];

    expect(Streaming::cut($units, ['A::one' => 1.0, 'B::one' => 1.0], 2))->toBe([
        ['files' => ['tests/A.php', 'tests/Shared.php'], 'tests' => ['A::one']],
        ['files' => ['tests/B.php', 'tests/Shared.php'], 'tests' => ['B::one']],
    ]);
});

it('runs the whole suite in one part where it knows no test file', function (): void {
    expect(Streaming::cut([], [], 4))->toBe([['files' => [], 'tests' => []]]);
});

it('streams no pass of killers whose ids PHPUnit could not read back from a file, and writes none', function (): void {
    $directory = Scratch::directory();
    $streaming = new Streaming($directory, [], [], 1, 1, new LiveProcesses());

    expect($streaming->killersPass(1, ['m1' => ['f1', 1, "P\\Tests\\MoneySpec::it adds\nand more", []]], StreamVerdicts::none()))->toEqual(NotGiven::value())
        ->and(glob(sprintf('%s/*', $directory)))->toBe([]);
});

it('starts no stream of a pass whose tests no loaded file declares, leaving their mutants to their own runs', function (): void {
    $directory = Scratch::directory();
    CoveringFiles::forget(Scratch::directory());
    $verdicts = StreamVerdicts::none();
    $streaming = new Streaming($directory, [], [], 1, 1, new LiveProcesses());

    expect($streaming->pass(1, ['m1' => ['f1', 1, '', []]], ['m1' => ['Never\\Loaded::test']], $verdicts))->toBe($verdicts)
        ->and(glob(sprintf('%s/*', $directory)))->toBe([]);
});

it('names each stream\'s covering tests by their ids where it runs them alone, and none where it runs whole files or records lines for a kept map', function (bool $coveringOnly, bool $records, bool $named): void {
    $directory = Scratch::directory();
    $recording = $records ? Scratch::directory() : '';
    $test = sprintf('%s::it_streams', $this::class);
    CoveringFiles::forget(Tree::at('tests/Unit/Adapter/Pest/Split'));
    $streaming = new Streaming($directory, [], ['-r', 'exit(0);', '--'], 1, 1, new LiveProcesses(), $recording, coveringOnly: $coveringOnly);
    $streaming->pass(1, ['m1' => ['f1', 1, '', []]], ['m1' => [$test]], StreamVerdicts::none());
    $ids = sprintf('%s/selected-1-0.ids', $directory);

    expect(is_file($ids) ? file_get_contents($ids) : '')->toBe($named ? sprintf("%s\n", $test) : '');
})->with([
    'covering tests only' => [true, false, true],
    'whole files' => [false, false, false],
    'lines recorded for a kept map' => [true, true, false],
]);

it('starts each stream in a stopped one\'s place at the backoff times the slowdown the stopped one reached, so repeated stops grow it', function (): void {
    $directory = Scratch::directory();
    $class = $this::class;
    $tests = [sprintf('%s::first', $class), sprintf('%s::second', $class), sprintf('%s::third', $class)];
    CoveringFiles::forget(Tree::at('tests/Unit/Adapter/Pest/Split'));
    $stuck = <<<'PHP'
        $plan = json_decode((string) file_get_contents((string) getenv('MUTATION_GATE_SPLIT_PLAN')), true);
        $ids = array_values(array_filter(explode("\n", (string) file_get_contents($argv[2]))));
        file_put_contents(sprintf('%s/live-%d', $plan['live'], getmypid()), json_encode(['t' => $ids[0], 'd' => 0, 's' => $plan['slowdown'] + 0.5]));
        sleep(30);
        PHP;
    $streaming = new Streaming($directory, [], ['-r', $stuck, '--'], 1, 1, new LiveProcesses(), coveringOnly: true, pace: new StreamPace(on: true, backoff: 2.0));

    $streaming->pass(1, ['m1' => ['f1', 1, '', []]], ['m1' => $tests], StreamVerdicts::none());
    $slowdowns = [];

    foreach ([0, 1, 2] as $at) {
        $plan = StreamPlanFile::read(sprintf('%s/plan-1-%d.json', $directory, $at));
        $slowdowns[] = $plan === false ? 0.0 : $plan->slowdown;
    }

    expect($slowdowns)->toBe([1.0, 3.0, 7.0])
        ->and(is_file(sprintf('%s/plan-1-3.json', $directory)))->toBeFalse();
});

it('starts each pass\'s streams at the highest slowdown the streams of the pass before measured themselves at', function (): void {
    $directory = Scratch::directory();
    $results = sprintf('%s/results.json', Scratch::directory());
    $class = $this::class;
    CoveringFiles::forget(Tree::at('tests/Unit/Adapter/Pest/Split'));
    $measured = <<<'PHP'
        $plan = json_decode((string) file_get_contents((string) getenv('MUTATION_GATE_SPLIT_PLAN')), true);
        file_put_contents(sprintf('%s/live-%d', $plan['live'], getmypid()), json_encode(['m' => $plan['slowdown'] + 1.5]));
        PHP;
    $streaming = new Streaming(
        $directory,
        [],
        ['-r', $measured, '--'],
        1,
        1,
        new LiveProcesses(),
        coveringOnly: true,
        pace: new StreamPace(on: true),
        slowdown: StreamSlowdown::carried(SlowdownCarry::Shard, $results),
    );

    $streaming->pass(1, ['m1' => ['f1', 1, '', []]], ['m1' => [sprintf('%s::first', $class)]], StreamVerdicts::none());
    $streaming->pass(2, ['m1' => ['f1', 1, '', []]], ['m1' => [sprintf('%s::first', $class)]], StreamVerdicts::none());
    $slowdowns = [];

    foreach ([1, 2] as $pass) {
        $plan = StreamPlanFile::read(sprintf('%s/plan-%d-0.json', $directory, $pass));
        $slowdowns[] = $plan === false ? 0.0 : $plan->slowdown;
    }

    expect($slowdowns)->toBe([1.0, 2.5])
        ->and(StreamSlowdown::carried(SlowdownCarry::Shard, $results)->start())->toBe(4.0);
});

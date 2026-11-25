<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\KillerFile;
use NightWorksIO\MutationGate\Adapter\Pest\Split\Noted;
use NightWorksIO\MutationGate\Adapter\Pest\Split\OwnRuns;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamLine;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamVerdicts;
use NightWorksIO\MutationGate\Tests\Support\Mutations;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Pest\Mutate\Event\Facade;
use Pest\Mutate\MutationTest;
use Pest\Mutate\Support\MutationTestResult;

afterEach(function (): void {
    putenv(GateVariable::Results->value);
    Scratch::sweep();
});

/** Verdicts read from one stream's results, written as these lines. */
function streamResults(string ...$lines): StreamVerdicts
{
    $results = sprintf('%s/results-%s.jsonl', Scratch::directory(), uniqid());
    file_put_contents($results, implode('', $lines));

    return StreamVerdicts::none()->reading($results);
}

/**
 * What a stream's verdict gave a mutant of these covering tests: its result, and its killer file.
 *
 * @param  list<string>                      $covering
 * @return array{MutationTestResult, string}
 */
function streamGiven(StreamVerdicts $verdicts, array $covering): array
{
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    putenv(sprintf('%s=%s', GateVariable::Results->value, $results));
    $test = new MutationTest(Mutations::mutation('src/Money.php', 'm', 11, sprintf('%s/copy.php', Scratch::directory())));
    $events = new ReflectionProperty(Facade::class, 'instance');
    $held = $events->getValue();
    $events->setValue(null, null);

    try {
        $verdicts->give('m', $test, $covering);
    } finally {
        $events->setValue(null, $held);
    }

    $killers = KillerFile::beside($results, $test->mutation->modifiedSourcePath);

    return [$test->result(), Noted::taken($killers)];
}

it('kills a mutant at a test its child failed and the parent passed, naming that test', function (): void {
    $verdicts = streamResults(
        StreamLine::parent('T::one', passed: true, seconds: 0.1),
        StreamLine::parent('T::two', passed: true, seconds: 0.1),
        StreamLine::child('m', 'T::one', failed: false, seconds: 0.1),
        StreamLine::child('m', 'T::two', failed: true, seconds: 0.1),
    );
    [$result, $killers] = streamGiven($verdicts, ['T::one', 'T::two']);

    expect($result)->toBe(MutationTestResult::Tested)
        ->and($killers)->toBe(sprintf("%skilled T%%3A%%3Atwo 0 - -\n", KillerFile::streamed()))
        ->and($verdicts->decides('m', ['T::one', 'T::two']))->toBeTrue();
});

it('kills a mutant at a test its child ended in without finishing it', function (): void {
    $verdicts = streamResults(StreamLine::parent('T::one', passed: true, seconds: 0.1), StreamLine::endedChild('m', 'T::one', 255));

    expect(streamGiven($verdicts, ['T::one'])[0])->toBe(MutationTestResult::Tested);
});

it('kills nothing at a test the parent failed, and leaves the mutant for its own run', function (): void {
    $verdicts = streamResults(StreamLine::parent('T::one', passed: false, seconds: 0.1), StreamLine::child('m', 'T::one', failed: true, seconds: 0.1));

    expect(streamGiven($verdicts, ['T::one']))->toBe([MutationTestResult::None, ''])
        ->and($verdicts->decides('m', ['T::one']))->toBeFalse();
});

it('fails a test in the parent where any stream failed it', function (): void {
    $results = sprintf('%s/second.jsonl', Scratch::directory());
    file_put_contents($results, StreamLine::parent('T::one', passed: false, seconds: 0.1));
    $verdicts = streamResults(StreamLine::parent('T::one', passed: true, seconds: 0.1), StreamLine::child('m', 'T::one', failed: true, seconds: 0.1))
        ->reading($results);

    expect(streamGiven($verdicts, ['T::one'])[0])->toBe(MutationTestResult::None);
});

it('times a mutant out at a test the parent passed and its child overran', function (): void {
    $verdicts = streamResults(
        StreamLine::parent('T::one', passed: true, seconds: 0.1),
        StreamLine::parent('T::two', passed: false, seconds: 0.1),
        StreamLine::timedOut('m', 'T::one'),
    );

    expect(streamGiven($verdicts, ['T::one', 'T::two']))->toBe([MutationTestResult::Timeout, KillerFile::streamed()])
        ->and($verdicts->decides('m', ['T::one']))->toBeTrue();
});

it('lets a mutant survive every covering test the parent passed and its children passed or never reached', function (): void {
    $verdicts = streamResults(
        StreamLine::parent('T::one', passed: true, seconds: 0.1),
        StreamLine::parent('T::two', passed: true, seconds: 0.1),
        StreamLine::child('m', 'T::one', failed: false, seconds: 0.1),
    );

    expect(streamGiven($verdicts, ['T::one', 'T::two']))->toBe([MutationTestResult::Untested, KillerFile::streamed()])
        ->and($verdicts->decides('m', ['T::one', 'T::two']))->toBeFalse();
});

it('leaves a mutant for its own run where a covering test forked nothing, or never ran', function (string $other): void {
    $verdicts = streamResults(
        StreamLine::parent('T::one', passed: true, seconds: 0.1),
        StreamLine::parent('T::two', passed: true, seconds: 0.1),
        StreamLine::fallback('T::two'),
    );

    expect(streamGiven($verdicts, ['T::one', $other]))->toBe([MutationTestResult::None, '']);
})->with([
    'a test that forked nothing' => ['T::two'],
    'a test no stream ran' => ['T::three'],
]);

it('leaves for its own run only the mutant a covering test forked nowhere, and lets another survive that test', function (string $unforked, MutationTestResult $given): void {
    $verdicts = streamResults(
        StreamLine::parent('T::one', passed: true, seconds: 0.1),
        StreamLine::parent('T::two', passed: true, seconds: 0.1),
        StreamLine::fallback('T::two', $unforked),
    );

    expect(streamGiven($verdicts, ['T::one', 'T::two'])[0])->toBe($given);
})->with([
    'the mutant' => ['m', MutationTestResult::None],
    'another mutant' => ['n', MutationTestResult::Untested],
]);

it('reads no results where a stream wrote none, and skips what is no line of its own', function (): void {
    $verdicts = StreamVerdicts::none()->reading(sprintf('%s/missing.jsonl', Scratch::directory()));
    $garbled = streamResults("not json\n", "{\"k\":\"other\",\"t\":\"T::one\"}\n", "[1]\n");

    expect(streamGiven($verdicts, ['T::one'])[0])->toBe(MutationTestResult::None)
        ->and(streamGiven($garbled, ['T::one'])[0])->toBe(MutationTestResult::None);
});

it('leaves for its own run a mutant whose child failed only after the test touched nondeterminism past the fork', function (): void {
    $verdicts = streamResults(
        StreamLine::parent('T::one', passed: true, seconds: 0.1),
        StreamLine::child('m', 'T::one', failed: true, seconds: 0.1, touched: true),
    );

    expect(streamGiven($verdicts, ['T::one']))->toBe([MutationTestResult::None, '']);
});

it('leaves for its own run a mutant whose file the stream\'s process loaded before its schema was served, and names those its boot ran', function (): void {
    $verdicts = streamResults(
        StreamLine::parent('T::one', passed: true, seconds: 0.1),
        StreamLine::unserved('m'),
        StreamLine::booted('b'),
    );

    expect(streamGiven($verdicts, ['T::one']))->toBe([MutationTestResult::None, ''])
        ->and(streamGiven(streamResults(StreamLine::parent('T::one', passed: true, seconds: 0.1)), ['T::one'])[0])->not->toBe(MutationTestResult::None)
        ->and($verdicts->booted())->toBe(['b']);
});

it('counts the forks whose parent and child both read their state, and those that found it alike', function (): void {
    $verdicts = streamResults(
        StreamLine::state('m', 'T::one', child: false, digest: 'a'),
        StreamLine::state('m', 'T::one', child: true, digest: 'a'),
        StreamLine::state('m', 'T::two', child: false, digest: 'a'),
        StreamLine::state('m', 'T::two', child: true, digest: 'b'),
        StreamLine::state('m', 'T::three', child: false, digest: 'a'),
    );

    expect($verdicts->forkStates())->toBe([2, 1]);
});

it('says a mutant runs its own run where no stream gave it a result, and none a stream killed, timed out or let survive', function (Closure $lines, array $covering, bool $own): void {
    $verdicts = streamResults(...array_filter(is_array($made = $lines()) ? $made : [], is_string(...)));
    $tests = array_values(array_filter($covering, is_string(...)));
    $given = streamGiven($verdicts, $tests)[0];

    expect($verdicts->runsItsOwn('m', $tests))->toBe($own)
        ->and($given === MutationTestResult::None)->toBe($own);
})->with([
    'killed' => [
        static fn(): array => [StreamLine::parent('T::one', passed: true, seconds: 0.1), StreamLine::child('m', 'T::one', failed: true, seconds: 0.1)],
        ['T::one'],
        false,
    ],
    'timed out' => [
        static fn(): array => [StreamLine::parent('T::one', passed: true, seconds: 0.1), StreamLine::timedOut('m', 'T::one')],
        ['T::one'],
        false,
    ],
    'survived every covering test' => [
        static fn(): array => [StreamLine::parent('T::one', passed: true, seconds: 0.1), StreamLine::child('m', 'T::one', failed: false, seconds: 0.1)],
        ['T::one'],
        false,
    ],
    'a covering test forked nothing' => [
        static fn(): array => [StreamLine::parent('T::one', passed: true, seconds: 0.1), StreamLine::parent('T::two', passed: true, seconds: 0.1), StreamLine::fallback('T::two')],
        ['T::one', 'T::two'],
        true,
    ],
    'a covering test no stream ran' => [
        static fn(): array => [StreamLine::parent('T::one', passed: true, seconds: 0.1)],
        ['T::one', 'T::three'],
        true,
    ],
    'timed out at one test, another forked nothing' => [
        static fn(): array => [StreamLine::parent('T::one', passed: true, seconds: 0.1), StreamLine::timedOut('m', 'T::one'), StreamLine::fallback('T::two')],
        ['T::one', 'T::two'],
        false,
    ],
]);

it('counts as own runs every mutant the verdicts gave no result, whatever kept a stream from judging it, one whose id is all digits among them', function (): void {
    $verdicts = streamResults(
        StreamLine::parent('T::one', passed: true, seconds: 0.1),
        StreamLine::child('k', 'T::one', failed: true, seconds: 0.1),
        StreamLine::child('s', 'T::one', failed: false, seconds: 0.1),
    );
    $covering = ['k' => ['T::one'], 's' => ['T::one'], 'o' => ['T::one', 'T::three'], '407712' => ['T::four'], 'u' => []];
    $tests = [];
    putenv(sprintf('%s=%s/results.jsonl', GateVariable::Results->value, Scratch::directory()));
    $events = new ReflectionProperty(Facade::class, 'instance');
    $held = $events->getValue();
    $events->setValue(null, null);

    try {
        foreach (['k', 's', 'o', '407712', 'u'] as $id) {
            $tests[$id] = new MutationTest(Mutations::mutation('src/Money.php', $id, 11, sprintf('%s/copy-%s.php', Scratch::directory(), $id)));
            $verdicts->give($id, $tests[$id], $covering[$id]);
        }
    } finally {
        $events->setValue(null, $held);
    }

    $unjudged = array_keys(array_filter($tests, static fn(MutationTest $test): bool => $test->result() === MutationTestResult::None));

    expect(array_keys($tests))->toContain(407712)
        ->and(OwnRuns::leftBy($tests))->toBe(count($unjudged))
        ->and($unjudged)->toContain('o', 407712)
        ->and($unjudged)->not->toContain('k', 's')
        ->and(OwnRuns::leftBy([$tests['k'], $tests['s']]))->toBe(0)
        ->and(OwnRuns::leftBy([$tests[407712]]))->toBe(1)
        ->and(OwnRuns::leftBy([]))->toBe(0);
});

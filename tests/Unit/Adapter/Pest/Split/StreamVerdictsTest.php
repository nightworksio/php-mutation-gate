<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\KillerFile;
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

    return [$test->result(), is_file($killers) ? (string) file_get_contents($killers) : ''];
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

/** @param list<string> $covering */
it('leaves a mutant for its own run where a covering test forked nothing, or never ran', function (array $covering): void {
    $verdicts = streamResults(
        StreamLine::parent('T::one', passed: true, seconds: 0.1),
        StreamLine::parent('T::two', passed: true, seconds: 0.1),
        StreamLine::fallback('T::two'),
    );

    expect(streamGiven($verdicts, $covering))->toBe([MutationTestResult::None, '']);
})->with([
    'a test that forked nothing' => [['T::one', 'T::two']],
    'a test no stream ran' => [['T::one', 'T::three']],
]);

it('reads no results where a stream wrote none, and skips what is no line of its own', function (): void {
    $verdicts = StreamVerdicts::none()->reading(sprintf('%s/missing.jsonl', Scratch::directory()));
    $garbled = streamResults("not json\n", "{\"k\":\"other\",\"t\":\"T::one\"}\n", "[1]\n");

    expect(streamGiven($verdicts, ['T::one'])[0])->toBe(MutationTestResult::None)
        ->and(streamGiven($garbled, ['T::one'])[0])->toBe(MutationTestResult::None);
});

<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Cost\NoHistory;
use NightWorksIO\MutationGate\Core\Report\Trend;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('appends a verdict\'s entry: its commit, time, the project\'s score and each tree\'s', function (): void {
    $trend = Trend::none()->with(Verdicts::failing(), Revision::ref('abc123'), Moment::at('2026-09-30T10:00:00Z'));

    expect(Decoded::at($trend->json()))->toBe([
        'format' => 1,
        'runs' => [[
            'commit' => 'abc123',
            'time' => '2026-09-30T10:00:00Z',
            'score' => 37.5,
            'trees' => ['src' => 37.5],
        ]],
    ])
        ->and(iterator_to_array($trend->scores(), preserve_keys: false))->toBe([37.5]);
});

it('leaves out the score of a set with nothing to mutate', function (): void {
    $trend = Trend::none()->with(Verdicts::empty(), Revision::ref('abc123'), Moment::at('2026-09-30T10:00:00Z'));

    expect(Decoded::at($trend->json(), 'runs', 0))->toBe([
        'commit' => 'abc123',
        'time' => '2026-09-30T10:00:00Z',
        'trees' => [],
    ])
        ->and(iterator_to_array($trend->scores(), preserve_keys: false))->toBe([]);
});

it('reads back what it wrote and appends to it', function (): void {
    $written = Trend::none()->with(Verdicts::failing(), Revision::ref('one'), Moment::at('2026-09-30T10:00:00Z'))->json();
    $trend = Trend::decode($written)->with(Verdicts::passing(), Revision::ref('two'), Moment::at('2026-09-30T11:00:00Z'));

    expect(iterator_to_array($trend->scores(), preserve_keys: false))->toBe([37.5, 100.0])
        ->and(Decoded::column($trend->json(), 'commit', 'runs'))->toBe(['one', 'two']);
});

it('keeps the newest 500 entries', function (): void {
    $runs = [];

    for ($run = 1; $run <= 500; ++$run) {
        $runs[] = ['commit' => sprintf('c%d', $run), 'time' => '2026-09-30T10:00:00Z', 'score' => 50.0, 'trees' => []];
    }

    $trend = Trend::decode((string) json_encode(['format' => 1, 'runs' => $runs]))->with(Verdicts::passing(), Revision::ref('c501'), Moment::at('2026-09-30T11:00:00Z'));
    $commits = Decoded::column($trend->json(), 'commit', 'runs');

    expect($commits)->toHaveCount(500)
        ->and($commits[0])->toBe('c2')
        ->and($commits[499])->toBe('c501');
});

it('drops an entry that is not in shape, and reads text that is not a trend as none', function (): void {
    $trend = Trend::decode((string) json_encode(['format' => 1, 'runs' => [
        ['commit' => 'kept', 'time' => '2026-09-30T10:00:00Z', 'score' => 80, 'trees' => ['src' => 80]],
        ['commit' => 'no time', 'trees' => []],
        ['commit' => 'a tree as text', 'time' => '2026-09-30T10:00:00Z', 'trees' => ['src' => 'high']],
    ]]));

    expect(Decoded::column($trend->json(), 'commit', 'runs'))->toBe(['kept'])
        ->and(iterator_to_array($trend->scores(), preserve_keys: false))->toBe([80.0])
        ->and(iterator_to_array(Trend::decode('not json')->scores(), preserve_keys: false))->toBe([])
        ->and(iterator_to_array(Trend::decode('{"runs": "many"}')->scores(), preserve_keys: false))->toBe([]);
});

it('writes a run\'s runner time and the full one-job run beside its scores, and reads them back', function (): void {
    $trend = Trend::none()->with(Verdicts::named('accounted'), Revision::ref('abc123'), Moment::at('2026-09-30T12:00:00Z'));
    $run = Decoded::at($trend->json(), 'runs', 0);

    expect($run)->toMatchArray(['commit' => 'abc123', 'runnerSeconds' => 840.0, 'fullRunSeconds' => 6060.0])
        ->and(Trend::decode($trend->json())->json())->toBe($trend->json())
        ->and(Decoded::at(Trend::none()->with(Verdicts::failing(), Revision::ref('abc123'), Moment::at('2026-09-30T12:00:00Z'))->json(), 'runs', 0))
        ->not->toHaveKeys(['runnerSeconds', 'fullRunSeconds']);
});

it('sums what the runs since an instant saved, never less than nothing each', function (): void {
    $trend = Trend::decode('{"format": 1, "runs": [
        {"commit": "a", "time": "2026-08-01T00:00:00Z", "trees": {}, "runnerSeconds": 10, "fullRunSeconds": 1000},
        {"commit": "b", "time": "2026-08-31T12:00:00Z", "trees": {}, "runnerSeconds": 100, "fullRunSeconds": 700},
        {"commit": "c", "time": "2026-09-11T00:00:00Z", "trees": {}, "runnerSeconds": 900, "fullRunSeconds": 700},
        {"commit": "d", "time": "2026-09-12T00:00:00Z", "trees": {}, "runnerSeconds": 100},
        {"commit": "e", "time": "2026-09-13T00:00:00Z", "trees": {}, "fullRunSeconds": 100}
    ]}');

    expect($trend->savedSince(Moment::at('2026-08-31T12:00:00Z')))->toEqual(Seconds::of(600.0))
        ->and($trend->savedSince(Moment::at('2026-09-01T00:00:00Z')))->toEqual(Seconds::of(0.0))
        ->and($trend->savedSince(Moment::at('2026-10-01T00:00:00Z')))->toEqual(NoHistory::yet())
        ->and(Trend::none()->savedSince(Moment::at('2026-08-31T12:00:00Z')))->toEqual(NoHistory::yet());
});

<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Report\Trend;
use NightWorksIO\MutationGate\Tests\Support\Decoded;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('appends a verdict\'s entry: its commit, time, the project\'s score and each tree\'s', function (): void {
    $trend = Trend::none()->with(Verdicts::failing(), 'abc123', Moment::at('2026-09-30T10:00:00Z'));

    expect(Decoded::at($trend->json()))->toBe([
        'format' => 1,
        'runs' => [[
            'commit' => 'abc123',
            'time' => '2026-09-30T10:00:00Z',
            'score' => 37.5,
            'trees' => ['src' => 37.5],
        ]],
    ])
        ->and($trend->scores())->toBe([37.5]);
});

it('leaves out the score of a set with nothing to mutate', function (): void {
    $trend = Trend::none()->with(Verdicts::empty(), 'abc123', Moment::at('2026-09-30T10:00:00Z'));

    expect(Decoded::at($trend->json(), 'runs', 0))->toBe([
        'commit' => 'abc123',
        'time' => '2026-09-30T10:00:00Z',
        'trees' => [],
    ])
        ->and($trend->scores())->toBe([]);
});

it('reads back what it wrote and appends to it', function (): void {
    $written = Trend::none()->with(Verdicts::failing(), 'one', Moment::at('2026-09-30T10:00:00Z'))->json();
    $trend = Trend::decode($written)->with(Verdicts::passing(), 'two', Moment::at('2026-09-30T11:00:00Z'));

    expect($trend->scores())->toBe([37.5, 100.0])
        ->and(Decoded::column($trend->json(), 'commit', 'runs'))->toBe(['one', 'two']);
});

it('keeps the newest 500 entries', function (): void {
    $runs = [];

    for ($run = 1; $run <= 500; ++$run) {
        $runs[] = ['commit' => sprintf('c%d', $run), 'time' => '2026-09-30T10:00:00Z', 'score' => 50.0, 'trees' => []];
    }

    $trend = Trend::decode((string) json_encode(['format' => 1, 'runs' => $runs]))->with(Verdicts::passing(), 'c501', Moment::at('2026-09-30T11:00:00Z'));
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
        ->and($trend->scores())->toBe([80.0])
        ->and(Trend::decode('not json')->scores())->toBe([])
        ->and(Trend::decode('{"runs": "many"}')->scores())->toBe([]);
});

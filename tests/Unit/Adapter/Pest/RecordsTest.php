<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Records;
use NightWorksIO\MutationGate\Adapter\Pest\Summary;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Pest\Mutate\Mutators\Arithmetic\PlusToMinus;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * A results file holding these records, one JSON line each, as the plugin writes them.
 *
 * @param list<array<string, mixed>|string> $records a string is written as it is
 */
$results = static function (array $records): string {
    $file = sprintf('%s/results.jsonl', Scratch::directory());
    $lines = array_map(
        static fn(mixed $record): string => is_string($record) ? $record : (string) json_encode($record),
        $records,
    );
    file_put_contents($file, sprintf("%s\n", implode("\n", $lines)));

    return $file;
};

/**
 * A mutant as the plugin plans it.
 *
 * @return array<string, mixed>
 */
$planned = static fn(string $id, string $file, int $start): array => [
    'event' => 'planned',
    'id' => $id,
    'file' => $file,
    'start' => $start,
    'end' => $start + 1,
    'mutator' => PlusToMinus::class,
    'diff' => sprintf('diff of %s', $id),
];

it('orders the planned mutants by file and by the line each starts on', function () use ($results, $planned): void {
    $records = Records::in($results([
        $planned('c', '/p/src/Money.php', 20),
        $planned('a', '/p/src/Held.php', 30),
        $planned('b', '/p/src/Money.php', 10),
        '',
        '{"not": "an event"}',
        'not JSON',
        ['event' => 'planned', 'id' => 'd', 'file' => 7, 'start' => '9'],
    ]));
    $plus = PlusToMinus::class;

    expect($records instanceof Records ? $records->planned() : [])->toBe([
        'd' => ['file' => '', 'start' => 0, 'end' => 0, 'mutator' => '', 'diff' => ''],
        'a' => ['file' => '/p/src/Held.php', 'start' => 30, 'end' => 31, 'mutator' => $plus, 'diff' => 'diff of a'],
        'b' => ['file' => '/p/src/Money.php', 'start' => 10, 'end' => 11, 'mutator' => $plus, 'diff' => 'diff of b'],
        'c' => ['file' => '/p/src/Money.php', 'start' => 20, 'end' => 21, 'mutator' => $plus, 'diff' => 'diff of c'],
    ]);
});

it('keeps each mutant\'s latest status, and none for one Pest never ran', function () use ($results, $planned): void {
    $records = Records::in($results([
        $planned('a', '/p/src/Money.php', 10),
        ['event' => 'outcome', 'id' => 'a', 'status' => 'untested'],
        ['event' => 'outcome', 'id' => 'b', 'status' => 'timeout'],
        ['event' => 'finished', 'id' => 'a', 'status' => 'tested', 'duration' => 0.5],
    ]));

    $statuses = $records instanceof Records
        ? [$records->statusOf('a'), $records->statusOf('b'), $records->statusOf('c')]
        : [];

    expect($statuses)->toBe(['tested', 'timeout', 'none']);
});

it('keeps every outcome of a run stopped before any mutant finished', function () use ($results): void {
    $records = Records::in($results([
        ['event' => 'outcome', 'id' => 'a', 'status' => 'untested'],
        ['event' => 'outcome', 'id' => 'b', 'status' => 'timeout'],
    ]));

    expect($records instanceof Records ? [$records->statusOf('a'), $records->statusOf('b')] : [])
        ->toBe(['untested', 'timeout']);
});

it('measures only a mutant that ran for some time', function () use ($results): void {
    $records = Records::in($results([
        ['event' => 'finished', 'id' => 'ran', 'status' => 'tested', 'duration' => 0.5],
        ['event' => 'finished', 'id' => 'never', 'status' => 'uncovered', 'duration' => 0.0],
        ['event' => 'finished', 'id' => 'odd', 'status' => 'tested', 'duration' => 3],
        ['event' => 'outcome', 'id' => 'running', 'status' => 'tested'],
    ]));

    expect($records instanceof Records ? [
        $records->durationOf('ran'),
        $records->durationOf('never'),
        $records->durationOf('odd'),
        $records->durationOf('running'),
    ] : [])->toEqual([Seconds::of(0.5), Unmeasured::duration(), Unmeasured::duration(), Unmeasured::duration()]);
});

it('adds up to a summary only once ended with every status as counted', function () use ($results, $planned): void {
    /** @return array<string, mixed> */
    $finished = static fn(string $id, string $status): array => [
        'event' => 'finished',
        'id' => $id,
        'status' => $status,
        'duration' => 0.1,
    ];
    $all = [
        $planned('a', '/p/Money.php', 10),
        $planned('b', '/p/Money.php', 10),
        $planned('c', '/p/Money.php', 10),
        $planned('d', '/p/Money.php', 10),
        $planned('e', '/p/Money.php', 10),
        $finished('a', 'tested'),
        $finished('b', 'untested'),
        $finished('c', 'uncovered'),
        $finished('d', 'timeout'),
    ];
    $end = ['event' => 'end'];
    /** @param list<array<string, mixed>|string> $records */
    $adds = static function (array $records, string $summary) use ($results): bool {
        $read = Records::in($results($records));
        $by = Summary::in($summary);

        return $read instanceof Records && $by instanceof Summary && $read->addUpTo($by);
    };
    $each = 'Mutations: 1 untested, 1 uncovered, 1 pending, 1 timeout, 1 tested';

    $ended = [...$all, $finished('e', 'none'), $end];
    $shifted = static fn(string $counts): string => sprintf('Mutations: %s', $counts);

    expect($adds($ended, $each))->toBeTrue()
        ->and($adds([...$all, $finished('e', 'none')], $each))->toBeFalse()
        ->and($adds([...$all, $end], $each))->toBeFalse()
        ->and($adds([...$all, $finished('e', 'none'), $finished('x', 'odd'), $end], $each))->toBeFalse()
        ->and($adds([...$all, $finished('x', 'none'), $end], $each))->toBeFalse()
        ->and($adds([...$all, $finished('e', 'odd'), $end], $shifted('1 untested, 1 uncovered, 1 timeout, 1 tested')))
        ->toBeFalse()
        ->and($adds($ended, $shifted('2 untested, 1 uncovered, 1 pending, 1 timeout, 0 tested')))->toBeFalse()
        ->and($adds($ended, $shifted('1 untested, 2 uncovered, 1 pending, 0 timeout, 1 tested')))->toBeFalse()
        ->and($adds($ended, $shifted('1 untested, 1 uncovered, 2 pending, 1 timeout, 0 tested')))->toBeFalse()
        ->and($adds($ended, $shifted('1 untested, 1 uncovered, 0 pending, 2 timeout, 1 tested')))->toBeFalse();
});

it('cannot judge a run that wrote no results', function (): void {
    expect(Records::in('/nowhere/results.jsonl'))->toEqual(CannotJudge::because(
        'Pest wrote no results to /nowhere/results.jsonl. Is pestphp/pest-plugin allowed to run in composer.json?',
    ));
});

it('allows a mutant the opening run\'s seconds plus the larger of 5 and a fifth', function () use ($results): void {
    $limit = static function (mixed $opening) use ($results): mixed {
        $records = Records::in($results([['event' => 'made', 'count' => 0, 'opening' => $opening]]));

        return $records instanceof Records ? $records->limit() : $records;
    };

    expect($limit(1.5))->toEqual(Seconds::of(6.0))
        ->and($limit(30.7))->toEqual(Seconds::of(36.0))
        ->and($limit(opening: false))->toEqual(Unmeasured::duration());
});

it('knows it wrote every mutant Pest made once it says how many', function () use ($results, $planned): void {
    $made = static function (array $records) use ($results): bool {
        $read = Records::in($results($records));

        return $read instanceof Records && $read->allMade();
    };

    expect($made([$planned('a', '/p/Money.php', 10), ['event' => 'made', 'count' => 1]]))->toBeTrue()
        ->and($made([$planned('a', '/p/Money.php', 10)]))->toBeFalse()
        ->and($made([$planned('a', '/p/Money.php', 10), ['event' => 'made', 'count' => 2]]))->toBeFalse();
});

it('knows whether the run reached its end', function () use ($results): void {
    $ended = Records::in($results([['event' => 'end']]));
    $running = Records::in($results([['event' => 'outcome', 'id' => 'a', 'status' => 'tested']]));

    expect($ended instanceof Records && $ended->ended())->toBeTrue()
        ->and($running instanceof Records && $running->ended())->toBeFalse();
});

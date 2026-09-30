<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\PestStatus;
use NightWorksIO\MutationGate\Adapter\Pest\PlannedMutant;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Adapter\Pest\Records;
use NightWorksIO\MutationGate\Adapter\Pest\Summary;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Pest\Mutate\Mutators\Arithmetic\PlusToMinus;

afterEach(function (): void {
    Scratch::sweep();
});

/**
 * A results file holding these lines, as the plugin writes them, or any text a test writes in their place, and
 * then the text a run stopped while it wrote leaves last, with no newline after it.
 *
 * @param list<string> $lines
 */
$results = static function (array $lines, string $cut = ''): string {
    $file = sprintf('%s/results.jsonl', Scratch::directory());
    file_put_contents($file, $cut);

    foreach (array_reverse($lines) as $line) {
        file_put_contents($file, sprintf("%s\n%s", rtrim(is_string($line) ? $line : '', "\n"), (string) file_get_contents($file)));
    }

    return $file;
};

$mutant = static fn(string $id, string $file, int $start): PlannedMutant => PlannedMutant::of(
    $id,
    DiskPath::of($file),
    Line::of($start),
    Line::of($start + 1),
    PlusToMinus::class,
    sprintf('diff of %s', $id),
    DiskPath::of(sprintf('/tmp/%s', $id)),
);

$planned = static fn(string $id, string $file, int $start): string => RecordLine::planned($mutant($id, $file, $start));

it('orders the planned mutants by file and by the line each starts on, past a last line cut short', function () use ($results, $planned, $mutant): void {
    $records = Records::in($results([
        $planned('c', '/p/src/Money.php', 20),
        $planned('a', '/p/src/Held.php', 30),
        $planned('b', '/p/src/Money.php', 10),
    ], cut: '{"event": "planned", "id": "d", "fi'));

    expect($records instanceof Records ? $records->planned() : [])->toEqual([
        $mutant('a', '/p/src/Held.php', 30),
        $mutant('b', '/p/src/Money.php', 10),
        $mutant('c', '/p/src/Money.php', 20),
    ]);
});

it('refuses a line cut short anywhere but last, which would lose a killer without a word', function () use ($results, $planned): void {
    $read = Records::in($results([
        $planned('a', '/p/src/Money.php', 10),
        '{"event": "killed", "mutated": "/tmp/a", "te',
        RecordLine::killed('/tmp/a', 'T::second'),
        RecordLine::end(),
    ]));

    expect($read instanceof CannotJudge ? $read->why() : '')->toMatch(
        '/^Line 2 of .*results\.jsonl is not a record the gate reads: the line is not JSON: only the last line can be cut short\.$/',
    );
});

it('refuses a whole record that names no event it knows, or lacks a field its event carries', function () use ($results): void {
    $refused = static fn(string $line): CannotJudge|Records => Records::in($results([RecordLine::end(), $line]));
    $why = static fn(CannotJudge|Records $read): string => $read instanceof CannotJudge ? $read->why() : '';

    expect($why($refused('{"not": "an event"}')))->toEndWith(': the record.event is missing.')
        ->and($why($refused('{"event": "started"}')))->toContain('the record.event is not an event the plugin writes')
        ->and($why($refused('{"event": "planned", "id": "d", "start": 1, "end": 1, "file": 7}')))->toContain('the record.file is not text')
        ->and($why($refused(RecordLine::outcome('a', 'resurrected'))))
        ->toContain('the record.status is not a status Pest records')
        ->and($why($refused('{"event": "made"}')))->toStartWith('Line 2 of ');
});

it('refuses a planned mutant on a line no file has, or ending before it starts', function () use ($results): void {
    $plannedAt = static fn(int $start, int $end): string => RecordLine::planned(PlannedMutant::of(
        'a',
        DiskPath::of('/p/src/Money.php'),
        Line::of($start),
        Line::of($end),
        PlusToMinus::class,
        'diff',
        DiskPath::of('/tmp/a'),
    ));
    $why = static function (string $line) use ($results): string {
        $read = Records::in($results([$line]));

        return $read instanceof CannotJudge ? $read->why() : '';
    };

    expect($why($plannedAt(0, 1)))->toEndWith(': the record.start is not a line of a file, which counts from 1.')
        ->and($why($plannedAt(5, 4)))->toEndWith(': the record.end is not a line at or after the one the mutant starts on.')
        ->and(Records::in($results([$plannedAt(1, 1)])))->toBeInstanceOf(Records::class);
});

it('keeps each mutant\'s latest status, and none for one Pest never ran', function () use ($results, $planned, $mutant): void {
    $records = Records::in($results([
        $planned('a', '/p/src/Money.php', 10),
        RecordLine::outcome('a', PestStatus::Untested),
        RecordLine::outcome('b', PestStatus::Timeout),
        RecordLine::finished('a', PestStatus::Tested, 0.5),
    ]));

    $statuses = $records instanceof Records
        ? array_map(
            static fn(string $id): PestStatus => $records->statusOf($mutant($id, '/p/src/Money.php', 10)),
            ['a', 'b', 'c'],
        )
        : [];

    expect($statuses)->toBe([PestStatus::Tested, PestStatus::Timeout, PestStatus::None]);
});

it('keeps every outcome of a run stopped before any mutant finished', function () use ($results, $mutant): void {
    $records = Records::in($results([
        RecordLine::outcome('a', PestStatus::Untested),
        RecordLine::outcome('b', PestStatus::Timeout),
    ]));

    $status = static fn(string $id): PestStatus => $records instanceof Records
        ? $records->statusOf($mutant($id, '/p/src/Money.php', 10))
        : PestStatus::None;

    expect([$status('a'), $status('b')])
        ->toBe([PestStatus::Untested, PestStatus::Timeout]);
});

it('measures only a mutant that ran for some time', function () use ($results, $mutant): void {
    $records = Records::in($results([
        RecordLine::finished('ran', PestStatus::Tested, 0.5),
        RecordLine::finished('never', PestStatus::Uncovered, 0.0),
        RecordLine::outcome('running', PestStatus::Tested),
    ]));

    expect($records instanceof Records ? array_map(
        static fn(string $id): Seconds|Unmeasured => $records->durationOf($mutant($id, '/p/src/Money.php', 10)),
        ['ran', 'never', 'running'],
    ) : [])->toEqual([Seconds::of(0.5), Unmeasured::duration(), Unmeasured::duration()]);
});

it('adds up to a summary only once ended with every status as counted', function () use ($results, $planned): void {
    $finished = static fn(string $id, PestStatus $status): string => RecordLine::finished($id, $status, 0.1);
    $all = [
        $planned('a', '/p/Money.php', 10),
        $planned('b', '/p/Money.php', 10),
        $planned('c', '/p/Money.php', 10),
        $planned('d', '/p/Money.php', 10),
        $planned('e', '/p/Money.php', 10),
        $finished('a', PestStatus::Tested),
        $finished('b', PestStatus::Untested),
        $finished('c', PestStatus::Uncovered),
        $finished('d', PestStatus::Timeout),
    ];
    $end = RecordLine::end();
    /** @param list<string> $lines */
    $adds = static function (array $lines, string $summary) use ($results): bool {
        $read = Records::in($results($lines));
        $by = Summary::in($summary);

        return $read instanceof Records && $by instanceof Summary && $read->addUpTo($by);
    };
    $each = 'Mutations: 1 untested, 1 uncovered, 1 pending, 1 timeout, 1 tested';

    $ended = [...$all, $finished('e', PestStatus::None), $end];
    $shifted = static fn(string $counts): string => sprintf('Mutations: %s', $counts);

    expect($adds($ended, $each))->toBeTrue()
        ->and($adds([...$all, $finished('e', PestStatus::None)], $each))->toBeFalse()
        ->and($adds([...$all, $end], $each))->toBeFalse()
        ->and($adds([...$all, $finished('e', PestStatus::None), $finished('x', PestStatus::None), $end], $each))
        ->toBeFalse()
        ->and($adds([...$all, $finished('x', PestStatus::None), $end], $each))->toBeFalse()
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
    $limit = static function (Seconds|Unmeasured $opening) use ($results): Seconds|Unmeasured|CannotJudge {
        $records = Records::in($results([RecordLine::made(0, $opening)]));

        return $records instanceof Records ? $records->limit() : $records;
    };

    expect($limit(Seconds::of(1.5)))->toEqual(Seconds::of(6.0))
        ->and($limit(Seconds::of(30.7)))->toEqual(Seconds::of(36.0))
        ->and($limit(Unmeasured::duration()))->toEqual(Unmeasured::duration());
});

it('knows it wrote every mutant Pest made once it says how many', function () use ($results, $planned): void {
    /** @param list<string> $lines */
    $made = static function (array $lines) use ($results): bool {
        $read = Records::in($results($lines));

        return $read instanceof Records && $read->allMade();
    };
    $opening = Seconds::of(1.5);

    expect($made([$planned('a', '/p/Money.php', 10), RecordLine::made(1, $opening)]))->toBeTrue()
        ->and($made([$planned('a', '/p/Money.php', 10)]))->toBeFalse()
        ->and($made([$planned('a', '/p/Money.php', 10), RecordLine::made(2, $opening)]))->toBeFalse()
        ->and($made([$planned('a', '/p/Money.php', 10), $planned('a', '/p/Money.php', 10), RecordLine::made(2, $opening)]))
        ->toBeTrue();
});

it('knows whether the run reached its end', function () use ($results): void {
    $ended = Records::in($results([RecordLine::end()]));
    $running = Records::in($results([RecordLine::outcome('a', PestStatus::Tested)]));

    expect($ended instanceof Records && $ended->ended())->toBeTrue()
        ->and($running instanceof Records && $running->ended())->toBeFalse();
});

it('names the tests that failed in each mutant\'s own process, in order, by the mutated copy they ran on', function () use ($results, $planned, $mutant): void {
    $records = Records::in($results([
        $planned('a', '/p/src/Money.php', 10),
        $planned('b', '/p/src/Money.php', 20),
        RecordLine::killed('/tmp/a', 'P\\Tests\\MoneySpec::__pest_evaluable_it_adds'),
        RecordLine::killed('/tmp/a', 'Tests\\LegacySpec::testAdds#(1)'),
        RecordLine::killed('/tmp/elsewhere', 'P\\Tests\\OtherSpec::__pest_evaluable_it'),
    ]));
    $named = static fn(string $id): array => $records instanceof Records
        ? array_map(static fn(TestId $test): string => $test->value(), [...$records->killersOf($mutant($id, '/p/src/Money.php', 10))])
        : [];

    expect($named('a'))->toBe(['P\\Tests\\MoneySpec::__pest_evaluable_it_adds', 'Tests\\LegacySpec::testAdds#(1)'])
        ->and($named('b'))->toBe([])
        ->and($named('unplanned'))->toBe([]);
});

it('keeps apart the mutants Pest gives one id, as two changes that leave the same source share it', function () use ($results, $planned, $mutant): void {
    $records = Records::in($results([
        $planned('same', '/p/src/Money.php', 10),
        $planned('same', '/p/src/Money.php', 10),
        RecordLine::outcome('same', PestStatus::Untested),
        RecordLine::outcome('same', PestStatus::Tested),
        RecordLine::finished('same', PestStatus::Tested, 0.5),
    ]));
    $read = $records instanceof Records ? $records->planned() : [];
    $of = static fn(PlannedMutant $each): array => $records instanceof Records
        ? [$each->occurrence(), $records->statusOf($each), $records->durationOf($each)]
        : [];

    expect(array_map($of, $read))->toEqual([
        [0, PestStatus::Tested, Seconds::of(0.5)],
        [1, PestStatus::Tested, Unmeasured::duration()],
    ])->and($mutant('same', '/p/src/Money.php', 10)->occurrence())->toBe(0);
});

it('adds up to a summary that counts each mutant sharing an id, once each finished', function () use ($results, $planned): void {
    $finished = static fn(PestStatus $status): string => RecordLine::finished('same', $status, 0.1);
    /** @param list<string> $finishes */
    $adds = static function (array $finishes, string $summary) use ($results, $planned): bool {
        $read = Records::in($results([
            $planned('same', '/p/Money.php', 10),
            $planned('same', '/p/Money.php', 10),
            ...$finishes,
            RecordLine::end(),
        ]));
        $by = Summary::in(sprintf('Mutations: %s', $summary));

        return $read instanceof Records && $by instanceof Summary && $read->addUpTo($by);
    };

    expect($adds([$finished(PestStatus::Tested), $finished(PestStatus::Uncovered)], '1 uncovered, 1 tested'))->toBeTrue()
        ->and($adds([$finished(PestStatus::Tested)], '1 tested'))->toBeFalse()
        ->and($adds([$finished(PestStatus::Tested)], '1 uncovered, 1 tested'))->toBeFalse()
        ->and($adds(array_fill(0, 3, $finished(PestStatus::Tested)), '3 tested'))->toBeFalse();
});

it('adds up only where the mutants finished in the order they were planned, as the plugin writes them', function () use ($results, $planned): void {
    $adds = static function (string ...$ids) use ($results, $planned): bool {
        $read = Records::in($results([
            $planned('a', '/p/Money.php', 10),
            $planned('b', '/p/Money.php', 20),
            ...array_map(static fn(string $id): string => RecordLine::finished($id, PestStatus::Tested, 0.1), $ids),
            RecordLine::end(),
        ]));
        $by = Summary::in('Mutations: 2 tested');

        return $read instanceof Records && $by instanceof Summary && $read->addUpTo($by);
    };

    expect($adds('a', 'b'))->toBeTrue()
        ->and($adds('b', 'a'))->toBeFalse();
});

<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\PestStatus;
use NightWorksIO\MutationGate\Adapter\Pest\PlannedMutant;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Placed;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordEvent;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Adapter\Pest\Records;
use NightWorksIO\MutationGate\Adapter\Pest\Summary;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Ended;
use NightWorksIO\MutationGate\Core\Mutant\OrderDigest;
use NightWorksIO\MutationGate\Core\Mutant\Prefix;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
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
        RecordLine::killed('/tmp/a', 'T::second', Placed::unplaced(1)),
        RecordLine::end(),
    ]));

    expect($read instanceof CannotJudge ? $read->why() : '')->toMatch(
        '/^Line 2 of .*results\.jsonl is not a record the gate reads: the line is not JSON: only the last line can be cut short, and it reads `\{"event": "killed", "mutated": "\/tmp\/a", "te`\.$/',
    );
});

it('shows a line cut short whole up to 160 characters, and cut to them past that', function () use ($results, $planned): void {
    $line = static fn(int $length): string => str_pad('{"event": "killed", "te', $length, 'x');
    $why = static function (string $cut) use ($results, $planned): string {
        $read = Records::in($results([$planned('a', '/p/src/Money.php', 10), $cut, RecordLine::end()]));

        return $read instanceof CannotJudge ? $read->why() : '';
    };

    expect($why($line(160)))->toEndWith(sprintf('it reads `%s`.', $line(160)))
        ->and($why($line(161)))->toEndWith(sprintf('it reads `%s…`.', mb_substr($line(161), 0, 159)));
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

it('allows a mutant the opening run\'s seconds plus the larger of 5 and a fifth, where no limit is recorded for it', function () use ($results, $mutant): void {
    $limit = static function (Seconds|Unmeasured $opening) use ($results, $mutant): Seconds|Unmeasured|CannotJudge {
        $records = Records::in($results([RecordLine::made(0, $opening)]));

        return $records instanceof Records ? $records->limitOf($mutant('a', '/p/src/Money.php', 10)) : $records;
    };

    expect($limit(Seconds::of(1.5)))->toEqual(Seconds::of(6.0))
        ->and($limit(Seconds::of(30.7)))->toEqual(Seconds::of(36.0))
        ->and($limit(Unmeasured::duration()))->toEqual(Unmeasured::duration());
});

it('allows a mutant the limit a patched run recorded by its mutated copy, over the opening run\'s', function () use ($results, $planned, $mutant): void {
    $records = Records::in($results([
        $planned('a', '/p/src/Money.php', 10),
        $planned('b', '/p/src/Money.php', 20),
        RecordLine::limited('/tmp/a', 7.25),
        RecordLine::made(2, Seconds::of(83.16)),
    ]));

    expect($records instanceof Records ? $records->limitOf($mutant('a', '/p/src/Money.php', 10)) : $records)->toEqual(Seconds::of(7.25))
        ->and($records instanceof Records ? $records->limitOf($mutant('b', '/p/src/Money.php', 20)) : $records)->toEqual(Seconds::of(99.0));
});

it('refuses a recorded limit of no seconds', function () use ($results, $planned): void {
    $read = Records::in($results([
        $planned('a', '/p/src/Money.php', 10),
        RecordLine::limited('/tmp/a', 0.0),
    ]));

    expect($read instanceof CannotJudge ? $read->why() : '')->toMatch(
        '/^Line 2 of .*results\.jsonl is not a record the gate reads: the record\.seconds is not a number of seconds above 0\.$/',
    );
});

it('knows the silence limit a patched run stopped a mutant\'s own run at, by its mutated copy, and refuses one of no seconds', function () use ($results, $planned, $mutant): void {
    $records = Records::in($results([
        $planned('a', '/p/src/Money.php', 10),
        $planned('b', '/p/src/Money.php', 20),
        RecordLine::silent('/tmp/a', 8.0),
    ]));
    $refused = Records::in($results([
        $planned('a', '/p/src/Money.php', 10),
        RecordLine::silent('/tmp/a', 0.0),
    ]));

    expect($records instanceof Records ? $records->silenceOf($mutant('a', '/p/src/Money.php', 10)) : $records)->toEqual(Seconds::of(8.0))
        ->and($records instanceof Records ? $records->silenceOf($mutant('b', '/p/src/Money.php', 20)) : $records)->toEqual(NotGiven::value())
        ->and($refused instanceof CannotJudge ? $refused->why() : '')->toMatch('/the record\.seconds is not a number of seconds above 0\.$/');
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
        RecordLine::killed('/tmp/a', 'P\\Tests\\MoneySpec::__pest_evaluable_it_adds', Placed::unplaced(1)),
        RecordLine::killed('/tmp/a', 'Tests\\LegacySpec::testAdds#(1)', Placed::unplaced(1)),
        RecordLine::killed('/tmp/elsewhere', 'P\\Tests\\OtherSpec::__pest_evaluable_it', Placed::unplaced(1)),
    ]));
    $named = static fn(string $id): array => $records instanceof Records
        ? array_map(static fn(TestId $test): string => $test->value(), [...$records->runOf($mutant($id, '/p/src/Money.php', 10))->killers()])
        : [];

    expect($named('a'))->toBe(['P\\Tests\\MoneySpec::__pest_evaluable_it_adds', 'Tests\\LegacySpec::testAdds#(1)'])
        ->and($named('b'))->toBe([])
        ->and($named('unplanned'))->toBe([]);
});

it('tells a mutant killed only by tests that errored from one a test failed on, errored tests named as killers too', function () use ($results, $planned, $mutant): void {
    $records = Records::in($results([
        $planned('a', '/p/src/Money.php', 10),
        $planned('b', '/p/src/Money.php', 20),
        $planned('c', '/p/src/Money.php', 30),
        RecordLine::errored('/tmp/a', 'T::adds', Placed::unplaced(1)),
        RecordLine::errored('/tmp/a', 'T::subtracts', Placed::unplaced(1)),
        RecordLine::errored('/tmp/b', 'T::adds', Placed::unplaced(1)),
        RecordLine::killed('/tmp/b', 'T::subtracts', Placed::unplaced(1)),
    ]));
    $only = static fn(string $id): bool => $records instanceof Records
        && $records->runOf($mutant($id, '/p/src/Money.php', 10))->killedByErrorsOnly();
    $named = static fn(string $id): array => $records instanceof Records
        ? array_map(static fn(TestId $test): string => $test->value(), [...$records->runOf($mutant($id, '/p/src/Money.php', 10))->killers()])
        : [];

    expect([$only('a'), $only('b'), $only('c')])->toBe([true, false, false])
        ->and($named('a'))->toBe(['T::adds', 'T::subtracts'])
        ->and($named('b'))->toBe(['T::adds', 'T::subtracts']);
});

it('names the test files each mutant\'s own run was narrowed to, by the mutated copy it ran on, and none where it loaded every one', function () use ($results, $planned, $mutant): void {
    $records = Records::in($results([
        $planned('a', '/p/src/Money.php', 10),
        $planned('b', '/p/src/Money.php', 20),
        RecordLine::narrowed('/tmp/a', ['/p/tests/AddsSpec.php', '/p/tests/HelpersSpec.php']),
    ]));
    $loaded = static fn(string $id): array => $records instanceof Records
        ? $records->runOf($mutant($id, '/p/src/Money.php', 10))->narrowedTo()
        : ['unread'];

    expect($loaded('a'))->toBe(['/p/tests/AddsSpec.php', '/p/tests/HelpersSpec.php'])
        ->and($loaded('b'))->toBe([]);
});

it('refuses a narrowed record whose files are not a list of paths', function () use ($results): void {
    $read = Records::in($results(['{"event": "narrowed", "mutated": "/tmp/a", "files": "/p/tests/AddsSpec.php"}']));

    expect($read instanceof CannotJudge ? $read->why() : '')->toEndWith('the record.files is not a list.');
});

it('keeps the memory limit each mutant\'s own process ran out of, by the mutated copy it ran on', function () use ($results, $planned, $mutant): void {
    $records = Records::in($results([
        $planned('a', '/p/src/Money.php', 10),
        $planned('b', '/p/src/Money.php', 20),
        RecordLine::exhausted('/tmp/a', MemoryCap::of(64, MemoryUnit::Megabytes)),
    ]));

    $refused = Records::in($results(['{"event": "exhausted", "mutated": "/tmp/a", "bytes": 0}', RecordLine::end()]));

    expect($records instanceof Records ? $records->runOf($mutant('a', '/p/src/Money.php', 10))->exhaustion() : $records)
        ->toEqual(MemoryCap::of(64, MemoryUnit::Megabytes))
        ->and($records instanceof Records ? $records->runOf($mutant('b', '/p/src/Money.php', 20))->exhaustion() : $records)
        ->toEqual(NotGiven::value())
        ->and($refused instanceof CannotJudge ? $refused->why() : '')
        ->toEndWith('is not a record the gate reads: the record.bytes is not a number of bytes.');
});

it('keeps whether each mutant\'s own process had loaded the original before the mutant was in place, by its copy', function () use ($results, $planned, $mutant): void {
    $records = Records::in($results([
        $planned('a', '/p/src/Money.php', 10),
        $planned('b', '/p/src/Money.php', 20),
        RecordLine::preloaded('/tmp/a'),
        RecordLine::preloaded('/tmp/a'),
    ]));

    expect($records instanceof Records ? $records->runOf($mutant('a', '/p/src/Money.php', 10))->ranTheOriginal() : $records)
        ->toBeTrue()
        ->and($records instanceof Records ? $records->runOf($mutant('b', '/p/src/Money.php', 20))->ranTheOriginal() : $records)
        ->toBeFalse();
});

it('keeps how many tests the own runs on each copy ran together, and none where no run said', function () use ($results, $planned, $mutant): void {
    $records = Records::in($results([
        $planned('a', '/p/src/Money.php', 10),
        $planned('b', '/p/src/Money.php', 20),
        $planned('c', '/p/src/Money.php', 30),
        RecordLine::ran('/tmp/a', 2),
        RecordLine::ran('/tmp/a', 0),
        RecordLine::ran('/tmp/b', 0),
    ]));
    $refused = Records::in($results(['{"event": "ran", "mutated": "/tmp/a", "count": -1}', RecordLine::end()]));
    $ranNone = static fn(string $id, int $line): bool => $records instanceof Records
        && $records->runOf($mutant($id, '/p/src/Money.php', $line))->ranNoTest();

    expect($ranNone('a', 10))->toBeFalse()
        ->and($ranNone('b', 20))->toBeTrue()
        ->and($ranNone('c', 30))->toBeFalse()
        ->and($refused instanceof CannotJudge ? $refused->why() : '')
        ->toEndWith('is not a record the gate reads: the record.count is not a number of tests, which is never below 0.');
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

it('gives where a mutant\'s own run first failed, keyed by its files and its order up to its last killer', function () use ($results, $planned, $mutant): void {
    $first = OrderDigest::of(TestId::of('T::passes'), TestId::of('T::adds'))->value();
    $last = OrderDigest::of(TestId::of('T::passes'), TestId::of('T::adds'), TestId::of('T::drains'))->value();
    $records = Records::in($results([
        $planned('a', '/p/src/Money.php', 10),
        $planned('b', '/p/src/Money.php', 20),
        $planned('c', '/p/src/Money.php', 30),
        $planned('d', '/p/src/Money.php', 40),
        $planned('e', '/p/src/Money.php', 50),
        RecordLine::killed('/tmp/a', 'T::adds', Placed::at(2, $first, 7)),
        RecordLine::errored('/tmp/a', 'T::drains', Placed::at(3, $last, 7)),
        RecordLine::errored('/tmp/b', 'T', Placed::unplaced(7)),
        RecordLine::killed('/tmp/b', 'T::adds', Placed::at(2, $first, 7)),
        RecordLine::killed('/tmp/c', 'T::adds', Placed::at(2, $first, 7)),
        RecordLine::killed('/tmp/c', 'T::adds', Placed::at(2, $first, 8)),
        RecordLine::killed('/tmp/e', 'T::adds', Placed::at(2, $first, 7)),
        RecordLine::errored('/tmp/e', 'T', Placed::unplaced(7)),
    ]));
    $files = Paths::of(Path::of('tests/MoneyTest.php'));
    $prefix = static fn(string $id): Prefix|NotGiven => $records instanceof Records
        ? $records->runOf($mutant($id, '/p/src/Money.php', 10))->prefix($files)
        : NotGiven::value();

    expect($prefix('a'))->toEqual(Prefix::keyedAt(2, Prefix::keyOf($files, $last)))
        ->and($prefix('b'))->toBeInstanceOf(NotGiven::class)
        ->and($prefix('c'))->toBeInstanceOf(NotGiven::class)
        ->and($prefix('d'))->toBeInstanceOf(NotGiven::class)
        ->and($prefix('e'))->toEqual(Prefix::at(2));
});

it('reads a killer line that names no process, as an earlier plugin wrote it, as one whose run cannot be told apart', function () use ($results, $planned, $mutant): void {
    $records = Records::in($results([
        $planned('a', '/p/src/Money.php', 10),
        '{"event":"killed","mutated":"/tmp/a","test":"T::adds","at":1}',
    ]));

    expect($records instanceof Records ? $records->runOf($mutant('a', '/p/src/Money.php', 10))->prefix(Paths::none()) : null)
        ->toBeInstanceOf(NotGiven::class)
        ->and($records instanceof Records ? count($records->runOf($mutant('a', '/p/src/Money.php', 10))->killers()) : 0)->toBe(1);
});

it('refuses a killer line placed before the first test or by an order that is not a SHA-256 digest', function () use ($results, $planned): void {
    $why = static function (string $line) use ($results, $planned): string {
        $read = Records::in($results([$planned('a', '/p/src/Money.php', 10), $line]));

        return $read instanceof CannotJudge ? $read->why() : '';
    };

    expect($why(RecordLine::killed('/tmp/a', 'T::adds', Placed::at(0, str_repeat('a', 64), 7))))
        ->toEndWith('the record.at is not a position, which counts from one.')
        ->and($why(RecordLine::errored('/tmp/a', 'T::adds', Placed::at(1, str_repeat('A', 64), 7))))
        ->toEndWith('the record.order is not a SHA-256 digest in lowercase hex.')
        ->and($why(RecordLine::killed('/tmp/a', 'T::adds', Placed::at(1, str_repeat('a', 63), 7))))
        ->toEndWith('the record.order is not a SHA-256 digest in lowercase hex.');
});

it('gives how a mutant\'s own process ended where one ending is recorded for its copy, and none where two or none are', function () use ($results, $planned, $mutant): void {
    $records = Records::in($results([
        $planned('a', '/p/src/Money.php', 10),
        RecordLine::ended('/tmp/a', Ended::of(255, signalled: false, printed: 'PHP Fatal error')),
        RecordLine::ended('/tmp/b', Ended::of(NotGiven::value(), NotGiven::value(), 'first')),
        RecordLine::ended('/tmp/b', Ended::of(1, signalled: false, printed: 'second')),
    ]));
    $ended = static fn(string $id): Ended|NotGiven => $records instanceof Records
        ? $records->runOf($mutant($id, '/p/src/Money.php', 10))->ended()
        : NotGiven::value();

    expect($ended('a'))->toEqual(Ended::unprinted(255, signalled: false))
        ->and($ended('b'))->toBeInstanceOf(NotGiven::class)
        ->and($ended('c'))->toBeInstanceOf(NotGiven::class);
});

it('gives a fatal error PHP recorded in a mutant\'s own process with its ending, or alone where Pest recorded no ending', function () use ($results, $planned, $mutant): void {
    $records = Records::in($results([
        $planned('a', '/p/src/Money.php', 10),
        RecordLine::ended('/tmp/a', Ended::unprinted(255, signalled: false)),
        RecordLine::fatal('/tmp/a'),
        RecordLine::fatal('/tmp/b'),
        RecordLine::ended('/tmp/c', Ended::unprinted(1, signalled: false)),
    ]));
    $ended = static fn(string $id): Ended|NotGiven => $records instanceof Records
        ? $records->runOf($mutant($id, '/p/src/Money.php', 10))->ended()
        : NotGiven::value();

    expect($ended('a'))->toEqual(Ended::unprinted(255, signalled: false)->withFatal(fatal: true))
        ->and($ended('b'))->toEqual(Ended::unprinted(NotGiven::value(), NotGiven::value())->withFatal(fatal: true))
        ->and($ended('c'))->toEqual(Ended::unprinted(1, signalled: false))
        ->and($ended('d'))->toBeInstanceOf(NotGiven::class);
});

it('reads an ending with no code or signal as one whose code and signal are not known, keeps nothing a line says was printed, and refuses a signal that is not true or false', function () use ($results, $planned, $mutant): void {
    $records = Records::in($results([
        $planned('a', '/p/src/Money.php', 10),
        '{"event":"ended","mutated":"/tmp/a","printed":"out"}',
    ]));
    $refused = Records::in($results([
        $planned('a', '/p/src/Money.php', 10),
        '{"event":"ended","mutated":"/tmp/a","signalled":"no","printed":"out"}',
    ]));

    expect($records instanceof Records ? $records->runOf($mutant('a', '/p/src/Money.php', 10))->ended() : null)
        ->toEqual(Ended::unprinted(NotGiven::value(), NotGiven::value()))
        ->and($refused instanceof CannotJudge ? $refused->why() : '')->toContain('the record.signalled is not');
});

it('judges a twin by the run of the mutant whose mutated copy it shares, which ran for it in no time', function () use ($results, $planned): void {
    $twin = PlannedMutant::of('t', DiskPath::of('/p/src/Money.php'), Line::of(10), Line::of(11), 'Twin', 'diff of t', DiskPath::of('/tmp/a'));
    $records = Records::in($results([
        $planned('a', '/p/src/Money.php', 10),
        $planned('c', '/p/src/Money.php', 30),
        RecordLine::planned($twin, RecordEvent::Twin),
        RecordLine::finished('a', PestStatus::Tested, 0.5),
        RecordLine::finished('c', PestStatus::Untested, 0.25),
        RecordLine::end(),
    ]));
    $read = $records instanceof Records ? $records->planned() : [];
    $of = static fn(PlannedMutant $each): array => $records instanceof Records
        ? [$each->id(), $each->isTwin(), $records->statusOf($each), $records->durationOf($each)]
        : [];
    $summary = Summary::in('Mutations: 1 untested, 1 tested');

    expect(array_map($of, $read))->toEqual([
        ['a', false, PestStatus::Tested, Seconds::of(0.5)],
        ['t', true, PestStatus::Tested, Seconds::of(0.0)],
        ['c', false, PestStatus::Untested, Seconds::of(0.25)],
    ])
        ->and($records instanceof Records && $summary instanceof Summary && $records->addUpTo($summary))->toBeTrue()
        ->and($records instanceof Records && $records->sharesItsCopy($read[0]))->toBeFalse()
        ->and($records instanceof Records && $records->sharesItsCopy($read[1]))->toBeFalse();
});

it('leaves a twin no mutant planned on its copy ran for without a status, and keeps apart a twin of another file', function () use ($results, $planned): void {
    $alone = PlannedMutant::of('t', DiskPath::of('/p/src/Tax.php'), Line::of(10), Line::of(11), 'Twin', 'diff of t', DiskPath::of('/tmp/a'));
    $records = Records::in($results([
        $planned('a', '/p/src/Money.php', 10),
        RecordLine::planned($alone, RecordEvent::Twin),
        RecordLine::finished('a', PestStatus::Tested, 0.5),
    ]));

    expect($records instanceof Records ? $records->statusOf($alone->asTwin()) : PestStatus::Tested)->toBe(PestStatus::None);
});

it('knows a mutant that shares its mutated copy with another, whose own runs cannot be told apart', function () use ($results, $planned, $mutant): void {
    $records = Records::in($results([
        $planned('a', '/p/src/Money.php', 10),
        RecordLine::planned(PlannedMutant::of('b', DiskPath::of('/p/src/Money.php'), Line::of(10), Line::of(11), PlusToMinus::class, 'diff', DiskPath::of('/tmp/a'))),
        $planned('c', '/p/src/Money.php', 30),
    ]));

    expect($records instanceof Records && $records->sharesItsCopy($mutant('a', '/p/src/Money.php', 10)))->toBeTrue()
        ->and($records instanceof Records && $records->sharesItsCopy($mutant('c', '/p/src/Money.php', 30)))->toBeFalse();
});

it('keeps the arguments a mutant\'s own run started with, none where two runs on its copy recorded theirs, and refuses a replay\'s stop', function () use ($results, $planned, $mutant): void {
    $one = Records::in($results([
        $planned('a', '/p/src/A.php', 1),
        $planned('b', '/p/src/B.php', 1),
        RecordLine::arguments('/tmp/a', ['vendor/bin/pest', '--bail', '--filter=A']),
        RecordLine::arguments('/tmp/b', ['vendor/bin/pest']),
        RecordLine::arguments('/tmp/b', ['vendor/bin/pest', '--bail']),
    ]));
    $stopped = Records::in($results([$planned('a', '/p/src/A.php', 1), RecordLine::stopped('/tmp/a', 2, str_repeat('a', 64))]));

    expect($one instanceof Records ? $one->runOf($mutant('a', '/p/src/A.php', 1))->arguments() : null)
        ->toBe(['vendor/bin/pest', '--bail', '--filter=A'])
        ->and($one instanceof Records ? $one->runOf($mutant('b', '/p/src/B.php', 1))->arguments() : null)
        ->toBeInstanceOf(NotGiven::class)
        ->and($one instanceof Records ? $one->runOf($mutant('c', '/p/src/C.php', 1))->arguments() : null)
        ->toBeInstanceOf(NotGiven::class)
        ->and($stopped)->toBeInstanceOf(CannotJudge::class);
});

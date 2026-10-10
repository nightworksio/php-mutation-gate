<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\Rejection;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Mutant\KilledRecords;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantRecord;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKill;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Mutant\Unreported;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

$makeId = static fn(): MutantId => MutantId::hash(Path::of('src/Money.php'), 'LessThan', "-<\n+<=", 0);

$makeTimedOut = static fn(): Mutant => Mutant::of(
    $makeId(),
    '9a0b7e',
    Location::of(Path::of('src/Money.php'), Line::of(42), Line::of(43)),
    Mutation::of('LessThan', MutatorFamily::Boundary, "-<\n+<="),
    MutantStatus::TimedOut,
    Seconds::of(0.4),
)->withLimit(Seconds::of(5.0))->withUnmutatedNeed(Seconds::of(1.5));

$makeKilled = static fn(): Mutant => Mutant::of(
    $makeId(),
    '17',
    Location::of(Path::of('src/Money.php'), Line::of(1), Unreported::line()),
    Mutation::of('LessThan', MutatorFamily::Boundary, "-<\n+<="),
    MutantStatus::Killed,
    Unmeasured::duration(),
);

$makeUnjudged = static fn(): Mutant => Mutant::of(
    $makeId(),
    '18',
    Location::of(Path::of('src/Money.php'), Line::of(7), Unreported::line()),
    Mutation::of('LessThan', MutatorFamily::Boundary, "-<\n+<="),
    MutantStatus::Unjudged,
    Unmeasured::duration(),
)->because(Reason::that('No test references the constant it changes.'));

$read = static fn(array $record): Node => Node::decode(JsonText::encode($record));

it('writes everything a runner reported of a mutant in the full record', function () use ($makeTimedOut, $makeId): void {
    $timedOut = $makeTimedOut();
    $id = $makeId();

    expect(MutantRecord::full($timedOut))->toBe([
        'id' => $id->value(),
        'native' => '9a0b7e',
        'file' => 'src/Money.php',
        'line' => 42,
        'end' => 43,
        'mutator' => 'LessThan',
        'family' => 'boundary',
        'diff' => "-<\n+<=",
        'status' => 'timed-out',
        'seconds' => 0.4,
        'limit' => 5.0,
        'testSeconds' => 1.5,
    ]);
});

it('leaves out of the full record what the runner did not report', function () use ($makeKilled, $makeId): void {
    $killed = $makeKilled();
    $id = $makeId();

    expect(MutantRecord::full($killed))->toBe([
        'id' => $id->value(),
        'native' => '17',
        'file' => 'src/Money.php',
        'line' => 1,
        'mutator' => 'LessThan',
        'family' => 'boundary',
        'diff' => "-<\n+<=",
        'status' => 'killed',
    ]);
});

it('writes the reason a runner left a mutant unjudged in the full record', function () use ($makeUnjudged): void {
    $unjudged = $makeUnjudged();

    expect(MutantRecord::full($unjudged))->toMatchArray(['status' => 'unjudged', 'reason' => 'No test references the constant it changes.']);
});

it('writes and reads back what a time budget ran out before, where one left the mutant unjudged', function () use ($makeTimedOut, $makeUnjudged, $read): void {
    $timedOut = $makeTimedOut();
    $unjudged = $makeUnjudged();

    $left = $timedOut->unjudged(OutOfTime::BeforeConfirming);

    expect(MutantRecord::full($left))->toMatchArray([
        'status' => 'unjudged',
        'reason' => OutOfTime::BeforeConfirming->reason()->text(),
        'outOfTime' => 'before-confirming',
    ])
        ->and(MutantRecord::full($unjudged))->not->toHaveKey('outOfTime')
        ->and(MutantRecord::readFull($read(MutantRecord::full($left))))->toEqual($left)
        ->and(OutOfTime::left(MutantRecord::readFull($read(MutantRecord::full($left)))))->toBeTrue();
});

it('writes a killed mutant as its id, line, the index of its mutator and those of its killers', function () use ($makeKilled, $makeId): void {
    $killed = $makeKilled();
    $id = $makeId();

    $killers = TestIds::of(TestId::of('CartTest::totals'), TestId::of('MoneyTest::adds'));

    expect(MutantRecord::killed($killed->killedBy($killers), ['Plus' => 0, 'LessThan' => 3], ['MoneyTest::adds' => 0, 'CartTest::totals' => 5]))
        ->toBe([$id->value(), 1, 3, [5, 0]])
        ->and(MutantRecord::killed($killed, ['LessThan' => 0], []))->toBe([$id->value(), 1, 0, []]);
});

it('writes and reads back in full the tests that killed a mutant', function () use ($makeKilled, $read): void {
    $killed = $makeKilled();

    $killers = TestIds::of(TestId::of('CartTest::totals'), TestId::of('MoneyTest::adds'));

    expect(MutantRecord::full($killed->killedBy($killers)))->toMatchArray(['killedBy' => ['CartTest::totals', 'MoneyTest::adds']])
        ->and(MutantRecord::full($killed))->not->toHaveKey('killedBy')
        ->and(MutantRecord::readFull($read(MutantRecord::full($killed->killedBy($killers)))))->toEqual($killed->killedBy($killers));
});

it('writes and reads back in full the rejection that killed a mutant, with the file its finding sits in', function () use ($makeUnjudged, $makeKilled, $read): void {
    $unjudged = $makeUnjudged();
    $killed = $makeKilled();

    $rejected = $unjudged->rejected(Rejection::by('phpstan', Finding::error(Path::of('src/Wallet.php'), 'return.type', 'Method Money::of() should return int but returns string.')));

    expect(MutantRecord::full($rejected))->toMatchArray([
        'status' => 'killed-by-static-analysis',
        'rejection' => [
            'analyser' => 'phpstan',
            'file' => 'src/Wallet.php',
            'code' => 'return.type',
            'message' => 'Method Money::of() should return int but returns string.',
        ],
    ])
        ->and(MutantRecord::full($killed))->not->toHaveKey('rejection')
        ->and(MutantRecord::readFull($read(MutantRecord::full($rejected))))->toEqual($rejected);
});

it('writes and reads back a registered mutator\'s own hint, and writes none where it has none', function () use ($makeId, $makeKilled, $read): void {
    $id = $makeId();
    $killed = $makeKilled();

    $hinted = Mutant::of(
        $id,
        '19',
        Location::of(Path::of('src/Money.php'), Line::of(3), Unreported::line()),
        Mutation::of('acme/RemoveEcho', MutatorFamily::RemovedCall, "-echo 1;", 'No test checks what is printed.'),
        MutantStatus::Survived,
        Unmeasured::duration(),
    );

    expect(MutantRecord::full($hinted))->toHaveKey('hint', 'No test checks what is printed.')
        ->and(MutantRecord::full($killed))->not->toHaveKey('hint')
        ->and(MutantRecord::readFull($read(MutantRecord::full($hinted))))->toEqual($hinted);
});

it('reads back the mutant it wrote in full', function () use ($makeTimedOut, $makeKilled, $makeUnjudged, $read): void {
    $timedOut = $makeTimedOut();
    $killed = $makeKilled();
    $unjudged = $makeUnjudged();

    expect(MutantRecord::readFull($read(MutantRecord::full($timedOut))))->toEqual($timedOut)
        ->and(MutantRecord::readFull($read(MutantRecord::full($killed))))->toEqual($killed)
        ->and(MutantRecord::readFull($read(MutantRecord::full($unjudged))))->toEqual($unjudged);
});

it('reads a killed record as the kill it proves in the unit it was proved in', function () use ($makeKilled, $makeId, $read): void {
    $killed = $makeKilled();
    $id = $makeId();

    $killers = TestIds::of(TestId::of('CartTest::totals'));
    $kill = MutantRecord::readKilled($read(MutantRecord::killed($killed, ['LessThan' => 1], [])), Path::of('src/Money.php'), KilledRecords::of(['Plus', 'LessThan'], ['CartTest::totals']));

    expect($kill)->toEqual(ProvedKill::of($id, Path::of('src/Money.php'), Line::of(1), 'LessThan', TestIds::none()))
        ->and(MutantRecord::readKilled($read(MutantRecord::killed($killed->killedBy($killers), ['LessThan' => 1], ['CartTest::totals' => 0])), Path::of('src/Money.php'), KilledRecords::of(['Plus', 'LessThan'], ['CartTest::totals'])))
        ->toEqual(ProvedKill::of($id, Path::of('src/Money.php'), Line::of(1), 'LessThan', $killers))
        ->and(MutantRecord::killed($kill, ['LessThan' => 1], []))->toBe(MutantRecord::killed($killed, ['LessThan' => 1], []));
});

it('tells a full record from a killed one', function () use ($makeKilled, $read): void {
    $killed = $makeKilled();

    expect(MutantRecord::isFull($read(MutantRecord::full($killed))))->toBeTrue()
        ->and(MutantRecord::isFull($read(MutantRecord::killed($killed, ['LessThan' => 0], []))))->toBeFalse();
});

it('refuses a record that does not hold a mutant, saying where', function (array $change, NotInShape $refusal) use ($makeTimedOut, $read): void {
    $timedOut = $makeTimedOut();

    expect(fn(): Mutant => MutantRecord::readFull($read([...MutantRecord::full($timedOut), ...$change])))->toThrow($refusal);
})->with([
    'an id that is not one' => [['id' => 'xyz'], fn(): NotInShape => NotInShape::at('the file.id', 'a mutant id')],
    'a line before the first' => [['line' => 0], fn(): NotInShape => NotInShape::at('the file.line', 'a line')],
    'an end before the first line' => [['end' => 0], fn(): NotInShape => NotInShape::at('the file.end', 'a line')],
    'a family there is not' => [['family' => 'nope'], fn(): NotInShape => NotInShape::at('the file.family', 'a mutator family')],
    'a status there is not' => [['status' => 'nope'], fn(): NotInShape => NotInShape::at('the file.status', 'a status')],
    'seconds that are not a number' => [['seconds' => 'long'], fn(): NotInShape => NotInShape::at('the file.seconds', 'a number')],
    'a limit that is not a number' => [['limit' => 'long'], fn(): NotInShape => NotInShape::at('the file.limit', 'a number')],
    'test seconds that are not a number' => [['testSeconds' => 'long'], fn(): NotInShape => NotInShape::at('the file.testSeconds', 'a number')],
    'a native id that is not text' => [['native' => 7], fn(): NotInShape => NotInShape::at('the file.native', 'text')],
    'a reason that is not text' => [['reason' => 7], fn(): NotInShape => NotInShape::at('the file.reason', 'text')],
    'a budget that ran out before what there is not' => [['outOfTime' => 'before-lunch'], fn(): NotInShape => NotInShape::at('the file.outOfTime', 'what a time budget ran out before')],
]);

it('refuses a killed record that does not hold a killed mutant, saying where', function (array $record, NotInShape $refusal) use ($read): void {
    expect(fn(): ProvedKill => MutantRecord::readKilled($read($record), Path::of('src/Money.php'), KilledRecords::of(['Plus'], ['MoneyTest::adds'])))->toThrow($refusal);
})->with([
    'too few fields' => [['3f9a1c2b7d04', 44, 0], fn(): NotInShape => NotInShape::at('the file', 'a killed mutant, as [id, line, mutator, killers]')],
    'too many fields' => [['3f9a1c2b7d04', 44, 0, [], 0], fn(): NotInShape => NotInShape::at('the file', 'a killed mutant, as [id, line, mutator, killers]')],
    'an id that is not one' => [['xyz', 44, 0, []], fn(): NotInShape => NotInShape::at('the file[0]', 'a mutant id')],
    'a line before the first' => [['3f9a1c2b7d04', 0, 0, []], fn(): NotInShape => NotInShape::at('the file[1]', 'a line')],
    'a mutator past the last' => [['3f9a1c2b7d04', 44, 1, []], fn(): NotInShape => NotInShape::at('the file[2]', 'a mutator')],
    'a mutator that is not an index' => [['3f9a1c2b7d04', 44, 'Plus', []], fn(): NotInShape => NotInShape::at('the file[2]', 'a whole number')],
    'killers that are not a list' => [['3f9a1c2b7d04', 44, 0, 0], fn(): NotInShape => NotInShape::at('the file[3]', 'a list of whole numbers')],
    'a killer past the last test' => [['3f9a1c2b7d04', 44, 0, [0, 1]], fn(): NotInShape => NotInShape::at('the file[3]', 'the index of a listed test, not 1')],
]);

it('refuses killers of a full record that are not text, saying where', function () use ($makeKilled, $read): void {
    $killed = $makeKilled();

    expect(fn(): Mutant => MutantRecord::readFull($read([...MutantRecord::full($killed), 'killedBy' => [7]])))->toThrow(NotInShape::at('the file.killedBy[0]', 'text'))
        ->and(fn(): Mutant => MutantRecord::readFull($read([...MutantRecord::full($killed), 'killedBy' => 'MoneyTest::adds'])))->toThrow(NotInShape::at('the file.killedBy', 'a list'));
});

it('refuses a record that gives a rejection to a mutant of any other status, saying where', function (string $status) use ($makeUnjudged, $read): void {
    $unjudged = $makeUnjudged();

    $record = MutantRecord::full($unjudged);
    $record['status'] = $status;
    $record['rejection'] = ['analyser' => 'phpstan', 'file' => 'src/Money.php', 'code' => 'return.type', 'message' => 'No.'];

    expect(static fn(): Mutant => MutantRecord::readFull($read($record)))
        ->toThrow(NotInShape::at('the file.rejection', 'a rejection only on a mutant killed by static analysis'));
})->with(['survived', 'unjudged', 'killed']);

it('refuses a rejection that does not name the file its finding sits in, saying where', function () use ($makeUnjudged, $read): void {
    $unjudged = $makeUnjudged();

    $record = MutantRecord::full($unjudged->rejected(Rejection::by('phpstan', Finding::error(Path::of('src/Money.php'), 'return.type', 'No.'))));
    unset($record['rejection']['file']);

    expect(static fn(): Mutant => MutantRecord::readFull($read($record)))->toThrow(NotInShape::class, 'the file.rejection.file');
});

it('writes and reads back a rejected mutant left unjudged as unjudged, with no rejection', function () use ($makeKilled, $read): void {
    $killed = $makeKilled();

    $left = $killed->rejected(Rejection::by('phpstan', Finding::error(Path::of('src/Money.php'), 'return.type', 'No.')))->unjudged(OutOfTime::BeforeMutating);

    expect(MutantRecord::full($left))->not->toHaveKey('rejection')
        ->and(MutantRecord::readFull($read(MutantRecord::full($left))))->toEqual($left);
});

it('refuses a record that gives a rejection a reason or a time budget beside it, saying where', function (array $beside) use ($makeUnjudged, $read): void {
    $unjudged = $makeUnjudged();

    $record = [
        ...MutantRecord::full($unjudged->rejected(Rejection::by('phpstan', Finding::error(Path::of('src/Money.php'), 'return.type', 'No.')))),
        ...$beside,
    ];

    expect(static fn(): Mutant => MutantRecord::readFull($read($record)))
        ->toThrow(NotInShape::at('the file.rejection', 'a rejection with no reason beside it'));
})->with([
    'a reason' => [['reason' => 'The runner said so.']],
    'a time budget' => [['outOfTime' => 'before-mutating']],
]);

it('writes and reads back, in bytes, the memory cap a mutant ran out of and the suite\'s peak', function () use ($makeId, $read): void {
    $id = $makeId();

    $outOfMemory = Mutant::of(
        $id,
        '19',
        Location::of(Path::of('src/Money.php'), Line::of(9), Unreported::line()),
        Mutation::of('LessThan', MutatorFamily::Boundary, "-<\n+<="),
        MutantStatus::OutOfMemory,
        Unmeasured::duration(),
    )->withLimit(MemoryCap::of(64, MemoryUnit::Megabytes))->withUnmutatedNeed(MemoryCap::of(20, MemoryUnit::Megabytes));
    $full = MutantRecord::full($outOfMemory);

    expect(array_slice($full, -3))->toBe(['status' => 'out-of-memory', 'limitBytes' => 67108864, 'suiteBytes' => 20971520])
        ->and(MutantRecord::readFull($read($full)))->toEqual($outOfMemory)
        ->and(fn(): Mutant => MutantRecord::readFull($read([...$full, 'limitBytes' => 0])))
        ->toThrow(NotInShape::class, 'is not a number of bytes');
});

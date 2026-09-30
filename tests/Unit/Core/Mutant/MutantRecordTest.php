<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
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
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

$id = MutantId::hash(Path::of('src/Money.php'), 'LessThan', "-<\n+<=", 0);

$timedOut = Mutant::of(
    $id,
    '9a0b7e',
    Location::of(Path::of('src/Money.php'), Line::of(42), Line::of(43)),
    Mutation::of('LessThan', MutatorFamily::Boundary, "-<\n+<="),
    MutantStatus::TimedOut,
    Seconds::of(0.4),
)->withLimit(Seconds::of(5.0))->withJudgingTime(Seconds::of(1.5));

$killed = Mutant::of(
    $id,
    '17',
    Location::of(Path::of('src/Money.php'), Line::of(1), Unreported::line()),
    Mutation::of('LessThan', MutatorFamily::Boundary, "-<\n+<="),
    MutantStatus::Killed,
    Unmeasured::duration(),
);

$unjudged = Mutant::of(
    $id,
    '18',
    Location::of(Path::of('src/Money.php'), Line::of(7), Unreported::line()),
    Mutation::of('LessThan', MutatorFamily::Boundary, "-<\n+<="),
    MutantStatus::Unjudged,
    Unmeasured::duration(),
)->because(Reason::that('No test references the constant it changes.'));

$read = static fn(array $record): Node => Node::decode(JsonText::encode($record));

it('writes everything a runner reported of a mutant in the full record', function () use ($timedOut, $id): void {
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

it('leaves out of the full record what the runner did not report', function () use ($killed, $id): void {
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

it('writes the reason a runner left a mutant unjudged in the full record', function () use ($unjudged): void {
    expect(MutantRecord::full($unjudged))->toMatchArray(['status' => 'unjudged', 'reason' => 'No test references the constant it changes.']);
});

it('writes and reads back what a time budget ran out before, where one left the mutant unjudged', function () use ($timedOut, $unjudged, $read): void {
    $left = $timedOut->unjudged(OutOfTime::BeforeRetrying);

    expect(MutantRecord::full($left))->toMatchArray([
        'status' => 'unjudged',
        'reason' => OutOfTime::BeforeRetrying->reason()->text(),
        'outOfTime' => 'before-retrying',
    ])
        ->and(MutantRecord::full($unjudged))->not->toHaveKey('outOfTime')
        ->and(MutantRecord::readFull($read(MutantRecord::full($left))))->toEqual($left)
        ->and(OutOfTime::left(MutantRecord::readFull($read(MutantRecord::full($left)))))->toBeTrue();
});

it('writes a killed mutant as its id, line, the index of its mutator and those of its killers', function () use ($killed, $id): void {
    $killers = TestIds::of(TestId::of('CartTest::totals'), TestId::of('MoneyTest::adds'));

    expect(MutantRecord::killed($killed->killedBy($killers), ['Plus' => 0, 'LessThan' => 3], ['MoneyTest::adds' => 0, 'CartTest::totals' => 5]))
        ->toBe([$id->value(), 1, 3, [5, 0]])
        ->and(MutantRecord::killed($killed, ['LessThan' => 0], []))->toBe([$id->value(), 1, 0, []]);
});

it('writes and reads back in full the tests that killed a mutant', function () use ($killed, $read): void {
    $killers = TestIds::of(TestId::of('CartTest::totals'), TestId::of('MoneyTest::adds'));

    expect(MutantRecord::full($killed->killedBy($killers)))->toMatchArray(['killedBy' => ['CartTest::totals', 'MoneyTest::adds']])
        ->and(MutantRecord::full($killed))->not->toHaveKey('killedBy')
        ->and(MutantRecord::readFull($read(MutantRecord::full($killed->killedBy($killers)))))->toEqual($killed->killedBy($killers));
});

it('reads back the mutant it wrote in full', function () use ($timedOut, $killed, $unjudged, $read): void {
    expect(MutantRecord::readFull($read(MutantRecord::full($timedOut))))->toEqual($timedOut)
        ->and(MutantRecord::readFull($read(MutantRecord::full($killed))))->toEqual($killed)
        ->and(MutantRecord::readFull($read(MutantRecord::full($unjudged))))->toEqual($unjudged);
});

it('reads a killed record as the kill it proves in the unit it was proved in', function () use ($killed, $id, $read): void {
    $killers = TestIds::of(TestId::of('CartTest::totals'));
    $kill = MutantRecord::readKilled($read(MutantRecord::killed($killed, ['LessThan' => 1], [])), Path::of('src/Money.php'), ['Plus', 'LessThan'], ['CartTest::totals']);

    expect($kill)->toEqual(ProvedKill::of($id, Path::of('src/Money.php'), Line::of(1), 'LessThan', TestIds::none()))
        ->and(MutantRecord::readKilled($read(MutantRecord::killed($killed->killedBy($killers), ['LessThan' => 1], ['CartTest::totals' => 0])), Path::of('src/Money.php'), ['Plus', 'LessThan'], ['CartTest::totals']))
        ->toEqual(ProvedKill::of($id, Path::of('src/Money.php'), Line::of(1), 'LessThan', $killers))
        ->and(MutantRecord::killed($kill, ['LessThan' => 1], []))->toBe(MutantRecord::killed($killed, ['LessThan' => 1], []));
});

it('tells a full record from a killed one', function () use ($killed, $read): void {
    expect(MutantRecord::isFull($read(MutantRecord::full($killed))))->toBeTrue()
        ->and(MutantRecord::isFull($read(MutantRecord::killed($killed, ['LessThan' => 0], []))))->toBeFalse();
});

it('refuses a record that does not hold a mutant, saying where', function (array $change, NotInShape $refusal) use ($timedOut, $read): void {
    expect(fn(): Mutant => MutantRecord::readFull($read([...MutantRecord::full($timedOut), ...$change])))->toThrow($refusal);
})->with([
    'an id that is not one' => [['id' => 'xyz'], NotInShape::at('the file.id', 'a mutant id')],
    'a line before the first' => [['line' => 0], NotInShape::at('the file.line', 'a line')],
    'an end before the first line' => [['end' => 0], NotInShape::at('the file.end', 'a line')],
    'a family there is not' => [['family' => 'nope'], NotInShape::at('the file.family', 'a mutator family')],
    'a status there is not' => [['status' => 'nope'], NotInShape::at('the file.status', 'a status')],
    'seconds that are not a number' => [['seconds' => 'long'], NotInShape::at('the file.seconds', 'a number')],
    'a limit that is not a number' => [['limit' => 'long'], NotInShape::at('the file.limit', 'a number')],
    'test seconds that are not a number' => [['testSeconds' => 'long'], NotInShape::at('the file.testSeconds', 'a number')],
    'a native id that is not text' => [['native' => 7], NotInShape::at('the file.native', 'text')],
    'a reason that is not text' => [['reason' => 7], NotInShape::at('the file.reason', 'text')],
    'a budget that ran out before what there is not' => [['outOfTime' => 'before-lunch'], NotInShape::at('the file.outOfTime', 'what a time budget ran out before')],
]);

it('refuses a killed record that does not hold a killed mutant, saying where', function (array $record, NotInShape $refusal) use ($read): void {
    expect(fn(): ProvedKill => MutantRecord::readKilled($read($record), Path::of('src/Money.php'), ['Plus'], ['MoneyTest::adds']))->toThrow($refusal);
})->with([
    'too few fields' => [['3f9a1c2b7d04', 44, 0], NotInShape::at('the file', 'a killed mutant, as [id, line, mutator, killers]')],
    'too many fields' => [['3f9a1c2b7d04', 44, 0, [], 0], NotInShape::at('the file', 'a killed mutant, as [id, line, mutator, killers]')],
    'an id that is not one' => [['xyz', 44, 0, []], NotInShape::at('the file[0]', 'a mutant id')],
    'a line before the first' => [['3f9a1c2b7d04', 0, 0, []], NotInShape::at('the file[1]', 'a line')],
    'a mutator past the last' => [['3f9a1c2b7d04', 44, 1, []], NotInShape::at('the file[2]', 'a mutator')],
    'a mutator that is not an index' => [['3f9a1c2b7d04', 44, 'Plus', []], NotInShape::at('the file[2]', 'a whole number')],
    'killers that are not a list' => [['3f9a1c2b7d04', 44, 0, 0], NotInShape::at('the file[3]', 'a list of whole numbers')],
    'a killer past the last test' => [['3f9a1c2b7d04', 44, 0, [0, 1]], NotInShape::at('the file[3]', 'the index of a listed test, not 1')],
]);

it('refuses killers of a full record that are not text, saying where', function () use ($killed, $read): void {
    expect(fn(): Mutant => MutantRecord::readFull($read([...MutantRecord::full($killed), 'killedBy' => [7]])))->toThrow(NotInShape::at('the file.killedBy[0]', 'text'))
        ->and(fn(): Mutant => MutantRecord::readFull($read([...MutantRecord::full($killed), 'killedBy' => 'MoneyTest::adds'])))->toThrow(NotInShape::at('the file.killedBy', 'a list'));
});

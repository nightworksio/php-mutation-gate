<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantRecord;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Mutant\Unreported;
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
)->withLimit(Seconds::of(5.0));

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

$read = static fn(array $record): Node => Node::decode(Json::encode($record));

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

it('writes a killed mutant as its id, line and the index of its mutator', function () use ($killed, $id): void {
    expect(MutantRecord::killed($killed, 3))->toBe([$id->value(), 1, 3]);
});

it('reads back the mutant it wrote in full', function () use ($timedOut, $killed, $unjudged, $read): void {
    expect(MutantRecord::readFull($read(MutantRecord::full($timedOut))))->toEqual($timedOut)
        ->and(MutantRecord::readFull($read(MutantRecord::full($killed))))->toEqual($killed)
        ->and(MutantRecord::readFull($read(MutantRecord::full($unjudged))))->toEqual($unjudged);
});

it('reads a killed record as a mutant of the unit it was proved in', function () use ($killed, $id, $read): void {
    $expected = Mutant::of(
        $id,
        '',
        Location::of(Path::of('src/Money.php'), Line::of(1), Unreported::line()),
        Mutation::of('LessThan', MutatorFamily::None, ''),
        MutantStatus::Killed,
        Unmeasured::duration(),
    );

    expect(MutantRecord::readKilled($read(MutantRecord::killed($killed, 1)), Path::of('src/Money.php'), ['Plus', 'LessThan']))
        ->toEqual($expected);
});

it('tells a full record from a killed one', function () use ($killed, $read): void {
    expect(MutantRecord::isFull($read(MutantRecord::full($killed))))->toBeTrue()
        ->and(MutantRecord::isFull($read(MutantRecord::killed($killed, 0))))->toBeFalse();
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
    'a native id that is not text' => [['native' => 7], NotInShape::at('the file.native', 'text')],
    'a reason that is not text' => [['reason' => 7], NotInShape::at('the file.reason', 'text')],
]);

it('refuses a killed record that does not hold a killed mutant, saying where', function (array $record, NotInShape $refusal) use ($read): void {
    expect(fn(): Mutant => MutantRecord::readKilled($read($record), Path::of('src/Money.php'), ['Plus']))->toThrow($refusal);
})->with([
    'too few fields' => [['3f9a1c2b7d04', 44], NotInShape::at('the file', 'a killed mutant, as [id, line, mutator]')],
    'too many fields' => [['3f9a1c2b7d04', 44, 0, 0], NotInShape::at('the file', 'a killed mutant, as [id, line, mutator]')],
    'an id that is not one' => [['xyz', 44, 0], NotInShape::at('the file[0]', 'a mutant id')],
    'a line before the first' => [['3f9a1c2b7d04', 0, 0], NotInShape::at('the file[1]', 'a line')],
    'a mutator past the last' => [['3f9a1c2b7d04', 44, 1], NotInShape::at('the file[2]', 'a mutator')],
    'a mutator that is not an index' => [['3f9a1c2b7d04', 44, 'Plus'], NotInShape::at('the file[2]', 'a whole number')],
]);

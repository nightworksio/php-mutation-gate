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

it('writes a killed mutant briefly: its id, line, mutator and status', function () use ($killed, $id): void {
    expect(MutantRecord::brief($killed))->toBe(['id' => $id->value(), 'line' => 1, 'mutator' => 'LessThan', 'status' => 'killed']);
});

it('reads back the mutant it wrote in full', function () use ($timedOut, $killed, $read): void {
    expect(MutantRecord::readFull($read(MutantRecord::full($timedOut))))->toEqual($timedOut)
        ->and(MutantRecord::readFull($read(MutantRecord::full($killed))))->toEqual($killed);
});

it('reads a brief record as a mutant of the unit it was proved in', function () use ($killed, $id, $read): void {
    $expected = Mutant::of(
        $id,
        '',
        Location::of(Path::of('src/Money.php'), Line::of(1), Unreported::line()),
        Mutation::of('LessThan', MutatorFamily::None, ''),
        MutantStatus::Killed,
        Unmeasured::duration(),
    );

    expect(MutantRecord::readBrief($read(MutantRecord::brief($killed)), Path::of('src/Money.php')))->toEqual($expected);
});

it('tells a full record from a brief one', function () use ($killed, $read): void {
    expect(MutantRecord::isFull($read(MutantRecord::full($killed))))->toBeTrue()
        ->and(MutantRecord::isFull($read(MutantRecord::brief($killed))))->toBeFalse();
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
]);

it('refuses a brief record that does not hold a mutant', function () use ($killed, $read): void {
    expect(fn(): Mutant => MutantRecord::readBrief($read([...MutantRecord::brief($killed), 'line' => 0]), Path::of('src/Money.php')))
        ->toThrow(NotInShape::at('the file.line', 'a line'))
        ->and(fn(): Mutant => MutantRecord::readBrief($read([...MutantRecord::brief($killed), 'status' => 'x']), Path::of('src/Money.php')))
        ->toThrow(NotInShape::at('the file.status', 'a status'));
});

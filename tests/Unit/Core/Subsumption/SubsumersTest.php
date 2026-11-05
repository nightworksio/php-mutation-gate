<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Subsumption\NegationRule;
use NightWorksIO\MutationGate\Core\Subsumption\Subsumers;
use NightWorksIO\MutationGate\Core\Subsumption\Subsumption;
use NightWorksIO\MutationGate\Tests\Support\Subsumed;

/**
 * Each held mutant's subsumers, by the values of the ids.
 *
 * @param  array<string, array{MutantId, list<MutantId>}> $held
 * @return array<string, list<string>>
 */
function heldValues(array $held): array
{
    $values = [];

    foreach ($held as [$mutant, $subsumers]) {
        $values[$mutant->value()] = array_map(static fn(MutantId $id): string => $id->value(), $subsumers);
    }

    return $values;
}

it('holds back a negation behind each change of the same line within the expression it negates', function (): void {
    $loosened = Subsumed::killed(Subsumed::diff('return $a < $b;', 'return $a <= $b;'));
    $negated = Subsumed::killed(Subsumed::diff('return $a < $b;', 'return $a >= $b;'));
    $elsewhere = Subsumed::mutant('src/Money.php', 2, Subsumed::diff('return $a < $b;', 'return $a <= $b;'));
    $joined = Subsumed::killed(Subsumed::diff('return $a < $b && $c;', 'return $a < $b || $c;'));
    $ofJoined = Subsumed::killed(Subsumed::diff('return $a < $b && $c;', 'return $a >= $b && $c;'));
    $inJoined = Subsumed::killed(Subsumed::diff('return $a < $b && $c;', 'return $a <= $b && $c;'));

    expect(heldValues(NegationRule::among($loosened, $negated, $elsewhere)))
        ->toBe([$negated->id()->value() => [$loosened->id()->value()]])
        ->and(heldValues(NegationRule::among($joined, $ofJoined, $inJoined)))
        ->toBe([$ofJoined->id()->value() => [$inJoined->id()->value()]]);
});

it('holds back, of two negations of the same expression, the one with the higher id', function (): void {
    $swapped = Subsumed::killed(Subsumed::diff('if ($a > $b) {', 'if ($a <= $b) {'));
    $wrapped = Subsumed::killed(Subsumed::diff('if ($a > $b) {', 'if (!($a > $b)) {'));
    [$low, $high] = $swapped->id()->value() < $wrapped->id()->value() ? [$swapped, $wrapped] : [$wrapped, $swapped];

    expect(heldValues(NegationRule::among($swapped, $wrapped)))->toBe([$high->id()->value() => [$low->id()->value()]]);
});

it('holds back a mutant another offered one subsumes as a run learned it, and by the rule for negations in a file none learned of', function (): void {
    $by = Subsumed::killed(Subsumed::diff('a', 'b'), 'T1');
    $of = Subsumed::killed(Subsumed::diff('a', 'c'), 'T1', 'T2');
    $loosened = Subsumed::mutant('src/Tax.php', 3, Subsumed::diff('return $a < $b;', 'return $a <= $b;'));
    $negated = Subsumed::mutant('src/Tax.php', 3, Subsumed::diff('return $a < $b;', 'return $a >= $b;'));
    $held = Subsumers::among(Subsumption::learned($by, $of), $by, $of, $loosened, $negated);
    $unoffered = Subsumers::among(Subsumption::learned($by, $of), $of);

    expect([$held->holds($of->id()), $held->holds($negated->id()), $held->holds($by->id()), $held->holds($loosened->id())])
        ->toBe([true, true, false, false])
        ->and($unoffered->holds($of->id()))->toBeFalse()
        ->and(Subsumers::none()->and($held)->holds($of->id()))->toBeTrue();
});

it('infers a held mutant\'s kill from a killed subsumer, or a held one inferred in turn, and none where its subsumer survived or the chain comes back on itself', function (): void {
    $first = Subsumed::killed(Subsumed::diff('a', 'b'), 'T1');
    $second = Subsumed::killed(Subsumed::diff('a', 'c'), 'T1', 'T2');
    $third = Subsumed::killed(Subsumed::diff('a', 'd'), 'T1', 'T2', 'T3');
    $held = Subsumers::among(Subsumption::learned($first, $second, $third), $first, $second, $third);
    $survived = Subsumed::mutant('src/Money.php', 1, Subsumed::diff('a', 'b'), MutantStatus::Survived);

    expect($held->inferredFrom(Mutants::of($first)))->toEqual([
        $second->id()->key() => $first->id(),
        $third->id()->key() => $first->id(),
    ])
        ->and($held->inferredFrom(Mutants::of($survived)))->toBe([]);
});

it('infers through a held subsumer and stops where two held mutants subsume each other', function (): void {
    $loosened = Subsumed::killed(Subsumed::diff('return $a < $b;', 'return $a <= $b;'));
    $swapped = Subsumed::killed(Subsumed::diff('return $a < $b;', 'return $a >= $b;'));
    $wrapped = Subsumed::killed(Subsumed::diff('return $a < $b;', 'return !($a < $b);'));
    $held = Subsumers::among(Subsumption::none(), $loosened, $swapped, $wrapped);
    $inferred = $held->inferredFrom(Mutants::of($loosened));
    $none = $held->inferredFrom(Mutants::none());

    expect(array_map(static fn(MutantId $id): string => $id->value(), $inferred))
        ->toEqual(array_fill_keys([$swapped->id()->key(), $wrapped->id()->key()], $loosened->id()->value()))
        ->and($none)->toBe([]);
});

it('holds a negation behind a change of the first or the last token of the expression it negates', function (): void {
    $first = Subsumed::killed(Subsumed::diff('return $a < $b;', 'return $c < $b;'));
    $last = Subsumed::killed(Subsumed::diff('return $a < $b;', 'return $a < $c;'));
    $negated = Subsumed::killed(Subsumed::diff('return $a < $b;', 'return $a >= $b;'));

    expect(heldValues(NegationRule::among($first, $last, $negated)))
        ->toBe([$negated->id()->value() => [$first->id()->value(), $last->id()->value()]]);
});

it('holds nothing back by the negation rule in a file a run learned of', function (): void {
    $by = Subsumed::killed(Subsumed::diff('a', 'b'), 'T1');
    $of = Subsumed::killed(Subsumed::diff('a', 'c'), 'T1', 'T2');
    $loosened = Subsumed::killed(Subsumed::diff('return $a < $b;', 'return $a <= $b;'));
    $negated = Subsumed::killed(Subsumed::diff('return $a < $b;', 'return $a >= $b;'));
    $held = Subsumers::among(Subsumption::learned($by, $of), $by, $of, $loosened, $negated);

    expect([$held->holds($of->id()), $held->holds($negated->id())])->toBe([true, false]);
});

it('infers nothing, and ends, where held mutants subsume each other in a ring of two or three and none was killed', function (): void {
    $one = Subsumed::killed(Subsumed::diff('a', 'b'));
    $two = Subsumed::killed(Subsumed::diff('a', 'c'));
    $three = Subsumed::killed(Subsumed::diff('a', 'd'));
    $file = Path::of('src/Money.php');
    $held = Subsumers::among(Subsumption::none()->with($file, $one->id(), $two->id())->with($file, $two->id(), $one->id()), $one, $two);
    $ring = Subsumers::among(
        Subsumption::none()
            ->with($file, $one->id(), $two->id())
            ->with($file, $two->id(), $three->id())
            ->with($file, $three->id(), $one->id()),
        $one,
        $two,
        $three,
    );

    expect([$held->holds($one->id()), $held->holds($two->id())])->toBe([true, true])
        ->and($held->inferredFrom(Mutants::none()))->toBe([])
        ->and($ring->inferredFrom(Mutants::none()))->toBe([]);
});

it('infers a kill along a chain of mutants a run learned subsume each other, each in turn', function (): void {
    $first = Subsumed::killed(Subsumed::diff('a', 'b'));
    $second = Subsumed::killed(Subsumed::diff('a', 'c'));
    $third = Subsumed::killed(Subsumed::diff('a', 'd'));
    $file = Path::of('src/Money.php');
    $held = Subsumers::among(
        Subsumption::none()->with($file, $second->id(), $first->id())->with($file, $third->id(), $second->id()),
        $first,
        $second,
        $third,
    );

    expect(array_map(static fn(MutantId $id): string => $id->value(), $held->inferredFrom(Mutants::of($first))))
        ->toEqual(array_fill_keys([$second->id()->key(), $third->id()->key()], $first->id()->value()));
});

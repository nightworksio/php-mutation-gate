<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Order\Bound;
use NightWorksIO\MutationGate\Core\Order\Enclosing;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Order\Kills;
use NightWorksIO\MutationGate\Core\Order\Lesson;
use NightWorksIO\MutationGate\Core\Order\RankedFunction;
use NightWorksIO\MutationGate\Core\Order\RankedMutant;
use NightWorksIO\MutationGate\Core\Order\Ranking;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Tests\Support\Growth;

/** A mutant of src/Money.php, with a status, killed by these tests. */
function historyMutant(string $diff, MutantStatus $status, string ...$killers): Mutant
{
    return Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'Plus', $diff, 0),
        'n',
        Location::of(Path::of('src/Money.php'), Line::of(11), Line::of(11)),
        Mutation::of('Plus', MutatorFamily::Arithmetic, $diff),
        $status,
        Unmeasured::duration(),
    )->killedBy(TestIds::of(...array_map(TestId::of(...), $killers)));
}

/** A mutant's id, as the ledger spells it. */
function historyId(string $id): MutantId
{
    $parsed = MutantId::parse($id);

    return $parsed instanceof MutantId ? $parsed : throw new RuntimeException($parsed->why());
}

/** @return list<string> the ids of the mutants a history knows, the newest last */
function historyMutants(KillHistory $history): array
{
    return array_map(static fn(RankedMutant $ranked): string => $ranked->mutant()->value(), [...$history->mutants()]);
}

/** @return list<string> the names of the functions a history knows, the newest last */
function historyFunctions(KillHistory $history): array
{
    return array_map(static fn(RankedFunction $ranked): string => $ranked->function()->function(), [...$history->functions()]);
}

/** @return list<string> */
function historyNames(TestIds $tests): array
{
    return array_map(static fn(TestId $test): string => $test->value(), [...$tests]);
}

$add = Enclosing::named(Path::of('src/Money.php'), 'add');

it('learns the first killer of each killed mutant, and of its function\'s mutants', function () use ($add): void {
    $one = historyMutant('-+ one', MutantStatus::Killed, 'MoneyTest::adds', 'MoneyTest::sums');
    $two = historyMutant('-+ two', MutantStatus::Killed, 'CartTest::totals');
    $history = KillHistory::none()
        ->learnedFrom(Lesson::of($one, $add), Lesson::of($one, $add))
        ->learnedFrom(Lesson::of($two, $add), Lesson::of(historyMutant('-+ three', MutantStatus::Killed, 'CartTest::totals'), Nameless::code()));

    expect(historyNames($history->likelyKillers($one->id(), $add)))->toBe(['MoneyTest::adds'])
        ->and(historyNames($history->likelyKillers($two->id(), Nameless::code())))->toBe(['CartTest::totals'])
        ->and(historyNames($history->likelyKillers(historyMutant('-+ new', MutantStatus::Killed)->id(), $add)))
        ->toBe(['MoneyTest::adds', 'CartTest::totals'])
        ->and(historyNames($history->likelyKillers(historyMutant('-+ new', MutantStatus::Killed)->id(), Nameless::code())))
        ->toBe([])
        ->and(historyMutants($history))->toBe([$one->id()->value(), $two->id()->value(), historyMutant('-+ three', MutantStatus::Killed)->id()->value()]);
});

it('keeps each mutant and function in the order it last learned something, the newest last', function () use ($add): void {
    $one = historyMutant('-+ one', MutantStatus::Killed, 'MoneyTest::adds');
    $two = historyMutant('-+ two', MutantStatus::Killed, 'MoneyTest::adds');
    $cart = Enclosing::named(Path::of('src/Cart.php'), 'total');
    $ranking = Ranking::of(Kills::of(TestId::of('MoneyTest::adds'), 1));
    $learned = KillHistory::none()->learnedFrom(Lesson::of($one, $add), Lesson::of($two, $cart), Lesson::of($one, $add));
    $given = KillHistory::none()->withFunction($add, $ranking)->withFunction($cart, $ranking)->withFunction($add, $ranking);

    expect(historyMutants($learned))->toBe([$two->id()->value(), $one->id()->value()])
        ->and(historyFunctions($learned))->toBe(['total', 'add'])
        ->and(historyFunctions($given))->toBe(['total', 'add']);
});

it('learns nothing from a mutant that survived, or was killed by a test nobody knows', function () use ($add): void {
    $history = KillHistory::none()->learnedFrom(
        Lesson::of(historyMutant('-+ one', MutantStatus::Survived, 'MoneyTest::adds'), $add),
        Lesson::of(historyMutant('-+ two', MutantStatus::Killed), $add),
    );

    expect($history)->toEqual(KillHistory::none());
});

it('reads two scopes\' histories together, this one\'s where both know a mutant or a function, and newest', function () use ($add): void {
    $mutant = historyMutant('-+ one', MutantStatus::Killed)->id();
    $other = historyMutant('-+ two', MutantStatus::Killed)->id();
    $cart = Enclosing::named(Path::of('src/Cart.php'), 'total');
    $mine = KillHistory::none()
        ->withMutant($mutant, Ranking::of(Kills::of(TestId::of('mine'), 1)))
        ->withFunction($add, Ranking::of(Kills::of(TestId::of('mine'), 1)));
    $default = KillHistory::none()
        ->withMutant($mutant, Ranking::of(Kills::of(TestId::of('default'), 9)))
        ->withMutant($other, Ranking::of(Kills::of(TestId::of('default'), 1)))
        ->withFunction($add, Ranking::of(Kills::of(TestId::of('default'), 9)))
        ->withFunction($cart, Ranking::of(Kills::of(TestId::of('default'), 1)));
    $read = $mine->and($default);

    expect(historyNames($read->likelyKillers($mutant, $add)))->toBe(['mine'])
        ->and(historyNames($read->likelyKillers($other, $add)))->toBe(['default'])
        ->and(historyMutants($read))->toBe([$other->value(), $mutant->value()])
        ->and(historyFunctions($read))->toBe(['total', 'add'])
        ->and(historyNames([...$read->functions()][1]->ranking()->tests()))->toBe(['mine']);
});

it('keeps the newest so many of the mutants held and of the functions, and the functions of files that still exist', function () use ($add): void {
    $mutants = array_map(
        static fn(string $diff): Mutant => historyMutant(sprintf("-x\n+%s", $diff), MutantStatus::Killed),
        ['a', 'b', 'c'],
    );
    $cart = Enclosing::named(Path::of('src/Cart.php'), 'total');
    $ranking = Ranking::of(Kills::of(TestId::of('t'), 1));
    $history = KillHistory::none()
        ->withMutant($mutants[0]->id(), $ranking)
        ->withMutant($mutants[1]->id(), $ranking)
        ->withMutant($mutants[2]->id(), $ranking)
        ->withFunction($add, $ranking)
        ->withFunction($cart, $ranking);
    $kept = $history->keeping(MutantIds::of(...array_map(static fn(Mutant $mutant): MutantId => $mutant->id(), $mutants)), Bound::atMost(2), Bound::atMost(1));

    expect(historyMutants($history->keeping(MutantIds::of($mutants[0]->id()), Bound::atMost(5), Bound::atMost(5))))
        ->toBe([$mutants[0]->id()->value()])
        ->and(historyMutants($kept))->toBe([$mutants[1]->id()->value(), $mutants[2]->id()->value()])
        ->and(historyFunctions($kept))->toBe(['total'])
        ->and(historyFunctions($history->onlyIn(Paths::of(Path::of('src/Money.php')))))->toBe(['add']);
});

it('keeps a mutant whose id reads as a number under that id, so learning of it again changes it in place', function (): void {
    $numeric = historyId('123456789012');
    $other = historyId('abcdefabcdef');
    $ranking = Ranking::of(Kills::of(TestId::of('MoneyTest::adds'), 1));
    $kept = KillHistory::none()->withMutant($numeric, $ranking)->withMutant($other, $ranking)
        ->keeping(MutantIds::of($numeric, $other), Bound::atMost(5), Bound::atMost(5));

    expect(historyMutants($kept->withMutant($numeric, $ranking)))->toBe(['abcdefabcdef', '123456789012']);
});

it('learns from a run in time linear in its mutants and in what the history already holds', function () use ($add): void {
    $learned = static function (int $size) use ($add): Closure {
        $lessons = array_map(
            static fn(int $at): Lesson => Lesson::of(historyMutant(sprintf("-a\n+%d", $at), MutantStatus::Killed, 'MoneyTest::adds'), $add),
            range(1, $size),
        );
        $held = KillHistory::none()->learnedFrom(...array_map(
            static fn(int $at): Lesson => Lesson::of(historyMutant(sprintf("-b\n+%d", $at), MutantStatus::Killed, 'MoneyTest::adds'), $add),
            range(1, $size),
        ));

        return static fn(): KillHistory => $held->learnedFrom(...$lessons);
    };

    expect(iterator_count($learned(10)()->mutants()))->toBe(20)
        ->and(Growth::of(2500, $learned))->toBeLessThan(Growth::LINEAR);
});

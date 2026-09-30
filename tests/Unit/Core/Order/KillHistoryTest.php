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
use NightWorksIO\MutationGate\Core\Order\RankedFunction;
use NightWorksIO\MutationGate\Core\Order\RankedMutant;
use NightWorksIO\MutationGate\Core\Order\Ranking;
use NightWorksIO\MutationGate\Core\Php\Nameless;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

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
        ->learnedFrom($one, $add)
        ->learnedFrom($one, $add)
        ->learnedFrom($two, $add)
        ->learnedFrom(historyMutant('-+ three', MutantStatus::Killed, 'CartTest::totals'), Nameless::code());

    expect(historyNames($history->likelyKillers($one->id(), $add)))->toBe(['MoneyTest::adds'])
        ->and(historyNames($history->likelyKillers($two->id(), Nameless::code())))->toBe(['CartTest::totals'])
        ->and(historyNames($history->likelyKillers(historyMutant('-+ new', MutantStatus::Killed)->id(), $add)))
        ->toBe(['MoneyTest::adds', 'CartTest::totals'])
        ->and(historyNames($history->likelyKillers(historyMutant('-+ new', MutantStatus::Killed)->id(), Nameless::code())))
        ->toBe([])
        ->and(historyMutants($history))->toBe([$one->id()->value(), $two->id()->value(), historyMutant('-+ three', MutantStatus::Killed)->id()->value()]);
});

it('learns nothing from a mutant that survived, or was killed by a test nobody knows', function () use ($add): void {
    $history = KillHistory::none()
        ->learnedFrom(historyMutant('-+ one', MutantStatus::Survived, 'MoneyTest::adds'), $add)
        ->learnedFrom(historyMutant('-+ two', MutantStatus::Killed), $add);

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

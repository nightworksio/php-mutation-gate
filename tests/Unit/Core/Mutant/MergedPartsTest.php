<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\MergedParts;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/** The unit every part here judges. */
function mergedUnit(): Path
{
    return Path::of('src/Money.php');
}

/** The mutant on a line of src/Money.php, with this status, in one part. */
function mergedPart(int $line, MutantStatus $status, string $native = ''): Mutant
{
    return Mutant::of(
        MutantId::hash(mergedUnit(), 'Minus', '- a + b', $line),
        $native === '' ? sprintf('native-%d', $line) : $native,
        Location::of(mergedUnit(), Line::of($line), Line::of($line)),
        Mutation::of('Minus', MutatorFamily::Arithmetic, '- a + b'),
        $status,
        Unmeasured::duration(),
    );
}

/**
 * Each mutant as its line and status.
 *
 * @return list<array{int, string}>|string
 */
function mergedOutcomes(Mutants|CannotJudge $merged): array|string
{
    return $merged instanceof CannotJudge ? $merged->why() : array_map(
        static fn(Mutant $mutant): array => [$mutant->location()->start()->number(), $mutant->status()->value],
        iterator_to_array($merged, preserve_keys: false),
    );
}

it('takes the status that stands among the parts\' outcomes of each mutant', function (MutantStatus $standing, MutantStatus ...$parts): void {
    $outcomes = [];

    foreach ($parts as $status) {
        $outcomes[] = Mutants::of(mergedPart(7, $status));
    }

    $merged = MergedParts::of(mergedUnit(), ...$outcomes);

    expect(mergedOutcomes($merged))->toBe([[7, $standing->value]]);
})->with([
    'a kill in any part' => [MutantStatus::Killed, MutantStatus::Survived, MutantStatus::NotReached, MutantStatus::Killed],
    'a kill over a timeout' => [MutantStatus::Killed, MutantStatus::TimedOut, MutantStatus::Killed],
    'a kill by a test over one by static analysis' => [MutantStatus::Killed, MutantStatus::KilledByStaticAnalysis, MutantStatus::Killed],
    'a timeout where no part killed' => [MutantStatus::TimedOut, MutantStatus::Survived, MutantStatus::TimedOut],
    'its owner\'s own run over a survivor elsewhere' => [MutantStatus::FailsUnmutated, MutantStatus::FailsUnmutated, MutantStatus::Survived],
    'an unjudged part over a survivor elsewhere' => [MutantStatus::Unjudged, MutantStatus::Survived, MutantStatus::Unjudged],
    'a survivor of every part that reached it' => [MutantStatus::Survived, MutantStatus::Survived, MutantStatus::NotReached, MutantStatus::Survived],
    'a status every part gives alike' => [MutantStatus::Uncovered, MutantStatus::Uncovered, MutantStatus::Uncovered],
    'a marker every part gives alike' => [MutantStatus::IgnoredByMarker, MutantStatus::IgnoredByMarker, MutantStatus::IgnoredByMarker],
]);

it('keeps the record of the first part, in their order, that gives the standing status', function (): void {
    $killed = static fn(string $test): Mutant => mergedPart(7, MutantStatus::Killed, $test)
        ->killedBy(TestIds::of(TestId::of($test)));
    $merged = MergedParts::of(
        mergedUnit(),
        Mutants::of(mergedPart(7, MutantStatus::Survived)),
        Mutants::of($killed('MoneyTest::adds')),
        Mutants::of($killed('MoneyTest::subtracts')),
    );

    expect($merged instanceof Mutants ? iterator_to_array($merged, preserve_keys: false)[0]->nativeId() : $merged)
        ->toBe('MoneyTest::adds');
});

it('keeps the mutants in the order the first part holds them', function (): void {
    $merged = MergedParts::of(
        mergedUnit(),
        Mutants::of(mergedPart(9, MutantStatus::Survived), mergedPart(7, MutantStatus::NotReached)),
        Mutants::of(mergedPart(7, MutantStatus::Killed), mergedPart(9, MutantStatus::NotReached)),
    );

    expect(mergedOutcomes($merged))->toBe([[9, 'survived'], [7, 'killed']]);
});

it('leaves one part\'s mutants as they are, and none as none', function (): void {
    $one = Mutants::of(mergedPart(7, MutantStatus::Survived, 'one'));

    expect(MergedParts::of(mergedUnit(), $one))->toBe($one)
        ->and(MergedParts::of(mergedUnit()))->toEqual(Mutants::none());
});

it('cannot judge a mutant no part reached', function (): void {
    $merged = MergedParts::of(
        mergedUnit(),
        Mutants::of(mergedPart(7, MutantStatus::NotReached)),
        Mutants::of(mergedPart(7, MutantStatus::NotReached)),
    );

    expect($merged)->toBeInstanceOf(CannotJudge::class)
        ->and(mergedOutcomes($merged))->toContain('No shard that judges src/Money.php by a part of the test files reached its mutant');
});

it('cannot judge parts that hold other mutants than each other', function (Mutants $other): void {
    $merged = MergedParts::of(mergedUnit(), Mutants::of(mergedPart(7, MutantStatus::Survived)), $other);

    expect(mergedOutcomes($merged))->toStartWith('The shards that judge src/Money.php by parts of the test files hold other mutants of it');
})->with([
    'another mutant' => [fn(): Mutants => Mutants::of(mergedPart(8, MutantStatus::Survived))],
    'one more' => [fn(): Mutants => Mutants::of(mergedPart(7, MutantStatus::Survived), mergedPart(8, MutantStatus::Survived))],
    'none' => [fn(): Mutants => Mutants::none()],
]);

it('holds every status but a shard\'s not-reached outcome as one a mutant\'s final record holds, in the enum\'s order', function (): void {
    expect(MergedParts::statuses())->not->toContain(MutantStatus::NotReached)
        ->and(MergedParts::statuses())->toHaveCount(count(MutantStatus::cases()) - 1)
        ->and(MergedParts::statuses()[0])->toBe(MutantStatus::cases()[0]);
});

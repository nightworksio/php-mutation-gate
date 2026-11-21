<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\LineSpan;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\MadeFile;
use NightWorksIO\MutationGate\Core\Mutant\MadeLines;
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

/** The mutant with this id on a line of src/Money.php, with this status, in one part. */
function mergedPartAs(string $id, int $line, MutantStatus $status): Mutant
{
    $parsed = MutantId::parse($id);

    return Mutant::of(
        $parsed instanceof MutantId ? $parsed : throw new RuntimeException($parsed->why()),
        sprintf('native-%d', $line),
        Location::of(mergedUnit(), Line::of($line), Line::of($line)),
        Mutation::of('Minus', MutatorFamily::Arithmetic, '- a + b'),
        $status,
        Unmeasured::duration(),
    );
}

/**
 * A part's made lines: these spans of src/Money.php, each a first and last line, of a file of this many lines.
 *
 * @param list<array{int, int}> $spans
 */
function mergedMade(int $last, array $spans): MadeLines
{
    return mergedMadeIn(mergedUnit(), $last, $spans);
}

/**
 * A part's made lines: these spans of a file, each a first and last line, of a file of this many lines.
 *
 * @param list<array{int, int}> $spans
 */
function mergedMadeIn(Path $file, int $last, array $spans): MadeLines
{
    return MadeLines::whole()->with(MadeFile::of(
        $file,
        Line::of($last),
        ...array_map(static fn(array $span): LineSpan => LineSpan::of(Line::of($span[0]), Line::of($span[1])), $spans),
    ));
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

it('merges parts that each made the mutants of some lines of a file alone, where each holds every mutant touching a line it made and together they made every line', function (): void {
    $first = mergedMade(20, [[1, 10]]);
    $second = mergedMade(20, [[8, 20]]);

    $merged = MergedParts::made(mergedUnit(), [
        [Mutants::of(mergedPart(5, MutantStatus::Killed), mergedPart(8, MutantStatus::Survived), mergedPart(10, MutantStatus::NotReached)), $first],
        [Mutants::of(mergedPart(8, MutantStatus::Survived), mergedPart(10, MutantStatus::Survived), mergedPart(15, MutantStatus::Survived)), $second],
    ]);

    expect(mergedOutcomes($merged))->toEqualCanonicalizing([[5, 'killed'], [8, 'survived'], [10, 'survived'], [15, 'survived']]);
});

it('cannot merge parts made on some lines alone where a part lacks a mutant touching a line it made, they left a line unmade, count a file\'s lines apart, or one part alone made some', function (Closure $parts, string $why): void {
    /** @var list<array{Mutants, MadeLines}> $made */
    $made = $parts();
    $merged = MergedParts::made(mergedUnit(), $made);

    expect($merged instanceof CannotJudge ? $merged->why() : 'merged')->toContain($why);
})->with([
    'a mutant on a line it made, lacking' => [static fn(): array => [
        [Mutants::of(mergedPart(5, MutantStatus::Killed)), mergedMade(20, [[1, 10]])],
        [Mutants::of(mergedPart(9, MutantStatus::Survived), mergedPart(15, MutantStatus::Survived)), mergedMade(20, [[8, 20]])],
    ], 'hold other mutants'],
    'a line no part made' => [static fn(): array => [
        [Mutants::of(mergedPart(5, MutantStatus::Killed)), mergedMade(20, [[1, 10]])],
        [Mutants::of(mergedPart(15, MutantStatus::Survived)), mergedMade(20, [[12, 20]])],
    ], 'made the mutants of every line of src/Money.php'],
    'the last line no part made' => [static fn(): array => [
        [Mutants::of(mergedPart(5, MutantStatus::Killed)), mergedMade(20, [[1, 10]])],
        [Mutants::of(mergedPart(15, MutantStatus::Survived)), mergedMade(20, [[11, 19]])],
    ], 'made the mutants of every line of src/Money.php'],
    'lines counted apart' => [static fn(): array => [
        [Mutants::of(mergedPart(5, MutantStatus::Killed)), mergedMade(20, [[1, 10]])],
        [Mutants::of(mergedPart(15, MutantStatus::Survived)), mergedMade(21, [[11, 21]])],
    ], 'count the lines of src/Money.php apart'],
    'one part alone, made on some lines' => [static fn(): array => [
        [Mutants::of(mergedPart(5, MutantStatus::Killed)), mergedMade(20, [[1, 10]])],
    ], 'made the mutants of every line of src/Money.php'],
]);

it('reads a part that made a file whole as making every mutant of it, and a file outside the unit as none of its business', function (): void {
    $elsewhere = mergedMadeIn(Path::of('src/Tax.php'), 9, [[1, 2]]);

    $merged = MergedParts::made(mergedUnit(), [
        [Mutants::of(mergedPart(5, MutantStatus::Killed), mergedPart(15, MutantStatus::NotReached)), $elsewhere],
        [Mutants::of(mergedPart(15, MutantStatus::Survived)), mergedMade(20, [[11, 20]])->and($elsewhere)],
    ]);

    expect(mergedOutcomes($merged))->toEqualCanonicalizing([[5, 'killed'], [15, 'survived']]);
});

it('merges parts that hold the same mutants in any order, whatever their ids read as numbers', function (int $seed): void {
    $ids = [
        '0393fbdb7276', '27848a53dd70', 'e99a9631fbd0', '105436102654', '06270e550940', '097b2ebee2d4',
        '000000000123', '1e0000000001', '0ef606e40458', '3293443e2dd6', '0a1b2c3d4e5f', '9e9999999999',
    ];
    $first = [];
    $second = [];

    foreach ($ids as $at => $id) {
        $first = $at < 8 ? [...$first, mergedPartAs($id, $at + 1, MutantStatus::Killed)] : $first;
        $second = $at >= 5 ? [...$second, mergedPartAs($id, $at + 1, MutantStatus::Survived)] : $second;
    }

    mt_srand($seed);
    shuffle($first);
    shuffle($second);

    $merged = MergedParts::made(mergedUnit(), [
        [Mutants::of(...$first), mergedMade(12, [[1, 8]])],
        [Mutants::of(...$second), mergedMade(12, [[6, 12]])],
    ]);

    expect(mergedOutcomes($merged))->toHaveCount(12);
})->with(range(1, 40));

it('merges parts made on some lines alone of a file whose path reads as a number', function (): void {
    $file = Path::of('2026');
    $mutant = static fn(int $line, MutantStatus $status): Mutant => Mutant::of(
        MutantId::hash($file, 'Minus', '- a + b', $line),
        sprintf('native-%d', $line),
        Location::of($file, Line::of($line), Line::of($line)),
        Mutation::of('Minus', MutatorFamily::Arithmetic, '- a + b'),
        $status,
        Unmeasured::duration(),
    );

    $merged = MergedParts::made($file, [
        [Mutants::of($mutant(2, MutantStatus::Killed)), mergedMadeIn($file, 6, [[1, 3]])],
        [Mutants::of($mutant(5, MutantStatus::Survived)), mergedMadeIn($file, 6, [[4, 6]])],
    ]);

    expect(mergedOutcomes($merged))->toBe([[2, 'killed'], [5, 'survived']]);
});

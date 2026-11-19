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

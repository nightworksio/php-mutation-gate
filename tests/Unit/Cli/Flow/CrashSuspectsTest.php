<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\CrashSuspects;
use NightWorksIO\MutationGate\Cli\Flow\Stopwatch;
use NightWorksIO\MutationGate\Cli\Flow\TimeLeft;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Cost\StepTime;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Time\Deadline;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Tests\Support\CallLimit;
use NightWorksIO\MutationGate\Tests\Support\StoppedClock;

/** A mutant of `src/Money.php` by its native id, as a run reported it. */
function suspect(string $nativeId, MutantStatus $status = MutantStatus::Unjudged): Mutant
{
    return Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'Plus', sprintf("-a\n+%s", $nativeId), 0),
        $nativeId,
        Location::of(Path::of('src/Money.php'), Line::of(3), Line::of(3)),
        Mutation::of('Plus', MutatorFamily::Arithmetic, ''),
        $status,
        Unmeasured::duration(),
    );
}

/** The mutants with these native ids, each left unjudged by a crashed run. */
function crashedSuspects(string ...$nativeIds): Mutants
{
    return Mutants::of(...array_map(
        static fn(string $nativeId): Mutant => suspect($nativeId)->unjudged(OutOfTime::Crashed),
        array_values($nativeIds),
    ));
}

/**
 * A run again that kills each mutant before the first of these killers, as
 * many as it tells of, and leaves the rest as its crash does; with what it
 * was asked to run, each time. It refuses to be asked more often than any
 * search through these few mutants takes, so a search that never ends fails.
 *
 * @param  list<string>                                                 $killers
 * @return array{Closure(Mutants): Mutants, ArrayObject<int, list<string>>}
 */
function crashingAt(array $killers, int $told = PHP_INT_MAX): array
{
    /** @var ArrayObject<int, list<string>> $asked */
    $asked = new ArrayObject();
    $limit = new CallLimit(32, 'The suspects were run again more often than a search through them takes.');

    return [static function (Mutants $mutants) use ($killers, $told, $asked, $limit): Mutants {
        $ids = array_map(static fn(Mutant $mutant): string => $mutant->nativeId(), [...$mutants]);
        $asked->append($ids);

        $limit->called();

        $at = array_find_key($ids, static fn(string $id): bool => in_array($id, $killers, strict: true));
        $judged = [];

        foreach ([...$mutants] as $place => $mutant) {
            $killed = $at === null || ($place < $at && $place < $told);
            $judged[] = $killed
                ? suspect($mutant->nativeId(), MutantStatus::Killed)
                : $mutant->unjudged(OutOfTime::Crashed);
        }

        return Mutants::of(...$judged);
    }, $asked];
}

/**
 * The suspects' step over this run again, a run again needing thirty seconds left to start, and its stopwatch.
 *
 * @param  Closure(Mutants): (Mutants|CannotJudge) $again
 * @return array{CrashSuspects, Stopwatch}
 */
function suspectsBy(Closure $again, Deadline|Unlimited $deadline): array
{
    $clock = new StoppedClock('2026-11-23T10:00:00+00:00');
    $stopwatch = new Stopwatch($clock, $clock->now());

    return [new CrashSuspects($again, new TimeLeft($deadline, $clock), Seconds::of(30.0), $stopwatch), $stopwatch];
}

/**
 * Each mutant's status, or what a budget or a crash left it before, by its native id.
 *
 * @return array<string, string>
 */
function settledAs(MutationResult $result): array
{
    $settled = [];

    foreach ($result->mutants() as $mutant) {
        $reason = $mutant->reason();
        $left = $reason instanceof Reason ? $reason->outOfTime() : $reason;
        $settled[$mutant->nativeId()] = $left instanceof OutOfTime ? $left->value : $mutant->status()->value;
    }

    return $settled;
}

it('leaves a result no crash left a mutant of as it is, and runs nothing again', function (): void {
    [$again, $asked] = crashingAt([]);
    [$suspects, $stopwatch] = suspectsBy($again, Unlimited::time());
    $result = MutationResult::skippingNone(Mutants::of(suspect('a', MutantStatus::Killed), suspect('b', MutantStatus::Survived)));

    expect($suspects->settled($result))->toBe($result)
        ->and([...$asked])->toBe([])
        ->and([...$stopwatch->steps()])->toBe([]);
});

it('runs the mutants a crash left again in one run, each judged as that run tells, and leaves every other as it was', function (): void {
    [$again, $asked] = crashingAt([]);
    [$suspects, $stopwatch] = suspectsBy($again, Unlimited::time());
    $result = MutationResult::skippingNone(crashedSuspects('a', 'b')->with(suspect('c', MutantStatus::Survived)));

    expect(settledAs($suspects->settled($result)))->toBe(['a' => 'killed', 'b' => 'killed', 'c' => 'survived'])
        ->and([...$asked])->toBe([['a', 'b']])
        ->and(array_map(static fn(StepTime $step): array => [$step->step(), $step->count()], [...$stopwatch->steps()]))
        ->toBe([[Step::Suspects, 2]]);
});

it('runs what a run again that crashed too left, until the mutant whose run alone crashes is found, and judges every other', function (): void {
    [$again, $asked] = crashingAt(['c']);
    [$suspects] = suspectsBy($again, Unlimited::time());

    expect(settledAs($suspects->settled(MutationResult::skippingNone(crashedSuspects('a', 'b', 'c', 'd', 'e')))))
        ->toBe(['a' => 'killed', 'b' => 'killed', 'c' => 'crashed-alone', 'd' => 'killed', 'e' => 'killed'])
        ->and([...$asked])->toBe([['a', 'b', 'c', 'd', 'e'], ['c', 'd', 'e'], ['c', 'd'], ['c'], ['d'], ['e']]);
});

it('splits the mutants in two halves where a run again that crashed told of none, and finds each mutant that crashes alone', function (): void {
    [$again, $asked] = crashingAt(['b', 'e'], told: 0);
    [$suspects] = suspectsBy($again, Unlimited::time());

    expect(settledAs($suspects->settled(MutationResult::skippingNone(crashedSuspects('a', 'b', 'c', 'd', 'e')))))
        ->toBe(['a' => 'killed', 'b' => 'crashed-alone', 'c' => 'killed', 'd' => 'killed', 'e' => 'crashed-alone'])
        ->and([...$asked])->toBe([
            ['a', 'b', 'c', 'd', 'e'],
            ['a', 'b', 'c'],
            ['a', 'b'],
            ['a'],
            ['b'],
            ['c'],
            ['d', 'e'],
            ['d'],
            ['e'],
        ]);
});

it('leaves the mutants as the crash left them where no time is left to run them again, or the run again cannot judge', function (Closure $making, Deadline|Unlimited $deadline): void {
    /** @var Closure(Mutants): (Mutants|CannotJudge) $again */
    $again = $making();
    [$suspects] = suspectsBy($again, $deadline);

    expect(settledAs($suspects->settled(MutationResult::skippingNone(crashedSuspects('a', 'b')))))
        ->toBe(['a' => 'crashed', 'b' => 'crashed']);
})->with([
    'no time left, though a run again would kill them' => [
        fn(): Closure => crashingAt([])[0],
        fn(): Deadline => Deadline::after(new DateTimeImmutable('2026-11-23T10:00:00+00:00'), Seconds::of(10.0)),
    ],
    'a run again that cannot judge' => [
        fn(): Closure => static fn(): CannotJudge => CannotJudge::because('Pest did not start.'),
        fn(): Unlimited => Unlimited::time(),
    ],
]);

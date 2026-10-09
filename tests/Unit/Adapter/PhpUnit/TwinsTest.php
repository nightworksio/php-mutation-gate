<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Twins;
use NightWorksIO\MutationGate\Core\Analysis\Finding;
use NightWorksIO\MutationGate\Core\Analysis\Rejection;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Evidence;
use NightWorksIO\MutationGate\Core\Mutant\Evidences;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\Prefix;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Mutator\Engine\MadeMutant;

/** A mutant the engine made of a file with a mutator, leaving the file as this text. */
function twinMade(string $file, string $mutator, string $mutated): MadeMutant
{
    $path = Path::of($file);

    return MadeMutant::of(
        MutantId::hash($path, $mutator, sprintf('-%s', $mutated), 0),
        Location::of($path, Line::of(3), Line::of(3)),
        Mutation::of($mutator, MutatorFamily::Condition, sprintf('-%s', $mutated)),
        Contents::of($mutated),
    );
}

/** The mutant a run judged of a made one, with this status. */
function twinJudged(MadeMutant $made, MutantStatus $status): Mutant
{
    return Mutant::of($made->id(), $made->id()->value(), $made->location(), $made->mutation(), $status, Seconds::of(2.0));
}

it('runs the first of the mutants that leave one file alike, and no other', function (): void {
    $first = twinMade('src/A.php', 'Less', '<?php a <= b;');
    $twin = twinMade('src/A.php', 'Greater', '<?php a <= b;');
    $other = twinMade('src/A.php', 'Plus', '<?php a - b;');
    $elsewhere = twinMade('src/B.php', 'Less', '<?php a <= b;');

    expect(Twins::of([$first, $twin, $other, $elsewhere])->first())->toBe([$first, $other, $elsewhere]);
});

it('gives each twin its first\'s verdict, killers, limit and reason, run in no time, right after it', function (): void {
    $first = twinMade('src/A.php', 'Less', '<?php a <= b;');
    $twin = twinMade('src/A.php', 'Greater', '<?php a <= b;');
    $other = twinMade('src/A.php', 'Plus', '<?php a - b;');
    $killers = TestIds::of(TestId::of('ATest::fits'));
    $judged = twinJudged($first, MutantStatus::Killed)->killedBy($killers)->withLimit(Seconds::of(4.0))
        ->because(Reason::that('it ran'));
    $twins = Twins::of([$first, $twin, $other]);

    expect($twins->joined([$judged, twinJudged($other, MutantStatus::Survived)]))->toEqual([
        $judged,
        Mutant::of($twin->id(), $twin->id()->value(), $twin->location(), $twin->mutation(), MutantStatus::Killed, Seconds::of(0.0))
            ->killedBy($killers)
            ->withLimit(Seconds::of(4.0))
            ->because(Reason::that('it ran')),
        twinJudged($other, MutantStatus::Survived),
    ])->and($twins->joined([twinJudged($other, MutantStatus::Survived)]))->toEqual([twinJudged($other, MutantStatus::Survived)]);
});

it('gives each twin its first\'s evidence', function (): void {
    $first = twinMade('src/A.php', 'Less', '<?php a <= b;');
    $twin = twinMade('src/A.php', 'Greater', '<?php a <= b;');
    $evidence = Evidence::none()->withPrefix(Prefix::at(2));
    $twinned = Twins::of([$first, $twin])->evidence(Evidences::none()->with($first->id(), $evidence));

    expect($twinned->of($twin->id()))->toEqual($evidence)
        ->and($twinned->of($first->id()))->toEqual($evidence)
        ->and(count($twinned))->toBe(2);
});

it('keeps its first\'s rejection and unmutated need, and leaves a twin no limit where its first has none', function (): void {
    $first = twinMade('src/A.php', 'Less', '<?php a <= b;');
    $twin = twinMade('src/A.php', 'Greater', '<?php a <= b;');
    $rejection = Rejection::by('phpstan', Finding::error(Path::of('src/A.php'), 'argument.type', 'No.'));
    $judged = twinJudged($first, MutantStatus::Survived)->rejected($rejection)->withUnmutatedNeed(Seconds::of(1.5));
    [, $copy] = Twins::of([$first, $twin])->joined([$judged]);

    expect($copy->status())->toBe(MutantStatus::KilledByStaticAnalysis)
        ->and($copy->reason())->toEqual($rejection)
        ->and($copy->unmutatedNeed())->toEqual(Seconds::of(1.5))
        ->and($copy->limit())->toEqual(Unmeasured::duration());
});

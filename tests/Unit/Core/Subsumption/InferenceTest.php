<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Subsumption\Inference;
use NightWorksIO\MutationGate\Core\Subsumption\Subsumption;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;
use NightWorksIO\MutationGate\Tests\Support\Subsumed;

/** The unit results of `src/Money.php` holding these mutants. */
function inferenceResults(Mutant ...$mutants): UnitResults
{
    return UnitResults::of(UnitResult::of(Unit::file(Path::of('src/Money.php')), Origin::Run, Mutants::of(...$mutants)));
}

/**
 * The reason each mutant of the results gives, by its id's value.
 *
 * @return array<string, string>
 */
function inferenceReasons(UnitResults $results): array
{
    $reasons = [];

    foreach ($results as $result) {
        foreach ($result->mutants() as $mutant) {
            $reason = $mutant->reason();
            $reasons[$mutant->id()->value()] = $reason instanceof Reason ? $reason->text() : '';
        }
    }

    return $reasons;
}

it('says the kill of a mutant the budget left unrun is inferred where a killed mutant subsumes it, and leaves it unjudged', function (): void {
    $loosened = Subsumed::killed(Subsumed::diff('return $a < $b;', 'return $a <= $b;'));
    $negated = Subsumed::killed(Subsumed::diff('return $a < $b;', 'return $a >= $b;'))->unjudged(OutOfTime::BeforeMutating);

    $left = Inference::in(inferenceResults($negated), Subsumption::none(), inferenceResults($loosened));
    $mutant = [...[...$left][0]->mutants()][0];

    expect(inferenceReasons($left))->toBe([$negated->id()->value() => sprintf(
        'Inferred killed: %s subsumes it and was killed, which proves nothing of it. %s',
        $loosened->id()->value(),
        OutOfTime::BeforeMutating->reason()->text(),
    )])
        ->and($mutant->status())->toBe(MutantStatus::Unjudged)
        ->and(OutOfTime::left($mutant))->toBeTrue();
});

it('infers by what a run learned of the file, and from a subsumer left beside it', function (): void {
    $by = Subsumed::killed(Subsumed::diff('a', 'b'), 'T1');
    $of = Subsumed::killed(Subsumed::diff('a', 'c'), 'T1', 'T2');
    $also = Subsumed::killed(Subsumed::diff('a', 'd'), 'T1', 'T3');
    $left = [$of->unjudged(OutOfTime::BeforeMutating), $also->unjudged(OutOfTime::BeforeMutating)];

    $inferred = Inference::in(inferenceResults(...$left), Subsumption::learned($by, $of, $also), inferenceResults($by));
    $cut = Inference::in(
        inferenceResults($of->unjudged(OutOfTime::BeforeRunning)),
        Subsumption::learned($by, $of),
        inferenceResults($by),
    );

    expect(inferenceReasons($inferred)[$of->id()->value()])->toStartWith(sprintf('Inferred killed: %s ', $by->id()->value()))
        ->and(inferenceReasons($inferred)[$also->id()->value()])->toStartWith(sprintf('Inferred killed: %s ', $by->id()->value()))
        ->and(inferenceReasons($cut)[$of->id()->value()])->toStartWith(sprintf('Inferred killed: %s ', $by->id()->value()));
});

it('infers nothing of a mutant that ran, one whose subsumer was not killed, or one nothing subsumes', function (): void {
    $loosened = Subsumed::killed(Subsumed::diff('return $a < $b;', 'return $a <= $b;'));
    $negated = Subsumed::killed(Subsumed::diff('return $a < $b;', 'return $a >= $b;'));
    $unconfirmed = $negated->unjudged(OutOfTime::BeforeConfirming);
    $unrun = $negated->unjudged(OutOfTime::BeforeMutating);
    $survived = Subsumed::mutant('src/Money.php', 1, Subsumed::diff('return $a < $b;', 'return $a <= $b;'), MutantStatus::Survived);
    $alone = Subsumed::mutant('src/Money.php', 7, Subsumed::diff('x', 'y'))->unjudged(OutOfTime::BeforeMutating);
    $reason = static fn(Mutant $mutant): string => inferenceReasons(inferenceResults($mutant))[$mutant->id()->value()];
    $unreported = $negated->unjudged(Reason::that('Pest could not select its test.'));

    expect(inferenceReasons(Inference::in(inferenceResults($unconfirmed), Subsumption::none(), inferenceResults($loosened))))
        ->toBe([$negated->id()->value() => $reason($unconfirmed)])
        ->and(inferenceReasons(Inference::in(inferenceResults($unrun), Subsumption::none(), inferenceResults($survived))))
        ->toBe([$negated->id()->value() => $reason($unrun)])
        ->and(inferenceReasons(Inference::in(inferenceResults($alone), Subsumption::none(), inferenceResults($loosened))))
        ->toBe([$alone->id()->value() => $reason($alone)])
        ->and(inferenceReasons(Inference::in(inferenceResults($unreported), Subsumption::none(), inferenceResults($loosened))))
        ->toBe([$negated->id()->value() => 'Pest could not select its test.'])
        ->and(inferenceReasons(Inference::in(inferenceResults($loosened), Subsumption::none(), inferenceResults($loosened))))
        ->toBe([$loosened->id()->value() => ''])
        ->and(count(Inference::in(UnitResults::none(), Subsumption::none(), inferenceResults($loosened))))->toBe(0);
});

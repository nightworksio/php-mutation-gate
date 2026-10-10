<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Ended;
use NightWorksIO\MutationGate\Core\Mutant\Evidence;
use NightWorksIO\MutationGate\Core\Mutant\Evidences;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Mutant\Unevidenced;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/** A mutant of src/Money.php on its own line, of this status. */
$mutant = static fn(int $line, MutantStatus $status): Mutant => Mutant::of(
    MutantId::hash(Path::of('src/Money.php'), 'Plus', sprintf('-%d', $line), 0),
    sprintf('%d', $line),
    Location::of(Path::of('src/Money.php'), Line::of($line), Line::of($line)),
    Mutation::of('Plus', MutatorFamily::Arithmetic, sprintf('-%d', $line)),
    $status,
    Seconds::of(0.1),
);

/** The one mutant's status and reason, as the evidence judges it. */
$judged = static function (Mutant $mutant, Evidence $evidence): array {
    $judged = [...Unevidenced::judged(Mutants::of($mutant), Evidences::none()->with($mutant->id(), $evidence))][0];
    $reason = $judged->reason();

    return [$judged->status(), $reason instanceof Reason ? $reason->text() : null];
};

it('leaves a kill no test is named for unjudged, saying how its process ended', function () use ($mutant, $judged): void {
    $ended = Ended::of(1, signalled: false, printed: "PHPUnit 13.3.4\nsomething went wrong\n")->withFatal(fatal: false);

    expect($judged($mutant(10, MutantStatus::Killed), Evidence::none()->withEnded($ended)))->toBe([
        MutantStatus::Unjudged,
        "No test is named as its killer, and no signal or fatal error PHP recorded ended its process: it exited with code 1. Its output ended:\nPHPUnit 13.3.4\nsomething went wrong\n",
    ]);
});

it('says where the runner read no exit code and kept none of the output', function (Evidence $evidence) use ($mutant, $judged): void {
    expect($judged($mutant(10, MutantStatus::Killed), $evidence))->toBe([
        MutantStatus::Unjudged,
        'No test is named as its killer, and no signal or fatal error PHP recorded ended its process: the runner read no exit code. None of its output is kept.',
    ]);
})->with([
    'no evidence' => [fn(): Evidence => Evidence::none()],
    'an ending with nothing known' => [fn(): Evidence => Evidence::none()->withEnded(Ended::unprinted(NotGiven::value(), NotGiven::value()))],
    'an ending that printed nothing' => [fn(): Evidence => Evidence::none()->withEnded(Ended::of(NotGiven::value(), NotGiven::value(), ''))],
]);

it('lets a kill stand that a test is named for, or that a signal or a fatal error PHP recorded ended', function (Mutant $killed, Evidence $evidence) use ($judged): void {
    expect($judged($killed, $evidence))->toBe([MutantStatus::Killed, null]);
})->with([
    'a killer named' => [
        fn(): Mutant => $mutant(10, MutantStatus::Killed)->killedBy(TestIds::of(TestId::of('Tests\MoneyTest::adds'))),
        fn(): Evidence => Evidence::none(),
    ],
    'ended by a signal' => [fn(): Mutant => $mutant(10, MutantStatus::Killed), fn(): Evidence => Evidence::none()->withEnded(Ended::unprinted(137, signalled: true))],
    'ended by a fatal error' => [
        fn(): Mutant => $mutant(10, MutantStatus::Killed),
        fn(): Evidence => Evidence::none()->withEnded(Ended::unprinted(255, signalled: false)->withFatal(fatal: true)),
    ],
]);

it('leaves every result but a kill as it was', function (MutantStatus $status) use ($mutant): void {
    $one = $mutant(10, $status);

    expect([...Unevidenced::judged(Mutants::of($one), Evidences::none())][0])->toBe($one);
})->with([
    MutantStatus::Survived,
    MutantStatus::KilledByStaticAnalysis,
    MutantStatus::TimedOut,
    MutantStatus::OutOfMemory,
    MutantStatus::Errored,
    MutantStatus::Uncovered,
]);

it('judges each mutant by its own evidence, in the order given', function () use ($mutant): void {
    $first = $mutant(10, MutantStatus::Killed);
    $second = $mutant(11, MutantStatus::Killed);
    $evidence = Evidences::none()->with($second->id(), Evidence::none()->withEnded(Ended::unprinted(139, signalled: true)));

    expect(array_map(
        static fn(Mutant $judged): array => [$judged->location()->start()->number(), $judged->status()],
        [...Unevidenced::judged(Mutants::of($first, $second), $evidence)],
    ))->toBe([[10, MutantStatus::Unjudged], [11, MutantStatus::Killed]]);
});

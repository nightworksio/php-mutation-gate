<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Unexecutable\Outcome;
use NightWorksIO\MutationGate\Adapter\Pest\Unexecutable\PartedTrial;
use NightWorksIO\MutationGate\Adapter\Pest\Unexecutable\TrialRun;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Ended;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * The trials of a mutant of src/Money.php, one for each of these test files.
 *
 * @return non-empty-list<TrialRun>
 */
function partedTrials(string $first, string ...$more): array
{
    return array_values(array_map(
        static fn(string $file): TrialRun => TrialRun::of(
            Paths::of(Path::of($file)),
            Path::of('src/Money.php'),
            '/results/mutant.php',
            Seconds::of(5.0),
        ),
        [$first, ...$more],
    ));
}

/** What two parts find, one after the other: the second only where the first leaves it waiting. */
function partedTwice(Outcome $first, Outcome $second): Outcome|NotGiven
{
    $parted = PartedTrial::of(partedTrials('tests/ASpec.php', 'tests/BSpec.php'))->after($first);

    return ($parted->next() instanceof TrialRun ? $parted->after($second) : $parted)->outcome();
}

it('runs its parts one after another, is judged by the first that kills it or that it runs out of time in, and survives where each passes', function (): void {
    $parted = PartedTrial::of(partedTrials('tests/ASpec.php', 'tests/BSpec.php'));
    $killed = Outcome::killed(TestIds::of(TestId::of('P\Tests\BSpec::__pest_evaluable_it_b')), Ended::unprinted(1, signalled: false));

    expect([$parted->next(), $parted->after(Outcome::survived())->next(), $parted->outcome()])
        ->toEqual([partedTrials('tests/ASpec.php')[0], partedTrials('tests/BSpec.php')[0], NotGiven::value()])
        ->and(partedTwice(Outcome::survived(), $killed))->toEqual($killed)
        ->and(partedTwice(Outcome::timedOut(), $killed))->toEqual(Outcome::timedOut())
        ->and(PartedTrial::of(partedTrials('tests/ASpec.php'))->after($killed)->next())->toEqual(NotGiven::value())
        ->and(partedTwice(Outcome::survived(), Outcome::survived()->took(Seconds::of(2.0))))
        ->toEqual(Outcome::survived()->took(Seconds::of(2.0)));
});

it('is what its only part found, as one trial is', function (Outcome $found): void {
    expect(PartedTrial::of(partedTrials('tests/ASpec.php'))->after($found)->outcome())->toEqual($found);
})->with([
    'survived' => [fn(): Outcome => Outcome::survived()->took(Seconds::of(2.0))],
    'skipped' => [fn(): Outcome => Outcome::skipped()],
    'unjudged' => [fn(): Outcome => Outcome::unjudged('the selected tests fail on their own')],
]);

it('runs the next part where one cannot judge it, and is what the first that could not found, a failure on its own before a run out of time', function (): void {
    $fails = Outcome::unjudged('the selected tests fail on their own');
    $failsToo = Outcome::unjudged('never loaded');
    $slow = Outcome::skipped()->took(Seconds::of(5.0));
    $killed = Outcome::killed(TestIds::of(TestId::of('P\Tests\BSpec::__pest_evaluable_it_b')), Ended::unprinted(1, signalled: false));

    expect([
        partedTwice(Outcome::skipped(), Outcome::survived()),
        partedTwice($slow, Outcome::skipped()),
        partedTwice($slow, $fails),
        partedTwice($fails, Outcome::skipped()),
        partedTwice($fails, $failsToo),
        partedTwice(Outcome::skipped(), Outcome::timedOut()),
        partedTwice($fails, $killed),
    ])->toEqual([Outcome::skipped(), $slow, $fails, $fails, $fails, Outcome::timedOut(), $killed]);
});

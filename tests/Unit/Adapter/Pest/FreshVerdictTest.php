<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\FreshVerdict;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Ended;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Native\Guard;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/** A JUnit log of these Pest test cases of `tests/MoneySpec.php`. */
function freshLog(string $cases): string
{
    return sprintf('<?xml version="1.0"?><testsuites><testsuite name="T">%s</testsuite></testsuites>', $cases);
}

/** A Pest test case of `tests/MoneySpec.php` by its description, holding this element where it did not pass. */
function freshCase(string $description, string $element = ''): string
{
    return sprintf(
        '<testcase name="%1$s" file="tests/MoneySpec.php::%1$s" class="Tests\MoneySpec">%2$s</testcase>',
        $description,
        $element,
    );
}

/** The test `tests/MoneySpec.php` declares with this description, as the coverage map names it. */
function freshTest(string $description): TestId
{
    return TestId::of(sprintf('P\Tests\MoneySpec::__pest_evaluable_%s', str_replace(' ', '_', $description)));
}

/** A mutant of `src/Money.php` its own Pest run left unjudged as preloaded. */
function freshMutant(): Mutant
{
    return Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'PlusToMinus', '-+', 0),
        'PlusToMinus',
        Location::of(Path::of('src/Money.php'), Line::of(2), Line::of(2)),
        Mutation::of('PlusToMinus', MutatorFamily::Arithmetic, '-+'),
        MutantStatus::Unjudged,
        Unmeasured::duration(),
    )->because(Reason::preloaded(Path::of('src/Money.php')));
}

/**
 * The status, killers and reason of the mutant a fresh run that ended so
 * judges, `it adds` covering it, allowed 7 s under a 64 MB cap.
 *
 * @return array{MutantStatus, list<string>, string}
 */
function freshJudged(FreshVerdict $verdict, Ran $ran): array
{
    $mutant = $verdict->mutant(freshMutant(), $ran->duration(), Seconds::of(7.0), MemoryCap::of(64, MemoryUnit::Megabytes));
    $reason = $mutant->reason();

    return [
        $mutant->status(),
        array_map(static fn(TestId $test): string => $test->value(), [...$mutant->killers()]),
        $reason instanceof Reason ? $reason->text() : '',
    ];
}

/** What a fresh run that ended so says, `it adds` covering the mutant, under a 64 MB cap. */
function freshVerdictOf(Ran $ran, bool $served, string|false $log): FreshVerdict
{
    return FreshVerdict::of(
        $ran,
        Guard::of($served ? [Guard::SERVED] : []),
        $log,
        TestIds::of(freshTest('it adds')),
        MemoryCap::of(64, MemoryUnit::Megabytes),
    );
}

it('judges a fresh run by its JUnit log, its exit status and the guard', function (Ran $ran, bool $served, string|false $log, array $judged): void {
    $verdict = freshVerdictOf($ran, $served, $log);

    expect($verdict->needsControl())->toBeFalse()
        ->and(freshJudged($verdict, $ran))->toBe($judged);
})->with([
    'killed by the covering test that failed' => [
        fn(): Ran => Ran::finished(succeeded: false, output: 'FAILED'),
        true,
        fn(): string => freshLog(freshCase('it adds', '<failure>x</failure>') . freshCase('it rounds', '<failure>x</failure>')),
        [MutantStatus::Killed, ['P\Tests\MoneySpec::__pest_evaluable_it_adds'], ''],
    ],
    'killed by the covering test that errored' => [
        fn(): Ran => Ran::finished(succeeded: false, output: 'ERRORS'),
        true,
        fn(): string => freshLog(freshCase('it adds', '<error>x</error>')),
        [MutantStatus::Killed, ['P\Tests\MoneySpec::__pest_evaluable_it_adds'], ''],
    ],
    'survived where every covering test passed' => [
        fn(): Ran => Ran::finished(succeeded: true, output: ''),
        true,
        fn(): string => freshLog(freshCase('it adds')),
        [MutantStatus::Survived, [], ''],
    ],
    'unjudged where it passed with no test run' => [
        fn(): Ran => Ran::finished(succeeded: true, output: ''),
        true,
        fn(): string => freshLog(''),
        [MutantStatus::Unjudged, [], 'Its fresh Pest run passed with none of the tests that cover it run.'],
    ],
    'unjudged where it skipped every test' => [
        fn(): Ran => Ran::finished(succeeded: true, output: ''),
        true,
        fn(): string => freshLog(freshCase('it adds', '<skipped/>')),
        [MutantStatus::Unjudged, [], 'Its fresh Pest run skipped, or marked incomplete, every test that covers it.'],
    ],
    'unjudged where the guard says the mutant was never served, though a test failed' => [
        fn(): Ran => Ran::finished(succeeded: false, output: 'FAILED'),
        false,
        fn(): string => freshLog(freshCase('it adds', '<failure>x</failure>')),
        [MutantStatus::Unjudged, [], 'In its fresh Pest run the mutated file never ran in its place: the file was loaded some other way.'],
    ],
    'unjudged where the guard says the mutant was never served, though every test passed' => [
        fn(): Ran => Ran::finished(succeeded: true, output: ''),
        false,
        fn(): string => freshLog(freshCase('it adds')),
        [MutantStatus::Unjudged, [], 'In its fresh Pest run the mutated file never ran in its place: the file was loaded some other way.'],
    ],
    'timed out at its limit where it served the mutant' => [
        fn(): Ran => Ran::stopped(''),
        true,
        false,
        [MutantStatus::TimedOut, [], ''],
    ],
    'unjudged where it was stopped before it served the mutant' => [
        fn(): Ran => Ran::stopped(''),
        false,
        false,
        [MutantStatus::Unjudged, [], 'Its fresh Pest run was stopped at the limit, and the mutated file never ran in its place.'],
    ],
    'out of memory where it failed on exactly the cap' => [
        fn(): Ran => Ran::finished(succeeded: false, output: 'PHP Fatal error:  Allowed memory size of 67108864 bytes exhausted'),
        true,
        false,
        [MutantStatus::OutOfMemory, [], ''],
    ],
    'unjudged, with what Pest said, where it failed with tests run and none failing' => [
        fn(): Ran => Ran::finished(succeeded: false, output: " risky \n"),
        true,
        fn(): string => freshLog(freshCase('it adds')),
        [MutantStatus::Unjudged, [], "Its fresh Pest run failed, though no test failed. Pest said:\nrisky"],
    ],
    'unjudged, saying Pest said nothing, where it failed silently with tests run and none failing' => [
        fn(): Ran => Ran::finished(succeeded: false, output: ''),
        true,
        fn(): string => freshLog(freshCase('it adds')),
        [MutantStatus::Unjudged, [], 'Its fresh Pest run failed, though no test failed, and Pest said nothing.'],
    ],
]);

it('keeps the limit of a timeout and the cap of a run out of memory', function (): void {
    $limitOf = static fn(Ran $ran): Seconds|MemoryCap|Unmeasured => freshVerdictOf($ran, served: true, log: false)
        ->mutant(freshMutant(), $ran->duration(), Seconds::of(7.0), MemoryCap::of(64, MemoryUnit::Megabytes))
        ->limit();

    expect($limitOf(Ran::stopped('')))->toEqual(Seconds::of(7.0))
        ->and($limitOf(Ran::finished(succeeded: false, output: 'Allowed memory size of 67108864 bytes exhausted')))
        ->toEqual(MemoryCap::of(64, MemoryUnit::Megabytes));
});

it('asks for the same run unmutated where it failed before any test, or with only tests that do not cover it failing', function (string|false $log): void {
    $ran = Ran::exited(255, 'PHP Fatal error: boot');
    $verdict = freshVerdictOf($ran, served: true, log: $log);

    expect($verdict->needsControl())->toBeTrue()
        ->and(freshJudged($verdict, $ran)[0])->toBe(MutantStatus::Unjudged)
        ->and($verdict->evidence($ran)->isEmpty())->toBeTrue();
})->with([
    'no log' => [fn(): bool => false],
    'no test case' => [fn(): string => freshLog('')],
    'only an uncovering test failed' => [fn(): string => freshLog(freshCase('it rounds', '<failure>x</failure>'))],
]);

it('counts a kill no test is named for only once the same run unmutated passed, vouched for and ending as it ended', function (): void {
    $ran = Ran::exited(255, 'PHP Fatal error: boot');
    $verdict = freshVerdictOf($ran, served: true, log: false);
    $vouched = $verdict->controlledBy(Ran::finished(succeeded: true, output: ''));
    $evidence = $vouched->evidence($ran);

    expect($vouched->needsControl())->toBeFalse()
        ->and(freshJudged($vouched, $ran))->toBe([MutantStatus::Killed, [], ''])
        ->and($evidence->isVouched())->toBeTrue()
        ->and($evidence->ended())->toEqual(Ended::ofRun($ran))
        ->and(freshJudged($verdict->controlledBy(Ran::finished(succeeded: false, output: 'FAILED')), $ran))
        ->toBe([MutantStatus::Unjudged, [], 'Its fresh Pest run failed with no test named, and the same run with the file unmutated fails too.'])
        ->and(freshJudged($verdict->controlledBy(Ran::stopped('')), $ran)[0])->toBe(MutantStatus::Unjudged)
        ->and(freshJudged($verdict->controlledBy(NotGiven::value()), $ran))
        ->toBe([MutantStatus::Unjudged, [], 'Its fresh Pest run failed with no test named, and no time was left for the same run unmutated.']);
});

it('leaves a verdict that needs no control as it is, whatever the unmutated run did', function (): void {
    $ran = Ran::finished(succeeded: false, output: 'FAILED');
    $killed = freshVerdictOf($ran, served: true, log: freshLog(freshCase('it adds', '<failure>x</failure>')));

    expect($killed->controlledBy(Ran::finished(succeeded: false, output: '')))->toBe($killed)
        ->and($killed->evidence($ran)->isEmpty())->toBeTrue();
});

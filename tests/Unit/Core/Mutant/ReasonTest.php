<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Mutant\Unreported;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('holds the sentence a report prints beside the mutant', function (): void {
    expect(Reason::that('Pest cannot name LegacySpec::decrements in a filter.')->text())
        ->toBe('Pest cannot name LegacySpec::decrements in a filter.');
});

it('keeps a text that fits whole, and of a longer one its start and end, saying how many bytes it left out, never splitting a character', function (): void {
    $fits = str_repeat('a', Reason::MOST_BYTES);
    $long = sprintf('%s%s%s', str_repeat('é', Reason::MOST_BYTES), 'middle', str_repeat('ü', Reason::MOST_BYTES));
    $kept = Reason::that($long)->text();
    $said = preg_match('/\n\[(\d+) bytes left out\]\n/', $kept, $leftOut);
    [$start, $end] = explode($leftOut[0] ?? "\0", $kept, 2) + ['', ''];

    expect(Reason::that($fits)->text())->toBe($fits)
        ->and($said)->toBe(1)
        ->and(strlen($kept))->toBeLessThanOrEqual(Reason::MOST_BYTES)
        ->and(mb_check_encoding($kept, 'UTF-8'))->toBeTrue()
        ->and(str_starts_with($long, $start))->toBeTrue()
        ->and(str_ends_with($long, $end))->toBeTrue()
        ->and(strlen($start) + strlen($end) + (int) ($leftOut[1] ?? 0))->toBe(strlen($long))
        ->and(strlen($start))->toBeGreaterThan(Reason::MOST_BYTES / 2 - 64)
        ->and(strlen($end))->toBeGreaterThan(Reason::MOST_BYTES / 2 - 64)
        ->and(Reason::that($kept)->text())->toBe($kept);
});

it('records what a time budget ran out before where one did, and nothing where a runner gave the reason', function (): void {
    $budget = Reason::ranOutOf(OutOfTime::BeforeConfirming, 'The time budget ran out.');

    expect($budget->text())->toBe('The time budget ran out.')
        ->and($budget->outOfTime())->toBe(OutOfTime::BeforeConfirming)
        ->and(Reason::that('The time budget ran out.')->outOfTime())->toEqual(Unreported::reason());
});

it('tells the reason no test reaches a value from any other, by its words', function (): void {
    expect(Reason::that(Reason::UNREACHED)->isUnreached())->toBeTrue()
        ->and(Reason::that('the budget ran out')->isUnreached())->toBeFalse();
});

it('says a file was loaded before its mutant was in place, and tells that reason from any other, by its words', function (): void {
    $preloaded = Reason::preloaded(Path::of('src/Plugin.php'));

    expect($preloaded->text())->toBe('src/Plugin.php was loaded before the mutant was in place, so its tests ran the original code')
        ->and($preloaded->isPreloaded())->toBeTrue()
        ->and(Reason::that($preloaded->text())->isPreloaded())->toBeTrue()
        ->and(Reason::that(Reason::UNREACHED)->isPreloaded())->toBeFalse();
});

it('says no test of a mutant\'s run finished for its silence limit, so its run was stopped', function (): void {
    expect(Reason::silent(Seconds::of(8.0))->text())
        ->toBe('No test finished for 8s, its silence limit, so its run was stopped.')
        ->and(Reason::silent(Seconds::of(8.0))->outOfTime())->toEqual(Unreported::reason());
});

it('says which test of a mutant\'s run ran past its own limit, so its run was stopped', function (): void {
    expect(Reason::overran(TestId::of('MoneyTest::adds'), Seconds::of(3.0))->text())
        ->toBe('Its test MoneyTest::adds ran past 3s, its own limit, so its run was stopped.');
});

it('says first that a mutant\'s kill is inferred from the subsumer that was killed, keeping what its time ran out before', function (): void {
    $subsumer = MutantId::firstOf(Path::of('src/Money.php'), 'Pest/IncrementInteger', '-1 +2');
    $inferred = Reason::ranOutOf(OutOfTime::BeforeRunning, 'The time budget ran out.')->inferredFrom($subsumer);

    expect($inferred->text())
        ->toBe(sprintf('Inferred killed: %s subsumes it and was killed, which proves nothing of it. The time budget ran out.', $subsumer->value()))
        ->and($inferred->outOfTime())->toBe(OutOfTime::BeforeRunning);
});

it('says a run stopped early where no test finished for the silence limit, or one ran past its own, and keeps that inferred', function (): void {
    $subsumer = MutantId::firstOf(Path::of('src/Money.php'), 'Pest/IncrementInteger', '-1 +2');

    expect(Reason::silent(Seconds::of(2.0))->stoppedEarly())->toBeTrue()
        ->and(Reason::silent(Seconds::of(2.0))->inferredFrom($subsumer)->stoppedEarly())->toBeTrue()
        ->and(Reason::overran(TestId::of('MoneyTest::adds'), Seconds::of(3.0))->inferredFrom($subsumer)->stoppedEarly())->toBeTrue()
        ->and(Reason::that('Stopped at the most.')->stoppedEarly())->toBeFalse();
});

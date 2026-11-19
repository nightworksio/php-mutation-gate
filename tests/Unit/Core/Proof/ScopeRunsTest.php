<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Proof\Judged;
use NightWorksIO\MutationGate\Core\Proof\JudgingRun;
use NightWorksIO\MutationGate\Core\Proof\LastRun;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\RunProfile;
use NightWorksIO\MutationGate\Core\Proof\ScopeRuns;
use NightWorksIO\MutationGate\Tests\Support\JudgedCommits;
use NightWorksIO\MutationGate\Tests\Support\Moment;

/** @return list<string> why each record of these is not told, or the record's class where it is */
function scopeRunsTold(ScopeRuns $runs): array
{
    return array_map(
        static fn(object $record): string => $record instanceof CannotTell ? $record->why() : $record::class,
        [$runs->passed(), $runs->lastRun(), $runs->judged()],
    );
}

it('holds the newest commit that passed, the last run and the run judged, each replacing only its own, and no last run once cut short', function (): void {
    $passed = Passed::of(Revision::ref('5eeca8f'), 'mutation / verdict', 0);
    $newer = Passed::of(Revision::ref('6ffdb90'), 'mutation / verdict', 0);
    $lastRun = LastRun::of(JudgedCommits::of('5eeca8f'), 'mutation / verdict', RunProfile::standard());
    $judged = Judged::of(Revision::ref('5eeca8f'), 'mutation / verdict', Moment::at('2026-11-09T12:00:00Z'), Paths::none());
    $runs = ScopeRuns::none()->passing($passed)->lastRunAt($lastRun)->judging($judged);

    expect(scopeRunsTold(ScopeRuns::none()))->toBe([
        'No commit of this scope has passed yet.',
        'No run of this scope has judged every unit it considered yet.',
        'No run of this scope has recorded what it judged yet.',
    ])
        ->and([$runs->passed(), $runs->lastRun(), $runs->judged()])->toBe([$passed, $lastRun, $judged])
        ->and([$runs->passing($newer)->passed(), $runs->passing($newer)->lastRun(), $runs->passing($newer)->judged()])->toBe([$newer, $lastRun, $judged])
        ->and([$runs->cutShort()->passed(), $runs->cutShort()->judged()])->toBe([$passed, $judged])
        ->and(scopeRunsTold($runs->cutShort())[1])->toBe('No run of this scope has judged every unit it considered yet.');
});

it('keeps the passes that record their inputs, the newest first, each digest once, and none that records none', function (): void {
    $pass = static fn(string $commit, string $inputs): Passed => Passed::of(Revision::ref($commit), 'mutation / verdict', 0)
        ->judgedIn(JudgingRun::of(Digest::sha256Of($inputs), '', whole: false));
    $unread = Passed::of(Revision::ref('0a0a0a0'), 'mutation / verdict', 0);
    $runs = ScopeRuns::none()->passing($pass('1111111', 'first'))->passing($pass('2222222', 'second'))->passing($unread);
    $again = $runs->passing($pass('3333333', 'first'));
    $commits = static fn(ScopeRuns $runs): array => array_map(static fn(Passed $passed): string => $passed->commit()->name(), [...$runs->reusable()]);

    expect($commits($runs))->toBe(['2222222', '1111111'])
        ->and($runs->passed())->toBe($unread)
        ->and($commits($again))->toBe(['3333333', '2222222'])
        ->and($commits(ScopeRuns::none()->reusing($pass('1111111', 'first'))->reusing($pass('2222222', 'first'))->reusing($unread)))->toBe(['1111111'])
        ->and($commits($runs->lastRunAt(LastRun::of(JudgedCommits::of('5eeca8f'), 'mutation / verdict', RunProfile::standard()))->cutShort()))
        ->toBe(['2222222', '1111111']);
});

<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Proof\Judged;
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

<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Proof\Judged;
use NightWorksIO\MutationGate\Core\Proof\LastRun;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\RunProfile;
use NightWorksIO\MutationGate\Core\Proof\ScopeRuns;
use NightWorksIO\MutationGate\Tests\Support\JudgedCommits;
use NightWorksIO\MutationGate\Tests\Support\Moment;

it('tells as others do where each record is the same one, or none in both', function (): void {
    $passed = Passed::of(Revision::ref('5eeca8f'), 'mutation / verdict', 0);
    $lastRun = LastRun::of(JudgedCommits::of('5eeca8f'), 'mutation / verdict', RunProfile::standard());
    $judged = Judged::of(Revision::ref('5eeca8f'), 'mutation / verdict', Moment::at('2026-11-09T12:00:00Z'), Paths::none());
    $runs = ScopeRuns::none()->passing($passed)->lastRunAt($lastRun)->judging($judged);

    expect(ScopeRuns::none()->tellsAs(ScopeRuns::none()))->toBeTrue()
        ->and($runs->tellsAs(ScopeRuns::none()->passing($passed)->lastRunAt($lastRun)->judging($judged)))->toBeTrue()
        ->and($runs->cutShort()->tellsAs(ScopeRuns::none()->passing($passed)->judging($judged)))->toBeTrue()
        ->and($runs->tellsAs($runs->passing(Passed::of(Revision::ref('5eeca8f'), 'mutation / verdict', 0))))->toBeFalse()
        ->and($runs->tellsAs($runs->cutShort()))->toBeFalse()
        ->and(ScopeRuns::none()->judging($judged)->tellsAs(ScopeRuns::none()))->toBeFalse();
});

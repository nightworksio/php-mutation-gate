<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Proof\Judged;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\LedgerFile;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\ScopeRuns;
use NightWorksIO\MutationGate\Tests\Support\Moment;

function judgedRun(): Judged
{
    return Judged::of(
        Revision::ref(str_repeat('a1', 20)),
        'mutation / verdict',
        Moment::at('2026-11-09T12:00:00Z'),
        Paths::of(Path::of('src/Money.php')),
    );
}

it('keeps the default branch\'s last judged run in the ledger file, and reads it back', function (): void {
    $ledger = Ledger::empty()->withRuns(ScopeRuns::none()->judging(judgedRun()));

    $read = LedgerFile::decode(LedgerFile::encode($ledger))->runs()->judged();

    expect($read)->toEqual(judgedRun());
});

it('reads a ledger without a judged run, as an earlier gate wrote it, as one with none', function (): void {
    expect(LedgerFile::decode(LedgerFile::encode(Ledger::empty()))->runs()->judged())->toBeInstanceOf(CannotTell::class);
});

it('is newer than a pass of another commit before it, and than none', function (): void {
    $passed = static fn(string $commit, string $at): Passed => Passed::of(Revision::ref($commit), 'mutation / verdict', 0)
        ->passedAt(Moment::at($at));

    expect(judgedRun()->isNewerThan(CannotTell::because('never passed')))->toBeTrue()
        ->and(judgedRun()->isNewerThan($passed(str_repeat('b2', 20), '2026-11-09T11:00:00Z')))->toBeTrue()
        ->and(judgedRun()->isNewerThan($passed(str_repeat('b2', 20), '2026-11-09T13:00:00Z')))->toBeFalse()
        ->and(judgedRun()->isNewerThan($passed(str_repeat('a1', 20), '2026-11-09T11:00:00Z')))->toBeFalse();
});

<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Proof\Copies;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\ScopeRuns;
use NightWorksIO\MutationGate\Core\Proof\Timing;
use NightWorksIO\MutationGate\Core\Proof\Timings;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Moment;

/** A ledger whose run at this moment proved these units and passed this commit. */
$ledger = static function (string $at, string $commit, string ...$units): Ledger {
    $ledger = Ledger::empty()->withRuns(ScopeRuns::none()->passing(
        Passed::of(Revision::ref($commit), 'mutation / verdict', 0)->passedAt(Moment::at($at)),
    ));

    foreach ($units as $unit) {
        $ledger = $ledger->withProof(Proof::of(Digest::sha256Of(sprintf('%s at %s', $unit, $at)), Path::of($unit), Mutants::none(), Run::of('main', Moment::at($at), Digest::sha256Of('base'))));
    }

    return $ledger;
};

/** The units a ledger proves, and the commit it last passed. */
$read = static function (Ledger $ledger): array {
    $passed = $ledger->runs()->passed();

    return [
        array_map(static fn(Proof $proof): string => $proof->unit()->value(), [...$ledger->proofs()]),
        $passed instanceof Passed ? $passed->commit()->name() : 'none',
    ];
};

it('reads the cache\'s copy a run on main saved at 19:06 over the store\'s copy its cancelled deliver left at 16:57, where the newest wins', function () use ($ledger, $read): void {
    $stored = $ledger('2026-11-02T16:57:00Z', '3ebe0a326', 'src/A.php');
    $kept = $ledger('2026-11-02T19:06:00Z', '0b18c8345', 'src/A.php', 'src/B.php');

    expect($read(Copies::Newest->chosen($kept, $stored)))->toBe([['src/A.php', 'src/B.php'], '0b18c8345'])
        ->and($read(Copies::Together->chosen($kept, $stored)))->toBe([['src/A.php'], '3ebe0a326'])
        ->and($read(Copies::Merged->chosen($kept, $stored)))->toBe([['src/A.php', 'src/B.php', 'src/A.php'], '0b18c8345']);
});

it('reads the store\'s copy where it is the later, as where the cache kept an older one', function () use ($ledger, $read): void {
    $stored = $ledger('2026-11-02T19:06:00Z', 'newer', 'src/B.php');
    $kept = $ledger('2026-11-02T16:57:00Z', 'older', 'src/A.php');

    expect($read(Copies::Newest->chosen($kept, $stored)))->toBe([['src/B.php'], 'newer'])
        ->and($read(Copies::Together->chosen($kept, $stored)))->toBe([['src/B.php'], 'newer'])
        ->and($read(Copies::Merged->chosen($kept, $stored)))->toBe([['src/B.php', 'src/A.php'], 'newer']);
});

it('reads the store\'s copy where neither is the later', function () use ($ledger, $read): void {
    $stored = $ledger('2026-11-02T16:57:00Z', 'stored', 'src/A.php');
    $kept = $ledger('2026-11-02T16:57:00Z', 'kept', 'src/B.php');

    expect($read(Copies::Newest->chosen($kept, $stored)))->toBe([['src/A.php'], 'stored'])
        ->and($read(Copies::Newest->chosen(Ledger::empty(), Ledger::empty())))->toBe([[], 'none'])
        ->and($read(Copies::Newest->chosen($kept, Ledger::empty())))->toBe([['src/B.php'], 'kept'])
        ->and($read(Copies::Newest->chosen(Ledger::empty(), $stored)))->toBe([['src/A.php'], 'stored']);
});

it('takes a copy\'s latest record from its proofs\' runs, its timings and the commit that passed, whichever is the latest', function () use ($ledger, $read): void {
    $stored = $ledger('2026-11-02T19:06:00Z', 'stored', 'src/A.php');
    $timed = Ledger::empty()->withTimings(Timings::of(Timing::of(Path::of('src/B.php'), Seconds::of(1.0), 'pest', Moment::at('2026-11-02T19:07:00Z'))));
    $passedLater = Ledger::empty()->withRuns(ScopeRuns::none()->passing(Passed::of(Revision::ref('kept'), 'check', 0)->passedAt(Moment::at('2026-11-02T19:08:00Z'))));
    $passedUntimed = Ledger::empty()->withRuns(ScopeRuns::none()->passing(Passed::of(Revision::ref('kept'), 'check', 0)));
    $older = $ledger('2026-11-02T16:57:00Z', 'kept', 'src/C.php')->withTimings(Timings::of(Timing::of(Path::of('src/C.php'), Seconds::of(1.0), 'pest', Moment::at('2026-11-02T17:00:00Z'))));

    expect(Copies::Newest->chosen($timed, $stored))->toBe($timed)
        ->and(Copies::Newest->chosen($passedLater, $stored))->toBe($passedLater)
        ->and(Copies::Newest->chosen($passedUntimed, $stored))->toBe($stored)
        ->and(Copies::Newest->chosen($older, $stored))->toBe($stored)
        ->and(Copies::Newest->chosen($stored, $older))->toBe($stored)
        ->and($read(Copies::Newest->chosen($passedUntimed, Ledger::empty())))->toBe([[], 'none']);
});

it('is named in the config by the words its cases carry', function (): void {
    expect(array_map(static fn(Copies $copies): string => $copies->value, Copies::cases()))->toBe(['newest', 'together', 'merged']);
});

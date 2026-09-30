<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKill;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKills;
use NightWorksIO\MutationGate\Core\Proof\Ambiguous;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\NoRecord;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Recorded;
use NightWorksIO\MutationGate\Core\Proof\Records;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Tests\Support\Moment;
use NightWorksIO\MutationGate\Tests\Support\Sought;

/** A mutant of `src/Money.php` with this id, recorded with this status. */
$mutant = static function (string $id, MutantStatus $status): Mutant {
    $parsed = MutantId::parse($id);

    return Mutant::of(
        $parsed instanceof MutantId ? $parsed : MutantId::hash(Path::of('src/Money.php'), 'Plus', '', 0),
        '',
        Location::of(Path::of('src/Money.php'), Line::of(11), Line::of(11)),
        Mutation::of('Plus', MutatorFamily::Arithmetic, ''),
        $status,
        Unmeasured::duration(),
    );
};

/** A ledger with one proof of `src/Money.php`, established by a run at this instant, holding these mutants. */
$ledger = static fn(string $at, Mutant ...$mutants): Ledger => Ledger::empty()->withProof(Proof::of(
    Digest::sha256Of($at),
    Path::of('src/Money.php'),
    Mutants::of(...$mutants),
    Run::of(sprintf('run at %s', $at), Moment::at($at), Digest::sha256Of('base')),
));

/** @return list<string> each record, as the run that holds it and the status it holds */
$history = static fn(Records $records): array => array_map(
    static fn(Recorded $record): string => sprintf(
        '%s %s %s',
        $record->scope()->name(),
        $record->proof()->run()->id(),
        $record->mutant()->status()->value,
    ),
    [...$records],
);

it('holds every record of a mutant across the ledgers, the newest first', function () use ($mutant, $ledger, $history): void {
    $records = Records::none(Sought::of('abcdef'))
        ->in(Scope::branch('main'), $ledger('2026-09-28T10:00:00Z', $mutant('abcdef000001', MutantStatus::Survived)))
        ->in(Scope::pullRequest(7), $ledger('2026-09-30T10:00:00Z', $mutant('abcdef000001', MutantStatus::Killed), $mutant('012345000001', MutantStatus::Killed)))
        ->in(Scope::branch('feature'), $ledger('2026-09-29T10:00:00Z', $mutant('abcdef000001', MutantStatus::Survived)));
    $newest = $records->newest();

    expect($history($records))->toBe([
        'refs/pull/7 run at 2026-09-30T10:00:00Z killed',
        'feature run at 2026-09-29T10:00:00Z survived',
        'main run at 2026-09-28T10:00:00Z survived',
    ])
        ->and($records)->toHaveCount(3)
        ->and($newest instanceof Recorded ? $newest->mutant()->status() : $newest)->toBe(MutantStatus::Killed);
});

it('keeps two records made at the same moment in the order the ledgers were given', function () use ($mutant, $ledger, $history): void {
    $records = Records::none(Sought::of('abcdef000001'))
        ->in(Scope::branch('main'), $ledger('2026-09-29T10:00:00Z', $mutant('abcdef000001', MutantStatus::Killed)))
        ->in(Scope::branch('main'), Ledger::empty()->withProof(Proof::of(Digest::sha256Of('other'), Path::of('src/Money.php'), Mutants::of($mutant('abcdef000001', MutantStatus::Survived)), Run::of('other run', Moment::at('2026-09-29T10:00:00Z'), Digest::sha256Of('base')))));

    expect($history($records))->toBe(['main run at 2026-09-29T10:00:00Z killed', 'main other run survived']);
});

it('has no record where no ledger holds a mutant the prefix names', function () use ($mutant, $ledger): void {
    $sought = Sought::of('abcdef');
    $records = Records::none($sought)->in(Scope::branch('main'), $ledger('2026-09-29T10:00:00Z', $mutant('012345000001', MutantStatus::Survived)));

    expect($records->newest())->toEqual(NoRecord::of($sought))
        ->and($records->newest() instanceof NoRecord ? $records->newest()->sought() : null)->toBe($sought)
        ->and($records->ids())->toEqual(MutantIds::none());
});

it('names each mutant a prefix names where it names several, the most recently recorded first', function () use ($mutant, $ledger): void {
    $records = Records::none(Sought::of('abcdef'))
        ->in(Scope::branch('main'), $ledger('2026-09-28T10:00:00Z', $mutant('abcdef000001', MutantStatus::Survived)))
        ->in(Scope::branch('main'), $ledger('2026-09-29T10:00:00Z', $mutant('abcdef000002', MutantStatus::Survived)));
    $newest = $records->newest();

    expect($newest instanceof Ambiguous ? $newest->why() : $newest)
        ->toBe('abcdef names 2 recorded mutants: abcdef000002, abcdef000001. Give more of the id.')
        ->and($newest instanceof Ambiguous ? count($newest->candidates()) : $newest)->toBe(2);
});

it('holds a kill a ledger proved beside the mutants its proofs reported', function () use ($mutant): void {
    $kill = ProvedKill::of(MutantId::hash(Path::of('src/Money.php'), 'Minus', '-b', 0), Path::of('src/Money.php'), Line::of(12), 'Minus', TestIds::none());
    $proof = Proof::held(
        Digest::sha256Of('held'),
        Path::of('src/Money.php'),
        Mutants::of($mutant('abcdef000001', MutantStatus::Survived)),
        ProvedKills::of($kill),
        Run::of('local', Moment::at('2026-09-29T10:00:00Z'), Digest::sha256Of('base')),
    );
    $records = Records::none(Sought::of(mb_substr($kill->id()->value(), 0, 6)))->in(Scope::branch('main'), Ledger::empty()->withProof($proof));
    $newest = $records->newest();

    expect($newest instanceof Recorded ? $newest->mutant() : $newest)->toBe($kill);
});

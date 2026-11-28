<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\KeysApart;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Proof\Digests;
use NightWorksIO\MutationGate\Core\Proof\Inputs;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Run;
use NightWorksIO\MutationGate\Core\Proof\RunProfile;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Reach\Reasons;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;

/** The default branch's ledger, holding a proof of each of these units of its source as it is now, under another key. */
function keysApartLedger(string ...$units): Ledger
{
    $ledger = Ledger::empty();

    foreach ($units as $unit) {
        $ledger = $ledger->withProof(Proof::of(
            Digest::of(sprintf('key of %s', $unit)),
            Path::of($unit),
            Mutants::none(),
            Run::of('default', Instant::at(new DateTimeImmutable('2026-09-29T20:48:17Z')), Digest::of(str_repeat('b', 64))),
        )->withInputs(Inputs::of(Digest::sha256Of(sprintf('%s now', $unit)), Digest::sha256Of('mutation'))));
    }

    return $ledger;
}

/** The digests of these units' sources as they are now. */
function keysApartNow(string ...$units): Digests
{
    $now = Digests::of(Digest::sha256Of('another mutation'));

    foreach ($units as $unit) {
        $now = $now->withSource(Path::of($unit), Digest::sha256Of(sprintf('%s now', $unit)));
    }

    return $now;
}

/** These units to run. */
function keysApartUnits(string ...$units): Units
{
    return Units::of(...array_map(static fn(string $unit): Unit => Unit::file(Path::of($unit)), $units));
}

it('says how many of the units a run mutates the default branch proved of their source now under another key', function (string $proved, string $toRun, string $said): void {
    $reasons = KeysApart::said(
        Reasons::of(Reason::that('reached')),
        keysApartUnits(...explode(',', $toRun)),
        keysApartLedger(...explode(',', $proved)),
        RunProfile::standard(),
        keysApartNow(...explode(',', $toRun)),
        offTheDefaultBranch: true,
        suggesting: true,
    );

    expect($reasons)->toEqual(Reasons::of(Reason::that('reached'), Reason::that(sprintf(<<<'SAID'
        The default branch proved %s this run mutates for the same code under another key, so they run
        again: their tests, or a setting that affects results, differ. Where a context block sets one for pull
        requests alone, doctor names it.
        SAID, $said))));
})->with([
    'one unit of one' => ['src/A.php', 'src/A.php', '1 of the unit'],
    'two units of three' => ['src/A.php,src/B.php', 'src/A.php,src/B.php,src/C.php', '2 of the units'],
    'one unit of two' => ['src/B.php', 'src/A.php,src/B.php', '1 of the units'],
]);

it('says nothing where the default branch proved none of the units under another key, the plan is on the default branch, or reports suggest nothing', function (string $proved, bool $off, bool $suggesting): void {
    $reasons = Reasons::of(Reason::that('reached'));
    $ledger = $proved === '' ? keysApartLedger() : keysApartLedger($proved);

    expect(KeysApart::said($reasons, keysApartUnits('src/A.php'), $ledger, RunProfile::standard(), keysApartNow('src/A.php'), $off, $suggesting))
        ->toBe($reasons);
})->with([
    'none proved' => ['', true, true],
    'on the default branch' => ['src/A.php', false, true],
    'suggesting nothing' => ['src/A.php', true, false],
]);

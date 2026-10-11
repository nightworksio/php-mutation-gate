<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Recheck\NoRecheck;
use NightWorksIO\MutationGate\Core\Recheck\Recheck;
use NightWorksIO\MutationGate\Core\Recheck\Rechecked;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Rechecks;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

$holds = [
    'holds:src/Adapter/Opcache/Compiler.php',
];

afterEach(function (): void {
    Scratch::sweep();
});

$store = static fn(string ...$files): ProofStoreFake => Rechecks::store(...$files);

/** The survivors re-checked, with what each became: its id and its status now, or gone. */
$outcomes = static fn(Rechecked|NoRecheck|CannotJudge $rechecked): array => $rechecked instanceof Rechecked
    ? array_map(static fn(Recheck $recheck): array => [
        $recheck->before()->mutant()->id()->value(),
        $recheck->now() instanceof JudgedMutant ? $recheck->now()->mutant()->status() : $recheck->now(),
    ], [...$rechecked])
    : [$rechecked];

it('leaves out a survivor proven equivalent to its original', function () use ($store, $outcomes): void {
    $equivalent = Checkable::inPlace(Contents::of("<?php\n\nfinal  class Money\n{\n}\n"));
    $ran = Rechecks::ports('feature', $store('src/Money.php'), ScriptedRunner::fixture()->checking($equivalent));

    expect(array_column($outcomes(Rechecks::in($ran, Flows::settings())), 0))->toBe(['95e61bd8bf62']);
})->group(...$holds);

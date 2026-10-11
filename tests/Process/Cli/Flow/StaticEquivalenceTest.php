<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\StaticEquivalence;
use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Core\Verdict\UnitResults;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\ProvenMoney;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

$holds = [
    'holds:src/Adapter/Opcache/Compiler.php',
    'holds:src/Adapter/Opcache/Prover.php',
    'holds:src/Cli/Flow/StaticEquivalence.php',
];

afterEach(function (): void {
    Scratch::sweep();
});

it('proves each survivor that compiles to its original as written, and no mutant that did not survive', function (): void {
    $survivors = array_values(array_map(
        static fn(Mutant $mutant): string => $mutant->nativeId(),
        array_filter(
            [...Flows::mutantsOf('src/Money.php')],
            static fn(Mutant $mutant): bool => $mutant->status() === MutantStatus::Survived,
        ),
    ));

    expect(ProvenMoney::proven(ScriptedRunner::fixture()->checking(Checkable::inPlace(Contents::of(ProvenMoney::EQUIVALENT)))))
        ->toBe($survivors)
        ->and($survivors)->not->toBe([]);
})->group(...$holds);

it('proves a survivor against its original printed as its runner prints it, where the runner gives one', function (): void {
    $printed = Checkable::printed(Contents::of("<?php\n\nclass Other\n{\n}\n"), Contents::of("<?php\n\nclass  Other\n{\n}\n"));

    expect(ProvenMoney::proven(ScriptedRunner::fixture()->checking($printed)))->not->toBe([]);
})->group(...$holds);

it('proves the survivors of every result it is handed', function (): void {
    $runner = ScriptedRunner::fixture()->checking(Checkable::inPlace(Contents::of(ProvenMoney::EQUIVALENT)));
    $check = new StaticEquivalence(Flows::adapters(Flows::project(), [], $runner), Flows::settings());
    $proven = $check->among(UnitResults::of(
        UnitResult::of(Unit::file(Path::of('src/Held.php')), Origin::Carried, Flows::mutantsOf('src/Held.php')),
        UnitResult::of(Unit::file(Path::of('src/Money.php')), Origin::Run, Flows::mutantsOf('src/Money.php')),
    ));

    expect(count($proven->proven))->toBeGreaterThan(0)
        ->and(array_map(static fn(MutantId $id): string => $id->value(), [...$proven->proven]))
        ->toBe(array_map(static fn(MutantId $id): string => $id->value(), [...$check->proven(Flows::mutantsOf('src/Money.php'))->proven]));
})->group(...$holds);

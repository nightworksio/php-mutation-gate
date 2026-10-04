<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\StaticEquivalence;
use NightWorksIO\MutationGate\Config\Equivalence;
use NightWorksIO\MutationGate\Config\Setting;
use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\CannotJudge;
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
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

afterEach(function (): void {
    Scratch::sweep();
});

const EQUIVALENT_MONEY = "<?php\n\nfinal class Money\n{\n\n}\n";

/** @return list<string> the native ids of the mutants of src/Money.php a check proves equivalent */
function provenOfMoney(ScriptedRunner $runner, string $project = '', Setting ...$settings): array
{
    $project = $project === '' ? Flows::project() : $project;
    $mutants = Flows::mutantsOf('src/Money.php');
    $proven = new StaticEquivalence(Flows::adapters($project, [], $runner), Flows::settings(...$settings))->proven($mutants);
    $native = [];

    foreach ($mutants as $mutant) {
        $native = $proven->proven->has($mutant->id()) ? [...$native, $mutant->nativeId()] : $native;
    }

    return $native;
}

it('proves each survivor that compiles to its original as written, and no mutant that did not survive', function (): void {
    $survivors = array_values(array_map(
        static fn(Mutant $mutant): string => $mutant->nativeId(),
        array_filter(
            [...Flows::mutantsOf('src/Money.php')],
            static fn(Mutant $mutant): bool => $mutant->status() === MutantStatus::Survived,
        ),
    ));

    expect(provenOfMoney(ScriptedRunner::fixture()->checking(Checkable::inPlace(Contents::of(EQUIVALENT_MONEY)))))
        ->toBe($survivors)
        ->and($survivors)->not->toBe([]);
});

it('proves a survivor against its original printed as its runner prints it, where the runner gives one', function (): void {
    $printed = Checkable::printed(Contents::of("<?php\nclass Other {}\n"), Contents::of("<?php\n\nclass Other\n{\n}\n"));

    expect(provenOfMoney(ScriptedRunner::fixture()->checking($printed)))->not->toBe([]);
});

it('proves nothing where the mutant differs, the runner cannot give it, the file is gone or the check is off', function (
    Closure $setUp,
): void {
    /** @var array{ScriptedRunner, string, list<Setting>} $given */
    $given = $setUp();
    [$runner, $project, $settings] = $given;

    expect(provenOfMoney($runner, $project, ...$settings))->toBe([]);
})->with([
    'a changed program' => [fn(): array => [
        ScriptedRunner::fixture()->checking(Checkable::inPlace(Contents::of("<?php\n\nfinal class Money\n{\n    const A = 1;\n}\n"))),
        '',
        [],
    ]],
    'no mutant from the runner' => [fn(): array => [
        ScriptedRunner::fixture()->checking(CannotJudge::because('The runner cannot print it.')),
        '',
        [],
    ]],
    'the file gone' => [function (): array {
        $project = Flows::project();
        unlink(sprintf('%s/src/Money.php', $project));

        return [ScriptedRunner::fixture()->checking(Checkable::inPlace(Contents::of(EQUIVALENT_MONEY))), $project, []];
    }],
    'equivalence.static false' => [fn(): array => [
        ScriptedRunner::fixture()->checking(Checkable::inPlace(Contents::of(EQUIVALENT_MONEY))),
        '',
        [Equivalence::notProvenStatically()],
    ]],
]);

it('proves the survivors of every result it is handed', function (): void {
    $runner = ScriptedRunner::fixture()->checking(Checkable::inPlace(Contents::of(EQUIVALENT_MONEY)));
    $check = new StaticEquivalence(Flows::adapters(Flows::project(), [], $runner), Flows::settings());
    $proven = $check->among(UnitResults::of(
        UnitResult::of(Unit::file(Path::of('src/Held.php')), Origin::Carried, Flows::mutantsOf('src/Held.php')),
        UnitResult::of(Unit::file(Path::of('src/Money.php')), Origin::Run, Flows::mutantsOf('src/Money.php')),
    ));

    expect(count($proven->proven))->toBeGreaterThan(0)
        ->and(array_map(static fn(MutantId $id): string => $id->value(), [...$proven->proven]))
        ->toBe(array_map(static fn(MutantId $id): string => $id->value(), [...$check->proven(Flows::mutantsOf('src/Money.php'))->proven]));
});
